<?php
/**
 * Uninstall handler.
 *
 * @package Update_Zombie
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'update_zombie_settings' );
delete_option( 'update_zombie_db_version' );
delete_transient( 'update_zombie_processing' );

foreach ( array( 'update_zombie_scan', 'update_zombie_process', 'update_zombie_prune', 'update_zombie_run_updater' ) as $update_zombie_hook ) {
	wp_clear_scheduled_hook( $update_zombie_hook );
}

$update_zombie_tables = array(
	$wpdb->prefix . 'update_zombie_reports',
	$wpdb->prefix . 'update_zombie_events',
);

foreach ( $update_zombie_tables as $update_zombie_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$wpdb->query( "DROP TABLE IF EXISTS {$update_zombie_table}" );
}
