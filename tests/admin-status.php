<?php
/**
 * Regression checks for compact status labels on WordPress core screens.
 *
 * Run with: php tests/admin-status.php
 */

define( 'ABSPATH', __DIR__ . '/' );

function __( $text ) {
	return $text;
}

function esc_html__( $text ) {
	return $text;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
	return esc_html( $text );
}

function esc_url( $url ) {
	return esc_html( $url );
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
}

function add_query_arg( $key, $value, $url ) {
	return $url . '?' . rawurlencode( $key ) . '=' . rawurlencode( (string) $value );
}

class Update_Zombie_Store {
	const STATUS_PENDING   = 'pending';
	const STATUS_ANALYZING = 'analyzing';
	const STATUS_DIFFED    = 'diffed';
	const STATUS_COMPLETE  = 'complete';
	const STATUS_ERROR     = 'error';

	public static function find() {
		return $GLOBALS['update_zombie_test_report'] ?? null;
	}
}

class Update_Zombie_Scanner {
	public static function plugin_slug() {
		return 'example-plugin';
	}
}

require_once dirname( __DIR__ ) . '/admin/class-update-zombie-admin.php';

$failures = 0;

function update_zombie_admin_assert( $condition, $label ) {
	global $failures;

	if ( $condition ) {
		echo "PASS: {$label}\n";
		return;
	}

	++$failures;
	echo "FAIL: {$label}\n";
}

function update_zombie_admin_status( $report ) {
	$method = new ReflectionMethod( 'Update_Zombie_Admin', 'report_status' );
	$method->setAccessible( true );

	return $method->invoke( null, $report );
}

$not_checked = update_zombie_admin_status( null );
update_zombie_admin_assert( 'Not checked' === $not_checked['label'] && 'neutral' === $not_checked['class'], 'Missing report is shown as Not checked' );

$queued = update_zombie_admin_status( (object) array( 'status' => 'pending' ) );
update_zombie_admin_assert( 'Queued' === $queued['label'] && 'pending' === $queued['class'], 'Pending report uses the existing Queued status' );

$working = update_zombie_admin_status( (object) array( 'status' => 'analyzing' ) );
update_zombie_admin_assert( 'Working' === $working['label'], 'Analyzing report uses the existing Working status' );

$diffed = update_zombie_admin_status( (object) array( 'status' => 'diffed' ) );
update_zombie_admin_assert( 'Diffed, awaiting review' === $diffed['label'], 'Diffed report keeps its existing review status' );

$failed = update_zombie_admin_status( (object) array( 'status' => 'error' ) );
update_zombie_admin_assert( 'Failed' === $failed['label'] && 'error' === $failed['class'], 'Failed report remains visibly failed' );

$security_report = (object) array(
	'id'             => 42,
	'status'         => 'complete',
	'verdict'        => 'security',
	'is_security'    => 1,
	'new_version'    => '2.0.0',
);
$security = update_zombie_admin_status( $security_report );
update_zombie_admin_assert( 'Security' === $security['label'] && $security['security'], 'Security verdict uses the clear Security badge label' );

$security_report->verdict = 'good';
$security                = update_zombie_admin_status( $security_report );
update_zombie_admin_assert( 'Security' === $security['label'] && 'security' === $security['class'], 'Security evidence takes precedence over a generic verdict label' );

exit( $failures ? 1 : 0 );
