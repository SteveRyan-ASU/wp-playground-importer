<?php
/**
 * Package inspection exception.
 *
 * @package WP_Playground_Importer
 */

declare(strict_types=1);

namespace WP_Playground_Importer\Package;

use RuntimeException;

/**
 * Represents a structured inspection failure.
 */
final class PackageInspectionException extends RuntimeException {
	/**
	 * User-presentable message.
	 *
	 * @var string
	 */
	private string $user_message;

	/**
	 * Structured error code.
	 *
	 * @var string
	 */
	private string $error_code;

	/**
	 * Create an inspection exception.
	 *
	 * @param string $error_code Error code.
	 * @param string $user_message User-presentable message.
	 */
	public function __construct( string $error_code, string $user_message ) {
		$this->error_code   = $error_code;
		$this->user_message = $user_message;

		parent::__construct( $error_code . ': ' . $user_message, 0 );
	}

	/**
	 * Get the structured error code.
	 *
	 * @return string
	 */
	public function get_error_code(): string {
		return $this->error_code;
	}

	/**
	 * Get the user-presentable message.
	 *
	 * @return string
	 */
	public function get_user_message(): string {
		return $this->user_message;
	}
}
