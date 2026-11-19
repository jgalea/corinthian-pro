<?php

namespace CorinthianPro\Tests;

use Corinthian\Plugin;
use Corinthian\Screens\Screen;
use Corinthian\Sets\SetRepository;
use CorinthianPro\Admin\DefaultSegment;
use CorinthianPro\Filters\ViewState;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class SegmentsTest extends WP_UnitTestCase {

	private int $set_id;
	private int $admin;
	private int $editor;

	/** @var array<string,mixed> */
	private array $get_backup;

	private const RULE = array(
		'column' => 'status',
		'op'     => 'in',
		'value'  => array( 'draft' ),
	);

	public function set_up(): void {
		parent::set_up();
		$this->get_backup = $_GET;
		$_GET             = array();
		$this->admin      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->editor     = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->admin );
		$this->set_id = Plugin::instance()->sets->save(
			array(
				'screen'   => 'post:post',
				'title'    => 'Posts',
				'columns'  => array(
					array(
						'id'   => 'status',
						'type' => 'post.status',
					),
				),
				'settings' => array(
					'segments' => array(
						array(
							'id'     => 'oldshared1',
							'name'   => 'Old shared',
							'filter' => array(
								'match' => 'all',
								'rules' => array( self::RULE ),
							),
						),
					),
				),
			)
		)->id;
	}

	public function tear_down(): void {
		$_GET = $this->get_backup;
		parent::tear_down();
	}

	/**
	 * @param array<string,mixed> $body
	 */
	private function call( string $method, string $route, array $body = array() ): WP_REST_Response {
		$path    = (string) strtok( $route, '?' );
		$request = new WP_REST_Request( $method, '/corinthian/v1/sets/' . $this->set_id . $path );
		parse_str( (string) wp_parse_url( $route, PHP_URL_QUERY ), $query );
		$request->set_query_params( $query );
		if ( $body ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_ensure_response( rest_do_request( $request ) );
	}

	/**
	 * @param array<string,mixed> $extra
	 * @return array<string,mixed>
	 */
	private function create( string $name, bool $shared = false, array $extra = array() ): array {
		$response = $this->call(
			'POST',
			'/segments',
			$extra + array(
				'name'    => $name,
				'shared'  => $shared,
				'filter'  => array(
					'match' => 'any',
					'rules' => array( self::RULE ),
				),
				'orderby' => 'title',
				'order'   => 'asc',
				'search'  => 'launch',
				'status'  => 'draft',
			)
		);
		$this->assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		return $response->get_data();
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function listed(): array {
		return $this->call( 'GET', '/segments' )->get_data();
	}

	public function test_a_segment_keeps_match_sort_search_and_status(): void {
		$segment = $this->create( 'Launch drafts' );

		$this->assertSame( 'any', $segment['filter']['match'] );
		$this->assertSame( array( self::RULE ), $segment['filter']['rules'] );
		$this->assertSame( 'title', $segment['orderby'] );
		$this->assertSame( 'asc', $segment['order'] );
		$this->assertSame( 'launch', $segment['search'] );
		$this->assertSame( 'draft', $segment['status'] );
		$this->assertSame( $segment, $this->listed()[1] );
	}

	public function test_rename_changes_only_the_name(): void {
		$segment  = $this->create( 'Launch drafts' );
		$response = $this->call( 'PATCH', '/segments/' . $segment['id'], array( 'name' => 'Ready to launch' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array_merge( $segment, array( 'name' => 'Ready to launch' ) ), $response->get_data() );
		$this->assertSame( 'Ready to launch', $this->listed()[1]['name'] );
	}

	public function test_update_replaces_rules_and_view_state(): void {
		$segment  = $this->create( 'Launch drafts' );
		$filter   = array(
			'match' => 'all',
			'rules' => array(
				array(
					'column' => 'status',
					'op'     => 'in',
					'value'  => array( 'pending', 'draft' ),
				),
			),
		);
		$response = $this->call(
			'PATCH',
			'/segments/' . $segment['id'],
			array(
				'filter'  => $filter,
				'orderby' => 'date',
				'order'   => 'DESC',
				'search'  => '',
				'status'  => 'pending',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$saved = $this->listed()[1];
		$this->assertSame( 'Launch drafts', $saved['name'] );
		$this->assertSame( $filter, $saved['filter'] );
		$this->assertSame( array( 'date', 'desc', '', 'pending' ), array( $saved['orderby'], $saved['order'], $saved['search'], $saved['status'] ) );
	}

	public function test_update_and_create_refuse_a_segment_with_nothing_to_restore(): void {
		$segment = $this->create( 'Launch drafts' );

		$this->assertSame( 400, $this->call( 'PATCH', '/segments/' . $segment['id'], array( 'name' => '  ' ) )->get_status() );
		$empty = $this->call(
			'PATCH',
			'/segments/' . $segment['id'],
			array(
				'filter'  => array( 'rules' => array() ),
				'orderby' => '',
				'search'  => '',
				'status'  => '',
			)
		);
		$this->assertSame( 400, $empty->get_status() );
		$this->assertSame( 'Launch drafts', $this->listed()[1]['name'], 'a refused update leaves the segment as it was' );

		$search_only = $this->call(
			'POST',
			'/segments',
			array(
				'name'   => 'Search only',
				'search' => 'launch',
			)
		);
		$this->assertSame( 201, $search_only->get_status(), 'a search alone is enough to save' );
	}

	public function test_updating_a_missing_segment_is_a_404(): void {
		$this->assertSame( 404, $this->call( 'PATCH', '/segments/nope', array( 'name' => 'x' ) )->get_status() );
	}

	public function test_old_segments_read_with_null_view_fields_and_still_apply(): void {
		$old = $this->listed()[0];

		$this->assertSame( 'oldshared1', $old['id'] );
		$this->assertTrue( $old['shared'] );
		$this->assertSame( array( self::RULE ), $old['filter']['rules'] );
		foreach ( ViewState::FIELDS as $field ) {
			$this->assertArrayHasKey( $field, $old );
			$this->assertNull( $old[ $field ], "$field is unset on an old segment" );
		}

		$screen = Plugin::instance()->screens->get( 'post:post' );
		$this->assertInstanceOf( Screen::class, $screen );
		$args = ViewState::segment_args( $screen, $old );
		$this->assertSame( array( 'corinthian_filter', 'corinthian_segment' ), array_keys( $args ), 'only the rules and the id; sort, search and status stay as they are' );
		$this->assertSame( array( self::RULE ), json_decode( $args['corinthian_filter'], true )['rules'] );

		$renamed = $this->call( 'PATCH', '/segments/oldshared1', array( 'name' => 'Old, renamed' ) )->get_data();
		$this->assertNull( $renamed['orderby'], 'a rename leaves an old segment without view fields' );
	}

	public function test_personal_and_shared_defaults_set_and_clear(): void {
		$mine   = $this->create( 'Mine' );
		$shared = $this->create( 'Team', true );

		$this->assertSame(
			array(
				'shared'    => null,
				'personal'  => null,
				'effective' => null,
			),
			$this->call( 'GET', '/default-segment' )->get_data()
		);

		$after_shared = $this->call(
			'PUT',
			'/default-segment',
			array(
				'id'    => $shared['id'],
				'scope' => 'shared',
			)
		)->get_data();
		$this->assertSame( $shared['id'], $after_shared['shared'] );
		$this->assertSame( $shared['id'], $after_shared['effective'] );

		$after_personal = $this->call(
			'PUT',
			'/default-segment',
			array(
				'id'    => $mine['id'],
				'scope' => 'personal',
			)
		)->get_data();
		$this->assertSame( $mine['id'], $after_personal['personal'] );
		$this->assertSame( $mine['id'], $after_personal['effective'], 'the personal default wins over the shared one' );

		$cleared = $this->call( 'DELETE', '/default-segment?scope=personal' )->get_data();
		$this->assertNull( $cleared['personal'] );
		$this->assertSame( $shared['id'], $cleared['effective'], 'with no personal default the shared one applies again' );

		$none = $this->call( 'DELETE', '/default-segment?scope=shared' )->get_data();
		$this->assertNull( $none['effective'] );
	}

	public function test_a_shared_default_must_be_a_shared_segment(): void {
		$mine = $this->create( 'Mine' );
		$this->assertSame(
			404,
			$this->call(
				'PUT',
				'/default-segment',
				array(
					'id'    => $mine['id'],
					'scope' => 'shared',
				)
			)->get_status()
		);
	}

	public function test_personal_default_overrides_shared_for_that_user_only(): void {
		$this->call(
			'PUT',
			'/default-segment',
			array(
				'id'    => 'oldshared1',
				'scope' => 'shared',
			)
		);

		wp_set_current_user( $this->editor );
		$this->assertSame( 'oldshared1', $this->call( 'GET', '/default-segment' )->get_data()['effective'], 'the shared default reaches other users' );
		$own = $this->create( 'Editor view' );
		$this->call(
			'PUT',
			'/default-segment',
			array(
				'id'    => $own['id'],
				'scope' => 'personal',
			)
		);
		$this->assertSame( $own['id'], $this->call( 'GET', '/default-segment' )->get_data()['effective'] );

		wp_set_current_user( $this->admin );
		$this->assertSame( 'oldshared1', $this->call( 'GET', '/default-segment' )->get_data()['effective'], "one user's default doesn't change anyone else's" );
	}

	public function test_a_non_admin_cannot_change_shared_segments_but_manages_their_own(): void {
		wp_set_current_user( $this->editor );

		$this->assertSame( 403, $this->call( 'PATCH', '/segments/oldshared1', array( 'name' => 'Hijacked' ) )->get_status() );
		$this->assertSame( 403, $this->call( 'DELETE', '/segments/oldshared1' )->get_status() );
		$this->assertSame(
			403,
			$this->call(
				'PUT',
				'/default-segment',
				array(
					'id'    => 'oldshared1',
					'scope' => 'shared',
				)
			)->get_status()
		);
		$this->assertSame( 403, $this->call( 'DELETE', '/default-segment?scope=shared' )->get_status() );
		$this->assertSame(
			403,
			$this->call(
				'POST',
				'/segments',
				array(
					'name'   => 'Team',
					'shared' => true,
					'search' => 'x',
				)
			)->get_status()
		);
		$this->assertSame( 'Old shared', $this->listed()[0]['name'], 'the shared segment is untouched' );

		$own = $this->create( 'Editor view' );
		$this->assertSame( 200, $this->call( 'PATCH', '/segments/' . $own['id'], array( 'name' => 'Renamed' ) )->get_status() );
		$this->assertSame(
			200,
			$this->call(
				'PUT',
				'/default-segment',
				array(
					'id'    => 'oldshared1',
					'scope' => 'personal',
				)
			)->get_status(),
			'anyone can open on a shared segment themselves'
		);
		$this->assertSame( 200, $this->call( 'DELETE', '/segments/' . $own['id'] )->get_status() );
	}

	public function test_delete_returns_what_undo_needs_and_restore_puts_it_back(): void {
		$first  = $this->create( 'First' );
		$second = $this->create( 'Second' );
		$third  = $this->create( 'Third' );
		$this->call(
			'PUT',
			'/default-segment',
			array(
				'id'    => $second['id'],
				'scope' => 'personal',
			)
		);

		$deleted = $this->call( 'DELETE', '/segments/' . $second['id'] )->get_data();
		$this->assertTrue( $deleted['deleted'] );
		$this->assertSame( 1, $deleted['position'] );
		$this->assertSame( $second, $deleted['segment'] );
		$this->assertSame( array( 'oldshared1', $first['id'], $third['id'] ), array_column( $this->listed(), 'id' ) );
		$this->assertNull( $this->call( 'GET', '/default-segment' )->get_data()['personal'], 'a deleted default reads as none' );

		$restored = $this->call(
			'POST',
			'/segments',
			array(
				'id'       => $second['id'],
				'position' => $deleted['position'],
			) + array_diff_key( $deleted['segment'], array( 'id' => 1 ) )
		);
		$this->assertSame( 201, $restored->get_status() );
		$this->assertSame( $second, $restored->get_data() );
		$this->assertSame( array( 'oldshared1', $first['id'], $second['id'], $third['id'] ), array_column( $this->listed(), 'id' ) );
		$this->assertSame( $second['id'], $this->call( 'GET', '/default-segment' )->get_data()['personal'], 'undo brings the default back' );

		$again = $this->call(
			'POST',
			'/segments',
			array(
				'id'     => $second['id'],
				'name'   => 'Clash',
				'search' => 'x',
			)
		);
		$this->assertSame( 409, $again->get_status(), 'an id in use is refused' );
		$this->assertSame(
			409,
			$this->call(
				'POST',
				'/segments',
				array(
					'id'     => 'oldshared1',
					'name'   => 'Clash',
					'search' => 'x',
				)
			)->get_status(),
			'a personal segment cannot take a shared id'
		);
	}

	public function test_default_segment_redirects_a_bare_visit_only(): void {
		$segment = $this->create( 'Launch drafts' );
		$this->call(
			'PUT',
			'/default-segment',
			array(
				'id'    => $segment['id'],
				'scope' => 'personal',
			)
		);
		update_user_meta( $this->admin, SetRepository::active_meta_key( 'post:post' ), $this->set_id );
		$screen = Plugin::instance()->screens->get( 'post:post' );
		$this->assertInstanceOf( Screen::class, $screen );
		( new \ReflectionProperty( Screen::class, 'active' ) )->setValue( $screen, null );
		$listener = new DefaultSegment( Plugin::instance() );
		if ( ! class_exists( '\WP_Screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}
		$wp_screen = \WP_Screen::get( 'edit-post' );

		$_GET = array( 'post_type' => 'post' );
		$url  = (string) $listener->redirect_url( $wp_screen );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( $segment['id'], $query['corinthian_segment'] );
		$this->assertSame( array( self::RULE ), json_decode( $query['corinthian_filter'], true )['rules'] );
		$this->assertSame( array( 'title', 'asc', 'launch', 'draft', 'post' ), array( $query['orderby'], $query['order'], $query['s'], $query['post_status'], $query['post_type'] ) );

		foreach (
			array(
				'cleared'          => array( 'corinthian_segment' => 'none' ),
				'explicit filter'  => array( 'corinthian_filter' => '{"rules":[]}' ),
				'explicit sort'    => array( 'orderby' => 'date' ),
				'status view link' => array(
					'post_type'   => 'post',
					'post_status' => 'publish',
				),
			) as $case => $get
		) {
			$_GET = $get;
			$this->assertNull( $listener->redirect_url( $wp_screen ), "no redirect for a $case visit" );
		}

		$_GET = array();
		wp_set_current_user( $this->editor );
		( new \ReflectionProperty( Screen::class, 'active' ) )->setValue( $screen, null );
		$this->assertNull( $listener->redirect_url( $wp_screen ), "another user's personal default doesn't apply" );
	}
}
