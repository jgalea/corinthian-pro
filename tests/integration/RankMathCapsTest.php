<?php

namespace CorinthianPro\Tests;

use Corinthian\Columns\Config;
use CorinthianPro\Integrations\RankMath\RobotsType;
use CorinthianPro\Integrations\RankMath\SeoTitleType;
use Corinthian\Plugin;
use WP_Error;
use WP_UnitTestCase;

/**
 * Corinthian must not let a role edit SEO fields that Rank Math's role manager withholds.
 */
final class RankMathCapsTest extends WP_UnitTestCase {

	private int $post;
	private int $editor;

	public function set_up(): void {
		parent::set_up();
		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->post   = self::factory()->post->create( array( 'post_author' => self::factory()->user->create( array( 'role' => 'administrator' ) ) ) );
	}

	public function tear_down(): void {
		foreach ( array( 'administrator', 'editor' ) as $role ) {
			foreach ( array( 'rank_math_onpage_general', 'rank_math_onpage_advanced' ) as $cap ) {
				get_role( $role )->remove_cap( $cap );
			}
		}
		parent::tear_down();
	}

	private function save_title(): bool|WP_Error {
		wp_set_current_user( $this->editor );
		$screen = Plugin::instance()->screens->get( 'post:post' );
		return ( new SeoTitleType() )->save( $this->post, new Config( 'c', 'rankmath.seo_title', 'x' ), $screen, 'New title' );
	}

	public function test_without_role_manager_caps_post_checks_alone_apply(): void {
		$this->assertTrue( $this->save_title() );
		$this->assertSame( 'New title', get_post_meta( $this->post, 'rank_math_title', true ) );
	}

	public function test_editor_without_rank_math_cap_is_refused(): void {
		get_role( 'administrator' )->add_cap( 'rank_math_onpage_general' );
		$result = $this->save_title();
		$this->assertWPError( $result );
		$this->assertSame( '', get_post_meta( $this->post, 'rank_math_title', true ) );
	}

	public function test_control_editor_with_rank_math_cap_may_edit(): void {
		get_role( 'administrator' )->add_cap( 'rank_math_onpage_general' );
		get_role( 'editor' )->add_cap( 'rank_math_onpage_general' );
		$this->assertTrue( $this->save_title() );
	}

	public function test_robots_needs_the_advanced_cap_not_general(): void {
		get_role( 'administrator' )->add_cap( 'rank_math_onpage_advanced' );
		get_role( 'editor' )->add_cap( 'rank_math_onpage_general' );
		wp_set_current_user( $this->editor );
		$screen = Plugin::instance()->screens->get( 'post:post' );
		$result = ( new RobotsType() )->save( $this->post, new Config( 'c', 'rankmath.robots', 'x' ), $screen, array( 'noindex' ) );
		$this->assertWPError( $result );
	}
}
