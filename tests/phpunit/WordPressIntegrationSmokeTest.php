<?php
/**
 * WordPress database integration smoke tests.
 *
 * @package WP_Playground_Importer
 */

declare(strict_types=1);

namespace WP_Playground_Importer\Tests;

use WP_UnitTestCase;

/**
 * Verifies WordPress APIs can read and write through the test database.
 */
final class WordPressIntegrationSmokeTest extends WP_UnitTestCase {
	/**
	 * WordPress post APIs write to and read from the integration database.
	 */
	public function test_wordpress_post_apis_read_and_write_posts(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Integration Smoke Test Post',
				'post_content' => 'Created by the WP Playground Importer scaffold tests.',
				'post_status'  => 'publish',
			)
		);

		$post = get_post( $post_id );

		$this->assertNotNull( $post );
		$this->assertSame( 'Integration Smoke Test Post', $post->post_title );
	}
}
