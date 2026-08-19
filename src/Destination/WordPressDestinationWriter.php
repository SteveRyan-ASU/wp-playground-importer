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
 * Narrow destination writer for supported Milestone 4 content.
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
					'skipped_records'            => $this->skipped_operations( $plan_data ),
					'failed_records'             => array(),
					'blocking_errors'            => $blockers,
					'warnings'                   => $plan_data['warnings'],
					'deferred_work'              => $this->deferred_work( $plan_data ),
				)
			);
		}

		$id_map = array();
		$failed = array();

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

		return new WriteResult(
			array(
				'planned_executable_records' => $this->count_executable_operations( $plan_data ),
				'created_records'            => count( $id_map ),
				'id_map'                     => $id_map,
				'skipped_records'            => $this->skipped_operations( $plan_data ),
				'failed_records'             => $failed,
				'blocking_errors'            => array(),
				'warnings'                   => $plan_data['warnings'],
				'deferred_work'              => $this->deferred_work( $plan_data ),
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
