<?php
/**
 * Developer inspection WP-CLI command.
 *
 * @package WP_Playground_Importer
 */

declare(strict_types=1);

namespace WP_Playground_Importer\Cli;

use WP_CLI;
use WP_Playground_Importer\Package\PackageInspectionException;
use WP_Playground_Importer\Package\PackageReader;

/**
 * Provides read-only Playground package inspection for development.
 */
final class InspectCommand {
	/**
	 * Register the command with WP-CLI.
	 */
	public static function register(): void {
		WP_CLI::add_command( 'playground-importer inspect', array( self::class, 'inspect' ) );
	}

	/**
	 * Inspect a local Playground export ZIP without importing anything.
	 *
	 * ## OPTIONS
	 *
	 * <zip>
	 * : Path to a local Playground export ZIP.
	 *
	 * ## EXAMPLES
	 *
	 *     wp playground-importer inspect /tmp/playground-export.zip
	 *
	 * @param array<int, string>        $args Positional arguments.
	 * @param array<string, string|int> $assoc_args Associative arguments.
	 */
	public static function inspect( array $args, array $assoc_args ): void {
		unset( $assoc_args );

		$zip_path = $args[0] ?? '';
		$reader   = new PackageReader();

		try {
			$result = $reader->inspect( $zip_path );
		} catch ( PackageInspectionException $exception ) {
			WP_CLI::error(
				sprintf(
					'%s: %s',
					$exception->get_error_code(),
					$exception->get_user_message()
				)
			);
		}

		WP_CLI::line( wp_json_encode( $result->to_array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}
}
