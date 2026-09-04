<?php
/**
 * PHPUnit bootstrap for the Opace Essential SEO Toolkit plugin.
 *
 * Runs inside the wp-env tests container:
 *   npx wp-env run tests-cli --env-cwd=wp-content/opace-eseot-dev phpunit
 *
 * wp-env exposes the WordPress PHPUnit test library through the
 * WP_TESTS_DIR environment variable and the Yoast polyfills through
 * WP_TESTS_PHPUNIT_POLYFILLS_PATH.
 *
 * @package Opace_ESEOT_Tests
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Composer autoloader for this workspace (PHPUnit 9 + Yoast polyfills), installed with:
//   npx wp-env run tests-cli --env-cwd=wp-content/opace-eseot-dev composer install
if ( file_exists( dirname( __DIR__ ) . '/vendor/autoload.php' ) ) {
	require_once dirname( __DIR__ ) . '/vendor/autoload.php';
}

$_polyfills = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( ! $_polyfills && is_dir( dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' ) ) {
	$_polyfills = dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills';
}
if ( $_polyfills && ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_polyfills );
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php. Is WP_TESTS_DIR set? Run this inside wp-env: npx wp-env run tests-cli --env-cwd=wp-content/opace-eseot-dev phpunit" . PHP_EOL; // phpcs:ignore
	exit( 1 );
}

require_once "{$_tests_dir}/includes/functions.php";

/**
 * Resolve the plugin main file.
 *
 * Priority: OPACE_ESEOT_PLUGIN_FILE env var, then the wp-env mapping
 * wp-content/plugins/opace-essential-seo-toolkit/ relative to this workspace
 * (mounted at wp-content/opace-eseot-dev/), then WP_PLUGIN_DIR.
 *
 * @return string
 */
function _opace_eseot_tests_plugin_file() {
	$env = getenv( 'OPACE_ESEOT_PLUGIN_FILE' );
	if ( $env && file_exists( $env ) ) {
		return $env;
	}
	$sibling = dirname( __DIR__, 2 ) . '/plugins/opace-essential-seo-toolkit/opace-essential-seo-toolkit.php';
	if ( file_exists( $sibling ) ) {
		return $sibling;
	}
	if ( defined( 'WP_PLUGIN_DIR' ) ) {
		return WP_PLUGIN_DIR . '/opace-essential-seo-toolkit/opace-essential-seo-toolkit.php';
	}
	return $sibling;
}

/**
 * Load the plugin the same way WordPress would (before plugins_loaded).
 */
function _opace_eseot_tests_load_plugin() {
	$file = _opace_eseot_tests_plugin_file();
	if ( ! file_exists( $file ) ) {
		echo "Plugin main file not found at {$file}" . PHP_EOL; // phpcs:ignore
		exit( 1 );
	}
	require $file;
}
tests_add_filter( 'muplugins_loaded', '_opace_eseot_tests_load_plugin' );

require_once __DIR__ . '/includes/class-opace-eseot-test-case.php';

require "{$_tests_dir}/includes/bootstrap.php";
