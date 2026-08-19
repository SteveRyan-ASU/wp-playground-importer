<?php
/**
 * Reserved package-reading boundary.
 *
 * @package WP_Playground_Importer
 */

declare(strict_types=1);

namespace WP_Playground_Importer\Package;

use ZipArchive;

/**
 * Reads and inspects Playground export packages.
 */
final class PackageReader {
	private const MANIFEST_PATH = 'playground-export.json';
	private const DATABASE_PATH = 'wp-content/database/.ht.sqlite';

	/**
	 * Inspect a Playground export ZIP.
	 *
	 * @param string $zip_path Absolute path to the candidate ZIP.
	 * @return PackageInspectionResult
	 *
	 * @throws PackageInspectionException When the package cannot be inspected.
	 */
	public function inspect( string $zip_path ): PackageInspectionResult {
		$this->assert_readable_zip_path( $zip_path );

		$zip         = new ZipArchive();
		$open_result = $zip->open( $zip_path );

		if ( true !== $open_result ) {
			throw new PackageInspectionException(
				'not_zip',
				'The selected file is not a readable ZIP archive.'
			);
		}

		try {
			$entries = $this->list_entries( $zip );
			$this->assert_required_entries( $entries );
			$manifest      = $this->read_manifest( $zip );
			$database_path = $this->extract_database_to_temporary_file( $zip );

			try {
				$source_inspector = new \WP_Playground_Importer\Source\SourceDataAccess();
				$source           = $source_inspector->inspect_database( $database_path );
			} finally {
				if ( file_exists( $database_path ) ) {
					wp_delete_file( $database_path );
				}
			}

			return new PackageInspectionResult(
				array(
					'manifest'      => $manifest,
					'manifest_path' => self::MANIFEST_PATH,
					'database_path' => self::DATABASE_PATH,
					'entries'       => $entries,
					'source'        => $source,
				)
			);
		} finally {
			$zip->close();
		}
	}

	/**
	 * Ensure the ZIP path is usable.
	 *
	 * @param string $zip_path Candidate ZIP path.
	 *
	 * @throws PackageInspectionException When the path cannot be read.
	 */
	private function assert_readable_zip_path( string $zip_path ): void {
		if ( '' === $zip_path || ! file_exists( $zip_path ) ) {
			throw new PackageInspectionException(
				'zip_not_found',
				'The selected Playground export ZIP could not be found.'
			);
		}

		if ( ! is_readable( $zip_path ) || ! is_file( $zip_path ) ) {
			throw new PackageInspectionException(
				'zip_not_readable',
				'The selected Playground export ZIP is not readable.'
			);
		}
	}

	/**
	 * List normalized archive entries.
	 *
	 * @param ZipArchive $zip Open ZIP archive.
	 * @return array<int, string>
	 */
	private function list_entries( ZipArchive $zip ): array {
		$entries = array();

		$file_count = $zip->count();

		for ( $index = 0; $index < $file_count; $index++ ) {
			$name = $zip->getNameIndex( $index );

			if ( false !== $name ) {
				$entries[] = ltrim( str_replace( '\\', '/', $name ), '/' );
			}
		}

		sort( $entries );

		return $entries;
	}

	/**
	 * Check minimum expected Playground export structure.
	 *
	 * @param array<int, string> $entries ZIP entries.
	 *
	 * @throws PackageInspectionException When required entries are missing.
	 */
	private function assert_required_entries( array $entries ): void {
		if ( ! in_array( self::MANIFEST_PATH, $entries, true ) ) {
			throw new PackageInspectionException(
				'manifest_missing',
				'The ZIP does not contain playground-export.json.'
			);
		}

		if ( ! $this->contains_directory_or_child( $entries, 'wp-content/' ) ) {
			throw new PackageInspectionException(
				'wp_content_missing',
				'The ZIP does not contain a wp-content directory.'
			);
		}

		if ( ! in_array( self::DATABASE_PATH, $entries, true ) ) {
			throw new PackageInspectionException(
				'database_missing',
				'The ZIP does not contain the expected Playground SQLite database.'
			);
		}
	}

	/**
	 * Determine whether an archive contains a directory or one of its children.
	 *
	 * @param array<int, string> $entries ZIP entries.
	 * @param string             $directory Directory path with trailing slash.
	 * @return bool
	 */
	private function contains_directory_or_child( array $entries, string $directory ): bool {
		foreach ( $entries as $entry ) {
			if ( $directory === $entry || str_starts_with( $entry, $directory ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Read and decode the Playground manifest.
	 *
	 * @param ZipArchive $zip Open ZIP archive.
	 * @return array<string, mixed>
	 *
	 * @throws PackageInspectionException When metadata cannot be parsed.
	 */
	private function read_manifest( ZipArchive $zip ): array {
		$contents = $zip->getFromName( self::MANIFEST_PATH );

		if ( false === $contents ) {
			throw new PackageInspectionException(
				'manifest_unreadable',
				'The Playground export metadata could not be read.'
			);
		}

		$manifest = json_decode( $contents, true );

		if ( ! is_array( $manifest ) || JSON_ERROR_NONE !== json_last_error() ) {
			throw new PackageInspectionException(
				'manifest_invalid_json',
				'The Playground export metadata is not valid JSON.'
			);
		}

		return $manifest;
	}

	/**
	 * Extract only the SQLite database to a controlled temporary file.
	 *
	 * @param ZipArchive $zip Open ZIP archive.
	 * @return string Temporary database path.
	 *
	 * @throws PackageInspectionException When extraction fails.
	 */
	private function extract_database_to_temporary_file( ZipArchive $zip ): string {
		$source = $zip->getStream( self::DATABASE_PATH );

		if ( false === $source ) {
			throw new PackageInspectionException(
				'database_unreadable',
				'The Playground SQLite database could not be read from the ZIP.'
			);
		}

		$temporary_path = wp_tempnam( 'wp-playground-importer-source.sqlite' );

		if ( false === $temporary_path ) {
			fclose( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

			throw new PackageInspectionException(
				'temporary_file_failed',
				'A temporary file could not be created for read-only inspection.'
			);
		}

		$destination = fopen( $temporary_path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( false === $destination ) {
			fclose( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

			throw new PackageInspectionException(
				'temporary_file_failed',
				'A temporary file could not be opened for read-only inspection.'
			);
		}

		$copied = stream_copy_to_stream( $source, $destination );

		fclose( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $destination ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( false === $copied ) {
			wp_delete_file( $temporary_path );

			throw new PackageInspectionException(
				'database_extraction_failed',
				'The Playground SQLite database could not be prepared for inspection.'
			);
		}

		return $temporary_path;
	}
}
