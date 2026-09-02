<?php
/**
 * Regression checks for forced update discovery and cron-loop prevention.
 *
 * Run with: php tests/update-refresh.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );

$test_filters       = array();
$test_last_checked  = array();
$test_scheduled     = 0;
$test_core_forced   = false;
$test_scanner_calls = 0;

function add_filter( $hook, $callback, $priority = 10 ) {
	global $test_filters;

	$test_filters[ $hook ][ $priority ][] = $callback;
}

function remove_filter( $hook, $callback, $priority = 10 ) {
	global $test_filters;

	foreach ( $test_filters[ $hook ][ $priority ] ?? array() as $index => $registered ) {
		if ( $registered === $callback ) {
			unset( $test_filters[ $hook ][ $priority ][ $index ] );
		}
	}
}

function apply_filters( $hook, $value ) {
	global $test_filters;

	$priorities = $test_filters[ $hook ] ?? array();
	ksort( $priorities );

	foreach ( $priorities as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$value = call_user_func( $callback, $value );
		}
	}

	return $value;
}

function wp_update_plugins() {
	global $test_last_checked;

	$updates                      = apply_filters( 'site_transient_update_plugins', (object) array( 'last_checked' => 123 ) );
	$test_last_checked['plugins'] = $updates->last_checked;
	$GLOBALS['test_plugin']->schedule_scan_soon();
}

function wp_update_themes() {
	global $test_last_checked;

	$updates                     = apply_filters( 'site_transient_update_themes', (object) array( 'last_checked' => 123 ) );
	$test_last_checked['themes'] = $updates->last_checked;
	$GLOBALS['test_plugin']->schedule_scan_soon();
}

function wp_version_check( $extra_stats = array(), $force_check = false ) {
	global $test_core_forced;

	$test_core_forced = array() === $extra_stats && true === $force_check;
	$GLOBALS['test_plugin']->schedule_scan_soon();
}

function wp_next_scheduled() {
	return false;
}

function wp_schedule_single_event() {
	global $test_scheduled;

	++$test_scheduled;
}

class Update_Zombie_Store {
	public static function maybe_upgrade() {}
}

class Update_Zombie_Scanner {
	public function scan_all() {
		global $test_scanner_calls;

		++$test_scanner_calls;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-update-zombie-plugin.php';

function update_zombie_refresh_assert( $condition, $label ) {
	if ( $condition ) {
		echo "PASS: {$label}\n";
		return 0;
	}

	echo "FAIL: {$label}\n";
	return 1;
}

$failures               = 0;
$GLOBALS['test_plugin'] = new Update_Zombie_Plugin();
$GLOBALS['test_plugin']->run_scan();

$failures += update_zombie_refresh_assert( 0 === $test_last_checked['plugins'], 'Plugin update check bypasses the cron cache in memory' );
$failures += update_zombie_refresh_assert( 0 === $test_last_checked['themes'], 'Theme update check bypasses the cron cache in memory' );
$failures += update_zombie_refresh_assert( $test_core_forced, 'Core update check uses the native force flag' );
$failures += update_zombie_refresh_assert( 0 === $test_scheduled, 'Self-induced transient writes do not queue another scan' );
$failures += update_zombie_refresh_assert( 1 === $test_scanner_calls, 'Scanner runs after update discovery' );
$failures += update_zombie_refresh_assert( 123 === apply_filters( 'site_transient_update_plugins', (object) array( 'last_checked' => 123 ) )->last_checked, 'Temporary plugin filter is removed' );
$failures += update_zombie_refresh_assert( 123 === apply_filters( 'site_transient_update_themes', (object) array( 'last_checked' => 123 ) )->last_checked, 'Temporary theme filter is removed' );

$GLOBALS['test_plugin']->schedule_scan_soon();
$failures += update_zombie_refresh_assert( 1 === $test_scheduled, 'External update refresh still queues a prompt scan' );

exit( $failures ? 1 : 0 );
