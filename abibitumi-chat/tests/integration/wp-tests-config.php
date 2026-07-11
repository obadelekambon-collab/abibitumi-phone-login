<?php
/**
 * WordPress PHPUnit configuration sourced from CI environment variables.
 */

define( 'DB_NAME', getenv( 'WP_TEST_DB_NAME' ) ?: 'wordpress_test' );
define( 'DB_USER', getenv( 'WP_TEST_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WP_TEST_DB_PASSWORD' ) ?: 'root' );
define( 'DB_HOST', getenv( 'WP_TEST_DB_HOST' ) ?: '127.0.0.1' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

define( 'ABSPATH', rtrim( getenv( 'WP_CORE_DIR' ) ?: '/tmp/wordpress', '/\\' ) . '/' );
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Abibitumi Chat Tests' );
define( 'WP_PHP_BINARY', PHP_BINARY );
define( 'WP_DEBUG', true );

$table_prefix = 'wptests_';
