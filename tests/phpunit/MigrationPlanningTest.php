<?php
/**
 * Migration planning tests.
 *
 * @package WP_Playground_Importer
 */

declare(strict_types=1);

namespace WP_Playground_Importer\Tests;

use SQLite3;
use WP_Playground_Importer\Destination\DestinationInspector;
use WP_Playground_Importer\Import\ImportPlanner;
use WP_Playground_Importer\Import\MigrationAction;
use WP_Playground_Importer\Package\PackageReader;
use WP_UnitTestCase;
use ZipArchive;

/**
 * Verifies read-only migration planning behavior.
 */
final class MigrationPlanningTest extends WP_UnitTestCase {
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
	 * Planner returns a structured, reusable plan for a clean destination.
	 */
	public function test_plans_clean_destination_without_writes(): void {
		$before = $this->snapshot_destination_state();
		$plan   = $this->create_plan( 'abc_' );
		$after  = $this->snapshot_destination_state();

		$this->assertSame( $before, $after );
		$this->assertSame( 'https://source.example/wp', $plan['source']['siteurl'] );
		$this->assertSame( site_url(), $plan['destination']['site']['siteurl'] );
		$this->assertSame( 'abc_', $plan['source']['table_prefix'] );
		$this->assertSame( MigrationAction::REMAP, $plan['urls']['action'] );
		$this->assertSame( MigrationAction::REMAP, $plan['relationships']['action'] );
		$this->assertSame( 1, $plan['relationships']['summary']['post_parents'] );
		$this->assertSame( 1, $plan['relationships']['summary']['featured_images'] );
		$this->assertSame( 1, $plan['files']['attachments'] );
		$this->assertSame( 2, $plan['files']['upload_files_in_package'] );
		$this->assertSame( 1, $plan['files']['matched_attachment_files'] );
		$this->assertSame( MigrationAction::REMAP, $plan['users'][0]['action'] );
		$this->assertSame( MigrationAction::PRESERVE_DESTINATION, $this->option_action( $plan, 'home' ) );
		$this->assertSame( MigrationAction::REMAP, $this->option_action( $plan, 'page_on_front' ) );
		$this->assertSame( MigrationAction::REVIEW, $this->content_action( $plan, 'book' ) );
		$this->assertSame( 'not_installed', $plan['theme']['status'] );
		$this->assertSame( 'not_installed', $plan['plugins'][0]['status'] );
		$this->assertSame( array( 'abc_plugin_data' ), $plan['tables']['additional_tables'] );
		$this->assertContains( 'source_theme_missing', wp_list_pluck( $plan['warnings'], 'code' ) );
		$this->assertContains( 'source_plugin_unavailable_or_inactive', wp_list_pluck( $plan['warnings'], 'code' ) );
	}

	/**
	 * Planner surfaces populated destination warnings.
	 */
	public function test_plans_populated_destination_warning(): void {
		self::factory()->post->create(
			array(
				'post_title'  => 'Existing Destination Content',
				'post_status' => 'publish',
			)
		);
		self::factory()->post->create(
			array(
				'post_title'  => 'More Destination Content',
				'post_status' => 'publish',
			)
		);

		$plan = $this->create_plan( 'wp_' );

		$this->assertContains( 'destination_populated', wp_list_pluck( $plan['warnings'], 'code' ) );
	}

	/**
	 * Planner output is deterministic for unchanged source and destination state.
	 */
	public function test_repeated_planning_is_deterministic(): void {
		$zip_path = $this->create_playground_zip( 'det_' );
		$first    = $this->plan_zip( $zip_path );
		$second   = $this->plan_zip( $zip_path );

		$this->assertSame( $first, $second );
	}

	/**
	 * Create a migration plan for a synthetic Playground package.
	 *
	 * @param string $prefix Source table prefix.
	 * @return array<string, mixed>
	 */
	private function create_plan( string $prefix ): array {
		return $this->plan_zip( $this->create_playground_zip( $prefix ) );
	}

	/**
	 * Plan a ZIP path.
	 *
	 * @param string $zip_path ZIP path.
	 * @return array<string, mixed>
	 */
	private function plan_zip( string $zip_path ): array {
		$package     = ( new PackageReader() )->inspect( $zip_path );
		$destination = ( new DestinationInspector() )->inspect();

		return ( new ImportPlanner() )->plan( $package, $destination )->to_array();
	}

	/**
	 * Get a planned option action.
	 *
	 * @param array<string, mixed> $plan Plan.
	 * @param string               $name Option name.
	 * @return string|null
	 */
	private function option_action( array $plan, string $name ): ?string {
		foreach ( $plan['options'] as $option ) {
			if ( $name === $option['name'] ) {
				return $option['action'];
			}
		}

		return null;
	}

	/**
	 * Get a planned post type action.
	 *
	 * @param array<string, mixed> $plan Plan.
	 * @param string               $post_type Post type.
	 * @return string|null
	 */
	private function content_action( array $plan, string $post_type ): ?string {
		foreach ( $plan['content'] as $content ) {
			if ( $post_type === $content['post_type'] ) {
				return $content['action'];
			}
		}

		return null;
	}

	/**
	 * Snapshot representative destination state.
	 *
	 * @return array<string, mixed>
	 */
	private function snapshot_destination_state(): array {
		return array(
			'post_count'     => wp_count_posts()->publish,
			'users'          => count_users(),
			'stylesheet'     => get_option( 'stylesheet' ),
			'template'       => get_option( 'template' ),
			'active_plugins' => get_option( 'active_plugins' ),
			'siteurl'        => get_option( 'siteurl' ),
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
				'playground-export.json'               => wp_json_encode(
					array(
						'formatVersion' => 2,
						'siteUrl'       => 'https://source.example/wp',
					)
				),
				'wp-content/database/.ht.sqlite'       => $this->create_wordpress_sqlite_database( $prefix ),
				'wp-content/uploads/2026/08/image.jpg' => 'image',
				'wp-content/uploads/2026/08/image-150x150.jpg' => 'thumb',
				'wp-content/plugins/example-plugin/example-plugin.php' => '<?php',
			)
		);
	}

	/**
	 * Create a ZIP from text entries and file-backed entries.
	 *
	 * @param array<string, string> $entries Entries keyed by archive path.
	 * @return string ZIP path.
	 */
	private function create_zip( array $entries ): string {
		$zip_path = $this->temporary_file( 'planning-playground-export.zip' );
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
	 * Create a small SQLite database with planning-relevant WordPress data.
	 *
	 * @param string $prefix Source table prefix.
	 * @return string SQLite database path.
	 */
	private function create_wordpress_sqlite_database( string $prefix ): string {
		$database_path = $this->temporary_file( 'planning-source.sqlite' );
		$database      = new SQLite3( $database_path );

		$database->exec( sprintf( 'CREATE TABLE %soptions (option_name TEXT PRIMARY KEY, option_value TEXT)', $prefix ) );
		$database->exec( sprintf( 'CREATE TABLE %sposts (ID INTEGER PRIMARY KEY, post_author INTEGER DEFAULT 0, post_parent INTEGER DEFAULT 0, post_type TEXT, post_status TEXT, post_title TEXT, guid TEXT)', $prefix ) );
		$database->exec( sprintf( 'CREATE TABLE %spostmeta (meta_id INTEGER PRIMARY KEY, post_id INTEGER, meta_key TEXT, meta_value TEXT)', $prefix ) );
		$database->exec( sprintf( 'CREATE TABLE %sterms (term_id INTEGER PRIMARY KEY, name TEXT)', $prefix ) );
		$database->exec( sprintf( 'CREATE TABLE %sterm_taxonomy (term_taxonomy_id INTEGER PRIMARY KEY, term_id INTEGER, taxonomy TEXT)', $prefix ) );
		$database->exec( sprintf( 'CREATE TABLE %sterm_relationships (object_id INTEGER, term_taxonomy_id INTEGER)', $prefix ) );
		$database->exec( sprintf( 'CREATE TABLE %susers (ID INTEGER PRIMARY KEY, user_login TEXT, user_email TEXT, display_name TEXT)', $prefix ) );
		$database->exec( sprintf( 'CREATE TABLE %susermeta (umeta_id INTEGER PRIMARY KEY, user_id INTEGER, meta_key TEXT, meta_value TEXT)', $prefix ) );
		$database->exec( sprintf( 'CREATE TABLE %splugin_data (id INTEGER PRIMARY KEY)', $prefix ) );

		$this->insert_option( $database, $prefix, 'home', 'https://source.example' );
		$this->insert_option( $database, $prefix, 'siteurl', 'https://source.example/wp' );
		$this->insert_option( $database, $prefix, 'show_on_front', 'page' );
		$this->insert_option( $database, $prefix, 'page_on_front', '2' );
		$this->insert_option( $database, $prefix, 'page_for_posts', '1' );
		$this->insert_option( $database, $prefix, 'permalink_structure', '/%postname%/' );
		$this->insert_option( $database, $prefix, 'stylesheet', 'missing-theme' );
		$this->insert_option( $database, $prefix, 'template', 'missing-theme' );
		$this->insert_option( $database, $prefix, 'active_plugins', serialize( array( 'example-plugin/example-plugin.php' ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize

		$database->exec( sprintf( "INSERT INTO %susers (ID, user_login, user_email, display_name) VALUES (7, 'source_admin', 'admin@localhost', 'Source Admin')", $prefix ) );
		$database->exec( sprintf( "INSERT INTO %sposts (ID, post_author, post_parent, post_type, post_status, post_title, guid) VALUES (1, 7, 0, 'post', 'publish', 'Hello', 'https://source.example/?p=1')", $prefix ) );
		$database->exec( sprintf( "INSERT INTO %sposts (ID, post_author, post_parent, post_type, post_status, post_title, guid) VALUES (2, 7, 1, 'page', 'publish', 'Front', 'https://source.example/?page_id=2')", $prefix ) );
		$database->exec( sprintf( "INSERT INTO %sposts (ID, post_author, post_parent, post_type, post_status, post_title, guid) VALUES (3, 7, 0, 'attachment', 'inherit', 'Image', 'https://source.example/wp-content/uploads/2026/08/image.jpg')", $prefix ) );
		$database->exec( sprintf( "INSERT INTO %sposts (ID, post_author, post_parent, post_type, post_status, post_title, guid) VALUES (4, 7, 0, 'wp_navigation', 'publish', 'Nav', '')", $prefix ) );
		$database->exec( sprintf( "INSERT INTO %sposts (ID, post_author, post_parent, post_type, post_status, post_title, guid) VALUES (5, 7, 0, 'book', 'publish', 'Book', '')", $prefix ) );
		$database->exec( sprintf( "INSERT INTO %spostmeta (post_id, meta_key, meta_value) VALUES (1, '_thumbnail_id', '3')", $prefix ) );
		$database->exec( sprintf( "INSERT INTO %spostmeta (post_id, meta_key, meta_value) VALUES (3, '_wp_attached_file', '2026/08/image.jpg')", $prefix ) );
		$database->exec( sprintf( 'INSERT INTO %sterm_relationships (object_id, term_taxonomy_id) VALUES (1, 1)', $prefix ) );

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
