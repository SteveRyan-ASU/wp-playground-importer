<?php
/**
 * Destination WordPress inspection.
 *
 * @package WP_Playground_Importer
 */

declare(strict_types=1);

namespace WP_Playground_Importer\Destination;

/**
 * Inspects the current destination WordPress site without changing it.
 */
final class DestinationInspector {
	/**
	 * Inspect the current WordPress installation.
	 *
	 * @return DestinationInspectionResult
	 */
	public function inspect(): DestinationInspectionResult {
		return new DestinationInspectionResult(
			array(
				'site'    => array(
					'home'              => home_url(),
					'siteurl'           => site_url(),
					'wordpress_version' => get_bloginfo( 'version' ),
					'is_multisite'      => is_multisite(),
				),
				'content' => $this->inspect_content(),
				'users'   => $this->inspect_users(),
				'theme'   => $this->inspect_theme(),
				'plugins' => $this->inspect_plugins(),
			)
		);
	}

	/**
	 * Inspect destination content density.
	 *
	 * @return array<string, mixed>
	 */
	private function inspect_content(): array {
		$counts = wp_count_posts();
		$total  = 0;

		foreach ( get_object_vars( $counts ) as $count ) {
			$total += (int) $count;
		}

		return array(
			'post_counts' => get_object_vars( $counts ),
			'total_posts' => $total,
			'freshness'   => $total <= 1 ? 'fresh_or_nearly_fresh' : 'populated',
		);
	}

	/**
	 * Inspect destination users with low-sensitivity fields.
	 *
	 * @return array<string, mixed>
	 */
	private function inspect_users(): array {
		$current_user_id = get_current_user_id();
		$administrators  = get_users(
			array(
				'role'   => 'administrator',
				'fields' => array( 'ID', 'user_login', 'display_name' ),
			)
		);

		return array(
			'current_user_id' => $current_user_id,
			'administrators'  => array_map(
				static fn ( object $user ): array => array(
					'id'           => (int) $user->ID,
					'login'        => $user->user_login,
					'display_name' => $user->display_name,
				),
				$administrators
			),
		);
	}

	/**
	 * Inspect destination theme state.
	 *
	 * @return array<string, mixed>
	 */
	private function inspect_theme(): array {
		$themes = wp_get_themes();

		return array(
			'active_stylesheet' => get_stylesheet(),
			'active_template'   => get_template(),
			'installed'         => array_keys( $themes ),
		);
	}

	/**
	 * Inspect destination plugin state.
	 *
	 * @return array<string, mixed>
	 */
	private function inspect_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed = array_keys( get_plugins() );

		sort( $installed );

		return array(
			'active'    => array_values( get_option( 'active_plugins', array() ) ),
			'installed' => $installed,
		);
	}
}
