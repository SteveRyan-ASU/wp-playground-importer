<?php
/**
 * Source inspection result.
 *
 * @package WP_Playground_Importer
 */

declare(strict_types=1);

namespace WP_Playground_Importer\Source;

/**
 * Structured description of a Playground source database.
 */
final class SourceInspectionResult {
	/**
	 * Result data.
	 *
	 * @var array<string, mixed>
	 */
	private array $data;

	/**
	 * Create a source inspection result.
	 *
	 * @param array<string, mixed> $data Result data.
	 */
	public function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * Get detected source table prefix.
	 *
	 * @return string
	 */
	public function get_table_prefix(): string {
		return $this->data['table_prefix'];
	}

	/**
	 * Get result data.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return $this->data;
	}
}
