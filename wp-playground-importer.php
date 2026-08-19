<?php
/**
 * Plugin Name:       WP Playground Importer
 * Description:       Imports WordPress Playground exports into conventional WordPress installations.
 * Version:           0.1.0
 * Requires at least: 6.8
 * Requires PHP:      8.3
 * Author:            Steve Ryan
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-playground-importer
 *
 * @package WP_Playground_Importer
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP_PLAYGROUND_IMPORTER_VERSION', '0.1.0' );
define( 'WP_PLAYGROUND_IMPORTER_FILE', __FILE__ );
define( 'WP_PLAYGROUND_IMPORTER_PATH', plugin_dir_path( __FILE__ ) );

$wp_playground_importer_autoload = WP_PLAYGROUND_IMPORTER_PATH . 'vendor/autoload.php';

if ( file_exists( $wp_playground_importer_autoload ) ) {
	require_once $wp_playground_importer_autoload;
}

if ( class_exists( WP_Playground_Importer\Plugin::class ) ) {
	add_action( 'plugins_loaded', array( WP_Playground_Importer\Plugin::class, 'load' ) );
}
