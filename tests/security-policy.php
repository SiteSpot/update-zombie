<?php
/**
 * Focused regression checks for evidence binding and auto-update policy.
 *
 * Run with: php tests/security-policy.php
 */

define( 'ABSPATH', __DIR__ . '/' );

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function apply_filters( $hook, $value ) {
	unset( $hook );

	return $value;
}

function wp_strip_all_tags( $text ) {
	return strip_tags( (string) $text );
}

require_once dirname( __DIR__ ) . '/includes/class-update-zombie-analyzer.php';
require_once dirname( __DIR__ ) . '/includes/class-update-zombie-scanner.php';

class Update_Zombie_Settings {
	const MODE_ADVISORY  = 'advisory';
	const MODE_GUARDED   = 'guarded';
	const MODE_AUTOPILOT = 'autopilot';

	public static function get( $key, $default = null ) {
		return 'security_confidence' === $key ? 70 : $default;
	}
}

class Update_Zombie_Store {
	public static function payload( $report ) {
		$payload = json_decode( (string) $report->payload, true );

		return is_array( $payload ) ? $payload : array();
	}
}

class Update_Zombie_Changelog {
	public static function is_point_release( $old, $new ) {
		unset( $old, $new );

		return true;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-update-zombie-enforcer.php';

$failures = 0;

function update_zombie_assert_same( $expected, $actual, $label ) {
	global $failures;

	if ( $expected === $actual ) {
		echo "PASS: {$label}\n";
		return;
	}

	++$failures;
	echo "FAIL: {$label}\n";
	echo '  expected: ' . var_export( $expected, true ) . "\n";
	echo '  actual:   ' . var_export( $actual, true ) . "\n";
}

$analyzer = new Update_Zombie_Analyzer();
$bind     = new ReflectionMethod( $analyzer, 'bind_security_findings_to_reviewed_files' );
$bind->setAccessible( true );

$findings = array(
	'security_findings' => array(
		array( 'file' => 'includes/valid.php' ),
		array( 'file' => 'b/includes/valid.php' ),
		array( 'file' => 'includes\\valid.php' ),
		array( 'file' => 'invented.php' ),
		array( 'file' => '../wp-config.php' ),
	),
);
$diff = array(
	'files' => array(
		array( 'path' => 'includes/valid.php' ),
	),
);

$bound = $bind->invoke( $analyzer, $findings, $diff );
$paths = array_column( $bound['security_findings'], 'file' );

update_zombie_assert_same(
	array( 'includes/valid.php', 'includes/valid.php', 'includes/valid.php', '', '' ),
	$paths,
	'Only exact reviewed-file citations survive normalization'
);

$clean = new ReflectionMethod( $analyzer, 'clean_findings' );
$clean->setAccessible( true );
$cleaned = $clean->invoke(
	$analyzer,
	array(
		array( 'title' => 'Missing severity', 'file' => 'a.php', 'confidence' => 90 ),
		array( 'title' => 'High severity', 'file' => 'b.php', 'confidence' => 90, 'severity' => 'HIGH' ),
	),
	false
);

update_zombie_assert_same( 'unknown', $cleaned[0]['severity'], 'Missing severity fails closed' );
update_zombie_assert_same( 'high', $cleaned[1]['severity'], 'Allowed severity is normalized' );

function update_zombie_report( array $finding, $verdict = 'security', $recommendation = 'apply' ) {
	return (object) array(
		'is_security'    => 'security' === $verdict ? 1 : 0,
		'confidence'     => (int) ( $finding['confidence'] ?? 0 ),
		'verdict'        => $verdict,
		'recommendation' => $recommendation,
		'item_type'      => 'plugin',
		'old_version'    => '1.0.0',
		'new_version'    => '1.0.1',
		'payload'        => json_encode( array( 'engine' => 'ai', 'security_findings' => array( $finding ) ) ),
	);
}

$high   = array( 'file' => 'includes/valid.php', 'severity' => 'high', 'confidence' => 90 );
$low    = array( 'file' => 'includes/valid.php', 'severity' => 'low', 'confidence' => 99 );
$medium = array( 'file' => 'includes/valid.php', 'severity' => 'medium', 'confidence' => 99 );

update_zombie_assert_same( 'apply', Update_Zombie_Enforcer::evaluate( update_zombie_report( $high ), 'guarded' ), 'High-impact cited finding can apply in Guarded mode' );
update_zombie_assert_same( 'defer', Update_Zombie_Enforcer::evaluate( update_zombie_report( $low ), 'guarded' ), 'Low-impact finding cannot widen auto-update' );
update_zombie_assert_same( 'defer', Update_Zombie_Enforcer::evaluate( update_zombie_report( $medium ), 'guarded' ), 'Medium-impact finding cannot widen auto-update' );
update_zombie_assert_same( 'defer', Update_Zombie_Enforcer::evaluate( update_zombie_report( $low ), 'autopilot' ), 'Low-impact security finding cannot borrow Autopilot policy' );
update_zombie_assert_same( 'defer', Update_Zombie_Enforcer::evaluate( update_zombie_report( array( 'file' => 'includes/valid.php', 'confidence' => 99 ) ), 'guarded' ), 'Stored finding without severity fails closed' );
update_zombie_assert_same( 'defer', Update_Zombie_Enforcer::evaluate( update_zombie_report( array( 'file' => 'includes/valid.php', 'severity' => 'critical', 'confidence' => 69 ) ), 'guarded' ), 'Severity and confidence must pass on the same finding' );
update_zombie_assert_same( 'defer', Update_Zombie_Enforcer::evaluate( update_zombie_report( array( 'file' => '', 'severity' => 'critical', 'confidence' => 99 ) ), 'guarded' ), 'Blank citation cannot widen auto-update' );
update_zombie_assert_same( 'defer', Update_Zombie_Enforcer::evaluate( update_zombie_report( $high ), 'advisory' ), 'Advisory mode remains non-enforcing' );
update_zombie_assert_same( 'apply', Update_Zombie_Enforcer::evaluate( update_zombie_report( array(), 'good', 'apply' ), 'autopilot' ), 'Autopilot still applies ordinary good updates' );

$forged = $bind->invoke(
	$analyzer,
	array( 'security_findings' => array( array( 'file' => 'invented.php', 'severity' => 'critical', 'confidence' => 100 ) ) ),
	$diff
);
update_zombie_assert_same( 'defer', Update_Zombie_Enforcer::evaluate( update_zombie_report( $forged['security_findings'][0] ), 'guarded' ), 'Original fabricated-citation path no longer reaches auto-update' );

update_zombie_assert_same( 'vendor-plugin', Update_Zombie_Scanner::plugin_slug( 'vendor-plugin/plugin.php', 'wrong-provider-slug' ), 'Directory plugin identity comes from plugin filepath' );
update_zombie_assert_same( 'single-plugin', Update_Zombie_Scanner::plugin_slug( 'single-plugin.php', 'wrong-provider-slug' ), 'Single-file plugin identity is stable' );
update_zombie_assert_same( 'premium-fallback', Update_Zombie_Scanner::plugin_slug( '', 'premium-fallback' ), 'Provider slug remains a fallback' );

exit( $failures ? 1 : 0 );
