<?php

namespace CorinthianPro\Tests;

use Corinthian\Plugin;
use WP_REST_Request;
use WP_UnitTestCase;

final class BulkDeleteTest extends WP_UnitTestCase {

	private int $set;

	public function set_up(): void {
		parent::set_up();
		$this->set = $this->make_set( 'post:post' );
	}

	private function make_set( string $screen, string $type = 'post.id' ): int {
		return Plugin::instance()->sets->save(
			array(
				'screen'  => $screen,
				'title'   => 'Delete',
				'columns' => array(
					array(
						'id'   => 'id',
						'type' => $type,
					),
				),
			)
		)->id;
	}

	/**
	 * @param array<string,mixed> $body
	 */
	private function post( array $body, ?int $set = null, string $screen = 'post:post' ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/corinthian/v1/bulk-delete' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body + array( 'screen' => $screen, 'set' => $set ?? $this->set ) ) );
		return rest_do_request( $request );
	}

	public function test_an_editor_trashes_posts_after_confirming_the_count(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$ids = self::factory()->post->create_many( 3 );

		$response = $this->post( array( 'ids' => $ids, 'mode' => 'trash', 'confirm' => 3 ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 3, $response->get_data()['done'] );
		foreach ( $ids as $id ) {
			$this->assertSame( 'trash', get_post_status( $id ) );
		}
	}

	public function test_nothing_is_removed_when_the_confirmed_count_does_not_match(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$ids = self::factory()->post->create_many( 3 );

		$missing = $this->post( array( 'ids' => $ids, 'mode' => 'delete' ) );
		$wrong   = $this->post( array( 'ids' => $ids, 'mode' => 'delete', 'confirm' => 2 ) );

		$this->assertSame( 400, $missing->get_status() );
		$this->assertSame( 400, $wrong->get_status() );
		foreach ( $ids as $id ) {
			$this->assertSame( 'publish', get_post_status( $id ) );
		}
	}

	public function test_each_object_is_checked_against_the_users_own_delete_capability(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$other  = self::factory()->user->create( array( 'role' => 'author' ) );
		$mine   = self::factory()->post->create( array( 'post_author' => $author ) );
		$theirs = self::factory()->post->create( array( 'post_author' => $other ) );
		wp_set_current_user( $author );

		$response = $this->post( array( 'ids' => array( $mine, $theirs ), 'mode' => 'delete', 'confirm' => 2 ) );

		$data = $response->get_data();
		$this->assertSame( 1, $data['done'] );
		$this->assertSame( array( $theirs ), array_column( $data['failed'], 'id' ) );
		$this->assertNull( get_post( $mine ) );
		$this->assertSame( 'publish', get_post_status( $theirs ) );
	}

	public function test_resolving_a_filter_only_returns_rows_the_user_may_delete(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$mine   = self::factory()->post->create( array( 'post_author' => $author, 'post_name' => 'findme-mine' ) );
		$theirs = self::factory()->post->create( array( 'post_name' => 'findme-theirs' ) );
		wp_set_current_user( $author );
		Plugin::instance()->sets->save(
			array(
				'screen'  => 'post:post',
				'title'   => 'Title',
				'columns' => array(
					array(
						'id'   => 'slug',
						'type' => 'post.slug',
					),
				),
			),
			$this->set
		);

		$response = $this->post( array( 'filter' => array( 'match' => 'all', 'rules' => array( array( 'column' => 'slug', 'op' => 'contains', 'value' => 'findme' ) ) ), 'mode' => 'delete' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( $mine, $response->get_data()['ids'] );
		$this->assertNotContains( $theirs, $response->get_data()['ids'] );
	}

	public function test_a_user_without_delete_rights_is_refused(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$id = self::factory()->post->create();

		$response = $this->post( array( 'ids' => array( $id ), 'mode' => 'delete', 'confirm' => 1 ) );

		$this->assertCount( 1, $response->get_data()['failed'] );
		$this->assertSame( 'publish', get_post_status( $id ) );
	}

	public function test_user_lists_are_not_supported(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$set    = $this->make_set( 'users', 'user.id' );
		$target = self::factory()->user->create();

		$response = $this->post( array( 'ids' => array( $target ), 'mode' => 'delete', 'confirm' => 1 ), $set, 'users' );

		$this->assertSame( 403, $response->get_status() );
		$this->assertNotFalse( get_userdata( $target ) );
	}

	public function test_terms_can_be_deleted_by_a_user_who_manages_them(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$set  = $this->make_set( 'term:category', 'term.id' );
		$term = self::factory()->category->create();

		$response = $this->post( array( 'ids' => array( $term ), 'mode' => 'delete', 'confirm' => 1 ), $set, 'term:category' );

		$this->assertSame( 1, $response->get_data()['done'] ?? 0 );
		$this->assertNull( get_term( $term ) );
	}
}
