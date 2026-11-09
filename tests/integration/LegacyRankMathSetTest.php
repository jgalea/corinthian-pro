<?php

namespace CorinthianPro\Tests;

use Corinthian\Columns\Registry;
use Corinthian\Screens\PostTypeScreen;
use Corinthian\Sets\SetRepository;
use WP_UnitTestCase;

class LegacyRankMathSetTest extends WP_UnitTestCase {

	public function test_a_saved_set_with_a_rankmath_column_renders_through_pro(): void {
		add_filter( 'corinthian_rankmath_active', '__return_true' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$repo = new SetRepository();
		$id   = $repo->save(
			array(
				'screen'  => 'post',
				'title'   => 'Saved before the split',
				'columns' => array(
					array(
						'id'    => 'c1',
						'type'  => 'rankmath.seo_score',
						'label' => 'SEO',
					),
				),
			)
		)->id;
		$set  = $repo->find( $id );
		$this->assertNotNull( $set );
		$this->assertSame( 'rankmath.seo_score', $set->columns[0]->type );

		$registry = new Registry();
		do_action( 'corinthian_register_types', $registry );
		$type = $registry->get( $set->columns[0]->type );
		$this->assertNotNull( $type );

		$post   = self::factory()->post->create();
		update_post_meta( $post, 'rank_math_seo_score', '90' );
		$screen = new PostTypeScreen( $registry, $repo, get_post_type_object( 'post' ) );
		$html   = $type->render( $post, $set->columns[0], $screen );
		$this->assertStringContainsString( '>90<', $html );
	}
}
