<?php
/**
 * Admin screens, actions and notices.
 *
 * @package Update_Zombie
 */

defined( 'ABSPATH' ) || exit;

/**
 * Everything that only matters inside wp-admin.
 *
 * @since 0.1.0
 */
class Update_Zombie_Admin {

	const PAGE_REPORTS  = 'update-zombie';
	const PAGE_ACTIVITY = 'update-zombie-activity';
	const PAGE_SETTINGS = 'update-zombie-settings';

	const CAP_VIEW    = 'update_plugins';
	const CAP_MANAGE  = 'manage_options';

	const OPTION_GROUP = 'update_zombie_group';

	/**
	 * Registers admin hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'redirect_legacy_urls' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );

		add_action( 'admin_post_update_zombie_action', array( $this, 'handle_action' ) );
		add_action( 'wp_ajax_update_zombie_status', array( $this, 'ajax_status' ) );
		add_action( 'update_option_' . Update_Zombie_Settings::OPTION, array( $this, 'on_settings_saved' ), 10, 2 );

		add_filter( 'plugin_action_links_' . plugin_basename( UPDATE_ZOMBIE_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Adds the menu.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_menu() {
		// The badge nags about what needs a human: security fixes not yet
		// installed and anything held back. Not the queue, not the done pile.
		$pending = Update_Zombie_Store::count_attention();

		$title = __( 'Update Zombie', 'update-zombie' );

		if ( $pending ) {
			$title .= sprintf(
				' <span class="awaiting-mod"><span class="pending-count">%d</span></span>',
				$pending
			);
		}

		// One entry under Tools; Reports, Activity and Settings are tabs.
		add_management_page(
			__( 'Update Zombie', 'update-zombie' ),
			$title,
			self::CAP_VIEW,
			self::PAGE_REPORTS,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Routes the single Tools-menu page to the right tab.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function render_page() {
		switch ( self::current_tab() ) {
			case 'activity':
				$this->render_activity_page();
				break;
			case 'settings':
				$this->render_settings_page();
				break;
			default:
				$this->render_reports_page();
		}
	}

	/**
	 * Returns the active tab.
	 *
	 * @since 0.5.0
	 *
	 * @return string One of reports, activity, settings.
	 */
	public static function current_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view routing.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'reports';

		return in_array( $tab, array( 'activity', 'settings' ), true ) ? $tab : 'reports';
	}

	/**
	 * Renders the tab navigation shared by the three screens.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public static function render_tabs() {
		$tabs = array(
			'reports'  => array( __( 'Reports', 'update-zombie' ), self::reports_url(), self::CAP_VIEW ),
			'activity' => array( __( 'Activity', 'update-zombie' ), self::activity_url(), self::CAP_VIEW ),
			'settings' => array( __( 'Settings', 'update-zombie' ), self::settings_url(), self::CAP_MANAGE ),
		);

		$current = self::current_tab();

		echo '<nav class="nav-tab-wrapper uz-tabs" aria-label="' . esc_attr__( 'Update Zombie sections', 'update-zombie' ) . '">';

		foreach ( $tabs as $key => $tab ) {
			if ( ! current_user_can( $tab[2] ) ) {
				continue;
			}

			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( $tab[1] ),
				$current === $key ? ' nav-tab-active' : '',
				esc_html( $tab[0] )
			);
		}

		echo '</nav>';
	}

	/**
	 * Sends the old top-level menu URLs to their new home under Tools.
	 *
	 * Bookmarks, notification emails already sent, and the webhook payloads
	 * all carry the old admin.php links.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function redirect_legacy_urls() {
		global $pagenow;

		if ( 'admin.php' !== $pagenow ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		$map = array(
			self::PAGE_REPORTS  => self::reports_url(),
			self::PAGE_ACTIVITY => self::activity_url(),
			self::PAGE_SETTINGS => self::settings_url(),
		);

		if ( ! isset( $map[ $page ] ) ) {
			return;
		}

		$target = $map[ $page ];

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Preserving a report ID in a redirect; read-only.
		if ( isset( $_GET['report'] ) ) {
			$target = add_query_arg( 'report', absint( $_GET['report'] ), $target );
		}
		// phpcs:enable

		wp_safe_redirect( $target, 301 );
		exit;
	}

	/**
	 * Registers the settings option.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			Update_Zombie_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'Update_Zombie_Settings', 'sanitize' ),
				'default'           => Update_Zombie_Settings::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Reschedules the scan cron when its interval changes.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $old Previous option value.
	 * @param mixed $new New option value.
	 * @return void
	 */
	public function on_settings_saved( $old, $new ) {
		$old_interval = is_array( $old ) ? ( $old['analysis_interval'] ?? '' ) : '';
		$new_interval = is_array( $new ) ? ( $new['analysis_interval'] ?? '' ) : '';

		if ( $new_interval && $old_interval !== $new_interval ) {
			Update_Zombie_Plugin::reschedule_scan( $new_interval );
		}
	}

	/**
	 * Loads the admin stylesheet on our own screens.
	 *
	 * @since 0.1.0
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, self::PAGE_REPORTS ) && ! in_array( $hook, array( 'plugins.php', 'update-core.php' ), true ) ) {
			return;
		}

		wp_enqueue_style(
			'update-zombie-admin',
			UPDATE_ZOMBIE_URL . 'admin/assets/admin.css',
			array(),
			UPDATE_ZOMBIE_VERSION
		);

		if ( in_array( $hook, array( 'plugins.php', 'update-core.php' ), true ) ) {
			wp_enqueue_script(
				'update-zombie-admin-status',
				UPDATE_ZOMBIE_URL . 'admin/assets/admin-status.js',
				array(),
				UPDATE_ZOMBIE_VERSION,
				true
			);

			wp_localize_script(
				'update-zombie-admin-status',
				'updateZombiePluginStatuses',
				$this->updates_page_statuses()
			);
		}
	}

	/**
	 * Renders the reports screen, or a single report.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function render_reports_page() {
		if ( ! current_user_can( self::CAP_VIEW ) ) {
			wp_die( esc_html__( 'You do not have permission to view update reports.', 'update-zombie' ) );
		}

		Update_Zombie_Store::maybe_upgrade();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view routing.
		$report_id = isset( $_GET['report'] ) ? absint( $_GET['report'] ) : 0;

		if ( $report_id ) {
			$report = Update_Zombie_Store::get( $report_id );

			if ( ! $report ) {
				wp_die( esc_html__( 'That report no longer exists.', 'update-zombie' ) );
			}

			$payload = Update_Zombie_Store::payload( $report );

			require UPDATE_ZOMBIE_DIR . 'admin/views/report-detail.php';

			return;
		}

		$table = new Update_Zombie_Reports_Table();
		$table->prepare_items();

		require UPDATE_ZOMBIE_DIR . 'admin/views/reports.php';
	}

	/**
	 * Renders the activity log screen.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public function render_activity_page() {
		if ( ! current_user_can( self::CAP_VIEW ) ) {
			wp_die( esc_html__( 'You do not have permission to view the activity log.', 'update-zombie' ) );
		}

		Update_Zombie_Store::maybe_upgrade();

		$table = new Update_Zombie_Activity_Table();
		$table->prepare_items();

		require UPDATE_ZOMBIE_DIR . 'admin/views/activity.php';
	}

	/**
	 * Returns the activity log URL.
	 *
	 * @since 0.3.0
	 *
	 * @return string
	 */
	public static function activity_url() {
		return admin_url( 'tools.php?page=' . self::PAGE_REPORTS . '&tab=activity' );
	}

	/**
	 * Renders the settings screen.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( self::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'update-zombie' ) );
		}

		$settings     = Update_Zombie_Settings::all();
		$availability = Update_Zombie_Analyzer::availability();

		require UPDATE_ZOMBIE_DIR . 'admin/views/settings.php';
	}

	/**
	 * Handles every admin-post action the plugin exposes.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function handle_action() {
		$action = isset( $_REQUEST['zombie_action'] ) ? sanitize_key( wp_unslash( $_REQUEST['zombie_action'] ) ) : '';
		$id     = isset( $_REQUEST['report'] ) ? absint( $_REQUEST['report'] ) : 0;

		check_admin_referer( 'update_zombie_' . $action . '_' . $id );

		if ( ! current_user_can( self::CAP_VIEW ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'update-zombie' ) );
		}

		$notice = '';

		switch ( $action ) {
			case 'scan':
				$scanner = new Update_Zombie_Scanner();
				$created = $scanner->scan_all();
				$notice  = $created ? 'queued' : 'nothing_new';
				break;

			case 'analyze':
				$report = Update_Zombie_Store::get( $id );

				if ( ! $report ) {
					$notice = 'missing';
					break;
				}

				/*
				 * Never run the analysis inside the browser request: it takes
				 * minutes and the web server will cut it off. Put the item at
				 * the front of the queue, poke cron, and send the user to the
				 * report page, which polls for the result.
				 */
				Update_Zombie_Store::update(
					$id,
					array(
						'status'        => ! empty( $report->prompt_cache ) ? Update_Zombie_Store::STATUS_DIFFED : Update_Zombie_Store::STATUS_PENDING,
						'attempts'      => 0,
						'priority'      => 1,
						'error_message' => null,
					)
				);

				Update_Zombie_Log::record(
					Update_Zombie_Log::ANALYSIS_START,
					__( 'Analysis requested from the admin screen; moved to the front of the queue.', 'update-zombie' ),
					$report
				);

				self::kick_queue();

				$notice = 'queued_front';
				break;

			case 'requeue':
				Update_Zombie_Processor::requeue( $id );
				$notice = 'requeued';
				break;

			case 'delete':
				Update_Zombie_Store::delete( $id );
				$notice = 'deleted';
				$id     = 0;
				break;

			case 'run_queue':
				self::kick_queue();
				$notice = 'kicked';
				break;
		}

		$redirect = 'delete' === $action || ! $id ? self::reports_url() : self::report_url( $id );

		wp_safe_redirect( add_query_arg( 'zombie_notice', $notice, $redirect ) );
		exit;
	}

	/**
	 * Asks cron to run the queue now rather than on its next five-minute tick.
	 *
	 * spawn_cron() fires a non-blocking loopback request, so the work happens
	 * in a detached process and the admin request returns immediately. With
	 * DISABLE_WP_CRON set, system cron picks the event up instead.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public static function kick_queue() {
		/*
		 * The args make this distinct from the recurring event: WordPress
		 * silently refuses a single event that duplicates a hook already due
		 * within ten minutes, which the five-minute recurrence always is.
		 */
		$args = array( 'now' );

		if ( ! wp_next_scheduled( Update_Zombie_Plugin::CRON_PROCESS, $args ) ) {
			wp_schedule_single_event( time() - 1, Update_Zombie_Plugin::CRON_PROCESS, $args );
		}

		if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
			spawn_cron();
		}
	}

	/**
	 * Describes where a report is in the pipeline, for the live status banner.
	 *
	 * @since 0.5.0
	 *
	 * @param object $report Report row.
	 * @return array{phase: string, label: string, done: bool, estimate: int}
	 */
	public static function phase_for( $report ) {
		$done = in_array( $report->status, array( Update_Zombie_Store::STATUS_COMPLETE, Update_Zombie_Store::STATUS_ERROR ), true );

		// A cached diff means downloading and diffing are behind us.
		$has_diff = ! empty( $report->prompt_cache ) || ! empty( $report->signals );

		if ( $done ) {
			$phase = Update_Zombie_Store::STATUS_COMPLETE === $report->status ? 'complete' : 'error';
			$label = Update_Zombie_Store::STATUS_COMPLETE === $report->status ? __( 'Analysis complete.', 'update-zombie' ) : __( 'Analysis failed.', 'update-zombie' );
		} elseif ( Update_Zombie_Store::STATUS_ANALYZING === $report->status && $has_diff ) {
			$phase = 'reviewing';
			$label = __( 'The model is reading the diff now.', 'update-zombie' );
		} elseif ( Update_Zombie_Store::STATUS_ANALYZING === $report->status ) {
			$phase = 'diffing';
			$label = __( 'Downloading the package and comparing it with the installed copy.', 'update-zombie' );
		} elseif ( Update_Zombie_Store::STATUS_DIFFED === $report->status ) {
			$phase = 'waiting_review';
			$label = __( 'Diffed. Waiting for the queue to send it to the model.', 'update-zombie' );
		} else {
			$phase = 'queued';
			$label = __( 'Queued. Download and diff start on the next queue run.', 'update-zombie' );
		}

		$estimate = 5;

		if ( ! empty( $report->prompt_cache ) ) {
			$cached = json_decode( (string) $report->prompt_cache, true );
			$length = strlen( (string) ( $cached['diff']['diff'] ?? '' ) );

			// Same ceiling the request itself gets, expressed in minutes.
			$estimate = (int) ceil( Update_Zombie_Analyzer::timeout_for( str_repeat( 'x', $length ) ) / 60 );
		}

		return array(
			'phase'    => $phase,
			'label'    => $label,
			'done'     => $done,
			'estimate' => max( 2, $estimate ),
		);
	}

	/**
	 * AJAX: reports a report's current phase so the report page can poll.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function ajax_status() {
		check_ajax_referer( 'update_zombie_status', 'nonce' );

		if ( ! current_user_can( self::CAP_VIEW ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'update-zombie' ) ), 403 );
		}

		$report = Update_Zombie_Store::get( isset( $_POST['report'] ) ? absint( $_POST['report'] ) : 0 );

		if ( ! $report ) {
			wp_send_json_error( array( 'message' => __( 'No such report.', 'update-zombie' ) ), 404 );
		}

		wp_send_json_success( self::phase_for( $report ) );
	}

	/**
	 * Renders admin notices.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function render_notices() {
		if ( ! current_user_can( self::CAP_VIEW ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice text, redirected to by our own handler.
		$notice = isset( $_GET['zombie_notice'] ) ? sanitize_key( wp_unslash( $_GET['zombie_notice'] ) ) : '';

		$messages = array(
			'queued'      => __( 'New updates were queued for analysis.', 'update-zombie' ),
			'queued_front' => __( 'Moved to the front of the queue. This page updates itself as the analysis runs.', 'update-zombie' ),
			'kicked'      => __( 'Queue run started in the background.', 'update-zombie' ),
			'nothing_new' => __( 'No unanalysed updates were found.', 'update-zombie' ),
			'analyzed'    => __( 'Analysis finished.', 'update-zombie' ),
			'queue_empty' => __( 'Nothing is waiting in the queue.', 'update-zombie' ),
			'requeued'    => __( 'Queued for another attempt.', 'update-zombie' ),
			'deleted'     => __( 'Report deleted.', 'update-zombie' ),
			'failed'      => __( 'The analysis failed. The report shows why.', 'update-zombie' ),
			'missing'     => __( 'That report no longer exists.', 'update-zombie' ),
		);

		if ( isset( $messages[ $notice ] ) ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( in_array( $notice, array( 'failed', 'missing' ), true ) ? 'error' : 'success' ),
				esc_html( $messages[ $notice ] )
			);
		}

		$this->render_attention_notice();
	}

	/**
	 * Warns about updates that were held back or flagged.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	protected function render_attention_notice() {
		$screen = get_current_screen();

		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins', 'update-core' ), true ) ) {
			return;
		}

		$result = Update_Zombie_Store::query(
			array(
				'status'   => Update_Zombie_Store::STATUS_COMPLETE,
				'per_page' => 50,
			)
		);

		$flagged = array();

		foreach ( $result['items'] as $row ) {
			if ( Update_Zombie_Store::DECISION_HELD === $row->decision || 'bad' === $row->verdict ) {
				$flagged[] = $row;
			}
		}

		if ( ! $flagged ) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>';
		echo esc_html(
			sprintf(
				/* translators: %d: number of updates. */
				_n( 'Update Zombie flagged %d update.', 'Update Zombie flagged %d updates.', count( $flagged ), 'update-zombie' ),
				count( $flagged )
			)
		);
		echo '</strong></p><ul style="margin-left:1.5em;list-style:disc;">';

		foreach ( array_slice( $flagged, 0, 5 ) as $row ) {
			printf(
				'<li><a href="%s">%s %s</a> — %s</li>',
				esc_url( self::report_url( $row->id ) ),
				esc_html( $row->item_name ),
				esc_html( $row->new_version ),
				esc_html( $row->headline )
			);
		}

		echo '</ul></div>';
	}

	/**
	 * Builds compact status data for each plugin on Dashboard > Updates.
	 *
	 * @since 0.5.0
	 *
	 * @return array<string, array<string, mixed>> Statuses keyed by plugin file.
	 */
	protected function updates_page_statuses() {
		if ( ! function_exists( 'get_plugin_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$statuses = array();

		foreach ( (array) get_plugin_updates() as $plugin_file => $plugin_data ) {
			$update = (object) ( $plugin_data->update ?? array() );
			$report = $this->plugin_report( $plugin_file, $update );
			$status = self::report_status( $report );

			$statuses[ $plugin_file ] = array(
				'label'    => $status['label'],
				'class'    => $status['class'],
				'security' => $status['security'],
				'url'      => $report ? self::report_url( $report->id ) : '',
			);
		}

		return $statuses;
	}

	/**
	 * Finds the report corresponding to one WordPress plugin update offer.
	 *
	 * @since 0.5.0
	 *
	 * @param string $plugin_file Plugin file, relative to the plugins directory.
	 * @param object $update      WordPress update offer.
	 * @return object|null
	 */
	protected function plugin_report( $plugin_file, $update ) {
		if ( empty( $update->new_version ) ) {
			return null;
		}

		$slug = Update_Zombie_Scanner::plugin_slug( $plugin_file, (string) ( $update->slug ?? '' ) );

		return Update_Zombie_Store::find( 'plugin', $slug, $update->new_version );
	}

	/**
	 * Converts a stored report into the small status used on core screens.
	 *
	 * @since 0.5.0
	 *
	 * @param object|null $report Stored report, or null when not checked.
	 * @return array{label: string, class: string, security: bool}
	 */
	protected static function report_status( $report ) {
		if ( ! $report ) {
			return array(
				'label'    => __( 'Not checked', 'update-zombie' ),
				'class'    => 'neutral',
				'security' => false,
			);
		}

		if ( Update_Zombie_Store::STATUS_ERROR === $report->status ) {
			return array(
				'label'    => __( 'Failed', 'update-zombie' ),
				'class'    => 'error',
				'security' => false,
			);
		}

		if ( Update_Zombie_Store::STATUS_COMPLETE === $report->status ) {
			if ( ! empty( $report->is_security ) ) {
				return array(
					'label'    => __( 'Security', 'update-zombie' ),
					'class'    => 'security',
					'security' => true,
				);
			}

			return array(
				'label'    => self::verdict_label( $report->verdict ),
				'class'    => sanitize_key( $report->verdict ? $report->verdict : 'neutral' ),
				'security' => false,
			);
		}

		$labels = array(
			Update_Zombie_Store::STATUS_ANALYZING => __( 'Working', 'update-zombie' ),
			Update_Zombie_Store::STATUS_DIFFED    => __( 'Diffed, awaiting review', 'update-zombie' ),
			Update_Zombie_Store::STATUS_PENDING   => __( 'Queued', 'update-zombie' ),
		);

		return array(
			'label'    => $labels[ $report->status ] ?? $report->status,
			'class'    => 'pending',
			'security' => false,
		);
	}

	/**
	 * Adds a settings link on the plugins screen.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( self::settings_url() ),
				esc_html__( 'Settings', 'update-zombie' )
			),
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( self::reports_url() ),
				esc_html__( 'Reports', 'update-zombie' )
			)
		);

		return $links;
	}

	/**
	 * Returns the reports list URL.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function reports_url() {
		return admin_url( 'tools.php?page=' . self::PAGE_REPORTS );
	}

	/**
	 * Returns a single report URL.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id Report ID.
	 * @return string
	 */
	public static function report_url( $id ) {
		return add_query_arg( 'report', (int) $id, self::reports_url() );
	}

	/**
	 * Returns the settings URL.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function settings_url() {
		return admin_url( 'tools.php?page=' . self::PAGE_REPORTS . '&tab=settings' );
	}

	/**
	 * Builds a nonced action URL.
	 *
	 * @since 0.1.0
	 *
	 * @param string $action Action name.
	 * @param int    $id     Report ID, or 0.
	 * @return string
	 */
	public static function action_url( $action, $id = 0 ) {
		$url = add_query_arg(
			array(
				'action'        => 'update_zombie_action',
				'zombie_action' => $action,
				'report'        => (int) $id,
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, 'update_zombie_' . $action . '_' . (int) $id );
	}

	/**
	 * Returns the human label for a verdict.
	 *
	 * @since 0.1.0
	 *
	 * @param string $verdict Stored verdict.
	 * @return string
	 */
	public static function verdict_label( $verdict ) {
		$labels = array(
			'security'     => __( 'Security fix', 'update-zombie' ),
			'good'         => __( 'Good update', 'update-zombie' ),
			'neutral'      => __( 'Housekeeping', 'update-zombie' ),
			'questionable' => __( 'Questionable', 'update-zombie' ),
			'bad'          => __( 'Avoid', 'update-zombie' ),
		);

		return $labels[ $verdict ] ?? __( 'Not judged yet', 'update-zombie' );
	}

	/**
	 * Returns the human label for a recommendation.
	 *
	 * @since 0.1.0
	 *
	 * @param string $recommendation Stored recommendation.
	 * @return string
	 */
	public static function recommendation_label( $recommendation ) {
		$labels = array(
			'apply_now' => __( 'Apply now', 'update-zombie' ),
			'apply'     => __( 'Safe to apply', 'update-zombie' ),
			'review'    => __( 'Review first', 'update-zombie' ),
			'hold'      => __( 'Hold back', 'update-zombie' ),
		);

		return $labels[ $recommendation ] ?? __( 'No recommendation', 'update-zombie' );
	}

	/**
	 * Returns the human label for a decision.
	 *
	 * @since 0.1.0
	 *
	 * @param string $decision Stored decision.
	 * @return string
	 */
	public static function decision_label( $decision ) {
		$labels = array(
			Update_Zombie_Store::DECISION_NONE      => __( 'No decision yet', 'update-zombie' ),
			Update_Zombie_Store::DECISION_ADVISORY  => __( 'Reported only; WordPress decides as usual', 'update-zombie' ),
			Update_Zombie_Store::DECISION_HELD      => __( 'Held back from automatic installation', 'update-zombie' ),
			Update_Zombie_Store::DECISION_SCHEDULED => __( 'Queued for automatic installation', 'update-zombie' ),
			Update_Zombie_Store::DECISION_AUTO      => __( 'Installed automatically', 'update-zombie' ),
		);

		return $labels[ $decision ] ?? $decision;
	}

	/**
	 * Returns the human label for an item type.
	 *
	 * @since 0.1.0
	 *
	 * @param string $type Item type.
	 * @return string
	 */
	public static function type_label( $type ) {
		$labels = array(
			'plugin' => __( 'Plugin', 'update-zombie' ),
			'theme'  => __( 'Theme', 'update-zombie' ),
			'core'   => __( 'Core', 'update-zombie' ),
		);

		return $labels[ $type ] ?? $type;
	}
}
