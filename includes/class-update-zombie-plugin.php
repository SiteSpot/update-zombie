<?php
/**
 * Plugin wiring.
 *
 * @package Update_Zombie
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers hooks, owns the cron schedule and holds the shared collaborators.
 *
 * @since 0.1.0
 */
class Update_Zombie_Plugin {

	const CRON_SCAN    = 'update_zombie_scan';
	const CRON_PROCESS = 'update_zombie_process';
	const CRON_PRUNE   = 'update_zombie_prune';
	const CRON_UPDATER = 'update_zombie_run_updater';

	const INTERVAL_PROCESS = 'update_zombie_five_minutes';
	const INTERVAL_SCAN    = 'update_zombie_fifteen_minutes';

	/**
	 * Enforcer instance.
	 *
	 * @since 0.1.0
	 * @var Update_Zombie_Enforcer
	 */
	protected $enforcer;

	/**
	 * Admin instance, only built in admin and cron contexts.
	 *
	 * @since 0.1.0
	 * @var Update_Zombie_Admin|null
	 */
	protected $admin = null;

	/**
	 * Whether this instance is refreshing WordPress update data.
	 *
	 * Prevents our own transient writes from scheduling another immediate scan.
	 *
	 * @since 0.5.0
	 * @var bool
	 */
	protected $refreshing_updates = false;

	/**
	 * Registers everything.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'cron_schedules', array( $this, 'register_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- Five minutes is the queue drain interval; each run processes at most one item.

		add_action( 'admin_init', array( 'Update_Zombie_Store', 'maybe_upgrade' ) );

		add_filter( 'wp_ai_client_default_request_timeout', array( $this, 'filter_request_timeout' ) );

		$this->register_ai_provider();

		add_action( self::CRON_SCAN, array( $this, 'run_scan' ) );
		add_action( self::CRON_PROCESS, array( $this, 'run_process' ) );
		add_action( self::CRON_PRUNE, array( 'Update_Zombie_Store', 'prune' ) );

		// React promptly when WordPress refreshes its own update data.
		foreach ( array( 'update_plugins', 'update_themes', 'update_core' ) as $transient ) {
			add_action( "set_site_transient_{$transient}", array( $this, 'schedule_scan_soon' ) );
		}

		$this->enforcer = new Update_Zombie_Enforcer();
		$this->enforcer->init();

		add_action( self::CRON_UPDATER, array( $this->enforcer, 'run_updater' ) );
		add_action( 'upgrader_process_complete', array( $this->enforcer, 'record_completion' ), 10, 2 );

		if ( is_admin() ) {
			$this->admin = new Update_Zombie_Admin();
			$this->admin->init();
		}
	}

	/**
	 * Registers OpenRouter with core's AI Client registry.
	 *
	 * WordPress ships the AI Client SDK but registers no providers, so without
	 * this there is nothing for wp_ai_client_prompt() to talk to. Registering
	 * into the shared registry rather than holding a private client means any
	 * other plugin's provider keeps working alongside this one.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function register_ai_provider() {
		if ( ! function_exists( 'wp_supports_ai' ) || ! class_exists( 'WordPress\AiClient\AiClient' ) ) {
			return;
		}

		$key = Update_Zombie_Credentials::openrouter_key();

		if ( '' === $key ) {
			return;
		}

		try {
			$registry = \WordPress\AiClient\AiClient::defaultRegistry();

			if ( ! $registry->hasProvider( Update_Zombie_OpenRouter_Provider::PROVIDER_ID ) ) {
				$registry->registerProvider( Update_Zombie_OpenRouter_Provider::class );
			}

			$registry->setProviderRequestAuthentication(
				Update_Zombie_OpenRouter_Provider::PROVIDER_ID,
				new \WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication( $key )
			);
		} catch ( Throwable $e ) {
			// A registry that refuses our provider is a hard failure, but not
			// one worth taking the whole admin down for. Analyzer availability
			// reports it instead.
			return;
		}
	}

	/**
	 * Raises the AI request timeout.
	 *
	 * Diff review sends far more input than a typical AI feature, and the
	 * default timeout assumes short prompts. A large diff can take minutes.
	 *
	 * @since 0.2.0
	 *
	 * @param int $timeout Default timeout in seconds.
	 * @return int
	 */
	public function filter_request_timeout( $timeout ) {
		$timeout = max( (int) $timeout, (int) Update_Zombie_Settings::get( 'request_timeout', 300 ) );

		/**
		 * Filters the request timeout used for update analysis.
		 *
		 * @since 0.2.0
		 *
		 * @param int $timeout Timeout in seconds.
		 */
		return (int) apply_filters( 'update_zombie_request_timeout', $timeout );
	}

	/**
	 * Adds the queue drain interval.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Existing schedules.
	 * @return array<string, array{interval: int, display: string}>
	 */
	public function register_schedule( $schedules ) {
		$schedules[ self::INTERVAL_PROCESS ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes (Update Zombie queue)', 'update-zombie' ),
		);

		$schedules[ self::INTERVAL_SCAN ] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every fifteen minutes (Update Zombie check)', 'update-zombie' ),
		);

		return $schedules;
	}

	/**
	 * Queues a scan shortly after WordPress refreshes its update transients.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function schedule_scan_soon() {
		if ( $this->refreshing_updates ) {
			return;
		}

		if ( ! wp_next_scheduled( self::CRON_SCAN ) || wp_next_scheduled( self::CRON_SCAN ) > time() + ( 2 * MINUTE_IN_SECONDS ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_SCAN );
		}
	}

	/**
	 * Cron callback: look for new updates to queue.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function run_scan() {
		Update_Zombie_Store::maybe_upgrade();

		/*
		 * Ask WordPress and every registered update provider ourselves rather
		 * than waiting for core's twice-daily check. A security patch should not
		 * sit unnoticed because nobody happened to log in.
		 */
		if ( ! function_exists( 'wp_update_plugins' ) ) {
			require_once ABSPATH . 'wp-includes/update.php';
		}

		/*
		 * Core normally throttles cron-context plugin and theme checks for two
		 * hours. Make the cached timestamp appear stale only while the official
		 * update functions run. The cached response remains intact if a provider
		 * is unavailable, and premium/private pre_set_site_transient hooks still
		 * receive and populate WordPress's normal update objects.
		 */
		$force_stale = static function ( $updates ) {
			if ( is_object( $updates ) ) {
				$updates               = clone $updates;
				$updates->last_checked = 0;
			}

			return $updates;
		};

		$this->refreshing_updates = true;

		try {
			foreach ( array( 'update_plugins' => 'wp_update_plugins', 'update_themes' => 'wp_update_themes' ) as $transient => $callback ) {
				$hook = "site_transient_{$transient}";
				add_filter( $hook, $force_stale, PHP_INT_MAX );

				try {
					call_user_func( $callback );
				} finally {
					remove_filter( $hook, $force_stale, PHP_INT_MAX );
				}
			}

			wp_version_check( array(), true );
		} finally {
			$this->refreshing_updates = false;
		}

		$scanner = new Update_Zombie_Scanner();
		$scanner->scan_all();
	}

	/**
	 * Cron callback: analyse one queued update.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function run_process() {
		Update_Zombie_Store::maybe_upgrade();

		$processor = new Update_Zombie_Processor();
		$processor->process_next();
	}

	/**
	 * Returns the enforcer.
	 *
	 * @since 0.1.0
	 *
	 * @return Update_Zombie_Enforcer
	 */
	public function enforcer() {
		return $this->enforcer;
	}

	/**
	 * Activation: build the table and schedule the crons.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function activate() {
		Update_Zombie_Store::install();

		$interval = (string) Update_Zombie_Settings::get( 'analysis_interval', 'hourly' );

		if ( ! wp_next_scheduled( self::CRON_SCAN ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, $interval, self::CRON_SCAN );
		}

		if ( ! wp_next_scheduled( self::CRON_PROCESS ) ) {
			wp_schedule_event( time() + ( 2 * MINUTE_IN_SECONDS ), self::INTERVAL_PROCESS, self::CRON_PROCESS );
		}

		if ( ! wp_next_scheduled( self::CRON_PRUNE ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_PRUNE );
		}
	}

	/**
	 * Deactivation: unschedule everything. Reports are left alone.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function deactivate() {
		foreach ( array( self::CRON_SCAN, self::CRON_PROCESS, self::CRON_PRUNE, self::CRON_UPDATER ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		delete_transient( Update_Zombie_Processor::LOCK_KEY );
	}

	/**
	 * Reschedules the scan cron after the interval setting changes.
	 *
	 * @since 0.1.0
	 *
	 * @param string $interval Cron schedule name.
	 * @return void
	 */
	public static function reschedule_scan( $interval ) {
		wp_clear_scheduled_hook( self::CRON_SCAN );
		wp_schedule_event( time() + MINUTE_IN_SECONDS, $interval, self::CRON_SCAN );
	}
}
