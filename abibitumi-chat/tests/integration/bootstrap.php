<?php
/**
 * Load the plugin inside the real WordPress PHPUnit environment.
 */

$plugin_dir = dirname( __DIR__, 2 );
require_once $plugin_dir . '/vendor/autoload.php';

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $plugin_dir . '/vendor/yoast/phpunit-polyfills' );
putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );

$tests_dir = getenv( 'WP_TESTS_FRAMEWORK_DIR' );
$tests_dir = $tests_dir ? $tests_dir : $plugin_dir . '/vendor/wp-phpunit/wp-phpunit';
require_once $tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	function () use ( $plugin_dir ) {
		require $plugin_dir . '/abibitumi-chat.php';
	}
);

require $tests_dir . '/includes/bootstrap.php';
