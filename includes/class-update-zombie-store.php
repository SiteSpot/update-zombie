<?php
/**
 * Report storage.
 *
 * @package Update_Zombie
 */

defined( 'ABSPATH' ) || exit;

/**
 * Owns the reports table: schema, CRUD and retention pruning.
 *
 * @since 0.1.0
 */
class Update_Zombie_Store {

	const DB_VERSION_OPTION = 'update_zombie_db_version';
	const DB_VERSION        = '3';

	const STATUS_PENDING   = 'pending';
	const STATUS_ANALYZING = 'analyzing';

	/**
	 * Diffed and waiting for the analysis phase on a later cron tick.
	 */
	const STATUS_DIFFED    = 'diffed';
	const STATUS_COMPLETE  = 'complete';
	const STATUS_ERROR     = 'error';

	const DECISION_NONE      = 'none';
	const DECISION_ADVISORY  = 'advisory';
	const DECISION_HELD      = 'held';
	const DECISION_AUTO      = 'auto_applied';
	const DECISION_SCHEDULED = 'auto_scheduled';

	/**
	 * Returns the reports table name.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'update_zombie_reports';
	}

	/**
	 * Creates or upgrades the reports table.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			item_type varchar(20) NOT NULL DEFAULT 'plugin',
			item_slug varchar(191) NOT NULL DEFAULT '',
			item_file varchar(191) NOT NULL DEFAULT '',
			item_name varchar(191) NOT NULL DEFAULT '',
			old_version varchar(64) NOT NULL DEFAULT '',
			new_version varchar(64) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'pending',
			verdict varchar(20) NOT NULL DEFAULT '',
			recommendation varchar(20) NOT NULL DEFAULT '',
			is_security tinyint(1) NOT NULL DEFAULT 0,
			confidence smallint(5) unsigned NOT NULL DEFAULT 0,
			headline text NULL,
			summary longtext NULL,
			payload longtext NULL,
			signals longtext NULL,
			prompt_cache longtext NULL,
			decision varchar(20) NOT NULL DEFAULT 'none',
			error_message text NULL,
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			analyzed_at datetime NULL,
			notified_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY item_version (item_type,item_slug,new_version),
			KEY status_created (status,created_at),
			KEY created_at (created_at)
		) {$collate};";

		dbDelta( $sql );

		$events = self::events_table();

		$events_sql = "CREATE TABLE {$events} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			event varchar(32) NOT NULL DEFAULT '',
			report_id bigint(20) unsigned NOT NULL DEFAULT 0,
			item_type varchar(20) NOT NULL DEFAULT '',
			item_slug varchar(191) NOT NULL DEFAULT '',
			item_name varchar(191) NOT NULL DEFAULT '',
			version varchar(64) NOT NULL DEFAULT '',
			message text NULL,
			context longtext NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY event (event),
			KEY report_id (report_id)
		) {$collate};";

		dbDelta( $events_sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Returns the activity events table name.
	 *
	 * @since 0.3.0
	 *
	 * @return string
	 */
	public static function events_table() {
		global $wpdb;

		return $wpdb->prefix . 'update_zombie_events';
	}

	/**
	 * Decodes a report's stored signals.
	 *
	 * @since 0.3.0
	 *
	 * @param object|null $report Report row.
	 * @return array<string, mixed>
	 */
	public static function signals( $report ) {
		if ( ! $report || empty( $report->signals ) ) {
			return array();
		}

		$decoded = json_decode( (string) $report->signals, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Runs the table upgrade when the stored schema version is out of date.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Inserts a pending report if one does not already exist for this version.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $item Report fields: item_type, item_slug, item_file,
	 *                                   item_name, old_version, new_version.
	 * @return int|false Report ID, or false when a row already existed or insertion failed.
	 */
	public static function enqueue( array $item ) {
		global $wpdb;

		if ( self::find( $item['item_type'], $item['item_slug'], $item['new_version'] ) ) {
			return false;
		}

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'item_type'   => substr( (string) $item['item_type'], 0, 20 ),
				'item_slug'   => substr( (string) $item['item_slug'], 0, 191 ),
				'item_file'   => substr( (string) ( $item['item_file'] ?? '' ), 0, 191 ),
				'item_name'   => substr( (string) ( $item['item_name'] ?? $item['item_slug'] ), 0, 191 ),
				'old_version' => substr( (string) ( $item['old_version'] ?? '' ), 0, 64 ),
				'new_version' => substr( (string) $item['new_version'], 0, 64 ),
				'status'      => self::STATUS_PENDING,
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Finds a report by item identity and target version.
	 *
	 * @since 0.1.0
	 *
	 * @param string $type    Item type: plugin, theme or core.
	 * @param string $slug    Item slug.
	 * @param string $version Target version.
	 * @return object|null
	 */
	public static function find( $type, $slug, $version ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE item_type = %s AND item_slug = %s AND new_version = %s LIMIT 1",
				$type,
				$slug,
				$version
			)
		);
	}

	/**
	 * Returns a single report by ID.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id Report ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	/**
	 * Updates a report row.
	 *
	 * @since 0.1.0
	 *
	 * @param int                  $id   Report ID.
	 * @param array<string, mixed> $data Column/value pairs.
	 * @return void
	 */
	public static function update( $id, array $data ) {
		global $wpdb;

		foreach ( array( 'payload', 'signals' ) as $json_column ) {
			if ( isset( $data[ $json_column ] ) && ! is_string( $data[ $json_column ] ) && null !== $data[ $json_column ] ) {
				$data[ $json_column ] = wp_json_encode( $data[ $json_column ] );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * Claims the oldest pending report, marking it as analyzing.
	 *
	 * The conditional UPDATE is the lock: if two cron runs race, only the one
	 * whose UPDATE matched a still-pending row gets the item.
	 *
	 * @since 0.1.0
	 *
	 * @return object|null The claimed report, or null when the queue is empty.
	 */
	public static function claim_next_pending() {
		global $wpdb;

		$table = self::table();

		/*
		 * Work happens in two phases across separate cron ticks: a pending row
		 * gets downloaded and diffed, a diffed row gets analysed. Diffed rows
		 * are taken first so items finish rather than piling up half-done.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status IN ( %s, %s ) AND attempts < 3
				 ORDER BY FIELD( status, %s, %s ), created_at ASC LIMIT 1",
				self::STATUS_DIFFED,
				self::STATUS_PENDING,
				self::STATUS_DIFFED,
				self::STATUS_PENDING
			)
		);

		if ( ! $row ) {
			return null;
		}

		$claimed_from = $row->status;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, attempts = attempts + 1 WHERE id = %d AND status = %s",
				self::STATUS_ANALYZING,
				$row->id,
				$claimed_from
			)
		);

		if ( ! $claimed ) {
			return null;
		}

		$row->status       = self::STATUS_ANALYZING;
		$row->claimed_from = $claimed_from;

		return $row;
	}

	/**
	 * Returns the number of reports matching a status.
	 *
	 * @since 0.1.0
	 *
	 * @param string $status Status to count.
	 * @return int
	 */
	public static function count_by_status( $status ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status ) );
	}

	/**
	 * Queries reports for the admin list table.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $args Query args: status, item_type, verdict, search,
	 *                                   per_page, page, orderby, order.
	 * @return array{items: object[], total: int}
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'status'    => '',
				'item_type' => '',
				'verdict'   => '',
				'search'    => '',
				'per_page'  => 20,
				'page'      => 1,
				'orderby'   => 'created_at',
				'order'     => 'DESC',
			)
		);

		$table  = self::table();
		$where  = array( '1=1' );
		$params = array();

		foreach ( array( 'status', 'item_type', 'verdict' ) as $field ) {
			if ( '' !== $args[ $field ] ) {
				$where[]  = "{$field} = %s";
				$params[] = $args[ $field ];
			}
		}

		if ( '' !== $args['search'] ) {
			$where[]  = '(item_name LIKE %s OR item_slug LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );

		$allowed_orderby = array( 'created_at', 'analyzed_at', 'item_name', 'verdict', 'confidence', 'status' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order           = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';

		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = max( 0, ( (int) $args['page'] - 1 ) * $per_page );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

		$list_sql    = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$list_params = array_merge( $params, array( $per_page, $offset ) );
		$items       = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ) );
		// phpcs:enable

		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Returns the completed report for an item at a given version, if any.
	 *
	 * @since 0.1.0
	 *
	 * @param string $type    Item type.
	 * @param string $slug    Item slug.
	 * @param string $version Target version.
	 * @return object|null
	 */
	public static function completed_for( $type, $slug, $version ) {
		$row = self::find( $type, $slug, $version );

		return ( $row && self::STATUS_COMPLETE === $row->status ) ? $row : null;
	}

	/**
	 * Decodes a report's stored analysis payload.
	 *
	 * @since 0.1.0
	 *
	 * @param object|null $report Report row.
	 * @return array<string, mixed>
	 */
	public static function payload( $report ) {
		if ( ! $report || empty( $report->payload ) ) {
			return array();
		}

		$decoded = json_decode( (string) $report->payload, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Deletes reports older than the configured retention window.
	 *
	 * @since 0.1.0
	 *
	 * @return int Number of rows removed.
	 */
	public static function prune() {
		global $wpdb;

		$days   = max( 1, (int) Update_Zombie_Settings::get( 'retention_days', 90 ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$table  = self::table();

		$events = self::events_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$removed = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$events} WHERE created_at < %s", $cutoff ) );
		// phpcs:enable

		return $removed;
	}

	/**
	 * Deletes a report.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id Report ID.
	 * @return void
	 */
	public static function delete( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Drops the reports table. Used by uninstall.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function drop() {
		global $wpdb;

		$table  = self::table();
		$events = self::events_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$events}" );
		// phpcs:enable
	}
}
