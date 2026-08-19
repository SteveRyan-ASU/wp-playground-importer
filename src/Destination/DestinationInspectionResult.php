<?php
/**
 * Destination inspection result.
 *
 * @package WP_Playground_Importer
 */

declare(strict_types=1);

namespace WP_Playground_Importer\Destination;

/**
 * Structured destination inspection data.
 */
final class DestinationInspectionResult {
	/**
	 * Result data.
	 *
	 * @var array<string, mixed>
	 */
	private array $data;

	/**
	 * Create a result.
	 *
	 * @param array<string, mixed> $data Result data.
	 */
	public function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * Convert result to an array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return $this->data;
	}
}
