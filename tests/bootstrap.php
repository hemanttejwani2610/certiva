<?php
/**
 * PHPUnit bootstrap for Certiva.
 *
 * Expects the WordPress core PHPUnit test suite (as produced by
 * bin/install-wp-tests.sh) to be available, with its path in the
 * WP_TESTS_DIR environment variable (defaults to /tmp/wordpress-tests-lib).
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore
	exit( 1 );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

require_once "{$_tests_dir}/includes/functions.php";

/**
 * Loads Certiva as if it were an active plugin.
 */
function _certiva_manually_load_plugin() {
	require dirname( __DIR__ ) . '/certiva.php';
	\Certiva\Activator::activate();
}
tests_add_filter( 'muplugins_loaded', '_certiva_manually_load_plugin' );

require "{$_tests_dir}/includes/bootstrap.php";
