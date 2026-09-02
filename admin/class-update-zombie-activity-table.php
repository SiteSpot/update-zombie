<?php
/**
 * Activity log list table.
 *
 * @package Update_Zombie
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists what the plugin did, newest first.
 *
 * @since 0.3.0
 */
class Update_Zombie_Activity_Table extends WP_List_Table {

	/**
	 * Constructor.
	 *
	 * @since 0.3.0
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'update_zombie_event',
				'plural'   => 'update_zombie_events',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Declares the columns.
	 *
	 * @since 0.3.0
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'created_at' => __( 'When', 'update-zombie' ),
			'event'      => __( 'Event', 'update-zombie' ),
			'item_name'  => __( 'Item', 'update-zombie' ),
			'message'    => __( 'Detail', 'update-zombie' ),
		);
	}

	/**
	 * Declares the event type views.
	 *
	 * @since 0.3.0
	 *
	 * @return array<string, string>
	 */
	protected function get_views() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$current = isset( $_GET['event'] ) ? sanitize_key( wp_unslash( $_GET['event'] ) ) : '';

		$views = array(
			'all' => sprintf(
				'<a href="%s"%s>%s</a>',
				esc_url( Update_Zombie_Admin::activity_url() ),
				'' === $current ? ' class="current"' : '',
				esc_html__( 'All', 'update-zombie' )
			),
		);

		foreach ( Update_Zombie_Log::labels() as $event => $label ) {
			$views[ $event ] = sprintf(
				'<a href="%s"%s>%s</a>',
				esc_url( add_query_arg( 'event', $event, Update_Zombie_Admin::activity_url() ) ),
				$current === $event ? ' class="current"' : '',
				esc_html( $label )
			);
		}

		return $views;
	}

	/**
	 * Loads the rows.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page = 50;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list filtering.
		$args = array(
			'event'    => isset( $_GET['event'] ) ? sanitize_key( wp_unslash( $_GET['event'] ) ) : '',
			'search'   => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
			'page'     => $this->get_pagenum(),
			'per_page' => $per_page,
		);
		// phpcs:enable

		$result = Update_Zombie_Log::query( $args );

		$this->items = $result['items'];

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $result['total'] / $per_page ),
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), array(), 'created_at' );
	}

	/**
	 * Renders the empty state.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'Nothing has happened yet.', 'update-zombie' );
	}

	/**
	 * Renders the timestamp column.
	 *
	 * @since 0.3.0
	 *
	 * @param object $item Event row.
	 * @return string
	 */
	public function column_created_at( $item ) {
		$timestamp = strtotime( $item->created_at . ' UTC' );

		if ( ! $timestamp ) {
			return '—';
		}

		return sprintf(
			'<span title="%s">%s</span>',
			esc_attr( wp_date( 'Y-m-d H:i:s', $timestamp ) ),
			esc_html(
				sprintf(
					/* translators: %s: human readable time difference. */
					__( '%s ago', 'update-zombie' ),
					human_time_diff( $timestamp )
				)
			)
		);
	}

	/**
	 * Renders the event column.
	 *
	 * @since 0.3.0
	 *
	 * @param object $item Event row.
	 * @return string
	 */
	public function column_event( $item ) {
		return sprintf(
			'<span class="uz-chip uz-chip-%s">%s</span>',
			esc_attr( Update_Zombie_Log::tone( $item->event ) ),
			esc_html( Update_Zombie_Log::label( $item->event ) )
		);
	}

	/**
	 * Renders the item column.
	 *
	 * @since 0.3.0
	 *
	 * @param object $item Event row.
	 * @return string
	 */
	public function column_item_name( $item ) {
		if ( '' === $item->item_name ) {
			return '<span class="uz-muted">—</span>';
		}

		$label = esc_html( $item->item_name );

		if ( '' !== $item->version ) {
			$label .= ' <code>' . esc_html( $item->version ) . '</code>';
		}

		if ( $item->report_id ) {
			return sprintf(
				'<a href="%s">%s</a>',
				esc_url( Update_Zombie_Admin::report_url( $item->report_id ) ),
				$label
			);
		}

		return $label;
	}

	/**
	 * Renders the detail column.
	 *
	 * @since 0.3.0
	 *
	 * @param object $item Event row.
	 * @return string
	 */
	public function column_message( $item ) {
		return esc_html( (string) $item->message );
	}

	/**
	 * Fallback renderer.
	 *
	 * @since 0.3.0
	 *
	 * @param object $item        Event row.
	 * @param string $column_name Column key.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return isset( $item->$column_name ) ? esc_html( (string) $item->$column_name ) : '';
	}
}
