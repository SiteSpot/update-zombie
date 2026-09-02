<?php
/**
 * Plugin Name:       Update Zombie
 * Plugin URI:        https://sitespot.dev/updatezombie
 * Description:       Don't let all these updates turn you into a zombie. Downloads each pending update, diffs it against what you're running, and has an AI judge whether it's a security fix, a good update, or one to avoid.
 * Version:           0.6.0
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Author:            AB Split Test
 * Author URI:        https://absplittest.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       update-zombie
 *
 * @package Update_Zombie
 */

defined( 'ABSPATH' ) || exit;

define( 'UPDATE_ZOMBIE_VERSION', '0.6.0' );
define( 'UPDATE_ZOMBIE_FILE', __FILE__ );
define( 'UPDATE_ZOMBIE_DIR', plugin_dir_path( __FILE__ ) );
define( 'UPDATE_ZOMBIE_URL', plugin_dir_url( __FILE__ ) );
define( 'UPDATE_ZOMBIE_SLUG', 'update-zombie' );

/**
 * PSR-ish autoloader for the plugin's Update_Zombie_* classes.
 *
 * Update_Zombie_Reports_Table lives in admin/, the AI provider classes in
 * includes/providers/, everything else in includes/.
 *
 * @since 0.1.0
 *
 * @param string $class_name Fully qualified class name being loaded.
 * @return void
 */
function update_zombie_autoload( $class_name ) {
	if ( 0 !== strpos( $class_name, 'Update_Zombie_' ) ) {
		return;
	}

	$file = 'class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';

	foreach ( array( 'includes', 'includes/providers', 'admin' ) as $dir ) {
		$path = UPDATE_ZOMBIE_DIR . $dir . '/' . $file;
		if ( is_readable( $path ) ) {
			require_once $path;
			return;
		}
	}
}
spl_autoload_register( 'update_zombie_autoload' );

/**
 * Returns the shared plugin instance.
 *
 * @since 0.1.0
 *
 * @return Update_Zombie_Plugin
 */
function update_zombie() {
	static $instance = null;

	if ( null === $instance ) {
		$instance = new Update_Zombie_Plugin();
	}

	return $instance;
}

update_zombie()->init();

register_activation_hook( __FILE__, array( 'Update_Zombie_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Update_Zombie_Plugin', 'deactivate' ) );
