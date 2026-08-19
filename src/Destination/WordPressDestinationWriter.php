<?php
/**
 * Reserved WordPress destination-writing boundary.
 *
 * @package WP_Playground_Importer
 */

declare(strict_types=1);

namespace WP_Playground_Importer\Destination;

use WP_Error;
use WP_Playground_Importer\Import\MigrationAction;
use WP_Playground_Importer\Import\MigrationPlan;

/**
 * Narrow destination writer for supported Milestone 5 content and relationships.
 */
final class WordPressDestinationWriter {
	/**
	 * Execute supported content operations from a migration plan.
	 *
	 * @param MigrationPlan $plan Migration plan.
	 * @return WriteResult
	 */
	public function execute( MigrationPlan $plan ): WriteResult {
		$plan_data = $plan->to_array();
		$blockers  = $this->preflight_blockers( $plan_data );

		if ( array() !== $blockers ) {
			return new WriteResult(
				array(
					'planned_executable_records' => $this->count_executable_operations( $plan_data ),
					'created_records'            => 0,
					'id_map'                     => array(),
					'post_id_map'                => array(),
					'term_id_map'                => array(),
					'skipped_records'            => $this->skipped_operations( $plan_data ),
					'failed_records'             => array(),
					'blocking_errors'            => $blockers,
					'warnings'                   => $plan_data['warnings'],
					'deferred_work'              => $this->deferred_work( $plan_data ),
					'relationships'              => array(
						'post_parents'    => array(
							'applied'  => array(),
							'deferred' => $plan_data['relationships']['post_parents'] ?? array(),
							'failed'   => array(),
						),
						'taxonomy'        => array(
							'applied'  => array(),
							'deferred' => $plan_data['taxonomy']['relationships'] ?? array(),
							'failed'   => array(),
						),
						'featured_images' => array(
							'deferred' => $plan_data['metadata']['defer'] ?? array(),
						),
					),
					'metadata'                   => array(
						'migrated' => array(),
						'skipped'  => $plan_data['metadata']['migrate'] ?? array(),
						'deferred' => $plan_data['metadata']['defer'] ?? array(),
						'review'   => $plan_data['metadata']['review'] ?? array(),
					),
					'taxonomy'                   => array(
						'term_id_map'   => array(),
						'created'       => array(),
						'reused'        => array(),
						'deferred'      => $plan_data['taxonomy']['terms'] ?? array(),
						'failed'        => array(),
						'relationships' => array(
							'applied'  => array(),
							'deferred' => $plan_data['taxonomy']['relationships'] ?? array(),
							'failed'   => array(),
						),
					),
				)
			);
		}

		$id_map   = array();
		$failed   = array();
		$term_map = array();

		foreach ( $plan_data['executable_content'] as $operation ) {
			if ( empty( $operation['is_executable'] ) ) {
				continue;
			}

			$result = $this->write_post( $operation, $plan_data['users'] );

			if ( $result instanceof WP_Error ) {
				$failed[] = array(
					'source_id' => $operation['source_id'],
					'error'     => $result->get_error_code(),
					'message'   => $result->get_error_message(),
				);
				break;
			}

			$id_map[ (string) $operation['source_id'] ] = $result;
		}

		$taxonomy_result      = $this->migrate_terms( $plan_data );
		$term_map             = $taxonomy_result['term_id_map'];
		$parent_relationships = $this->apply_parent_relationships( $plan_data, $id_map );
		$metadata_result      = $this->migrate_metadata( $plan_data, $id_map );
		$term_relationships   = $this->assign_terms( $plan_data, $id_map, $term_map );

		return new WriteResult(
			array(
				'planned_executable_records' => $this->count_executable_operations( $plan_data ),
				'created_records'            => count( $id_map ),
				'id_map'                     => $id_map,
				'post_id_map'                => $id_map,
				'term_id_map'                => $term_map,
				'skipped_records'            => $this->skipped_operations( $plan_data ),
				'failed_records'             => $failed,
				'blocking_errors'            => array(),
				'warnings'                   => $plan_data['warnings'],
				'deferred_work'              => $this->deferred_work( $plan_data ),
				'relationships'              => array(
					'post_parents'    => $parent_relationships,
					'taxonomy'        => $term_relationships,
					'featured_images' => array(
						'deferred' => $plan_data['metadata']['defer'] ?? array(),
					),
				),
				'metadata'                   => $metadata_result,
				'taxonomy'                   => array_merge(
					$taxonomy_result,
					array(
						'relationships' => $term_relationships,
					)
				),
			)
		);
	}

	/**
	 * Validate hard execution blockers before writing.
	 *
	 * @param array<string, mixed> $plan Plan data.
	 * @return array<int, array<string, string>>
	 */
	private function preflight_blockers( array $plan ): array {
		$blockers = array();

		if ( 'fresh_or_nearly_fresh' !== $plan['destination']['content']['freshness'] ) {
			$blockers[] = $this->blocker( 'destination_populated', 'Execution is refused because the destination is populated.' );
		}

		if ( ! empty( $plan['destination']['site']['is_multisite'] ) ) {
			$blockers[] = $this->blocker( 'multisite_unsupported', 'Execution is refused because multisite is unsupported.' );
		}

		if ( array() === $plan['users'] ) {
			$blockers[] = $this->blocker( 'author_mapping_unavailable', 'Execution is refused because no safe destination author mapping is available.' );
		}

		return $blockers;
	}

	/**
	 * Write one supported post/page operation.
	 *
	 * @param array<string, mixed>             $operation Executable operation.
	 * @param array<int, array<string, mixed>> $user_mappings User mappings.
	 * @return int|WP_Error
	 */
	private function write_post( array $operation, array $user_mappings ): int|WP_Error {
		$source_record = $operation['source_record'];
		$author_id     = $this->mapped_author_id( (int) $source_record['author_id'], $user_mappings );

		if ( null === $author_id ) {
			return new WP_Error( 'author_mapping_missing', 'No destination author mapping exists for the source author.' );
		}

		$postarr = array(
			'post_author'       => $author_id,
			'post_content'      => $source_record['content'],
			'post_excerpt'      => $source_record['excerpt'],
			'post_status'       => 'publish',
			'post_type'         => $source_record['type'],
			'post_title'        => $source_record['title'],
			'post_name'         => $source_record['slug'],
			'post_date'         => $source_record['post_date'],
			'post_date_gmt'     => $source_record['post_date_gmt'],
			'post_modified'     => $source_record['modified'],
			'post_modified_gmt' => $source_record['modified_gmt'],
			'menu_order'        => $source_record['menu_order'],
			'comment_status'    => $source_record['comment_status'],
			'ping_status'       => $source_record['ping_status'],
			'post_password'     => $source_record['password'],
		);

		return wp_insert_post( wp_slash( $postarr ), true );
	}

	/**
	 * Find the destination author ID for a source author.
	 *
	 * @param int                              $source_author_id Source author ID.
	 * @param array<int, array<string, mixed>> $user_mappings User mappings.
	 * @return int|null
	 */
	private function mapped_author_id( int $source_author_id, array $user_mappings ): ?int {
		foreach ( $user_mappings as $mapping ) {
			if ( (int) $mapping['source_user_id'] === $source_author_id ) {
				return (int) $mapping['destination_user_id'];
			}
		}

		return null;
	}

	/**
	 * Create or reuse supported taxonomy terms.
	 *
	 * @param array<string, mixed> $plan Plan data.
	 * @return array<string, mixed>
	 */
	private function migrate_terms( array $plan ): array {
		$result = array(
			'term_id_map' => array(),
			'created'     => array(),
			'reused'      => array(),
			'deferred'    => array(),
			'failed'      => array(),
		);

		foreach ( $plan['taxonomy']['terms'] ?? array() as $term ) {
			if ( empty( $term['is_executable'] ) ) {
				$result['deferred'][] = $term;
				continue;
			}

			$destination_term = term_exists( $term['slug'], $term['taxonomy'] );

			if ( 0 === $destination_term || null === $destination_term ) {
				$destination_term = term_exists( $term['name'], $term['taxonomy'] );
			}

			if ( 0 !== $destination_term && null !== $destination_term ) {
				$destination_id                                     = is_array( $destination_term ) ? (int) $destination_term['term_id'] : (int) $destination_term;
				$result['term_id_map'][ (string) $term['term_id'] ] = $destination_id;
				$result['reused'][]                                 = array(
					'source_term_id'      => $term['term_id'],
					'destination_term_id' => $destination_id,
					'taxonomy'            => $term['taxonomy'],
				);
				continue;
			}

			$inserted = wp_insert_term(
				$term['name'],
				$term['taxonomy'],
				array(
					'slug'        => $term['slug'],
					'description' => $term['description'],
				)
			);

			if ( $inserted instanceof WP_Error ) {
				$result['failed'][] = array(
					'source_term_id' => $term['term_id'],
					'taxonomy'       => $term['taxonomy'],
					'error'          => $inserted->get_error_code(),
					'message'        => $inserted->get_error_message(),
				);
				continue;
			}

			$destination_id                                     = (int) $inserted['term_id'];
			$result['term_id_map'][ (string) $term['term_id'] ] = $destination_id;
			$result['created'][]                                = array(
				'source_term_id'      => $term['term_id'],
				'destination_term_id' => $destination_id,
				'taxonomy'            => $term['taxonomy'],
			);
		}

		return $result;
	}

	/**
	 * Apply source parent relationships using destination IDs.
	 *
	 * @param array<string, mixed> $plan Plan data.
	 * @param array<string, int>   $id_map Source to destination post map.
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function apply_parent_relationships( array $plan, array $id_map ): array {
		$result = array(
			'applied'  => array(),
			'deferred' => array(),
			'failed'   => array(),
		);

		foreach ( $plan['relationships']['post_parents'] ?? array() as $relationship ) {
			$source_id = (string) $relationship['source_id'];
			$parent_id = (string) $relationship['parent_source_id'];

			if ( ! isset( $id_map[ $source_id ], $id_map[ $parent_id ] ) ) {
				$result['deferred'][] = array_merge(
					$relationship,
					array(
						'reason' => 'Parent relationship could not be applied because one side was not migrated.',
					)
				);
				continue;
			}

			$updated = wp_update_post(
				wp_slash(
					array(
						'ID'          => $id_map[ $source_id ],
						'post_parent' => $id_map[ $parent_id ],
					)
				),
				true
			);

			if ( $updated instanceof WP_Error ) {
				$result['failed'][] = array(
					'source_id'        => (int) $relationship['source_id'],
					'parent_source_id' => (int) $relationship['parent_source_id'],
					'error'            => $updated->get_error_code(),
					'message'          => $updated->get_error_message(),
				);
				continue;
			}

			$result['applied'][] = array(
				'source_id'             => (int) $relationship['source_id'],
				'parent_source_id'      => (int) $relationship['parent_source_id'],
				'destination_id'        => $id_map[ $source_id ],
				'destination_parent_id' => $id_map[ $parent_id ],
			);
		}

		return $result;
	}

	/**
	 * Migrate allowlisted post metadata.
	 *
	 * @param array<string, mixed> $plan Plan data.
	 * @param array<string, int>   $id_map Source to destination post map.
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function migrate_metadata( array $plan, array $id_map ): array {
		$result = array(
			'migrated' => array(),
			'skipped'  => array(),
			'deferred' => $plan['metadata']['defer'] ?? array(),
			'review'   => $plan['metadata']['review'] ?? array(),
		);

		foreach ( $plan['metadata']['migrate'] ?? array() as $meta ) {
			$source_post_id = (string) $meta['source_post_id'];

			if ( ! isset( $id_map[ $source_post_id ] ) ) {
				$result['skipped'][] = array_merge(
					$meta,
					array(
						'reason' => 'Metadata belongs to a source post that was not migrated.',
					)
				);
				continue;
			}

			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			update_post_meta( $id_map[ $source_post_id ], $meta['meta_key'], wp_slash( $meta['meta_value'] ) );
			$result['migrated'][] = array(
				'source_post_id'      => (int) $meta['source_post_id'],
				'destination_post_id' => $id_map[ $source_post_id ],
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key'            => $meta['meta_key'],
			);
		}

		return $result;
	}

	/**
	 * Assign supported taxonomy relationships.
	 *
	 * @param array<string, mixed> $plan Plan data.
	 * @param array<string, int>   $id_map Source to destination post map.
	 * @param array<string, int>   $term_map Source to destination term map.
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function assign_terms( array $plan, array $id_map, array $term_map ): array {
		$result = array(
			'applied'  => array(),
			'deferred' => array(),
			'failed'   => array(),
		);

		foreach ( $plan['taxonomy']['relationships'] ?? array() as $relationship ) {
			$source_id = (string) $relationship['object_source_id'];
			$term_id   = (string) $relationship['term_id'];

			if ( empty( $relationship['is_executable'] ) || ! isset( $id_map[ $source_id ], $term_map[ $term_id ] ) ) {
				$result['deferred'][] = $relationship;
				continue;
			}

			$assigned = wp_set_object_terms( $id_map[ $source_id ], array( $term_map[ $term_id ] ), $relationship['taxonomy'], true );

			if ( $assigned instanceof WP_Error ) {
				$result['failed'][] = array(
					'object_source_id' => (int) $relationship['object_source_id'],
					'term_id'          => (int) $relationship['term_id'],
					'taxonomy'         => $relationship['taxonomy'],
					'error'            => $assigned->get_error_code(),
					'message'          => $assigned->get_error_message(),
				);
				continue;
			}

			$result['applied'][] = array(
				'object_source_id'      => (int) $relationship['object_source_id'],
				'source_term_id'        => (int) $relationship['term_id'],
				'destination_object_id' => $id_map[ $source_id ],
				'destination_term_id'   => $term_map[ $term_id ],
				'taxonomy'              => $relationship['taxonomy'],
			);
		}

		return $result;
	}

	/**
	 * Count executable operations.
	 *
	 * @param array<string, mixed> $plan Plan data.
	 * @return int
	 */
	private function count_executable_operations( array $plan ): int {
		return count(
			array_filter(
				$plan['executable_content'],
				static fn ( array $operation ): bool => ! empty( $operation['is_executable'] )
			)
		);
	}

	/**
	 * Return skipped source records.
	 *
	 * @param array<string, mixed> $plan Plan data.
	 * @return array<int, array<string, mixed>>
	 */
	private function skipped_operations( array $plan ): array {
		return array_values(
			array_filter(
				$plan['executable_content'],
				static fn ( array $operation ): bool => MigrationAction::SKIP === $operation['action']
			)
		);
	}

	/**
	 * Return deferred relationship/follow-up work.
	 *
	 * @param array<string, mixed> $plan Plan data.
	 * @return array<string, mixed>
	 */
	private function deferred_work( array $plan ): array {
		return array(
			'relationships' => $plan['relationships'],
			'files'         => $plan['files'],
			'urls'          => $plan['urls'],
			'options'       => $plan['options'],
		);
	}

	/**
	 * Build a structured blocker.
	 *
	 * @param string $code Blocker code.
	 * @param string $message Blocker message.
	 * @return array<string, string>
	 */
	private function blocker( string $code, string $message ): array {
		return array(
			'code'    => $code,
			'message' => $message,
		);
	}
}
