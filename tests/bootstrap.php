<?php
/**
 * PHPUnit bootstrap for WordPress integration tests.
 *
 * @package WP_Playground_Importer
 */

declare(strict_types=1);

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = '/wordpress-phpunit';
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/wp-playground-importer.php';
	}
);

require $_tests_dir . '/includes/bootstrap.php';
