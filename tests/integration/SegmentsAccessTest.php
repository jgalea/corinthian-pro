<?php

namespace CorinthianPro\Tests;

use Corinthian\Plugin;
use CorinthianPro\Abilities\Abilities;
use WP_REST_Request;
use WP_UnitTestCase;

final class SegmentsAccessTest extends WP_UnitTestCase {

	private int $set_id;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$set          = Plugin::instance()->sets->save(
			array(
				'screen'   => 'users',
				'title'    => 'People',
				'roles'    => array( 'subscriber' ),
				'columns'  => array(
					array(
						'id'   => 'id',
						'type' => 'user.id',
					),
				),
				'settings' => array(
					'segments' => array(
						array(
							'id'     => 'abc123',
							'name'   => 'Secret',
							'filter' => array(
								'match' => 'all',
								'rules' => array(
									array(
										'column' => 'id',
										'op'     => 'eq',
										'value'  => 1,
									),
								),
							),
						),
					),
				),
			)
		);
		$this->set_id = $set->id;
	}

	private function call( string $method, string $route ): \WP_REST_Response|\WP_Error {
		$response = rest_do_request( new WP_REST_Request( $method, $route ) );
		return $response->is_error() ? $response->as_error() : $response;
	}

	public function test_a_user_who_cannot_open_the_list_screen_cannot_read_its_shared_segments(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$rest = $this->call( 'GET', '/corinthian/v1/sets/' . $this->set_id . '/segments' );
		$this->assertWPError( $rest );
		$this->assertSame( 404, $rest->get_error_data()['status'] );

		$ability = Abilities::list_segments( array( 'set' => $this->set_id ) );
		$this->assertWPError( $ability );
	}

	public function test_a_user_who_can_open_the_list_screen_still_reads_them(): void {
		$rest = $this->call( 'GET', '/corinthian/v1/sets/' . $this->set_id . '/segments' );
		$this->assertNotWPError( $rest );
		$this->assertSame( 'Secret', $rest->get_data()[0]['name'] );
	}

	public function test_a_shared_segment_on_a_code_set_returns_a_clear_error(): void {
		corinthian_register_set(
			array(
				'key'     => 'seg_code',
				'screen'  => 'users',
				'title'   => 'Code',
				'columns' => array(
					array(
						'id'   => 'id',
						'type' => 'user.id',
					),
				),
			)
		);
		$id      = \Corinthian\Sets\CodeSets::id_for( 'seg_code' );
		$request = new WP_REST_Request( 'POST', '/corinthian/v1/sets/' . $id . '/segments' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'name'   => 'Mine',
					'shared' => true,
					'filter' => array(
						'match' => 'all',
						'rules' => array(
							array(
								'column' => 'id',
								'op'     => 'eq',
								'value'  => 1,
							),
						),
					),
				)
			)
		);
		$response = rest_do_request( $request );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'corinthian_readonly', $response->as_error()->get_error_code() );
	}
}
