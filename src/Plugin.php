<?php
/**
 * Main plugin bootstrap.
 *
 * @package WP_Playground_Importer
 */

declare(strict_types=1);

namespace WP_Playground_Importer;

use WP_Playground_Importer\Cli\InspectCommand;

/**
 * Coordinates plugin loading.
 */
final class Plugin {
	/**
	 * Register plugin runtime hooks.
	 */
	public static function load(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			InspectCommand::register();
		}

		do_action( 'wp_playground_importer_loaded' );
	}
}
