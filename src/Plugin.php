<?php
/**
 * Main plugin bootstrap.
 *
 * @package WP_Playground_Importer
 */

declare(strict_types=1);

namespace WP_Playground_Importer;

/**
 * Coordinates plugin loading.
 */
final class Plugin {
	/**
	 * Register plugin runtime hooks.
	 */
	public static function load(): void {
		do_action( 'wp_playground_importer_loaded' );
	}
}
