<?php
/**
 * Package inspection result.
 *
 * @package WP_Playground_Importer
 */

declare(strict_types=1);

namespace WP_Playground_Importer\Package;

use WP_Playground_Importer\Source\SourceInspectionResult;

/**
 * Structured description of an inspected Playground package.
 */
final class PackageInspectionResult {
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
	 * Get decoded Playground manifest metadata.
	 *
	 * @return array<string, mixed>
	 */
	public function get_manifest(): array {
		return $this->data['manifest'];
	}

	/**
	 * Get source inspection result.
	 *
	 * @return SourceInspectionResult
	 */
	public function get_source(): SourceInspectionResult {
		return $this->data['source'];
	}

	/**
	 * Convert result to an array for tests, CLI, or future UI.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$source = $this->get_source();

		return array(
			'package'  => array(
				'manifest_path' => $this->data['manifest_path'],
				'database_path' => $this->data['database_path'],
				'manifest'      => $this->get_manifest(),
			),
			'source'   => $source->to_array(),
			'warnings' => array(),
		);
	}
}
