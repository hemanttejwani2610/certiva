<?php
/**
 * Plugin Name:       Certiva
 * Description:       Student & Event Certificates for WordPress. Register students for exams, seminars, and conferences, design certificate templates, and let recipients securely request and download their certificates by email.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Certiva
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       certiva
 * Domain Path:       /languages
 *
 * @package Certiva
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CERTIVA_VERSION', '1.0.0' );
define( 'CERTIVA_FILE', __FILE__ );
define( 'CERTIVA_DIR', plugin_dir_path( __FILE__ ) );
define( 'CERTIVA_URL', plugin_dir_url( __FILE__ ) );
define( 'CERTIVA_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Composer autoloader (mPDF and its dependencies).
 */
if ( file_exists( CERTIVA_DIR . 'vendor/autoload.php' ) ) {
	require_once CERTIVA_DIR . 'vendor/autoload.php';
}

/**
 * Plugin class autoloader (PSR-4-ish, namespace Certiva\).
 */
spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, 'Certiva\\' ) ) {
			return;
		}

		$relative = substr( $class, strlen( 'Certiva\\' ) );
		$relative = str_replace( '\\', DIRECTORY_SEPARATOR, $relative );
		$path     = CERTIVA_DIR . 'includes' . DIRECTORY_SEPARATOR . $relative . '.php';

		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( CERTIVA_FILE, [ \Certiva\Activator::class, 'activate' ] );
register_deactivation_hook( CERTIVA_FILE, [ \Certiva\Deactivator::class, 'deactivate' ] );

\Certiva\Plugin::register();
