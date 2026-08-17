<?php
/**
 * Plugin bootstrap smoke tests.
 *
 * @package WP_Playground_Importer
 */

declare(strict_types=1);

namespace WP_Playground_Importer\Tests;

use WP_Playground_Importer\Plugin;
use WP_UnitTestCase;

/**
 * Verifies that the plugin loads in a bootstrapped WordPress test environment.
 */
final class PluginSmokeTest extends WP_UnitTestCase {
	/**
	 * The plugin bootstrap class is autoloaded.
	 */
	public function test_plugin_bootstrap_class_is_available(): void {
		$this->assertTrue( class_exists( Plugin::class ) );
	}

	/**
	 * The plugin load hook fires when the bootstrap runs.
	 */
	public function test_plugin_load_hook_fires(): void {
		$loaded = false;

		add_action(
			'wp_playground_importer_loaded',
			static function () use ( &$loaded ): void {
				$loaded = true;
			}
		);

		Plugin::load();

		$this->assertTrue( $loaded );
	}
}
