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
		$post_types          = array( 'post', 'page', 'attachment' );
		$meaningful_statuses = array( 'publish', 'draft', 'pending', 'private', 'future' );
		$counts              = array();
		$total               = 0;

		foreach ( $post_types as $post_type ) {
			$counts[ $post_type ] = array();

			foreach ( $meaningful_statuses as $status ) {
				$ids = get_posts(
					array(
						'fields'         => 'ids',
						'post_type'      => $post_type,
						'post_status'    => $status,
						'posts_per_page' => -1,
						'orderby'        => 'ID',
						'order'          => 'ASC',
					)
				);

				$meaningful_ids = array_filter(
					$ids,
					fn ( int $post_id ): bool => ! $this->is_stock_wordpress_content( $post_id )
				);

				$counts[ $post_type ][ $status ] = count( $meaningful_ids );
				$total                          += count( $meaningful_ids );
			}
		}

		return array(
			'meaningful_counts' => $counts,
			'meaningful_total'  => $total,
			'freshness'         => 0 === $total ? 'fresh_or_nearly_fresh' : 'populated',
		);
	}

	/**
	 * Determine whether a post is stock WordPress starter content.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function is_stock_wordpress_content( int $post_id ): bool {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return false;
		}

		$stock_records = array(
			'post:hello-world:publish',
			'page:sample-page:publish',
			'page:privacy-policy:draft',
		);

		return in_array(
			sprintf(
				'%s:%s:%s',
				$post->post_type,
				$post->post_name,
				$post->post_status
			),
			$stock_records,
			true
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
