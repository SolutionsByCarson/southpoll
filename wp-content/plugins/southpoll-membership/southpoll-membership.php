<?php
/**
 * Plugin Name:       SouthPoll Membership
 * Plugin URI:        https://southpoll.com
 * Description:       Membership, farm directory, and cattle classifieds for the South Poll Grass Cattle Association.
 * Version:           0.3.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Carson Bennett / Ranch House Designs
 * Author URI:        https://ranchhousedesigns.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       southpoll-membership
 * Domain Path:       /languages
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'SP_VERSION', '0.3.0' );
define( 'SP_DB_VERSION', '0.5.0' ); // Bump whenever Core\Schema or Core\Migrator data steps change. 0.5.0 = sp_feedback (S8, 2026-11-18).
define( 'SP_PLUGIN_FILE', __FILE__ );
define( 'SP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>SouthPoll Membership requires PHP 8.1 or newer. This server runs ' . esc_html( PHP_VERSION ) . '.</p></div>';
		}
	);
	return;
}

/**
 * PSR-4 autoloader for the SouthPoll\Membership namespace (maps to /src).
 */
spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'SouthPoll\\Membership\\';
		if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$file     = SP_PLUGIN_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_file( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, [ \SouthPoll\Membership\Core\Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \SouthPoll\Membership\Core\Deactivator::class, 'deactivate' ] );

add_action(
	'plugins_loaded',
	static function (): void {
		\SouthPoll\Membership\Core\Plugin::instance()->boot();
	},
	5
);

/**
 * Global accessor.
 */
function sp_membership(): \SouthPoll\Membership\Core\Plugin {
	return \SouthPoll\Membership\Core\Plugin::instance();
}
