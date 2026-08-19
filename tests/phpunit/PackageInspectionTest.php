<?php
/**
 * Playground package inspection tests.
 *
 * @package WP_Playground_Importer
 */

declare(strict_types=1);

namespace WP_Playground_Importer\Tests;

use SQLite3;
use WP_Playground_Importer\Package\PackageInspectionException;
use WP_Playground_Importer\Package\PackageReader;
use WP_UnitTestCase;
use ZipArchive;

/**
 * Verifies read-only Playground ZIP/source inspection behavior.
 */
final class PackageInspectionTest extends WP_UnitTestCase {
	/**
	 * Temporary files created by tests.
	 *
	 * @var array<int, string>
	 */
	private array $temporary_files = array();

	/**
	 * Clean up temporary files.
	 */
	public function tear_down(): void {
		foreach ( $this->temporary_files as $file ) {
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}

		$this->temporary_files = array();

		parent::tear_down();
	}

	/**
	 * A synthetic Playground ZIP can be inspected without hard-coding wp_.
	 */
	public function test_inspects_synthetic_playground_export(): void {
		$zip_path = $this->create_playground_zip( 'abc_' );
		$result   = ( new PackageReader() )->inspect( $zip_path )->to_array();

		$this->assertSame( 'abc_', $result['source']['table_prefix'] );
		$this->assertSame( 'https://source.example', $result['source']['home'] );
		$this->assertSame( 'https://source.example/wp', $result['source']['siteurl'] );
		$this->assertNull( $result['source']['wordpress_version'] );
		$this->assertSame( '59000', $result['source']['db_version'] );
		$this->assertSame( 'twentytwentysix', $result['source']['theme']['stylesheet'] );
		$this->assertSame( array( 'hello/hello.php' ), $result['source']['plugins']['active'] );
		$this->assertSame( 1, $result['source']['content_summary']['post']['publish'] );
		$this->assertSame( 1, $result['source']['content_summary']['page']['draft'] );
		$this->assertContains( 'abc_options', $result['source']['tables'] );
		$this->assertSame( '1', $result['package']['manifest']['version'] );
	}

	/**
	 * Inspection does not alter representative destination WordPress state.
	 */
	public function test_inspection_does_not_modify_destination_wordpress(): void {
		$destination_post_id = self::factory()->post->create(
			array(
				'post_title'  => 'Destination State',
				'post_status' => 'publish',
			)
		);

		update_option( 'wp_playground_importer_destination_marker', 'before' );

		$before   = $this->snapshot_destination_state();
		$zip_path = $this->create_playground_zip( 'safe_' );

		( new PackageReader() )->inspect( $zip_path );

		$after = $this->snapshot_destination_state();

		$this->assertSame( $before, $after );
		$this->assertSame( 'Destination State', get_post( $destination_post_id )->post_title );
	}

	/**
	 * Missing ZIPs fail with a structured error.
	 */
	public function test_missing_zip_fails_gracefully(): void {
		$this->expect_inspection_error( 'zip_not_found' );

		( new PackageReader() )->inspect( '/tmp/does-not-exist-playground-export.zip' );
	}

	/**
	 * Non-ZIP files fail with a structured error.
	 */
	public function test_non_zip_fails_gracefully(): void {
		$file = $this->temporary_file( 'not-a-zip.txt' );
		file_put_contents( $file, 'not a zip' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$this->expect_inspection_error( 'not_zip' );

		( new PackageReader() )->inspect( $file );
	}

	/**
	 * Missing manifests fail with a structured error.
	 */
	public function test_missing_manifest_fails_gracefully(): void {
		$zip_path = $this->create_zip(
			array(
				'wp-content/database/.ht.sqlite' => $this->create_wordpress_sqlite_database( 'wp_' ),
			)
		);

		$this->expect_inspection_error( 'manifest_missing' );

		( new PackageReader() )->inspect( $zip_path );
	}

	/**
	 * Invalid manifest JSON fails with a structured error.
	 */
	public function test_invalid_manifest_fails_gracefully(): void {
		$zip_path = $this->create_zip(
			array(
				'playground-export.json'         => '{',
				'wp-content/database/.ht.sqlite' => $this->create_wordpress_sqlite_database( 'wp_' ),
			)
		);

		$this->expect_inspection_error( 'manifest_invalid_json' );

		( new PackageReader() )->inspect( $zip_path );
	}

	/**
	 * Packages missing the SQLite database fail with a structured error.
	 */
	public function test_missing_sqlite_database_fails_gracefully(): void {
		$zip_path = $this->create_zip(
			array(
				'playground-export.json' => wp_json_encode( array( 'version' => '1' ) ),
				'wp-content/.gitkeep'    => '',
			)
		);

		$this->expect_inspection_error( 'database_missing' );

		( new PackageReader() )->inspect( $zip_path );
	}

	/**
	 * Non-WordPress SQLite databases fail with a structured error.
	 */
	public function test_non_wordpress_sqlite_database_fails_gracefully(): void {
		$database_path = $this->temporary_file( 'not-wordpress.sqlite' );
		$database      = new SQLite3( $database_path );
		$database->exec( 'CREATE TABLE unrelated (id INTEGER PRIMARY KEY)' );
		$database->close();

		$zip_path = $this->create_zip(
			array(
				'playground-export.json'         => wp_json_encode( array( 'version' => '1' ) ),
				'wp-content/database/.ht.sqlite' => $database_path,
			)
		);

		$this->expect_inspection_error( 'database_not_wordpress' );

		( new PackageReader() )->inspect( $zip_path );
	}

	/**
	 * Databases missing required WordPress tables fail with a structured error.
	 */
	public function test_missing_required_tables_fail_gracefully(): void {
		$database_path = $this->temporary_file( 'partial-wordpress.sqlite' );
		$database      = new SQLite3( $database_path );
		$database->exec( 'CREATE TABLE wp_options (option_name TEXT PRIMARY KEY, option_value TEXT)' );
		$database->close();

		$zip_path = $this->create_zip(
			array(
				'playground-export.json'         => wp_json_encode( array( 'version' => '1' ) ),
				'wp-content/database/.ht.sqlite' => $database_path,
			)
		);

		$this->expect_inspection_error( 'required_tables_missing' );

		( new PackageReader() )->inspect( $zip_path );
	}

	/**
	 * Assert the next inspection failure has a specific structured code.
	 *
	 * @param string $error_code Expected error code.
	 */
	private function expect_inspection_error( string $error_code ): void {
		$this->expectException( PackageInspectionException::class );
		$this->expectExceptionMessage( $error_code );
	}

	/**
	 * Snapshot representative destination WordPress state.
	 *
	 * @return array<string, mixed>
	 */
	private function snapshot_destination_state(): array {
		return array(
			'post_count'      => wp_count_posts()->publish,
			'marker'          => get_option( 'wp_playground_importer_destination_marker' ),
			'users'           => count_users(),
			'stylesheet'      => get_option( 'stylesheet' ),
			'template'        => get_option( 'template' ),
			'active_plugins'  => get_option( 'active_plugins' ),
			'importer_option' => get_option( 'wp_playground_importer_last_inspection', null ),
		);
	}

	/**
	 * Create a valid synthetic Playground export ZIP.
	 *
	 * @param string $prefix Source table prefix.
	 * @return string ZIP path.
	 */
	private function create_playground_zip( string $prefix ): string {
		return $this->create_zip(
			array(
				'playground-export.json'         => wp_json_encode(
					array(
						'version' => '1',
						'source'  => 'synthetic-test-fixture',
					)
				),
				'wp-content/database/.ht.sqlite' => $this->create_wordpress_sqlite_database( $prefix ),
			)
		);
	}

	/**
	 * Create a ZIP from text entries and file-backed entries.
	 *
	 * @param array<string, string> $entries Entries keyed by archive path. Existing file paths are added as files.
	 * @return string ZIP path.
	 */
	private function create_zip( array $entries ): string {
		$zip_path = $this->temporary_file( 'playground-export.zip' );
		$zip      = new ZipArchive();

		$this->assertTrue( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) );
		$zip->addEmptyDir( 'wp-content' );

		foreach ( $entries as $archive_path => $contents_or_file ) {
			if ( is_file( $contents_or_file ) ) {
				$zip->addFile( $contents_or_file, $archive_path );
			} else {
				$zip->addFromString( $archive_path, $contents_or_file );
			}
		}

		$zip->close();

		return $zip_path;
	}

	/**
	 * Create a small SQLite database with recognizable WordPress tables.
	 *
	 * @param string $prefix Source table prefix.
	 * @return string SQLite database path.
	 */
	private function create_wordpress_sqlite_database( string $prefix ): string {
		$database_path = $this->temporary_file( 'source.sqlite' );
		$database      = new SQLite3( $database_path );

		$database->exec( sprintf( 'CREATE TABLE %soptions (option_name TEXT PRIMARY KEY, option_value TEXT)', $prefix ) );
		$database->exec( sprintf( 'CREATE TABLE %sposts (ID INTEGER PRIMARY KEY, post_author INTEGER DEFAULT 0, post_parent INTEGER DEFAULT 0, post_type TEXT, post_status TEXT, post_title TEXT, post_content TEXT, post_excerpt TEXT, post_name TEXT, post_date TEXT, post_date_gmt TEXT, post_modified TEXT, post_modified_gmt TEXT, menu_order INTEGER DEFAULT 0, comment_status TEXT, ping_status TEXT, post_password TEXT, guid TEXT)', $prefix ) );
		$database->exec( sprintf( 'CREATE TABLE %spostmeta (meta_id INTEGER PRIMARY KEY, post_id INTEGER, meta_key TEXT, meta_value TEXT)', $prefix ) );
		$database->exec( sprintf( 'CREATE TABLE %sterms (term_id INTEGER PRIMARY KEY, name TEXT)', $prefix ) );
		$database->exec( sprintf( 'CREATE TABLE %sterm_taxonomy (term_taxonomy_id INTEGER PRIMARY KEY, term_id INTEGER, taxonomy TEXT)', $prefix ) );
		$database->exec( sprintf( 'CREATE TABLE %sterm_relationships (object_id INTEGER, term_taxonomy_id INTEGER)', $prefix ) );
		$database->exec( sprintf( 'CREATE TABLE %susers (ID INTEGER PRIMARY KEY, user_login TEXT, user_email TEXT, display_name TEXT)', $prefix ) );
		$database->exec( sprintf( 'CREATE TABLE %susermeta (umeta_id INTEGER PRIMARY KEY, user_id INTEGER, meta_key TEXT, meta_value TEXT)', $prefix ) );

		$this->insert_option( $database, $prefix, 'home', 'https://source.example' );
		$this->insert_option( $database, $prefix, 'siteurl', 'https://source.example/wp' );
		$this->insert_option( $database, $prefix, 'db_version', '59000' );
		$this->insert_option( $database, $prefix, 'stylesheet', 'twentytwentysix' );
		$this->insert_option( $database, $prefix, 'template', 'twentytwentysix' );
		$this->insert_option( $database, $prefix, 'active_plugins', serialize( array( 'hello/hello.php' ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize

		$database->exec( sprintf( "INSERT INTO %susers (ID, user_login, user_email, display_name) VALUES (1, 'admin', 'admin@localhost', 'Admin')", $prefix ) );
		$database->exec( sprintf( "INSERT INTO %sposts (ID, post_author, post_parent, post_type, post_status, post_title, post_content, post_excerpt, post_name, post_date, post_date_gmt, post_modified, post_modified_gmt, comment_status, ping_status, post_password, guid) VALUES (1, 1, 0, 'post', 'publish', 'Hello', 'Content', '', 'hello', '2026-01-01 00:00:00', '2026-01-01 00:00:00', '2026-01-01 00:00:00', '2026-01-01 00:00:00', 'closed', 'closed', '', 'https://source.example/?p=1')", $prefix ) );
		$database->exec( sprintf( "INSERT INTO %sposts (ID, post_author, post_parent, post_type, post_status, post_title, post_content, post_excerpt, post_name, post_date, post_date_gmt, post_modified, post_modified_gmt, comment_status, ping_status, post_password, guid) VALUES (2, 1, 1, 'page', 'draft', 'Draft Page', 'Draft content', '', 'draft-page', '2026-01-02 00:00:00', '2026-01-02 00:00:00', '2026-01-02 00:00:00', '2026-01-02 00:00:00', 'closed', 'closed', '', 'https://source.example/?page_id=2')", $prefix ) );

		$database->close();

		return $database_path;
	}

	/**
	 * Insert an option into a source SQLite database.
	 *
	 * @param SQLite3 $database SQLite database.
	 * @param string  $prefix Table prefix.
	 * @param string  $name Option name.
	 * @param string  $value Option value.
	 */
	private function insert_option( SQLite3 $database, string $prefix, string $name, string $value ): void {
		$statement = $database->prepare( sprintf( 'INSERT INTO %soptions (option_name, option_value) VALUES (:name, :value)', $prefix ) );

		$this->assertNotFalse( $statement );

		$statement->bindValue( ':name', $name, SQLITE3_TEXT );
		$statement->bindValue( ':value', $value, SQLITE3_TEXT );
		$statement->execute();
		$statement->close();
	}

	/**
	 * Create a temporary file path.
	 *
	 * @param string $filename Filename hint.
	 * @return string Temporary path.
	 */
	private function temporary_file( string $filename ): string {
		$path = wp_tempnam( $filename );

		$this->assertIsString( $path );
		$this->temporary_files[] = $path;

		return $path;
	}
}
