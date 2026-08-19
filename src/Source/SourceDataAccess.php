<?php
/**
 * Reserved source-data boundary.
 *
 * @package WP_Playground_Importer
 */

declare(strict_types=1);

namespace WP_Playground_Importer\Source;

use SQLite3;
use WP_Playground_Importer\Package\PackageInspectionException;

/**
 * Reads source data from a Playground SQLite database.
 */
final class SourceDataAccess {
	private const REQUIRED_TABLE_SUFFIXES = array(
		'posts',
		'postmeta',
		'options',
		'terms',
		'term_taxonomy',
		'term_relationships',
		'users',
		'usermeta',
	);

	/**
	 * Inspect a WordPress SQLite database.
	 *
	 * @param string $database_path SQLite database path.
	 * @return SourceInspectionResult
	 *
	 * @throws PackageInspectionException When the source database is unsupported.
	 */
	public function inspect_database( string $database_path ): SourceInspectionResult {
		if ( ! class_exists( SQLite3::class ) ) {
			throw new PackageInspectionException(
				'sqlite_unavailable',
				'The current PHP runtime does not provide SQLite3 support.'
			);
		}

		try {
			$database = new SQLite3( $database_path, SQLITE3_OPEN_READONLY );
		} catch ( \Throwable $exception ) {
			throw new PackageInspectionException(
				'database_open_failed',
				'The Playground SQLite database could not be opened read-only.'
			);
		}

		try {
			$tables = $this->get_tables( $database );
			$prefix = $this->detect_table_prefix( $tables );
			$this->assert_required_tables( $tables, $prefix );

			return new SourceInspectionResult(
				array(
					'table_prefix'      => $prefix,
					'tables'            => $tables,
					'home'              => $this->get_option( $database, $prefix, 'home' ),
					'siteurl'           => $this->get_option( $database, $prefix, 'siteurl' ),
					'wordpress_version' => null,
					'db_version'        => $this->get_option( $database, $prefix, 'db_version' ),
					'content_summary'   => $this->get_content_summary( $database, $prefix ),
					'theme'             => array(
						'stylesheet' => $this->get_option( $database, $prefix, 'stylesheet' ),
						'template'   => $this->get_option( $database, $prefix, 'template' ),
					),
					'plugins'           => array(
						'active' => $this->get_active_plugins( $database, $prefix ),
					),
				)
			);
		} finally {
			$database->close();
		}
	}

	/**
	 * Get user tables in the source database.
	 *
	 * @param SQLite3 $database Read-only SQLite database.
	 * @return array<int, string>
	 *
	 * @throws PackageInspectionException When table discovery fails.
	 */
	private function get_tables( SQLite3 $database ): array {
		$result = $database->query(
			"SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
		);

		if ( false === $result ) {
			throw new PackageInspectionException(
				'database_table_discovery_failed',
				'The source database tables could not be inspected.'
			);
		}

		$tables = array();

		$row = $result->fetchArray( SQLITE3_ASSOC );

		while ( false !== $row ) {
			if ( isset( $row['name'] ) && is_string( $row['name'] ) ) {
				$tables[] = $row['name'];
			}

			$row = $result->fetchArray( SQLITE3_ASSOC );
		}

		$result->finalize();

		if ( array() === $tables ) {
			throw new PackageInspectionException(
				'database_not_wordpress',
				'The SQLite database does not contain recognizable WordPress tables.'
			);
		}

		return $tables;
	}

	/**
	 * Detect the WordPress table prefix from table names.
	 *
	 * @param array<int, string> $tables Table names.
	 * @return string
	 *
	 * @throws PackageInspectionException When no prefix is recognizable.
	 */
	private function detect_table_prefix( array $tables ): string {
		$candidates = array();

		foreach ( $tables as $table ) {
			if ( str_ends_with( $table, 'options' ) && strlen( $table ) > strlen( 'options' ) ) {
				$prefix = substr( $table, 0, -strlen( 'options' ) );

				if ( preg_match( '/^[A-Za-z0-9_]+$/', $prefix ) ) {
					$candidates[ $prefix ] = $this->count_matching_core_tables( $tables, $prefix );
				}
			}
		}

		arsort( $candidates );
		$prefix = array_key_first( $candidates );

		if ( ! is_string( $prefix ) || 0 === $candidates[ $prefix ] ) {
			throw new PackageInspectionException(
				'database_not_wordpress',
				'The SQLite database does not contain recognizable WordPress tables.'
			);
		}

		return $prefix;
	}

	/**
	 * Count expected core tables for a candidate prefix.
	 *
	 * @param array<int, string> $tables Table names.
	 * @param string             $prefix Candidate prefix.
	 * @return int
	 */
	private function count_matching_core_tables( array $tables, string $prefix ): int {
		$count = 0;

		foreach ( self::REQUIRED_TABLE_SUFFIXES as $suffix ) {
			if ( in_array( $prefix . $suffix, $tables, true ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Ensure required WordPress tables are present.
	 *
	 * @param array<int, string> $tables Table names.
	 * @param string             $prefix Table prefix.
	 *
	 * @throws PackageInspectionException When required tables are missing.
	 */
	private function assert_required_tables( array $tables, string $prefix ): void {
		$missing = array();

		foreach ( self::REQUIRED_TABLE_SUFFIXES as $suffix ) {
			$table = $prefix . $suffix;

			if ( ! in_array( $table, $tables, true ) ) {
				$missing[] = $table;
			}
		}

		if ( array() !== $missing ) {
			throw new PackageInspectionException(
				'required_tables_missing',
				'The source database is missing required WordPress tables.'
			);
		}
	}

	/**
	 * Get an option value from the source database.
	 *
	 * @param SQLite3 $database Read-only SQLite database.
	 * @param string  $prefix Table prefix.
	 * @param string  $option_name Option name.
	 * @return mixed
	 */
	private function get_option( SQLite3 $database, string $prefix, string $option_name ): mixed {
		$statement = $database->prepare(
			sprintf(
				'SELECT option_value FROM %s WHERE option_name = :option_name LIMIT 1',
				$this->quote_identifier( $prefix . 'options' )
			)
		);

		if ( false === $statement ) {
			return null;
		}

		$statement->bindValue( ':option_name', $option_name, SQLITE3_TEXT );
		$result = $statement->execute();

		if ( false === $result ) {
			$statement->close();

			return null;
		}

		$row = $result->fetchArray( SQLITE3_ASSOC );
		$result->finalize();
		$statement->close();

		if ( ! is_array( $row ) || ! array_key_exists( 'option_value', $row ) ) {
			return null;
		}

		return $this->maybe_unserialize( $row['option_value'] );
	}

	/**
	 * Get post counts by post type and status.
	 *
	 * @param SQLite3 $database Read-only SQLite database.
	 * @param string  $prefix Table prefix.
	 * @return array<string, array<string, int>>
	 */
	private function get_content_summary( SQLite3 $database, string $prefix ): array {
		$result = $database->query(
			sprintf(
				'SELECT post_type, post_status, COUNT(*) AS count FROM %s GROUP BY post_type, post_status ORDER BY post_type, post_status',
				$this->quote_identifier( $prefix . 'posts' )
			)
		);

		if ( false === $result ) {
			return array();
		}

		$summary = array();

		$row = $result->fetchArray( SQLITE3_ASSOC );

		while ( false !== $row ) {
			if ( ! isset( $row['post_type'], $row['post_status'], $row['count'] ) ) {
				$row = $result->fetchArray( SQLITE3_ASSOC );
				continue;
			}

			$post_type = (string) $row['post_type'];
			$status    = (string) $row['post_status'];

			if ( ! isset( $summary[ $post_type ] ) ) {
				$summary[ $post_type ] = array();
			}

			$summary[ $post_type ][ $status ] = (int) $row['count'];
			$row                              = $result->fetchArray( SQLITE3_ASSOC );
		}

		$result->finalize();

		return $summary;
	}

	/**
	 * Get active plugin paths from standard WordPress options.
	 *
	 * @param SQLite3 $database Read-only SQLite database.
	 * @param string  $prefix Table prefix.
	 * @return array<int, string>
	 */
	private function get_active_plugins( SQLite3 $database, string $prefix ): array {
		$active_plugins = $this->get_option( $database, $prefix, 'active_plugins' );

		if ( ! is_array( $active_plugins ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$active_plugins,
				static fn ( mixed $plugin ): bool => is_string( $plugin )
			)
		);
	}

	/**
	 * Safely quote a SQLite identifier created from a detected prefix and known suffix.
	 *
	 * @param string $identifier Identifier.
	 * @return string
	 */
	private function quote_identifier( string $identifier ): string {
		return '"' . str_replace( '"', '""', $identifier ) . '"';
	}

	/**
	 * Decode WordPress serialized option values when possible.
	 *
	 * @param mixed $value Raw option value.
	 * @return mixed
	 */
	private function maybe_unserialize( mixed $value ): mixed {
		if ( function_exists( 'maybe_unserialize' ) ) {
			return maybe_unserialize( $value );
		}

		if ( ! is_string( $value ) ) {
			return $value;
		}

		$decoded = @unserialize( $value ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize

		return false === $decoded && 'b:0;' !== $value ? $value : $decoded;
	}
}
