<?php
/**
 * Update policy enforcement.
 *
 * @package Update_Zombie
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns stored verdicts into answers for WordPress's auto-update filters.
 *
 * In advisory mode this class reports and nothing more: every filter returns
 * whatever WordPress had already decided.
 *
 * @since 0.1.0
 */
class Update_Zombie_Enforcer {

	/**
	 * Registers the auto-update filters.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'auto_update_plugin', array( $this, 'filter_plugin' ), 20, 2 );
		add_filter( 'auto_update_theme', array( $this, 'filter_theme' ), 20, 2 );
		add_filter( 'auto_update_core', array( $this, 'filter_core' ), 20, 2 );
		add_filter( 'allow_major_auto_core_updates', array( $this, 'filter_major_core' ), 20 );
	}

	/**
	 * Decides whether a plugin update may install automatically.
	 *
	 * @since 0.1.0
	 *
	 * @param bool|null $update Whether WordPress would update it.
	 * @param object    $item   The update offer.
	 * @return bool|null
	 */
	public function filter_plugin( $update, $item ) {
		$item = (object) $item;

		if ( empty( $item->new_version ) ) {
			return $update;
		}

		$slug = Update_Zombie_Scanner::plugin_slug(
			(string) ( $item->plugin ?? '' ),
			(string) ( $item->slug ?? '' )
		);

		if ( '' === $slug ) {
			return $update;
		}

		return $this->decide_for( 'plugin', $slug, $item->new_version, $update );
	}

	/**
	 * Decides whether a theme update may install automatically.
	 *
	 * @since 0.1.0
	 *
	 * @param bool|null    $update Whether WordPress would update it.
	 * @param object|array $item   The update offer.
	 * @return bool|null
	 */
	public function filter_theme( $update, $item ) {
		$item = (array) $item;

		if ( empty( $item['new_version'] ) || empty( $item['theme'] ) ) {
			return $update;
		}

		return $this->decide_for( 'theme', $item['theme'], $item['new_version'], $update );
	}

	/**
	 * Decides whether a core update may install automatically.
	 *
	 * @since 0.1.0
	 *
	 * @param bool|null $update Whether WordPress would update.
	 * @param object    $item   The core update offer.
	 * @return bool|null
	 */
	public function filter_core( $update, $item ) {
		$item = (object) $item;

		if ( empty( $item->current ) ) {
			return $update;
		}

		if ( ! Update_Zombie_Changelog::is_point_release( Update_Zombie_Scanner::current_core_version(), $item->current )
			&& ! Update_Zombie_Settings::get( 'core_majors' ) ) {
			return $update;
		}

		return $this->decide_for( 'core', 'wordpress', $item->current, $update );
	}

	/**
	 * Keeps major core releases manual unless the site owner opted in.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $allow Whether WordPress would allow major auto-updates.
	 * @return bool
	 */
	public function filter_major_core( $allow ) {
		if ( self::mode() === Update_Zombie_Settings::MODE_ADVISORY ) {
			return $allow;
		}

		return Update_Zombie_Settings::get( 'core_majors' ) ? $allow : false;
	}

	/**
	 * Applies the configured policy to one item.
	 *
	 * @since 0.1.0
	 *
	 * @param string    $type    Item type.
	 * @param string    $slug    Item slug.
	 * @param string    $version Offered version.
	 * @param bool|null $default What WordPress had already decided.
	 * @return bool|null
	 */
	protected function decide_for( $type, $slug, $version, $default ) {
		$mode = self::mode();

		if ( Update_Zombie_Settings::MODE_ADVISORY === $mode ) {
			return $default;
		}

		$report = Update_Zombie_Store::completed_for( $type, $slug, $version );

		if ( ! $report ) {
			// No verdict yet. Never widen WordPress's own decision on a guess.
			return $default;
		}

		$decision = self::evaluate( $report, $mode );

		if ( 'apply' === $decision ) {
			return true;
		}

		if ( 'hold' === $decision ) {
			return false;
		}

		return $default;
	}

	/**
	 * Evaluates a completed report against a mode.
	 *
	 * Split out from the filters so the admin screens and the processor can
	 * show the same answer without going through WordPress's update machinery.
	 *
	 * @since 0.1.0
	 *
	 * @param object $report Completed report row.
	 * @param string $mode   Enforcement mode.
	 * @return string One of apply, hold, defer.
	 */
	public static function evaluate( $report, $mode = null ) {
		$mode = $mode ? $mode : self::mode();

		if ( Update_Zombie_Settings::MODE_ADVISORY === $mode ) {
			return 'defer';
		}

		// An update judged actively harmful never installs itself, even when it
		// also claims to patch something. A security fix bundled into a release
		// this bad is a contradiction worth a human's attention; "questionable"
		// deliberately does not veto, so genuine fixes still land promptly with
		// their concerns reported alongside.
		if ( 'bad' === $report->verdict ) {
			return 'hold';
		}

		$threshold = (int) Update_Zombie_Settings::get( 'security_confidence', 70 );

		$is_security = ! empty( $report->is_security )
			&& self::security_substantiated( $report, $threshold );

		if ( $is_security && self::core_release_allowed( $report ) ) {
			return 'apply';
		}

		if ( in_array( $report->verdict, array( 'bad', 'questionable' ), true ) ) {
			return 'hold';
		}

		if ( 'hold' === $report->recommendation ) {
			return 'hold';
		}

		// A security-labelled release that did not pass the strict severity,
		// citation and confidence gate must follow WordPress's existing policy.
		// It cannot fall through and borrow Autopilot's good-update permission.
		if ( ! empty( $report->is_security ) || 'security' === $report->verdict ) {
			return 'defer';
		}

		if ( Update_Zombie_Settings::MODE_AUTOPILOT === $mode
			&& in_array( $report->verdict, array( 'good', 'neutral' ), true )
			&& in_array( $report->recommendation, array( 'apply', 'apply_now' ), true )
			&& self::core_release_allowed( $report ) ) {
			return 'apply';
		}

		return 'defer';
	}

	/**
	 * Requires the model to have shown its working before anything installs
	 * itself on the strength of a security claim.
	 *
	 * The cited file is bound to the reviewed diff by the analyzer. This final
	 * gate also requires high/critical impact and confidence on the same
	 * finding; a strong score on a separate low-impact finding cannot borrow
	 * its way into an unattended installation.
	 *
	 * @since 0.1.0
	 *
	 * @param object $report    Completed report row.
	 * @param int    $threshold Minimum per-finding confidence.
	 * @return bool
	 */
	protected static function security_substantiated( $report, $threshold ) {
		$payload = Update_Zombie_Store::payload( $report );

		foreach ( (array) ( $payload['security_findings'] ?? array() ) as $finding ) {
			$severity   = strtolower( (string) ( $finding['severity'] ?? 'unknown' ) );
			$confidence = is_numeric( $finding['confidence'] ?? null ) ? (int) $finding['confidence'] : 0;

			if ( ! empty( $finding['file'] )
				&& in_array( $severity, array( 'critical', 'high' ), true )
				&& $confidence >= $threshold ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Blocks automatic core majors unless the site owner opted in.
	 *
	 * @since 0.1.0
	 *
	 * @param object $report Report row.
	 * @return bool
	 */
	protected static function core_release_allowed( $report ) {
		if ( 'core' !== $report->item_type ) {
			return true;
		}

		if ( Update_Zombie_Settings::get( 'core_majors' ) ) {
			return true;
		}

		return Update_Zombie_Changelog::is_point_release( $report->old_version, $report->new_version );
	}

	/**
	 * Returns the active enforcement mode.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function mode() {
		$mode = (string) Update_Zombie_Settings::get( 'mode', Update_Zombie_Settings::MODE_ADVISORY );

		/**
		 * Filters the active enforcement mode.
		 *
		 * Lets a site force advisory mode from wp-config or an mu-plugin
		 * without touching the stored settings.
		 *
		 * @since 0.1.0
		 *
		 * @param string $mode The configured mode.
		 */
		return apply_filters( 'update_zombie_mode', $mode );
	}

	/**
	 * Records the decision a completed report leads to, and kicks off the
	 * updater when something should install now.
	 *
	 * @since 0.1.0
	 *
	 * @param object $report Completed report row.
	 * @return string The stored decision.
	 */
	public static function record_decision( $report ) {
		$mode     = self::mode();
		$outcome  = self::evaluate( $report, $mode );
		$decision = Update_Zombie_Store::DECISION_ADVISORY;

		if ( 'apply' === $outcome ) {
			$decision = Update_Zombie_Store::DECISION_SCHEDULED;

			if ( ! wp_next_scheduled( 'update_zombie_run_updater' ) ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'update_zombie_run_updater' );
			}
		} elseif ( 'hold' === $outcome ) {
			$decision = Update_Zombie_Store::DECISION_HELD;
		}

		Update_Zombie_Store::update( $report->id, array( 'decision' => $decision ) );

		$report->decision = $decision;

		return $decision;
	}

	/**
	 * Runs WordPress's own auto-updater.
	 *
	 * Our filters have already decided what is allowed through; this just asks
	 * core to act on them without waiting for the twice-daily cron.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function run_updater() {
		if ( Update_Zombie_Settings::MODE_ADVISORY === self::mode() ) {
			return;
		}

		if ( ! function_exists( 'wp_maybe_auto_update' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		if ( function_exists( 'wp_maybe_auto_update' ) ) {
			wp_maybe_auto_update();
		}
	}

	/**
	 * Marks scheduled reports as applied once the item reaches the new version.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Upgrader          $upgrader Upgrader instance.
	 * @param array<string, mixed> $hook_extra Update context.
	 * @return void
	 */
	public function record_completion( $upgrader, $hook_extra ) {
		unset( $upgrader );

		$type = $hook_extra['type'] ?? '';

		if ( 'core' === $type ) {
			$this->mark_applied( 'core', 'wordpress' );

			return;
		}

		$items = array();

		if ( 'plugin' === $type ) {
			$items = ! empty( $hook_extra['plugins'] ) ? (array) $hook_extra['plugins'] : array_filter( array( $hook_extra['plugin'] ?? '' ) );
		} elseif ( 'theme' === $type ) {
			$items = ! empty( $hook_extra['themes'] ) ? (array) $hook_extra['themes'] : array_filter( array( $hook_extra['theme'] ?? '' ) );
		}

		foreach ( $items as $item ) {
			$slug = 'plugin' === $type ? dirname( (string) $item ) : (string) $item;

			if ( '.' === $slug ) {
				$slug = basename( (string) $item, '.php' );
			}

			$this->mark_applied( $type, $slug );
		}
	}

	/**
	 * Flips a scheduled report to applied.
	 *
	 * @since 0.1.0
	 *
	 * @param string $type Item type.
	 * @param string $slug Item slug.
	 * @return void
	 */
	protected function mark_applied( $type, $slug ) {
		// Search by slug rather than taking the newest few rows of this type:
		// with dozens of plugins queued, the one just installed is often not
		// among them.
		$result = Update_Zombie_Store::query(
			array(
				'item_type' => $type,
				'search'    => $slug,
				'per_page'  => 20,
			)
		);

		foreach ( $result['items'] as $row ) {
			if ( $row->item_slug !== $slug ) {
				continue;
			}

			$was_ours = Update_Zombie_Store::DECISION_SCHEDULED === $row->decision;
			$changes  = array( 'applied_at' => current_time( 'mysql', true ) );

			if ( $was_ours ) {
				$changes['decision'] = Update_Zombie_Store::DECISION_AUTO;
				$row->decision       = Update_Zombie_Store::DECISION_AUTO;
			}

			Update_Zombie_Store::update( $row->id, $changes );

			Update_Zombie_Log::record(
				Update_Zombie_Log::INSTALLED,
				$was_ours
					? __( 'Installed automatically by Update Zombie.', 'update-zombie' )
					: __( 'Installed by WordPress or by hand.', 'update-zombie' ),
				$row,
				array( 'automatic' => $was_ours )
			);

			return;
		}
	}
}
