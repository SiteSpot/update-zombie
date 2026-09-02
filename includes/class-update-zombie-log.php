<?php
/**
 * Activity log.
 *
 * @package Update_Zombie
 */

defined( 'ABSPATH' ) || exit;

/**
 * Records what the plugin did, so there is an audit trail for anything that
 * happened unattended.
 *
 * @since 0.3.0
 */
class Update_Zombie_Log {

	const UPDATE_SPOTTED = 'update_spotted';
	const SCAN           = 'scan';
	const ANALYSIS_START = 'analysis_start';
	const VERDICT        = 'verdict';
	const ANALYSIS_ERROR = 'analysis_error';
	const DECISION       = 'decision';
	const INSTALLED      = 'installed';
	const NOTIFIED       = 'notified';

	/**
	 * Writes an event.
	 *
	 * @since 0.3.0
	 *
	 * @param string               $event   One of the class constants.
	 * @param string               $message Human readable summary.
	 * @param object|null          $report  Related report row, if any.
	 * @param array<string, mixed> $context Extra structured detail.
	 * @return void
	 */
	public static function record( $event, $message, $report = null, array $context = array() ) {
		global $wpdb;

		$row = array(
			'created_at' => current_time( 'mysql', true ),
			'event'      => substr( (string) $event, 0, 32 ),
			'report_id'  => $report ? (int) $report->id : 0,
			'item_type'  => $report ? substr( (string) $report->item_type, 0, 20 ) : '',
			'item_slug'  => $report ? substr( (string) $report->item_slug, 0, 191 ) : '',
			'item_name'  => $report ? substr( (string) $report->item_name, 0, 191 ) : '',
			'version'    => $report ? substr( (string) $report->new_version, 0, 64 ) : '',
			'message'    => (string) $message,
			'context'    => $context ? wp_json_encode( $context ) : null,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert( Update_Zombie_Store::events_table(), $row );

		/**
		 * Fires after an activity event is recorded.
		 *
		 * @since 0.3.0
		 *
		 * @param string               $event   Event key.
		 * @param string               $message Human readable summary.
		 * @param array<string, mixed> $context Extra structured detail.
		 */
		do_action( 'update_zombie_event_logged', $event, $message, $context );
	}

	/**
	 * Queries events for the activity screen.
	 *
	 * @since 0.3.0
	 *
	 * @param array<string, mixed> $args Query args: event, item_slug, per_page, page.
	 * @return array{items: object[], total: int}
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'event'     => '',
				'item_slug' => '',
				'search'    => '',
				'per_page'  => 50,
				'page'      => 1,
			)
		);

		$table  = Update_Zombie_Store::events_table();
		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $args['event'] ) {
			$where[]  = 'event = %s';
			$params[] = $args['event'];
		}

		if ( '' !== $args['item_slug'] ) {
			$where[]  = 'item_slug = %s';
			$params[] = $args['item_slug'];
		}

		if ( '' !== $args['search'] ) {
			$where[]  = '(item_name LIKE %s OR message LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );
		$per_page  = max( 1, (int) $args['per_page'] );
		$offset    = max( 0, ( (int) $args['page'] - 1 ) * $per_page );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

		$list_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d";
		$items    = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $params, array( $per_page, $offset ) ) ) );
		// phpcs:enable

		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Returns the display label for an event type.
	 *
	 * @since 0.3.0
	 *
	 * @param string $event Event key.
	 * @return string
	 */
	public static function label( $event ) {
		$labels = self::labels();

		return $labels[ $event ] ?? $event;
	}

	/**
	 * Returns every event type and its label.
	 *
	 * @since 0.3.0
	 *
	 * @return array<string, string>
	 */
	public static function labels() {
		return array(
			self::SCAN           => __( 'Update check', 'update-zombie' ),
			self::UPDATE_SPOTTED => __( 'Update spotted', 'update-zombie' ),
			self::ANALYSIS_START => __( 'Analysis started', 'update-zombie' ),
			self::VERDICT        => __( 'Verdict', 'update-zombie' ),
			self::ANALYSIS_ERROR => __( 'Analysis failed', 'update-zombie' ),
			self::DECISION       => __( 'Decision', 'update-zombie' ),
			self::INSTALLED      => __( 'Installed', 'update-zombie' ),
			self::NOTIFIED       => __( 'Notification sent', 'update-zombie' ),
		);
	}

	/**
	 * Returns the tone used to colour an event row.
	 *
	 * @since 0.3.0
	 *
	 * @param string $event Event key.
	 * @return string
	 */
	public static function tone( $event ) {
		$tones = array(
			self::ANALYSIS_ERROR => 'alert',
			self::INSTALLED      => 'good',
			self::VERDICT        => 'notable',
			self::DECISION       => 'notable',
		);

		return $tones[ $event ] ?? 'neutral';
	}
}
