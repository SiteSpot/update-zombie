<?php
/**
 * Reports list table.
 *
 * @package Update_Zombie
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists analysed and queued updates.
 *
 * @since 0.1.0
 */
class Update_Zombie_Reports_Table extends WP_List_Table {

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'update_zombie_report',
				'plural'   => 'update_zombie_reports',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Declares the columns.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'item_name' => __( 'Item', 'update-zombie' ),
			'versions'  => __( 'Versions', 'update-zombie' ),
			'verdict'   => __( 'Verdict', 'update-zombie' ),
			'security'  => __( 'Security fix', 'update-zombie' ),
			'created_at' => __( 'Seen', 'update-zombie' ),
		);
	}

	/**
	 * Declares the sortable columns.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function get_sortable_columns() {
		return array(
			'item_name'  => array( 'item_name', false ),
			'verdict'    => array( 'verdict', false ),
			'security'   => array( 'confidence', false ),
			'created_at' => array( 'created_at', true ),
		);
	}

	/**
	 * Declares the status views.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	protected function get_views() {
		$current = self::current_view();

		$counts = array(
			'attention' => Update_Zombie_Store::query( array( 'applied' => 'no', 'per_page' => 1 ) )['total'],
			'done'      => Update_Zombie_Store::query( array( 'applied' => 'yes', 'per_page' => 1 ) )['total'],
			'failed'    => Update_Zombie_Store::count_by_status( Update_Zombie_Store::STATUS_ERROR ),
		);

		$labels = array(
			'attention' => __( 'Needs attention', 'update-zombie' ),
			'done'      => __( 'Done', 'update-zombie' ),
			'failed'    => __( 'Failed', 'update-zombie' ),
			'all'       => __( 'All', 'update-zombie' ),
		);

		$views = array();

		foreach ( $labels as $key => $label ) {
			$url = 'attention' === $key
				? Update_Zombie_Admin::reports_url()
				: add_query_arg( 'view', $key, Update_Zombie_Admin::reports_url() );

			$views[ $key ] = sprintf(
				'<a href="%s"%s>%s%s</a>',
				esc_url( $url ),
				$current === $key ? ' class="current"' : '',
				esc_html( $label ),
				isset( $counts[ $key ] ) ? ' <span class="count">(' . (int) $counts[ $key ] . ')</span>' : ''
			);
		}

		return $views;
	}

	/**
	 * Returns the active list view.
	 *
	 * The default is the inbox: updates not yet installed. Done and Failed
	 * are one click away, and All is the old flat list.
	 *
	 * @since 0.5.0
	 *
	 * @return string One of attention, done, failed, all.
	 */
	public static function current_view() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'attention';

		return in_array( $view, array( 'done', 'failed', 'all' ), true ) ? $view : 'attention';
	}

	/**
	 * Loads the rows.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page = 20;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list filtering.
		$view = self::current_view();

		$args = array(
			'status'    => 'failed' === $view ? Update_Zombie_Store::STATUS_ERROR : '',
			'applied'   => 'attention' === $view ? 'no' : ( 'done' === $view ? 'yes' : '' ),
			'item_type' => isset( $_GET['item_type'] ) ? sanitize_key( wp_unslash( $_GET['item_type'] ) ) : '',
			'search'    => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
			// The inbox sorts by urgency unless the user clicked a column.
			'orderby'   => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : ( 'attention' === $view ? 'urgency' : 'created_at' ),
			'order'     => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc',
			'page'      => $this->get_pagenum(),
			'per_page'  => $per_page,
		);
		// phpcs:enable

		$result = Update_Zombie_Store::query( $args );

		$this->items = $result['items'];

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $result['total'] / $per_page ),
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'item_name' );
	}

	/**
	 * Renders the empty state.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function no_items() {
		if ( 'attention' === self::current_view() ) {
			esc_html_e( 'Nothing needs your attention. Everything WordPress has offered is either installed or waiting on the queue.', 'update-zombie' );

			return;
		}

		esc_html_e( 'Nothing here yet. When WordPress offers an update, Update Zombie will pick it up.', 'update-zombie' );
	}

	/**
	 * Renders the item column.
	 *
	 * @since 0.1.0
	 *
	 * @param object $item Report row.
	 * @return string
	 */
	public function column_item_name( $item ) {
		$actions = array(
			'view' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( Update_Zombie_Admin::report_url( $item->id ) ),
				esc_html__( 'View report', 'update-zombie' )
			),
		);

		if ( Update_Zombie_Store::STATUS_COMPLETE !== $item->status ) {
			$actions['analyze'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( Update_Zombie_Admin::action_url( 'analyze', $item->id ) ),
				esc_html__( 'Analyse now', 'update-zombie' )
			);
		} else {
			$actions['reanalyze'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( Update_Zombie_Admin::action_url( 'analyze', $item->id ) ),
				esc_html__( 'Re-analyse', 'update-zombie' )
			);
		}

		$actions['delete'] = sprintf(
			'<a href="%s" class="submitdelete">%s</a>',
			esc_url( Update_Zombie_Admin::action_url( 'delete', $item->id ) ),
			esc_html__( 'Delete', 'update-zombie' )
		);

		// Chips come from the signals column, which is written straight after
		// diffing, so they show even for a report whose analysis later failed.
		$chips = Update_Zombie_Chips::render( Update_Zombie_Store::signals( $item ) );

		return sprintf(
			'<strong><a href="%s">%s</a></strong><br><span class="uz-muted">%s</span>%s%s',
			esc_url( Update_Zombie_Admin::report_url( $item->id ) ),
			esc_html( $item->item_name ),
			esc_html( Update_Zombie_Admin::type_label( $item->item_type ) . ' · ' . $item->item_slug ),
			$chips,
			$this->row_actions( $actions )
		);
	}

	/**
	 * Renders the versions column.
	 *
	 * @since 0.1.0
	 *
	 * @param object $item Report row.
	 * @return string
	 */
	public function column_versions( $item ) {
		$state = self::installed_state( $item );

		// Bold whichever version is actually running; green once it's the new one.
		$old = sprintf( '<code class="%s">%s</code>', $state['updated'] ? '' : 'uz-ver-current', esc_html( $item->old_version ? $item->old_version : '?' ) );
		$new = sprintf( '<code class="%s">%s</code>', $state['updated'] ? 'uz-ver-current uz-ver-updated' : '', esc_html( $item->new_version ) );

		return sprintf(
			'%s → %s<br>%s',
			$old,
			$new,
			self::status_badge( $item, $state )
		);
	}

	/**
	 * Works out what is actually installed right now.
	 *
	 * The verdict says what the zombie decided; this says what happened. The
	 * two differ whenever WordPress or a human installed the update instead.
	 *
	 * @since 0.5.0
	 *
	 * @param object $item Report row.
	 * @return array{installed: string, updated: bool}
	 */
	public static function installed_state( $item ) {
		static $plugins = null;

		$installed = '';

		if ( 'plugin' === $item->item_type ) {
			if ( null === $plugins ) {
				if ( ! function_exists( 'get_plugins' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}

				$plugins = get_plugins();
			}

			$installed = (string) ( $plugins[ $item->item_file ]['Version'] ?? '' );
		} elseif ( 'theme' === $item->item_type ) {
			$theme     = wp_get_theme( $item->item_slug );
			$installed = $theme->exists() ? (string) $theme->get( 'Version' ) : '';
		} elseif ( 'core' === $item->item_type ) {
			$installed = Update_Zombie_Scanner::current_core_version();
		}

		return array(
			'installed' => $installed,
			'updated'   => '' !== $installed && version_compare( $installed, $item->new_version, '>=' ),
		);
	}

	/**
	 * Renders the one-line outcome shown under the version numbers.
	 *
	 * @since 0.5.0
	 *
	 * @param object                                $item  Report row.
	 * @param array{installed: string, updated: bool} $state Installed state.
	 * @return string
	 */
	protected static function status_badge( $item, array $state ) {
		if ( $state['updated'] ) {
			$auto = in_array( $item->decision, array( Update_Zombie_Store::DECISION_AUTO, Update_Zombie_Store::DECISION_SCHEDULED ), true );

			return sprintf(
				'<span class="uz-status uz-status-ok">&#10003; %s</span>',
				esc_html( $auto ? __( 'Automatically updated', 'update-zombie' ) : __( 'Updated', 'update-zombie' ) )
			);
		}

		if ( Update_Zombie_Store::STATUS_COMPLETE !== $item->status ) {
			// A row that failed and was requeued says so, rather than looking
			// like it has never been tried.
			if ( Update_Zombie_Store::STATUS_ERROR !== $item->status && ! empty( $item->error_message ) && (int) $item->attempts > 0 ) {
				return sprintf(
					'<span class="uz-status uz-status-warn" title="%s">%s</span>',
					esc_attr( $item->error_message ),
					esc_html(
						sprintf(
							/* translators: %d: attempt number. */
							__( 'Failed, retrying — attempt %d of 3', 'update-zombie' ),
							min( 3, (int) $item->attempts + 1 )
						)
					)
				);
			}

			return '<span class="uz-status uz-status-muted">' . esc_html__( 'No decision yet', 'update-zombie' ) . '</span>';
		}

		$link = self::update_link( $item );

		switch ( $item->decision ) {
			case Update_Zombie_Store::DECISION_SCHEDULED:
				return '<span class="uz-status uz-status-info">' . esc_html__( 'Queued for automatic update', 'update-zombie' ) . '</span>';
			case Update_Zombie_Store::DECISION_HELD:
				return '<span class="uz-status uz-status-warn">' . esc_html__( 'Held back — needs manual update', 'update-zombie' ) . '</span>' . $link;
			case Update_Zombie_Store::DECISION_ADVISORY:
				return '<span class="uz-status uz-status-muted">' . esc_html( $item->is_security ? __( 'Needs manual update', 'update-zombie' ) : __( 'Reported only — WordPress decides', 'update-zombie' ) ) . '</span>' . $link;
		}

		return '<span class="uz-status uz-status-muted">' . esc_html__( 'No decision yet', 'update-zombie' ) . '</span>' . $link;
	}

	/**
	 * Returns WordPress's own "update now" link for an item.
	 *
	 * This is the same nonced URL the Plugins screen uses, so it goes through
	 * core's updater with core's permission checks. Nothing here is ours.
	 *
	 * @since 0.5.0
	 *
	 * @param object $item Report row.
	 * @return string Link markup, or an empty string when not applicable.
	 */
	public static function update_link( $item ) {
		if ( 'plugin' === $item->item_type && current_user_can( 'update_plugins' ) && $item->item_file ) {
			$url = wp_nonce_url(
				self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $item->item_file ) ),
				'upgrade-plugin_' . $item->item_file
			);
		} elseif ( 'theme' === $item->item_type && current_user_can( 'update_themes' ) ) {
			$url = wp_nonce_url(
				self_admin_url( 'update.php?action=upgrade-theme&theme=' . rawurlencode( $item->item_slug ) ),
				'upgrade-theme_' . $item->item_slug
			);
		} elseif ( 'core' === $item->item_type && current_user_can( 'update_core' ) ) {
			$url = self_admin_url( 'update-core.php' );
		} else {
			return '';
		}

		$label = Update_Zombie_Store::DECISION_HELD === $item->decision
			? __( 'Update anyway', 'update-zombie' )
			: __( 'Update now', 'update-zombie' );

		return sprintf( ' <a class="uz-update-link" href="%s">%s &rarr;</a>', esc_url( $url ), esc_html( $label ) );
	}

	/**
	 * Renders the verdict column.
	 *
	 * @since 0.1.0
	 *
	 * @param object $item Report row.
	 * @return string
	 */
	public function column_verdict( $item ) {
		if ( Update_Zombie_Store::STATUS_ERROR === $item->status ) {
			return sprintf(
				'<span class="uz-badge uz-badge-error">%s</span><br><span class="uz-muted">%s</span>',
				esc_html__( 'Failed', 'update-zombie' ),
				esc_html( (string) $item->error_message )
			);
		}

		if ( Update_Zombie_Store::STATUS_COMPLETE !== $item->status ) {
			$labels = array(
				Update_Zombie_Store::STATUS_ANALYZING => __( 'Working', 'update-zombie' ),
				Update_Zombie_Store::STATUS_DIFFED    => __( 'Diffed, awaiting review', 'update-zombie' ),
				Update_Zombie_Store::STATUS_PENDING   => __( 'Queued', 'update-zombie' ),
			);

			return sprintf(
				'<span class="uz-badge uz-badge-pending">%s</span>',
				esc_html( $labels[ $item->status ] ?? $item->status )
			);
		}

		$applied = self::installed_state( $item )['updated']
			? ' <span class="uz-badge uz-badge-applied">&#10003; ' . esc_html__( 'Applied', 'update-zombie' ) . '</span>'
			: '';

		return sprintf(
			'<span class="uz-badge uz-badge-%s">%s</span>%s<br><span class="uz-muted">%s</span>',
			esc_attr( $item->verdict ),
			esc_html( Update_Zombie_Admin::verdict_label( $item->verdict ) ),
			$applied,
			esc_html( $item->headline )
		);
	}

	/**
	 * Renders the security column.
	 *
	 * @since 0.1.0
	 *
	 * @param object $item Report row.
	 * @return string
	 */
	public function column_security( $item ) {
		if ( Update_Zombie_Store::STATUS_COMPLETE !== $item->status ) {
			return '—';
		}

		if ( empty( $item->is_security ) ) {
			return '<span class="uz-muted">' . esc_html__( 'No', 'update-zombie' ) . '</span>';
		}

		return sprintf(
			'<strong>%s</strong><br><span class="uz-muted">%s</span>',
			esc_html__( 'Yes', 'update-zombie' ),
			esc_html(
				sprintf(
					/* translators: %d: confidence percentage. */
					__( '%d%% confidence', 'update-zombie' ),
					(int) $item->confidence
				)
			)
		);
	}

	/**
	 * Renders the decision column.
	 *
	 * @since 0.1.0
	 *
	 * @param object $item Report row.
	 * @return string
	 */
	public function column_decision( $item ) {
		// Folded into the versions column; kept for any custom column config.
		return esc_html( Update_Zombie_Admin::decision_label( $item->decision ) );
	}

	/**
	 * Renders the timestamp column.
	 *
	 * @since 0.1.0
	 *
	 * @param object $item Report row.
	 * @return string
	 */
	public function column_created_at( $item ) {
		$timestamp = strtotime( $item->created_at . ' UTC' );

		if ( ! $timestamp ) {
			return '—';
		}

		return esc_html(
			sprintf(
				/* translators: %s: human readable time difference. */
				__( '%s ago', 'update-zombie' ),
				human_time_diff( $timestamp )
			)
		);
	}

	/**
	 * Fallback renderer.
	 *
	 * @since 0.1.0
	 *
	 * @param object $item        Report row.
	 * @param string $column_name Column key.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return isset( $item->$column_name ) ? esc_html( (string) $item->$column_name ) : '';
	}
}
