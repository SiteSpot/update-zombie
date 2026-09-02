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
			'decision'  => __( 'Action', 'update-zombie' ),
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
		$statuses = array(
			''                                   => __( 'All', 'update-zombie' ),
			Update_Zombie_Store::STATUS_PENDING  => __( 'Queued', 'update-zombie' ),
			Update_Zombie_Store::STATUS_DIFFED   => __( 'Awaiting review', 'update-zombie' ),
			Update_Zombie_Store::STATUS_COMPLETE => __( 'Analysed', 'update-zombie' ),
			Update_Zombie_Store::STATUS_ERROR    => __( 'Failed', 'update-zombie' ),
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$current = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

		$views = array();

		foreach ( $statuses as $status => $label ) {
			$url = $status
				? add_query_arg( 'status', $status, Update_Zombie_Admin::reports_url() )
				: Update_Zombie_Admin::reports_url();

			$count = $status ? Update_Zombie_Store::count_by_status( $status ) : null;

			$views[ $status ? $status : 'all' ] = sprintf(
				'<a href="%s"%s>%s%s</a>',
				esc_url( $url ),
				$current === $status ? ' class="current"' : '',
				esc_html( $label ),
				null === $count ? '' : ' <span class="count">(' . (int) $count . ')</span>'
			);
		}

		return $views;
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
		$args = array(
			'status'    => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
			'item_type' => isset( $_GET['item_type'] ) ? sanitize_key( wp_unslash( $_GET['item_type'] ) ) : '',
			'search'    => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
			'orderby'   => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'created_at',
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
		esc_html_e( 'Nothing analysed yet. When WordPress offers an update, Update Zombie will pick it up.', 'update-zombie' );
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
		return sprintf(
			'<code>%s</code> → <code>%s</code>',
			esc_html( $item->old_version ? $item->old_version : '?' ),
			esc_html( $item->new_version )
		);
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

		return sprintf(
			'<span class="uz-badge uz-badge-%s">%s</span><br><span class="uz-muted">%s</span>',
			esc_attr( $item->verdict ),
			esc_html( Update_Zombie_Admin::verdict_label( $item->verdict ) ),
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
