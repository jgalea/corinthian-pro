<?php

namespace CorinthianPro\Tests;

use Corinthian\Plugin;
use CorinthianPro\Admin\FilterLabels;
use CorinthianPro\Filters\FilterSet;
use CorinthianPro\Filters\Matcher;
use WP_REST_Request;
use WP_UnitTestCase;

final class FilterValuesTest extends WP_UnitTestCase {

	private const TERMS = 520;
	private const USERS = 215;

	private static int $set_id;
	private static int $admin;
	private static int $last_term;
	private static int $last_user;
	private static int $tagged_post;
	private static int $authored_post;

	/** @var array<string,int> */
	private static array $priority_posts = array();

	public static function wpSetUpBeforeClass( \WP_UnitTest_Factory $factory ): void {
		global $wpdb;
		self::$admin = $factory->user->create( array( 'role' => 'administrator' ) );

		for ( $i = 1; $i <= self::TERMS; $i++ ) {
			$term = wp_insert_term( sprintf( 'tag-%04d', $i ), 'post_tag' );
			if ( self::TERMS === $i ) {
				self::$last_term = (int) $term['term_id'];
			}
		}

		// Inserted directly: hashing a password for each of 200+ factory users takes minutes.
		for ( $i = 1; $i <= self::USERS; $i++ ) {
			$login = sprintf( 'bulk-%04d', $i );
			$wpdb->insert(
				$wpdb->users,
				array(
					'user_login'      => $login,
					'user_pass'       => '',
					'user_nicename'   => $login,
					'user_email'      => $login . '@example.com',
					'user_registered' => '2026-01-01 00:00:00',
					'display_name'    => sprintf( 'Bulk Person %04d', $i ),
				)
			);
			update_user_meta( (int) $wpdb->insert_id, $wpdb->prefix . 'capabilities', array( 'subscriber' => true ) );
		}
		self::$last_user = $factory->user->create(
			array(
				'role'         => 'author',
				'display_name' => 'Zed Beyond',
			)
		);

		self::$tagged_post   = $factory->post->create();
		self::$authored_post = $factory->post->create( array( 'post_author' => self::$last_user ) );
		wp_set_post_terms( self::$tagged_post, array( self::$last_term ), 'post_tag' );

		foreach ( array( 'low', 'medium', 'high', 'urgent', 'high' ) as $i => $priority ) {
			$id = $factory->post->create( array( 'post_title' => "Priority {$i}" ) );
			update_post_meta( $id, 'priority', $priority );
			self::$priority_posts[ "{$priority}{$i}" ] = $id;
		}
		for ( $i = 1; $i <= 60; $i++ ) {
			update_post_meta( $factory->post->create(), 'code', sprintf( 'code-%03d', $i ) );
		}
		$private = $factory->post->create(
			array(
				'post_status' => 'private',
				'post_author' => self::$admin,
			)
		);
		update_post_meta( $private, 'priority', 'secret' );

		wp_set_current_user( self::$admin );
		self::$set_id = Plugin::instance()->sets->save(
			array(
				'screen'  => 'post:post',
				'title'   => 'Picker',
				'columns' => array(
					array(
						'id'   => 'author',
						'type' => 'post.author',
					),
					array(
						'id'       => 'tags',
						'type'     => 'post.taxonomy',
						'settings' => array( 'taxonomy' => 'post_tag' ),
					),
					array(
						'id'       => 'priority',
						'type'     => 'post.meta',
						'settings' => array( 'meta_key' => 'priority' ),
					),
					array(
						'id'       => 'code',
						'type'     => 'post.meta',
						'settings' => array( 'meta_key' => 'code' ),
					),
					array(
						'id'       => 'score',
						'type'     => 'post.meta',
						'settings' => array(
							'meta_key' => 'priority',
							'display'  => 'number',
						),
					),
					array(
						'id'   => 'id',
						'type' => 'post.id',
					),
				),
			)
		)->id;
	}

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::$admin );
	}

	/**
	 * @param array<string,mixed> $params
	 */
	private function call( array $params ): \WP_REST_Response|\WP_Error {
		$request = new WP_REST_Request( 'GET', '/corinthian/v1/sets/' . self::$set_id . '/filter-values' );
		$request->set_query_params( $params );
		$response = rest_do_request( $request );
		return $response->is_error() ? $response->as_error() : $response;
	}

	/**
	 * @param array<string,mixed> $params
	 * @return array{items:list<array{value:string,label:string}>,page:int,more:bool}
	 */
	private function ok( array $params ): array {
		$response = $this->call( $params );
		$this->assertNotWPError( $response );
		return $response->get_data();
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function operators( string $column ): array {
		$set    = Plugin::instance()->sets->find( self::$set_id );
		$config = $set->column( $column );
		$screen = Plugin::instance()->screens->get( 'post:post' );
		return Plugin::instance()->registry->get( $config->type )->filter_operators( $config, $screen );
	}

	public function test_a_tag_past_the_old_500_cap_is_found_by_search(): void {
		$data = $this->ok(
			array(
				'column' => 'tags',
				'search' => sprintf( 'tag-%04d', self::TERMS ),
			)
		);
		$this->assertSame(
			array(
				array(
					'value' => (string) self::$last_term,
					'label' => sprintf( 'tag-%04d', self::TERMS ),
				),
			),
			$data['items']
		);
		$this->assertFalse( $data['more'] );
	}

	public function test_paging_walks_every_tag_without_gaps_or_repeats(): void {
		$seen = array();
		$page = 1;
		do {
			$data = $this->ok(
				array(
					'column'   => 'tags',
					'page'     => $page,
					'per_page' => 50,
				)
			);
			$this->assertLessThanOrEqual( 50, count( $data['items'] ) );
			foreach ( $data['items'] as $item ) {
				$seen[] = $item['value'];
			}
			++$page;
		} while ( $data['more'] && $page < 20 );

		$this->assertCount( self::TERMS, $seen, 'More than the old 500 cap is reachable' );
		$this->assertSame( $seen, array_values( array_unique( $seen ) ), 'No tag repeats across pages' );
		$this->assertContains( (string) self::$last_term, $seen );
		$this->assertSame( 11, $page - 1, '520 tags at 50 a page is 11 pages' );
	}

	public function test_a_user_past_the_old_200_cap_is_found_and_paged(): void {
		$data = $this->ok(
			array(
				'column' => 'author',
				'search' => 'Zed',
			)
		);
		$this->assertSame( array( (string) self::$last_user ), array_column( $data['items'], 'value' ) );

		$first = $this->ok(
			array(
				'column'   => 'author',
				'per_page' => 20,
			)
		);
		$this->assertCount( 20, $first['items'] );
		$this->assertTrue( $first['more'] );
		$second = $this->ok(
			array(
				'column'   => 'author',
				'per_page' => 20,
				'page'     => 2,
			)
		);
		$this->assertSame( array(), array_intersect( array_column( $first['items'], 'value' ), array_column( $second['items'], 'value' ) ) );
	}

	public function test_inline_options_are_capped_and_flagged_for_search(): void {
		foreach ( array( 'tags', 'author' ) as $column ) {
			$in = array_values( array_filter( $this->operators( $column ), static fn( $o ) => 'in' === $o['op'] ) )[0];
			$this->assertTrue( $in['search'] ?? false, "$column is searched" );
			$this->assertCount( 50, $in['options'], "$column ships 50 values inline" );
		}
	}

	public function test_picking_a_value_past_the_old_caps_filters_the_list(): void {
		$set    = Plugin::instance()->sets->find( self::$set_id );
		$screen = Plugin::instance()->screens->get( 'post:post' );
		$tagged = Matcher::ids(
			$screen,
			$set,
			FilterSet::from(
				array(
					'rules' => array(
						array(
							'column' => 'tags',
							'op'     => 'in',
							'value'  => array( (string) self::$last_term ),
						),
					),
				)
			)
		);
		$this->assertSame( array( self::$tagged_post ), $tagged );

		$authored = Matcher::ids(
			$screen,
			$set,
			FilterSet::from(
				array(
					'rules' => array(
						array(
							'column' => 'author',
							'op'     => 'in',
							'value'  => array( (string) self::$last_user ),
						),
					),
				)
			)
		);
		$this->assertSame( array( self::$authored_post ), $authored );
	}

	public function test_labels_for_picked_values_outside_the_first_page(): void {
		$data = $this->ok(
			array(
				'column'  => 'tags',
				'include' => array( (string) self::$last_term, '99999999' ),
			)
		);
		$this->assertSame(
			array(
				array(
					'value' => (string) self::$last_term,
					'label' => sprintf( 'tag-%04d', self::TERMS ),
				),
			),
			$data['items'],
			'A known value gets its label; a deleted one is left out'
		);

		$users = $this->ok(
			array(
				'column'  => 'author',
				'include' => array( (string) self::$last_user ),
			)
		);
		$this->assertSame( 'Zed Beyond', $users['items'][0]['label'] );
	}

	public function test_the_table_payload_carries_labels_for_the_current_filter(): void {
		$set     = Plugin::instance()->sets->find( self::$set_id );
		$screen  = Plugin::instance()->screens->get( 'post:post' );
		$columns = array();
		foreach ( array( 'tags', 'author' ) as $id ) {
			$columns[] = array(
				'id'        => $id,
				'label'     => $id,
				'operators' => $this->operators( $id ),
			);
		}
		$data = array(
			'filter' => array(
				'current' => array(
					'match' => 'all',
					'rules' => array(
						array(
							'column' => 'tags',
							'op'     => 'in',
							'value'  => array( (string) self::$last_term ),
						),
						array(
							'column' => 'author',
							'op'     => 'not_in',
							'value'  => array( (string) self::$last_user ),
						),
					),
				),
				'columns' => $columns,
			),
		);
		$this->assertArrayNotHasKey( self::$last_term, $columns[0]['operators'][0]['options'], 'Control: the tag is past the inline values' );

		$out = ( new FilterLabels( Plugin::instance() ) )->extend( $data, $screen, $set );

		$this->assertSame( sprintf( 'tag-%04d', self::TERMS ), $out['filter']['columns'][0]['operators'][0]['options'][ self::$last_term ] );
		$not_in = array_values( array_filter( $out['filter']['columns'][1]['operators'], static fn( $o ) => 'not_in' === $o['op'] ) )[0];
		$this->assertSame( 'Zed Beyond', $not_in['options'][ self::$last_user ] );
	}

	public function test_a_meta_field_with_few_values_becomes_a_pick_list(): void {
		$ops = $this->operators( 'priority' );
		$this->assertSame( array( 'in', 'not_in' ), array( $ops[0]['op'], $ops[1]['op'] ), 'Pick list first' );
		$this->assertSame( array( 'high', 'low', 'medium', 'secret', 'urgent' ), array_keys( $ops[0]['options'] ), 'Admin sees every distinct value' );
		$this->assertArrayNotHasKey( 'search', $ops[0] );
		$this->assertContains( 'contains', array_column( $ops, 'op' ), 'Text operators stay for saved filters' );

		$set    = Plugin::instance()->sets->find( self::$set_id );
		$screen = Plugin::instance()->screens->get( 'post:post' );
		$ids    = Matcher::ids(
			$screen,
			$set,
			FilterSet::from(
				array(
					'rules' => array(
						array(
							'column' => 'priority',
							'op'     => 'in',
							'value'  => array( 'high', 'urgent' ),
						),
					),
				)
			)
		);
		sort( $ids );
		$this->assertSame( array( self::$priority_posts['high2'], self::$priority_posts['urgent3'], self::$priority_posts['high4'] ), $ids );
	}

	public function test_a_meta_field_with_many_values_keeps_free_text_with_suggestions(): void {
		$ops = $this->operators( 'code' );
		$this->assertSame( 'contains', $ops[0]['op'], 'Free text first' );
		$this->assertTrue( $ops[0]['search'] ?? false, 'Text operators offer suggestions' );
		$in = array_values( array_filter( $ops, static fn( $o ) => 'in' === $o['op'] ) )[0];
		$this->assertTrue( $in['search'] ?? false );
		$this->assertCount( 50, $in['options'] );

		$data = $this->ok(
			array(
				'column' => 'code',
				'search' => 'code-05',
			)
		);
		$this->assertSame( array( 'code-050', 'code-051', 'code-052', 'code-053', 'code-054', 'code-055', 'code-056', 'code-057', 'code-058', 'code-059' ), array_column( $data['items'], 'value' ) );
	}

	public function test_the_threshold_sits_at_fifty_distinct_values(): void {
		$ids = array();
		for ( $i = 1; $i <= 51; $i++ ) {
			$ids[] = self::factory()->post->create();
			update_post_meta( end( $ids ), 'level', 'L' . $i );
		}
		$set = Plugin::instance()->sets->find( self::$set_id );
		$this->assertNotNull( $set );
		$config = \Corinthian\Columns\Config::from_array(
			array(
				'id'       => 'level',
				'type'     => 'post.meta',
				'settings' => array( 'meta_key' => 'level' ),
			)
		);
		$screen = Plugin::instance()->screens->get( 'post:post' );
		$type   = Plugin::instance()->registry->get( 'post.meta' );

		$this->assertSame( 'contains', $type->filter_operators( $config, $screen )[0]['op'], '51 values: free text' );
		delete_post_meta( $ids[50], 'level' );
		$this->assertSame( 'in', $type->filter_operators( $config, $screen )[0]['op'], '50 values: pick list' );
	}

	public function test_numbers_and_unset_fields_get_no_pick_list(): void {
		$this->assertNotContains( 'in', array_column( $this->operators( 'score' ), 'op' ) );
	}

	public function test_private_values_stay_hidden_from_people_who_cannot_read_private_posts(): void {
		wp_set_current_user( self::$last_user );
		$ops = $this->operators( 'priority' );
		$this->assertSame( array( 'high', 'low', 'medium', 'urgent' ), array_keys( $ops[0]['options'] ) );
		$data = $this->ok(
			array(
				'column' => 'priority',
				'search' => 's',
			)
		);
		$this->assertSame( array(), $data['items'] );
	}

	public function test_user_meta_gets_the_same_pick_list(): void {
		foreach ( array( 'gold', 'silver', 'gold' ) as $tier ) {
			update_user_meta( self::factory()->user->create(), 'tier', $tier );
		}
		$config = \Corinthian\Columns\Config::from_array(
			array(
				'id'       => 'tier',
				'type'     => 'user.meta',
				'settings' => array( 'meta_key' => 'tier' ),
			)
		);
		$screen = Plugin::instance()->screens->get( 'users' );
		$type   = Plugin::instance()->registry->get( 'user.meta' );
		$ops    = $type->filter_operators( $config, $screen );
		$this->assertSame( 'in', $ops[0]['op'] );
		$this->assertSame( array( 'gold', 'silver' ), array_keys( $ops[0]['options'] ) );

		$sql = $type->filter_condition( $config, 'in', array( 'silver' ), $screen, 'clf0' );
		$this->assertNotNull( $sql );
		$this->assertStringContainsString( "IN ('silver')", $sql['where'] );
	}

	public function test_a_search_is_trimmed_and_wildcards_are_literal(): void {
		$data = $this->ok(
			array(
				'column' => 'tags',
				'search' => '%',
			)
		);
		$this->assertSame( array(), $data['items'], 'A percent sign matches nothing rather than everything' );
	}

	public function test_people_who_cannot_open_the_list_are_refused(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$response = $this->call( array( 'column' => 'tags' ) );
		$this->assertWPError( $response );
		$this->assertSame( 404, $response->get_error_data()['status'] );

		wp_set_current_user( 0 );
		$response = $this->call( array( 'column' => 'tags' ) );
		$this->assertWPError( $response );
		$this->assertSame( 401, $response->get_error_data()['status'] );
	}

	public function test_columns_without_searchable_values_are_rejected(): void {
		foreach ( array( 'id', 'nope', 'score' ) as $column ) {
			$response = $this->call( array( 'column' => $column ) );
			$this->assertWPError( $response );
			$this->assertSame( 400, $response->get_error_data()['status'], $column );
		}
	}

	public function test_page_size_is_bounded(): void {
		$response = $this->call(
			array(
				'column'   => 'tags',
				'per_page' => 500,
			)
		);
		$this->assertWPError( $response );
		$this->assertSame( 400, $response->get_error_data()['status'] );
	}
}
