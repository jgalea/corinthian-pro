<?php

namespace CorinthianPro\Tests;

use Corinthian\Columns\Registry;
use Corinthian\Columns\Types\ScreenTypeLoader;
use Corinthian\Columns\Types\Sites\SiteTypeLoader;
use Corinthian\Columns\Config;
use Corinthian\Columns\Editable;
use Corinthian\Plugin;
use Corinthian\Screens\NetworkSiteScreen;
use Corinthian\Screens\NetworkUserScreen;
use Corinthian\Sets\ColumnSet;
use Corinthian\Sets\SetRepository;
use CorinthianPro\Rest\EditController;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Network site edits. Run with phpunit-multisite.xml.dist.
 *
 * @group MultisiteTest
 * @group ms-required
 */
class MultisiteEditTest extends WP_UnitTestCase {

	private Registry $registry;
	private SetRepository $sets;
	private NetworkSiteScreen $sites_screen;
	private NetworkUserScreen $users_screen;
	private int $super;
	private int $plain_admin;

	/** @var array<string,int> */
	private array $ids = array();

	public function set_up(): void {
		parent::set_up();
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs multisite: run phpunit-multisite.xml.dist.' );
		}
		$this->registry     = new Registry();
		ScreenTypeLoader::register( $this->registry );
		SiteTypeLoader::register( $this->registry );
		$this->sets         = new SetRepository();
		$this->sites_screen = new NetworkSiteScreen( $this->registry, $this->sets );
		$this->users_screen = new NetworkUserScreen( $this->registry, $this->sets );

		$this->super = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $this->super );
		$this->plain_admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->super );

		$rows = array(
			'alpha' => array( 'a.example.org', '2020-01-10 10:00:00', '2023-03-01 10:00:00', 'Alpha Site' ),
			'beta'  => array( 'b.example.org', '2021-02-10 10:00:00', '2022-02-01 10:00:00', 'Beta Site' ),
			'gamma' => array( 'c.example.org', '2022-03-10 10:00:00', '2021-01-05 10:00:00', 'Gamma Site' ),
			'delta' => array( 'd.example.org', '2023-04-10 10:00:00', '2020-06-01 10:00:00', 'Delta Site' ),
		);
		global $wpdb;
		foreach ( $rows as $slug => $row ) {
			$id = self::factory()->blog->create(
				array(
					'domain'  => get_network()->domain,
					'path'    => '/s-' . $slug . '/',
					'title'   => $row[3],
					'user_id' => $this->super,
				)
			);
			$this->ids[ $slug ] = $id;
		}

		foreach ( $this->ids as $id ) {
			update_blog_status( $id, 'public', 1 );
		}
		update_blog_status( $this->ids['beta'], 'public', 0 );
		update_blog_status( $this->ids['beta'], 'archived', 1 );
		update_blog_status( $this->ids['gamma'], 'spam', 1 );
		update_blog_status( $this->ids['gamma'], 'mature', 1 );
		update_blog_status( $this->ids['delta'], 'deleted', 1 );

		// Last, because every status or option change above bumps last_updated.
		foreach ( $rows as $slug => $row ) {
			$wpdb->update(
				$wpdb->blogs,
				array(
					'domain'       => $row[0],
					'registered'   => $row[1],
					'last_updated' => $row[2],
				),
				array( 'blog_id' => $this->ids[ $slug ] )
			);
			clean_blog_cache( $this->ids[ $slug ] );
		}
	}

	private function config( string $key, array $settings = array() ): Config {
		return new Config( 'c', $key, 'Label', null, 'px', $settings );
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private function column_set( string $screen, string $key, array $settings = array() ): ColumnSet {
		return $this->sets->save(
			array(
				'screen'  => $screen,
				'title'   => 'Test ' . $key,
				'columns' => array(
					array(
						'id'       => 'c',
						'type'     => $key,
						'label'    => 'Label',
						'settings' => $settings,
					),
				),
			)
		);
	}

	private function request( string $route, array $params ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/corinthian/v1/' . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	private function edit_set(): ColumnSet {
		return Plugin::instance()->sets->save(
			array(
				'screen'  => 'network-sites',
				'title'   => 'Edits',
				'columns' => array(
					array( 'id' => 'name', 'type' => 'site.name', 'label' => 'Name' ),
					array( 'id' => 'pub', 'type' => 'site.public', 'label' => 'Public' ),
					array( 'id' => 'arch', 'type' => 'site.archived', 'label' => 'Archived' ),
					array( 'id' => 'dom', 'type' => 'site.domain', 'label' => 'Domain' ),
					array( 'id' => 'mail', 'type' => 'site.admin_email', 'label' => 'Mail' ),
				),
			)
		);
	}

	public function test_site_edits_round_trip(): void {
		$controller = new EditController( Plugin::instance() );
		$set        = $this->edit_set();
		$id         = $this->ids['alpha'];
		$base       = array(
			'screen' => 'network-sites',
			'set'    => $set->id,
			'id'     => $id,
		);

		$this->assertInstanceOf( Editable::class, $this->registry->get( 'site.name' ) );
		$this->assertInstanceOf( Editable::class, $this->registry->get( 'site.public' ) );
		$this->assertInstanceOf( Editable::class, $this->registry->get( 'site.archived' ) );
		foreach ( array( 'site.id', 'site.domain', 'site.spam', 'site.deleted', 'site.mature', 'site.language', 'site.admin_email', 'site.meta', 'site.post_count', 'site.registered' ) as $key ) {
			$this->assertNotInstanceOf( Editable::class, $this->registry->get( $key ), "$key is read-only" );
		}

		$response = $controller->inline( $this->request( 'edit', $base + array( 'column' => 'name', 'value' => 'Alpha Renamed' ) ) );
		$this->assertNotInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'Alpha Renamed', $response->get_data()['value'] );
		$this->assertStringContainsString( 'Alpha Renamed', $response->get_data()['html'] );
		$this->assertSame( 'Alpha Renamed', get_blog_option( $id, 'blogname' ) );

		$response = $controller->inline( $this->request( 'edit', $base + array( 'column' => 'pub', 'value' => false ) ) );
		$this->assertNotInstanceOf( WP_Error::class, $response );
		$this->assertFalse( $response->get_data()['value'] );
		$this->assertSame( '0', get_site( $id )->public );
		$controller->inline( $this->request( 'edit', $base + array( 'column' => 'pub', 'value' => true ) ) );
		$this->assertSame( '1', get_site( $id )->public );

		$response = $controller->inline( $this->request( 'edit', $base + array( 'column' => 'arch', 'value' => true ) ) );
		$this->assertTrue( $response->get_data()['value'] );
		$this->assertSame( '1', get_site( $id )->archived );
		$controller->inline( $this->request( 'edit', $base + array( 'column' => 'arch', 'value' => false ) ) );
		$this->assertSame( '0', get_site( $id )->archived );
	}

	public function test_site_edit_rejects_bad_input_and_read_only_columns(): void {
		$controller = new EditController( Plugin::instance() );
		$set        = $this->edit_set();
		$id         = $this->ids['alpha'];
		$base       = array(
			'screen' => 'network-sites',
			'set'    => $set->id,
			'id'     => $id,
		);
		$response   = $controller->inline( $this->request( 'edit', $base + array( 'column' => 'name', 'value' => '   ' ) ) );
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'Alpha Site', get_blog_option( $id, 'blogname' ) );

		foreach ( array( 'dom', 'mail' ) as $column ) {
			$response = $controller->inline( $this->request( 'edit', $base + array( 'column' => $column, 'value' => 'x' ) ) );
			$this->assertInstanceOf( WP_Error::class, $response );
			$this->assertSame( 'corinthian_not_editable', $response->get_error_code() );
		}

		$response = $controller->inline( $this->request( 'edit', array_merge( $base, array( 'id' => 99999999, 'column' => 'name', 'value' => 'Ghost' ) ) ) );
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'corinthian_forbidden', $response->get_error_code() );
	}

	public function test_bulk_edit_archives_several_sites_and_resolves_a_filter(): void {
		$controller = new EditController( Plugin::instance() );
		$set        = $this->edit_set();
		$base       = array(
			'screen' => 'network-sites',
			'set'    => $set->id,
		);

		$resolve = $controller->bulk(
			$this->request(
				'bulk-edit',
				$base + array(
					'column' => 'arch',
					'mode'   => 'set',
					'filter' => array(
						'match' => 'all',
						'rules' => array( array( 'column' => 'pub', 'op' => 'eq', 'value' => '0' ) ),
					),
				)
			)
		);
		$this->assertContains( $this->ids['beta'], $resolve->get_data()['ids'] );
		$this->assertNotContains( $this->ids['alpha'], $resolve->get_data()['ids'] );

		$ids  = array( $this->ids['alpha'], $this->ids['gamma'] );
		$done = $controller->bulk( $this->request( 'bulk-edit', $base + array( 'column' => 'arch', 'mode' => 'set', 'value' => true, 'ids' => $ids ) ) );
		$this->assertSame( 2, $done->get_data()['done'] );
		$this->assertSame( array(), $done->get_data()['failed'] );
		foreach ( $ids as $id ) {
			$this->assertSame( '1', get_site( $id )->archived );
		}
		$this->assertSame( '0', get_site( $this->ids['delta'] )->archived );
	}

	public function test_non_super_admin_cannot_edit_through_the_rest_controller(): void {
		$controller = new EditController( Plugin::instance() );
		$set        = $this->edit_set();
		$id         = $this->ids['alpha'];
		wp_set_current_user( $this->plain_admin );
		$response = $controller->inline( $this->request( 'edit', array( 'screen' => 'network-sites', 'set' => $set->id, 'id' => $id, 'column' => 'name', 'value' => 'Hijacked' ) ) );
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'corinthian_forbidden', $response->get_error_code() );
		$this->assertSame( 'Alpha Site', get_blog_option( $id, 'blogname' ) );

		$bulk = $controller->bulk( $this->request( 'bulk-edit', array( 'screen' => 'network-sites', 'set' => $set->id, 'column' => 'arch', 'mode' => 'set', 'value' => true, 'ids' => array( $id ) ) ) );
		$this->assertInstanceOf( WP_Error::class, $bulk );
		$this->assertSame( '0', get_site( $id )->archived );

		// The editable type also checks the capability itself, in case another caller skips the controller.
		$type = $this->registry->get( 'site.archived' );
		$this->assertInstanceOf( Editable::class, $type );
		$this->assertInstanceOf( WP_Error::class, $type->save( $id, $this->config( 'site.archived' ), $this->sites_screen, true ) );
		$this->assertSame( '0', get_site( $id )->archived );
	}

	public function test_clean_blog_cache_runs_after_a_site_edit(): void {
		$id   = $this->ids['alpha'];
		$seen = array();
		add_action(
			'clean_site_cache',
			static function ( $site_id ) use ( &$seen ) {
				$seen[] = (int) $site_id;
			}
		);
		$set = $this->edit_set();
		( new EditController( Plugin::instance() ) )->inline( $this->request( 'edit', array( 'screen' => 'network-sites', 'set' => $set->id, 'id' => $id, 'column' => 'name', 'value' => 'Cache check' ) ) );
		$this->assertContains( $id, $seen );
	}

	public function test_non_super_admin_is_refused_for_edit_and_super_admin_is_allowed(): void {
		wp_set_current_user( $this->plain_admin );
		$this->assertFalse( EditController::can_edit( $this->sites_screen, $this->ids['alpha'] ) );
		$this->assertFalse( EditController::can_edit( $this->users_screen, $this->plain_admin ) );

		wp_set_current_user( $this->super );
		$this->assertTrue( EditController::can_edit( $this->sites_screen, $this->ids['alpha'] ) );
		$this->assertFalse( EditController::can_edit( $this->sites_screen, 99999999 ) );
		$this->assertTrue( EditController::can_edit( $this->users_screen, $this->plain_admin ) );
	}
}
