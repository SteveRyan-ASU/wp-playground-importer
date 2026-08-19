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
					'content_items'     => $this->get_content_items( $database, $prefix ),
					'postmeta'          => $this->get_postmeta_for_planning( $database, $prefix ),
					'taxonomy'          => $this->get_taxonomy_for_planning( $database, $prefix ),
					'users'             => $this->get_users( $database, $prefix ),
					'options'           => $this->get_options_for_planning( $database, $prefix ),
					'relationships'     => $this->get_relationships( $database, $prefix ),
					'additional_tables' => $this->get_additional_tables( $tables, $prefix ),
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
	 * Get source content items relevant to migration planning.
	 *
	 * @param SQLite3 $database Read-only SQLite database.
	 * @param string  $prefix Table prefix.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_content_items( SQLite3 $database, string $prefix ): array {
		$result = $database->query(
			sprintf(
				'SELECT ID, post_author, post_parent, post_type, post_status, post_title, post_content, post_excerpt, post_name, post_date, post_date_gmt, post_modified, post_modified_gmt, menu_order, comment_status, ping_status, post_password, guid FROM %s ORDER BY ID',
				$this->quote_identifier( $prefix . 'posts' )
			)
		);

		if ( false === $result ) {
			return array();
		}

		$items = array();
		$row   = $result->fetchArray( SQLITE3_ASSOC );

		while ( false !== $row ) {
			$items[] = array(
				'id'             => (int) ( $row['ID'] ?? 0 ),
				'author_id'      => (int) ( $row['post_author'] ?? 0 ),
				'parent_id'      => (int) ( $row['post_parent'] ?? 0 ),
				'type'           => (string) ( $row['post_type'] ?? '' ),
				'status'         => (string) ( $row['post_status'] ?? '' ),
				'title'          => (string) ( $row['post_title'] ?? '' ),
				'content'        => (string) ( $row['post_content'] ?? '' ),
				'excerpt'        => (string) ( $row['post_excerpt'] ?? '' ),
				'slug'           => (string) ( $row['post_name'] ?? '' ),
				'post_date'      => (string) ( $row['post_date'] ?? '' ),
				'post_date_gmt'  => (string) ( $row['post_date_gmt'] ?? '' ),
				'modified'       => (string) ( $row['post_modified'] ?? '' ),
				'modified_gmt'   => (string) ( $row['post_modified_gmt'] ?? '' ),
				'menu_order'     => (int) ( $row['menu_order'] ?? 0 ),
				'comment_status' => (string) ( $row['comment_status'] ?? 'closed' ),
				'ping_status'    => (string) ( $row['ping_status'] ?? 'closed' ),
				'password'       => (string) ( $row['post_password'] ?? '' ),
				'guid'           => (string) ( $row['guid'] ?? '' ),
				'attached_file'  => $this->get_post_meta( $database, $prefix, (int) ( $row['ID'] ?? 0 ), '_wp_attached_file' ),
			);
			$row     = $result->fetchArray( SQLITE3_ASSOC );
		}

		$result->finalize();

		return $items;
	}

	/**
	 * Get source users without exposing credentials.
	 *
	 * @param SQLite3 $database Read-only SQLite database.
	 * @param string  $prefix Table prefix.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_users( SQLite3 $database, string $prefix ): array {
		$result = $database->query(
			sprintf(
				'SELECT ID, user_login, user_email, display_name FROM %s ORDER BY ID',
				$this->quote_identifier( $prefix . 'users' )
			)
		);

		if ( false === $result ) {
			return array();
		}

		$users = array();
		$row   = $result->fetchArray( SQLITE3_ASSOC );

		while ( false !== $row ) {
			$users[] = array(
				'id'           => (int) ( $row['ID'] ?? 0 ),
				'login'        => (string) ( $row['user_login'] ?? '' ),
				'email_domain' => $this->email_domain( (string) ( $row['user_email'] ?? '' ) ),
				'display_name' => (string) ( $row['display_name'] ?? '' ),
			);
			$row     = $result->fetchArray( SQLITE3_ASSOC );
		}

		$result->finalize();

		return $users;
	}

	/**
	 * Get source post meta rows for planning.
	 *
	 * @param SQLite3 $database Read-only SQLite database.
	 * @param string  $prefix Table prefix.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_postmeta_for_planning( SQLite3 $database, string $prefix ): array {
		$result = $database->query(
			sprintf(
				'SELECT pm.post_id, pm.meta_key, pm.meta_value FROM %s pm INNER JOIN %s p ON p.ID = pm.post_id WHERE p.post_type IN (\'post\', \'page\') ORDER BY pm.post_id, pm.meta_id',
				$this->quote_identifier( $prefix . 'postmeta' ),
				$this->quote_identifier( $prefix . 'posts' )
			)
		);

		if ( false === $result ) {
			return array();
		}

		$rows = array();
		$row  = $result->fetchArray( SQLITE3_ASSOC );

		while ( false !== $row ) {
			$rows[] = array(
				'post_id'    => (int) ( $row['post_id'] ?? 0 ),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key'   => (string) ( $row['meta_key'] ?? '' ),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value' => $this->maybe_unserialize( $row['meta_value'] ?? '' ),
			);
			$row    = $result->fetchArray( SQLITE3_ASSOC );
		}

		$result->finalize();

		return $rows;
	}

	/**
	 * Get source taxonomy data for planning.
	 *
	 * @param SQLite3 $database Read-only SQLite database.
	 * @param string  $prefix Table prefix.
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function get_taxonomy_for_planning( SQLite3 $database, string $prefix ): array {
		return array(
			'terms'         => $this->get_terms_for_planning( $database, $prefix ),
			'relationships' => $this->get_term_relationships_for_planning( $database, $prefix ),
		);
	}

	/**
	 * Get terms and term taxonomy rows.
	 *
	 * @param SQLite3 $database Read-only SQLite database.
	 * @param string  $prefix Table prefix.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_terms_for_planning( SQLite3 $database, string $prefix ): array {
		$terms_table         = $prefix . 'terms';
		$term_taxonomy_table = $prefix . 'term_taxonomy';
		$slug_expression     = $this->has_column( $database, $terms_table, 'slug' ) ? 't.slug' : "''";
		$description_expr    = $this->has_column( $database, $term_taxonomy_table, 'description' ) ? 'tt.description' : "''";
		$parent_expression   = $this->has_column( $database, $term_taxonomy_table, 'parent' ) ? 'tt.parent' : '0';

		$result = $database->query(
			sprintf(
				'SELECT t.term_id, t.name, COALESCE(%s, \'\') AS slug, tt.term_taxonomy_id, tt.taxonomy, COALESCE(%s, \'\') AS description, COALESCE(%s, 0) AS parent FROM %s t INNER JOIN %s tt ON tt.term_id = t.term_id ORDER BY tt.term_taxonomy_id',
				$slug_expression,
				$description_expr,
				$parent_expression,
				$this->quote_identifier( $terms_table ),
				$this->quote_identifier( $term_taxonomy_table )
			)
		);

		if ( false === $result ) {
			return array();
		}

		$terms = array();
		$row   = $result->fetchArray( SQLITE3_ASSOC );

		while ( false !== $row ) {
			$terms[] = array(
				'term_id'          => (int) ( $row['term_id'] ?? 0 ),
				'name'             => (string) ( $row['name'] ?? '' ),
				'slug'             => (string) ( $row['slug'] ?? '' ),
				'term_taxonomy_id' => (int) ( $row['term_taxonomy_id'] ?? 0 ),
				'taxonomy'         => (string) ( $row['taxonomy'] ?? '' ),
				'description'      => (string) ( $row['description'] ?? '' ),
				'parent'           => (int) ( $row['parent'] ?? 0 ),
			);
			$row     = $result->fetchArray( SQLITE3_ASSOC );
		}

		$result->finalize();

		return $terms;
	}

	/**
	 * Get term relationships.
	 *
	 * @param SQLite3 $database Read-only SQLite database.
	 * @param string  $prefix Table prefix.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_term_relationships_for_planning( SQLite3 $database, string $prefix ): array {
		$result = $database->query(
			sprintf(
				'SELECT tr.object_id, tr.term_taxonomy_id, tt.term_id, tt.taxonomy FROM %s tr INNER JOIN %s tt ON tt.term_taxonomy_id = tr.term_taxonomy_id ORDER BY tr.object_id, tr.term_taxonomy_id',
				$this->quote_identifier( $prefix . 'term_relationships' ),
				$this->quote_identifier( $prefix . 'term_taxonomy' )
			)
		);

		if ( false === $result ) {
			return array();
		}

		$relationships = array();
		$row           = $result->fetchArray( SQLITE3_ASSOC );

		while ( false !== $row ) {
			$relationships[] = array(
				'object_source_id' => (int) ( $row['object_id'] ?? 0 ),
				'term_taxonomy_id' => (int) ( $row['term_taxonomy_id'] ?? 0 ),
				'term_id'          => (int) ( $row['term_id'] ?? 0 ),
				'taxonomy'         => (string) ( $row['taxonomy'] ?? '' ),
			);
			$row             = $result->fetchArray( SQLITE3_ASSOC );
		}

		$result->finalize();

		return $relationships;
	}

	/**
	 * Get source options useful for planning.
	 *
	 * @param SQLite3 $database Read-only SQLite database.
	 * @param string  $prefix Table prefix.
	 * @return array<string, mixed>
	 */
	private function get_options_for_planning( SQLite3 $database, string $prefix ): array {
		$option_names = array(
			'home',
			'siteurl',
			'show_on_front',
			'page_on_front',
			'page_for_posts',
			'permalink_structure',
			'template',
			'stylesheet',
			'active_plugins',
			'upload_path',
		);
		$options      = array();

		foreach ( $option_names as $option_name ) {
			$options[ $option_name ] = $this->get_option( $database, $prefix, $option_name );
		}

		return $options;
	}

	/**
	 * Get known source relationships that will require remapping.
	 *
	 * @param SQLite3 $database Read-only SQLite database.
	 * @param string  $prefix Table prefix.
	 * @return array<string, mixed>
	 */
	private function get_relationships( SQLite3 $database, string $prefix ): array {
		return array(
			'post_authors'       => $this->count_distinct_post_values( $database, $prefix, 'post_author', 'post_author > 0' ),
			'post_parents'       => $this->count_distinct_post_values( $database, $prefix, 'post_parent', 'post_parent > 0' ),
			'featured_images'    => $this->count_meta_rows( $database, $prefix, '_thumbnail_id' ),
			'attachment_files'   => $this->count_meta_rows( $database, $prefix, '_wp_attached_file' ),
			'taxonomy_links'     => $this->count_rows( $database, $prefix . 'term_relationships' ),
			'front_page_options' => array_filter(
				array(
					'page_on_front'  => $this->get_option( $database, $prefix, 'page_on_front' ),
					'page_for_posts' => $this->get_option( $database, $prefix, 'page_for_posts' ),
				)
			),
		);
	}

	/**
	 * Get source tables outside the recognized WordPress core set.
	 *
	 * @param array<int, string> $tables Table names.
	 * @param string             $prefix Table prefix.
	 * @return array<int, string>
	 */
	private function get_additional_tables( array $tables, string $prefix ): array {
		$core_suffixes = array_merge(
			self::REQUIRED_TABLE_SUFFIXES,
			array( 'comments', 'commentmeta', 'links', 'termmeta' )
		);
		$core_tables   = array_map(
			static fn ( string $suffix ): string => $prefix . $suffix,
			$core_suffixes
		);

		return array_values(
			array_filter(
				$tables,
				static fn ( string $table ): bool => ! in_array( $table, $core_tables, true ) && ! str_starts_with( $table, '_wp_sqlite_' )
			)
		);
	}

	/**
	 * Get a single post meta value.
	 *
	 * @param SQLite3 $database Read-only SQLite database.
	 * @param string  $prefix Table prefix.
	 * @param int     $post_id Post ID.
	 * @param string  $meta_key Meta key.
	 * @return mixed
	 */
	private function get_post_meta( SQLite3 $database, string $prefix, int $post_id, string $meta_key ): mixed {
		$statement = $database->prepare(
			sprintf(
				'SELECT meta_value FROM %s WHERE post_id = :post_id AND meta_key = :meta_key LIMIT 1',
				$this->quote_identifier( $prefix . 'postmeta' )
			)
		);

		if ( false === $statement ) {
			return null;
		}

		$statement->bindValue( ':post_id', $post_id, SQLITE3_INTEGER );
		$statement->bindValue( ':meta_key', $meta_key, SQLITE3_TEXT );
		$result = $statement->execute();

		if ( false === $result ) {
			$statement->close();
			return null;
		}

		$row = $result->fetchArray( SQLITE3_ASSOC );
		$result->finalize();
		$statement->close();

		return is_array( $row ) && array_key_exists( 'meta_value', $row ) ? $this->maybe_unserialize( $row['meta_value'] ) : null;
	}

	/**
	 * Count distinct values in the posts table.
	 *
	 * @param SQLite3 $database Read-only SQLite database.
	 * @param string  $prefix Table prefix.
	 * @param string  $column Known column name.
	 * @param string  $where Known WHERE clause.
	 * @return int
	 */
	private function count_distinct_post_values( SQLite3 $database, string $prefix, string $column, string $where ): int {
		$result = $database->querySingle(
			sprintf(
				'SELECT COUNT(DISTINCT %s) FROM %s WHERE %s',
				$column,
				$this->quote_identifier( $prefix . 'posts' ),
				$where
			)
		);

		return (int) $result;
	}

	/**
	 * Count rows for a known meta key.
	 *
	 * @param SQLite3 $database Read-only SQLite database.
	 * @param string  $prefix Table prefix.
	 * @param string  $meta_key Meta key.
	 * @return int
	 */
	private function count_meta_rows( SQLite3 $database, string $prefix, string $meta_key ): int {
		$statement = $database->prepare(
			sprintf(
				'SELECT COUNT(*) FROM %s WHERE meta_key = :meta_key',
				$this->quote_identifier( $prefix . 'postmeta' )
			)
		);

		if ( false === $statement ) {
			return 0;
		}

		$statement->bindValue( ':meta_key', $meta_key, SQLITE3_TEXT );
		$result = $statement->execute();

		if ( false === $result ) {
			$statement->close();
			return 0;
		}

		$row = $result->fetchArray( SQLITE3_NUM );
		$result->finalize();
		$statement->close();

		return is_array( $row ) ? (int) $row[0] : 0;
	}

	/**
	 * Count rows in a known table.
	 *
	 * @param SQLite3 $database Read-only SQLite database.
	 * @param string  $table Table name.
	 * @return int
	 */
	private function count_rows( SQLite3 $database, string $table ): int {
		return (int) $database->querySingle(
			sprintf(
				'SELECT COUNT(*) FROM %s',
				$this->quote_identifier( $table )
			)
		);
	}

	/**
	 * Check whether a SQLite table has a given column.
	 *
	 * @param SQLite3 $database Read-only SQLite database.
	 * @param string  $table Table name.
	 * @param string  $column Column name.
	 * @return bool
	 */
	private function has_column( SQLite3 $database, string $table, string $column ): bool {
		$result = $database->query(
			sprintf(
				'PRAGMA table_info(%s)',
				$this->quote_identifier( $table )
			)
		);

		if ( false === $result ) {
			return false;
		}

		$row = $result->fetchArray( SQLITE3_ASSOC );

		while ( false !== $row ) {
			if ( (string) ( $row['name'] ?? '' ) === $column ) {
				$result->finalize();
				return true;
			}

			$row = $result->fetchArray( SQLITE3_ASSOC );
		}

		$result->finalize();

		return false;
	}

	/**
	 * Return only the email domain for low-sensitivity planning output.
	 *
	 * @param string $email Email address.
	 * @return string|null
	 */
	private function email_domain( string $email ): ?string {
		$parts = explode( '@', $email );

		return 2 === count( $parts ) ? $parts[1] : null;
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
