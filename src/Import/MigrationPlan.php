<?php
/**
 * Migration plan result.
 *
 * @package WP_Playground_Importer
 */

declare(strict_types=1);

namespace WP_Playground_Importer\Import;

/**
 * Structured read-only migration plan.
 */
final class MigrationPlan {
	/**
	 * Plan data.
	 *
	 * @var array<string, mixed>
	 */
	private array $data;

	/**
	 * Create a migration plan.
	 *
	 * @param array<string, mixed> $data Plan data.
	 */
	public function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * Convert the plan to an array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return $this->data;
	}
}
