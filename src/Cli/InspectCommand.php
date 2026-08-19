<?php
/**
 * Developer inspection WP-CLI command.
 *
 * @package WP_Playground_Importer
 */

declare(strict_types=1);

namespace WP_Playground_Importer\Cli;

use WP_CLI;
use WP_Playground_Importer\Destination\DestinationInspector;
use WP_Playground_Importer\Destination\WordPressDestinationWriter;
use WP_Playground_Importer\Import\ImportPlanner;
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
		WP_CLI::add_command( 'playground-importer plan', array( self::class, 'plan' ) );
		WP_CLI::add_command( 'playground-importer execute', array( self::class, 'execute' ) );
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

	/**
	 * Create a read-only migration plan for a local Playground export ZIP.
	 *
	 * ## OPTIONS
	 *
	 * <zip>
	 * : Path to a local Playground export ZIP.
	 *
	 * [--format=<format>]
	 * : Output format. Use json for machine-readable output.
	 *
	 * ## EXAMPLES
	 *
	 *     wp playground-importer plan /tmp/playground-export.zip
	 *     wp playground-importer plan /tmp/playground-export.zip --format=json
	 *
	 * @param array<int, string>        $args Positional arguments.
	 * @param array<string, string|int> $assoc_args Associative arguments.
	 */
	public static function plan( array $args, array $assoc_args ): void {
		$zip_path = $args[0] ?? '';
		$format   = (string) ( $assoc_args['format'] ?? 'summary' );
		$reader   = new PackageReader();

		try {
			$package     = $reader->inspect( $zip_path );
			$destination = ( new DestinationInspector() )->inspect();
			$plan        = ( new ImportPlanner() )->plan( $package, $destination );
		} catch ( PackageInspectionException $exception ) {
			WP_CLI::error(
				sprintf(
					'%s: %s',
					$exception->get_error_code(),
					$exception->get_user_message()
				)
			);
		}

		$plan_data = $plan->to_array();

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $plan_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		self::render_plan_summary( $plan_data );
	}

	/**
	 * Execute the experimental Milestone 5 writer for supported posts/pages and relationships.
	 *
	 * ## OPTIONS
	 *
	 * <zip>
	 * : Path to a local Playground export ZIP.
	 *
	 * ## EXAMPLES
	 *
	 *     wp playground-importer execute /tmp/playground-export.zip
	 *
	 * @param array<int, string>        $args Positional arguments.
	 * @param array<string, string|int> $assoc_args Associative arguments.
	 */
	public static function execute( array $args, array $assoc_args ): void {
		unset( $assoc_args );

		$zip_path = $args[0] ?? '';
		$reader   = new PackageReader();

		try {
			$package     = $reader->inspect( $zip_path );
			$destination = ( new DestinationInspector() )->inspect();
			$plan        = ( new ImportPlanner() )->plan( $package, $destination );
			$result      = ( new WordPressDestinationWriter() )->execute( $plan );
		} catch ( PackageInspectionException $exception ) {
			WP_CLI::error(
				sprintf(
					'%s: %s',
					$exception->get_error_code(),
					$exception->get_user_message()
				)
			);
		}

		$result_data = $result->to_array();
		self::render_execute_summary( $result_data );
		WP_CLI::line( wp_json_encode( $result_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Render a human-readable planning summary.
	 *
	 * @param array<string, mixed> $plan Plan data.
	 */
	private static function render_plan_summary( array $plan ): void {
		WP_CLI::line( 'WP Playground Importer migration plan (read-only)' );
		WP_CLI::line( '' );
		WP_CLI::line( sprintf( 'Source: %s', $plan['source']['siteurl'] ?? '(unknown)' ) );
		WP_CLI::line( sprintf( 'Destination: %s', $plan['destination']['site']['siteurl'] ?? '(unknown)' ) );
		WP_CLI::line( sprintf( 'Table prefix: %s', $plan['source']['table_prefix'] ?? '(unknown)' ) );
		WP_CLI::line( '' );

		WP_CLI::line( 'Content:' );
		foreach ( $plan['content'] as $content ) {
			WP_CLI::line(
				sprintf(
					'  - %s: %s (%s)',
					$content['post_type'],
					wp_json_encode( $content['statuses'], JSON_UNESCAPED_SLASHES ),
					$content['action']
				)
			);
		}

		WP_CLI::line( '' );
		WP_CLI::line(
			sprintf(
				'Theme: source %s, destination %s, status %s',
				$plan['theme']['source_stylesheet'],
				$plan['theme']['destination_stylesheet'],
				$plan['theme']['status']
			)
		);
		WP_CLI::line( sprintf( 'Plugins: %d source active plugin(s) planned', count( $plan['plugins'] ) ) );
		WP_CLI::line(
			sprintf(
				'Files: %d attachment(s), %d upload file(s) in package, %d missing attachment file(s)',
				$plan['files']['attachments'],
				$plan['files']['upload_files_in_package'],
				count( $plan['files']['missing_attachment_files'] )
			)
		);
		WP_CLI::line( sprintf( 'URL transform: %s -> %s', $plan['urls']['source_siteurl'], $plan['urls']['destination_url'] ) );
		WP_CLI::line( sprintf( 'Warnings: %d', count( $plan['warnings'] ) ) );

		foreach ( $plan['warnings'] as $warning ) {
			WP_CLI::line( sprintf( '  - %s: %s', $warning['code'], $warning['message'] ) );
		}
	}

	/**
	 * Render execution summary.
	 *
	 * @param array<string, mixed> $result Result data.
	 */
	private static function render_execute_summary( array $result ): void {
		WP_CLI::line( 'WP Playground Importer experimental execution result' );
		WP_CLI::line( 'Scope: published core posts/pages plus supported relationships, metadata, and taxonomies' );
		WP_CLI::line( sprintf( 'Planned executable records: %d', $result['planned_executable_records'] ) );
		WP_CLI::line( sprintf( 'Created records: %d', $result['created_records'] ) );
		WP_CLI::line( sprintf( 'Skipped records: %d', count( $result['skipped_records'] ) ) );
		WP_CLI::line( sprintf( 'Blocking errors: %d', count( $result['blocking_errors'] ) ) );
		WP_CLI::line( sprintf( 'Failed records: %d', count( $result['failed_records'] ) ) );
		WP_CLI::line( '' );
	}
}
