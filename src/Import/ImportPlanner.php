<?php
/**
 * Reserved import-planning boundary.
 *
 * @package WP_Playground_Importer
 */

declare(strict_types=1);

namespace WP_Playground_Importer\Import;

use WP_Playground_Importer\Destination\DestinationInspectionResult;
use WP_Playground_Importer\Package\PackageInspectionResult;

/**
 * Builds a read-only migration plan.
 */
final class ImportPlanner {
	private const SUPPORTED_META_KEYS = array(
		'_wp_page_template',
	);

	private const SUPPORTED_TAXONOMIES = array(
		'category',
		'post_tag',
	);

	private const KNOWN_MIGRATABLE_POST_TYPES = array(
		'post',
		'page',
		'attachment',
		'wp_navigation',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
	);

	/**
	 * Build a migration plan from inspected package and destination state.
	 *
	 * @param PackageInspectionResult     $package Inspected source package.
	 * @param DestinationInspectionResult $destination Inspected destination.
	 * @return MigrationPlan
	 */
	public function plan( PackageInspectionResult $package, DestinationInspectionResult $destination ): MigrationPlan {
		$package_data     = $package->to_array();
		$source           = $package_data['source'];
		$destination_data = $destination->to_array();
		$warnings         = array();

		if ( $destination_data['site']['is_multisite'] ) {
			$warnings[] = $this->warning( 'multisite_unsupported', 'Destination multisite is out of scope for the MVP.' );
		}

		if ( 'populated' === $destination_data['content']['freshness'] ) {
			$warnings[] = $this->warning( 'destination_populated', 'The destination already contains meaningful content and requires review.' );
		}

		$content = $this->plan_content( $source );
		$options = $this->plan_options( $source );
		$theme   = $this->plan_theme( $source, $destination_data );
		$plugins = $this->plan_plugins( $source, $destination_data );
		$files   = $this->plan_files( $source, $package );
		$users   = $this->plan_users( $source, $destination_data );
		$tables  = $this->plan_tables( $source );
		$meta    = $this->plan_metadata( $source );
		$tax     = $this->plan_taxonomy( $source );

		$warnings = array_merge(
			$warnings,
			$content['warnings'],
			$options['warnings'],
			$theme['warnings'],
			$plugins['warnings'],
			$files['warnings'],
			$users['warnings'],
			$tables['warnings']
		);

		return new MigrationPlan(
			array(
				'classification_model' => array(
					MigrationAction::MIGRATE,
					MigrationAction::REMAP,
					MigrationAction::PRESERVE_DESTINATION,
					MigrationAction::SKIP,
					MigrationAction::REVIEW,
					MigrationAction::UNSUPPORTED,
				),
				'source'               => array(
					'home'              => $source['home'],
					'siteurl'           => $source['siteurl'],
					'table_prefix'      => $source['table_prefix'],
					'wordpress_version' => $source['wordpress_version'],
					'db_version'        => $source['db_version'],
				),
				'destination'          => $destination_data,
				'content'              => $content['plan'],
				'executable_content'   => $content['operations'],
				'metadata'             => $meta,
				'users'                => $users['plan'],
				'options'              => $options['plan'],
				'theme'                => $theme['plan'],
				'plugins'              => $plugins['plan'],
				'files'                => $files['plan'],
				'urls'                 => $this->plan_urls( $source, $destination_data ),
				'relationships'        => $this->plan_relationships( $source, $content['operations'], $meta, $tax ),
				'taxonomy'             => $tax,
				'tables'               => $tables['plan'],
				'warnings'             => $warnings,
			)
		);
	}

	/**
	 * Plan source content.
	 *
	 * @param array<string, mixed> $source Source data.
	 * @return array<string, mixed>
	 */
	private function plan_content( array $source ): array {
		$warnings   = array();
		$items      = array();
		$operations = array();

		foreach ( $source['content_summary'] as $post_type => $statuses ) {
			$action = $this->aggregate_content_action( $post_type );

			if ( MigrationAction::REVIEW === $action ) {
				$warnings[] = $this->warning( 'unknown_post_type', 'Source contains a post type whose migration behavior requires review.', array( 'post_type' => $post_type ) );
			}

			$items[] = array(
				'post_type' => $post_type,
				'statuses'  => $statuses,
				'action'    => $action,
			);
		}

		foreach ( $source['content_items'] as $item ) {
			$record_action = $this->record_content_action( $item );
			$operation     = array(
				'source_id'     => $item['id'],
				'post_type'     => $item['type'],
				'post_status'   => $item['status'],
				'post_title'    => $item['title'],
				'action'        => $record_action['action'],
				'reason'        => $record_action['reason'],
				'is_executable' => MigrationAction::MIGRATE === $record_action['action'] && in_array( $item['type'], array( 'post', 'page' ), true ),
				'source_record' => $item,
				'deferred_work' => $this->deferred_work_for_record( $item ),
			);

			$operations[] = $operation;
		}

		return array(
			'plan'       => $items,
			'operations' => $operations,
			'warnings'   => $warnings,
		);
	}

	/**
	 * Get aggregate content action by post type.
	 *
	 * @param string $post_type Post type.
	 * @return string
	 */
	private function aggregate_content_action( string $post_type ): string {
		if ( 'revision' === $post_type ) {
			return MigrationAction::SKIP;
		}

		return in_array( $post_type, self::KNOWN_MIGRATABLE_POST_TYPES, true ) ? MigrationAction::MIGRATE : MigrationAction::REVIEW;
	}

	/**
	 * Get per-record content action.
	 *
	 * @param array<string, mixed> $item Source content item.
	 * @return array<string, string>
	 */
	private function record_content_action( array $item ): array {
		if ( in_array( $item['status'], array( 'auto-draft', 'trash' ), true ) ) {
			return array(
				'action' => MigrationAction::SKIP,
				'reason' => 'Transient or deleted source content is intentionally excluded.',
			);
		}

		if ( 'revision' === $item['type'] ) {
			return array(
				'action' => MigrationAction::SKIP,
				'reason' => 'Revision history is intentionally excluded from the MVP writer.',
			);
		}

		if ( in_array( $item['type'], array( 'post', 'page' ), true ) && 'publish' === $item['status'] ) {
			return array(
				'action' => MigrationAction::MIGRATE,
				'reason' => 'Published core posts and pages are executable in Milestone 4.',
			);
		}

		if ( in_array( $item['type'], array( 'post', 'page' ), true ) ) {
			return array(
				'action' => MigrationAction::SKIP,
				'reason' => 'Only published core posts and pages are executable in Milestone 4.',
			);
		}

		return array(
			'action' => MigrationAction::REVIEW,
			'reason' => 'This post type remains planning-only for this milestone.',
		);
	}

	/**
	 * Explain relationship work deferred for a record.
	 *
	 * @param array<string, mixed> $item Source content item.
	 * @return array<int, string>
	 */
	private function deferred_work_for_record( array $item ): array {
		$deferred = array();

		if ( 0 < (int) $item['parent_id'] ) {
			$deferred[] = 'post_parent_remap';
		}

		return $deferred;
	}

	/**
	 * Plan source options.
	 *
	 * @param array<string, mixed> $source Source data.
	 * @return array<string, mixed>
	 */
	private function plan_options( array $source ): array {
		$plans    = array();
		$warnings = array();

		foreach ( $source['options'] as $name => $value ) {
			$action = match ( $name ) {
				'home', 'siteurl', 'upload_path' => MigrationAction::PRESERVE_DESTINATION,
				'page_on_front', 'page_for_posts' => MigrationAction::REMAP,
				'show_on_front', 'permalink_structure', 'template', 'stylesheet' => MigrationAction::MIGRATE,
				'active_plugins' => MigrationAction::PRESERVE_DESTINATION,
				default => MigrationAction::REVIEW,
			};

			if ( MigrationAction::REVIEW === $action ) {
				$warnings[] = $this->warning( 'option_requires_review', 'A source option requires review before migration.', array( 'option' => $name ) );
			}

			$plans[] = array(
				'name'   => $name,
				'value'  => $value,
				'action' => $action,
			);
		}

		return array(
			'plan'     => $plans,
			'warnings' => $warnings,
		);
	}

	/**
	 * Plan theme requirements.
	 *
	 * @param array<string, mixed> $source Source data.
	 * @param array<string, mixed> $destination Destination data.
	 * @return array<string, mixed>
	 */
	private function plan_theme( array $source, array $destination ): array {
		$stylesheet = (string) ( $source['theme']['stylesheet'] ?? '' );
		$installed  = in_array( $stylesheet, $destination['theme']['installed'], true );
		$active     = $stylesheet === $destination['theme']['active_stylesheet'];
		$warnings   = array();
		$status     = $active ? 'already_installed_and_active' : ( $installed ? 'installed_inactive' : 'not_installed' );
		$action     = $active ? MigrationAction::PRESERVE_DESTINATION : ( $installed ? MigrationAction::REVIEW : MigrationAction::REVIEW );

		if ( ! $installed ) {
			$warnings[] = $this->warning( 'source_theme_missing', 'The source active theme is not installed on the destination.', array( 'theme' => $stylesheet ) );
		}

		return array(
			'plan'     => array(
				'source_stylesheet'      => $stylesheet,
				'source_template'        => $source['theme']['template'] ?? null,
				'destination_stylesheet' => $destination['theme']['active_stylesheet'],
				'destination_template'   => $destination['theme']['active_template'],
				'status'                 => $status,
				'action'                 => $action,
			),
			'warnings' => $warnings,
		);
	}

	/**
	 * Plan plugin requirements.
	 *
	 * @param array<string, mixed> $source Source data.
	 * @param array<string, mixed> $destination Destination data.
	 * @return array<string, mixed>
	 */
	private function plan_plugins( array $source, array $destination ): array {
		$plans    = array();
		$warnings = array();

		foreach ( $source['plugins']['active'] as $plugin ) {
			$installed = in_array( $plugin, $destination['plugins']['installed'], true );
			$active    = in_array( $plugin, $destination['plugins']['active'], true );
			$status    = $active ? 'already_installed_and_active' : ( $installed ? 'installed_inactive' : 'not_installed' );

			if ( ! $active ) {
				$warnings[] = $this->warning( 'source_plugin_unavailable_or_inactive', 'A source active plugin is not active on the destination.', array( 'plugin' => $plugin ) );
			}

			$plans[] = array(
				'plugin' => $plugin,
				'status' => $status,
				'action' => $active ? MigrationAction::PRESERVE_DESTINATION : MigrationAction::REVIEW,
			);
		}

		return array(
			'plan'     => $plans,
			'warnings' => $warnings,
		);
	}

	/**
	 * Plan file and upload requirements.
	 *
	 * @param array<string, mixed>    $source Source data.
	 * @param PackageInspectionResult $package Package inspection result.
	 * @return array<string, mixed>
	 */
	private function plan_files( array $source, PackageInspectionResult $package ): array {
		$upload_entries     = $package->get_upload_entries();
		$attachment_items   = array_filter(
			$source['content_items'],
			static fn ( array $item ): bool => 'attachment' === $item['type']
		);
		$attached_files     = array_values(
			array_filter(
				array_map(
					static fn ( array $item ): mixed => $item['attached_file'],
					$attachment_items
				),
				static fn ( mixed $file ): bool => is_string( $file ) && '' !== $file
			)
		);
		$missing_uploads    = array();
		$normalized_uploads = array_map(
			static fn ( string $entry ): string => preg_replace( '#^wp-content/uploads/#', '', $entry ),
			$upload_entries
		);

		foreach ( $attached_files as $attached_file ) {
			if ( ! in_array( $attached_file, $normalized_uploads, true ) ) {
				$missing_uploads[] = $attached_file;
			}
		}

		$warnings = array();

		if ( array() !== $missing_uploads ) {
			$warnings[] = $this->warning( 'missing_attachment_files', 'Some source attachment file references were not found in the package uploads.', array( 'files' => $missing_uploads ) );
		}

		return array(
			'plan'     => array(
				'attachments'              => count( $attachment_items ),
				'upload_files_in_package'  => count( $upload_entries ),
				'matched_attachment_files' => count( $attached_files ) - count( $missing_uploads ),
				'missing_attachment_files' => $missing_uploads,
				'action'                   => MigrationAction::MIGRATE,
			),
			'warnings' => $warnings,
		);
	}

	/**
	 * Plan user mapping.
	 *
	 * @param array<string, mixed> $source Source data.
	 * @param array<string, mixed> $destination Destination data.
	 * @return array<string, mixed>
	 */
	private function plan_users( array $source, array $destination ): array {
		$admin    = $this->resolve_destination_author( $destination );
		$plans    = array();
		$warnings = array();

		foreach ( $source['users'] as $user ) {
			if ( is_array( $admin ) ) {
				$plans[] = array(
					'source_user_id'      => $user['id'],
					'source_login'        => $user['login'],
					'source_email_domain' => $user['email_domain'],
					'destination_user_id' => $admin['id'],
					'destination_login'   => $admin['login'],
					'action'              => MigrationAction::REMAP,
				);
			} else {
				$warnings[] = $this->warning( 'no_destination_admin', 'No destination administrator was available for source author mapping.' );
			}
		}

		return array(
			'plan'     => $plans,
			'warnings' => $warnings,
		);
	}

	/**
	 * Resolve a deterministic destination author for MVP execution.
	 *
	 * @param array<string, mixed> $destination Destination data.
	 * @return array<string, mixed>|null
	 */
	private function resolve_destination_author( array $destination ): ?array {
		$current_user_id = (int) ( $destination['users']['current_user_id'] ?? 0 );
		$administrators  = $destination['users']['administrators'];

		foreach ( $administrators as $administrator ) {
			if ( $current_user_id > 0 && (int) $administrator['id'] === $current_user_id ) {
				return $administrator;
			}
		}

		return 1 === count( $administrators ) ? $administrators[0] : null;
	}

	/**
	 * Plan URL transformation requirements.
	 *
	 * @param array<string, mixed> $source Source data.
	 * @param array<string, mixed> $destination Destination data.
	 * @return array<string, mixed>
	 */
	private function plan_urls( array $source, array $destination ): array {
		return array(
			'source_home'     => $source['home'],
			'source_siteurl'  => $source['siteurl'],
			'destination_url' => $destination['site']['home'],
			'action'          => MigrationAction::REMAP,
			'affected_data'   => array( 'post_content', 'post_excerpt', 'postmeta', 'options', 'attachments', 'navigation' ),
		);
	}

	/**
	 * Plan post metadata migration.
	 *
	 * @param array<string, mixed> $source Source data.
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function plan_metadata( array $source ): array {
		$metadata = array(
			'migrate' => array(),
			'defer'   => array(),
			'review'  => array(),
		);

		foreach ( $source['postmeta'] as $row ) {
			$key  = (string) $row['meta_key'];
			$plan = array(
				'source_post_id' => (int) $row['post_id'],
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key'       => $key,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value'     => $row['meta_value'],
			);

			if ( in_array( $key, self::SUPPORTED_META_KEYS, true ) ) {
				$plan['action']        = MigrationAction::MIGRATE;
				$plan['is_executable'] = true;
				$metadata['migrate'][] = $plan;
				continue;
			}

			if ( '_thumbnail_id' === $key ) {
				$plan['action']        = MigrationAction::REMAP;
				$plan['is_executable'] = false;
				$plan['reason']        = 'Featured image remapping is deferred until attachment migration is supported.';
				$metadata['defer'][]   = $plan;
				continue;
			}

			$plan['action']       = MigrationAction::REVIEW;
			$plan['reason']       = 'Only allowlisted core post meta is copied by the MVP writer.';
			$metadata['review'][] = $plan;
		}

		return $metadata;
	}

	/**
	 * Plan taxonomy term and relationship migration.
	 *
	 * @param array<string, mixed> $source Source data.
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function plan_taxonomy( array $source ): array {
		$taxonomy = array(
			'terms'         => array(),
			'relationships' => array(),
		);

		foreach ( $source['taxonomy']['terms'] as $term ) {
			$supported = in_array( $term['taxonomy'], self::SUPPORTED_TAXONOMIES, true );

			$taxonomy['terms'][] = array(
				'term_id'          => (int) $term['term_id'],
				'name'             => $term['name'],
				'slug'             => $term['slug'],
				'term_taxonomy_id' => (int) $term['term_taxonomy_id'],
				'taxonomy'         => $term['taxonomy'],
				'description'      => $term['description'],
				'parent'           => (int) $term['parent'],
				'action'           => $supported ? MigrationAction::MIGRATE : MigrationAction::REVIEW,
				'is_executable'    => $supported,
				'reason'           => $supported ? 'Core category and post_tag terms are supported.' : 'Custom taxonomies are deferred for review.',
			);
		}

		foreach ( $source['taxonomy']['relationships'] as $relationship ) {
			$supported = in_array( $relationship['taxonomy'], self::SUPPORTED_TAXONOMIES, true );

			$taxonomy['relationships'][] = array(
				'object_source_id' => (int) $relationship['object_source_id'],
				'term_id'          => (int) $relationship['term_id'],
				'term_taxonomy_id' => (int) $relationship['term_taxonomy_id'],
				'taxonomy'         => $relationship['taxonomy'],
				'action'           => $supported ? MigrationAction::REMAP : MigrationAction::REVIEW,
				'is_executable'    => $supported,
				'reason'           => $supported ? 'Core taxonomy relationships are remapped after posts and terms are created.' : 'Custom taxonomy relationships are deferred for review.',
			);
		}

		return $taxonomy;
	}

	/**
	 * Plan relationship remapping.
	 *
	 * @param array<string, mixed>                           $source Source data.
	 * @param array<int, array<string, mixed>>               $content_operations Planned content operations.
	 * @param array<string, array<int, array<string,mixed>>> $metadata Planned metadata.
	 * @param array<string, array<int, array<string,mixed>>> $taxonomy Planned taxonomy.
	 * @return array<string, mixed>
	 */
	private function plan_relationships( array $source, array $content_operations, array $metadata, array $taxonomy ): array {
		$parents = array();

		foreach ( $content_operations as $operation ) {
			$parent_id = (int) $operation['source_record']['parent_id'];

			if ( 0 >= $parent_id ) {
				continue;
			}

			$parents[] = array(
				'source_id'        => (int) $operation['source_id'],
				'parent_source_id' => $parent_id,
				'action'           => MigrationAction::REMAP,
				'is_executable'    => ! empty( $operation['is_executable'] ),
				'reason'           => ! empty( $operation['is_executable'] ) ? 'Parent relationships are applied after destination IDs are known.' : 'Parent relationship belongs to content that is not executable.',
			);
		}

		return array(
			'summary'         => $source['relationships'],
			'post_parents'    => $parents,
			'featured_images' => $metadata['defer'],
			'taxonomy'        => $taxonomy['relationships'],
			'action'          => MigrationAction::REMAP,
		);
	}

	/**
	 * Plan source tables.
	 *
	 * @param array<string, mixed> $source Source data.
	 * @return array<string, mixed>
	 */
	private function plan_tables( array $source ): array {
		$warnings = array();

		if ( array() !== $source['additional_tables'] ) {
			$warnings[] = $this->warning( 'additional_source_tables', 'The source contains additional tables that require review.', array( 'tables' => $source['additional_tables'] ) );
		}

		return array(
			'plan'     => array(
				'recognized_core_tables' => 'recognized',
				'additional_tables'      => $source['additional_tables'],
				'action'                 => array() === $source['additional_tables'] ? MigrationAction::PRESERVE_DESTINATION : MigrationAction::REVIEW,
			),
			'warnings' => $warnings,
		);
	}

	/**
	 * Build a structured warning.
	 *
	 * @param string               $code Warning code.
	 * @param string               $message Warning message.
	 * @param array<string, mixed> $context Optional context.
	 * @return array<string, mixed>
	 */
	private function warning( string $code, string $message, array $context = array() ): array {
		return array(
			'code'    => $code,
			'message' => $message,
			'context' => $context,
			'action'  => MigrationAction::REVIEW,
		);
	}
}
