<?php

namespace CorinthianPro\Tests;

use Corinthian\Plugin;
use WP_REST_Request;
use WP_UnitTestCase;

final class EditStatusTest extends WP_UnitTestCase {

	public function test_a_refused_inline_edit_returns_403_not_400(): void {
		$set    = Plugin::instance()->sets->save(
			array(
				'screen'  => 'post:post',
				'title'   => 'Authors',
				'columns' => array(
					array(
						'id'   => 'author',
						'type' => 'post.author',
					),
				),
			)
		);
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$other  = self::factory()->user->create( array( 'role' => 'author' ) );
		$post   = self::factory()->post->create( array( 'post_author' => $author ) );
		wp_set_current_user( $author );

		$request = new WP_REST_Request( 'POST', '/corinthian/v1/edit' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'screen' => 'post:post',
					'set'    => $set->id,
					'column' => 'author',
					'id'     => $post,
					'value'  => $other,
				)
			)
		);
		$response = rest_do_request( $request );

		$this->assertTrue( $response->is_error() );
		$this->assertSame( 'corinthian_forbidden', $response->as_error()->get_error_code() );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( $author, (int) get_post_field( 'post_author', $post ) );
	}
}
