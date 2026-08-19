<?php
/**
 * Migration planning action classifications.
 *
 * @package WP_Playground_Importer
 */

declare(strict_types=1);

namespace WP_Playground_Importer\Import;

/**
 * Shared action constants for structured migration plans.
 */
final class MigrationAction {
	public const MIGRATE              = 'migrate';
	public const REMAP                = 'remap';
	public const PRESERVE_DESTINATION = 'preserve_destination';
	public const SKIP                 = 'skip';
	public const REVIEW               = 'review';
	public const UNSUPPORTED          = 'unsupported';

	/**
	 * This class only contains constants.
	 */
	private function __construct() {}
}
