<?php

namespace CorinthianPro\Tests;

use Corinthian\Columns\Filterable;
use Corinthian\Columns\Registry;
use Corinthian\Columns\Types\ScreenTypeLoader;
use Corinthian\Columns\Types\Sites\SiteTypeLoader;
use Corinthian\Screens\NetworkSiteScreen;
use Corinthian\Sets\ColumnSet;
use Corinthian\Sets\SetRepository;
use CorinthianPro\Filters\FilterSet;
use CorinthianPro\Filters\Matcher;
use WP_UnitTestCase;

/**
 * Network site filters. Run with phpunit-multisite.xml.dist.
 *
 * @group MultisiteTest
 * @group ms-required
 */
class MultisiteFilterTest extends WP_UnitTestCase {

	private Registry $registry;
	private SetRepository $sets;
	private NetworkSiteScreen $sites_screen;
	private int $super;

	/** @var array<string,int> */
	private array $ids = array();

	/** @var list<int> */
	private array $all = array();

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

		$this->super = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $this->super );
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
		$this->all = array_values( $this->ids );

		foreach ( $this->ids as $id ) {
			update_blog_status( $id, 'public', 1 );
		}
		update_blog_status( $this->ids['beta'], 'public', 0 );
		update_blog_status( $this->ids['beta'], 'archived', 1 );
		update_blog_status( $this->ids['gamma'], 'spam', 1 );
		update_blog_status( $this->ids['gamma'], 'mature', 1 );
		update_blog_status( $this->ids['delta'], 'deleted', 1 );

		update_site_meta( $this->ids['alpha'], 'rank', '30' );
		update_site_meta( $this->ids['beta'], 'rank', '10' );
		update_site_meta( $this->ids['gamma'], 'rank', '20' );
		add_filter(
			'get_available_languages',
			static function ( $languages ) {
				return array_merge( (array) $languages, array( 'fr_FR' ) );
			}
		);
		update_blog_option( $this->ids['beta'], 'WPLANG', 'fr_FR' );
		update_blog_option( $this->ids['alpha'], 'admin_email', 'alpha-owner@example.org' );

		switch_to_blog( $this->ids['gamma'] );
		self::factory()->post->create_many( 2, array( 'post_status' => 'publish' ) );
		restore_current_blog();

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

	/**
	 * @param array<string,mixed> $settings
	 * @param list<int>|null      $within
	 * @return list<int>
	 */
	private function match_ids( string $key, array $settings, string $op, mixed $value, ?array $within = null ): array {
		$set    = $this->column_set( 'network-sites', $key, $settings );
		$filter = FilterSet::from(
			array(
				'match' => 'all',
				'rules' => array(
					array(
						'column' => 'c',
						'op'     => $op,
						'value'  => $value,
					),
				),
			)
		);
		$sql    = $filter->compile( $this->sites_screen, $set );
		$this->assertNotSame( '', $sql['where'], "$key $op compiled to a real condition" );
		return Matcher::ids( $this->sites_screen, $set, $filter, $within ?? $this->all );
	}

	/**
	 * @param list<string> $slugs
	 * @return list<int>
	 */
	private function ids_of( array $slugs ): array {
		return array_map( fn( $s ) => $this->ids[ $s ], $slugs );
	}

	/**
	 * Every filterable site type: one exact match and one control that matches nothing.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>,2:string,3:mixed,4:list<string>,5:mixed}>
	 */
	public static function filter_cases(): array {
		return array(
			'id eq'              => array( 'site.id', array(), 'eq', '{beta}', array( 'beta' ), 99999999 ),
			'id gt'              => array( 'site.id', array(), 'gt', '{gamma}', array( 'delta' ), 99999999 ),
			'domain eq'          => array( 'site.domain', array(), 'eq', 'c.example.org', array( 'gamma' ), 'nope.example.org' ),
			'domain contains'    => array( 'site.domain', array(), 'contains', 'b.exa', array( 'beta' ), 'zzz' ),
			'path contains'      => array( 'site.path', array(), 'contains', 's-delta', array( 'delta' ), 's-zzz' ),
			'path starts'        => array( 'site.path', array(), 'starts', '/s-alpha', array( 'alpha' ), '/s-zzz' ),
			'name eq'            => array( 'site.name', array(), 'eq', 'Beta Site', array( 'beta' ), 'No such site' ),
			'name contains'      => array( 'site.name', array(), 'contains', 'amm', array( 'gamma' ), 'zzz' ),
			'name neq'           => array( 'site.name', array(), 'neq', 'Alpha Site', array( 'beta', 'gamma', 'delta' ), null ),
			'registered before'  => array( 'site.registered', array(), 'before', '2020-06-01', array( 'alpha' ), '1999-01-01' ),
			'registered after'   => array( 'site.registered', array(), 'after', '2022-12-31', array( 'delta' ), '2999-01-01' ),
			'updated before'     => array( 'site.last_updated', array(), 'before', '2020-12-31', array( 'delta' ), '1999-01-01' ),
			'updated after'      => array( 'site.last_updated', array(), 'after', '2023-01-01', array( 'alpha' ), '2999-01-01' ),
			'public eq 0'        => array( 'site.public', array(), 'eq', '0', array( 'beta' ), null ),
			'archived eq 1'      => array( 'site.archived', array(), 'eq', '1', array( 'beta' ), null ),
			'spam eq 1'          => array( 'site.spam', array(), 'eq', '1', array( 'gamma' ), null ),
			'deleted eq 1'       => array( 'site.deleted', array(), 'eq', '1', array( 'delta' ), null ),
			'mature eq 1'        => array( 'site.mature', array(), 'eq', '1', array( 'gamma' ), null ),
			'meta eq'            => array( 'site.meta', array( 'meta_key' => 'rank', 'display' => 'number' ), 'eq', '20', array( 'gamma' ), '99' ),
			'meta gt'            => array( 'site.meta', array( 'meta_key' => 'rank', 'display' => 'number' ), 'gt', '25', array( 'alpha' ), '99' ),
			'meta empty'         => array( 'site.meta', array( 'meta_key' => 'rank' ), 'empty', null, array( 'delta' ), null ),
		);
	}

	/**
	 * @dataProvider filter_cases
	 * @param array<string,mixed> $settings
	 * @param list<string>        $expected
	 */
	public function test_each_filterable_type_matches_exactly_and_a_control_matches_nothing( string $key, array $settings, string $op, mixed $value, array $expected, mixed $control ): void {
		$this->assertInstanceOf( Filterable::class, $this->registry->get( $key ) );
		$value = is_string( $value ) ? preg_replace_callback( '/\{(\w+)\}/', fn( $m ) => (string) $this->ids[ $m[1] ], $value ) : $value;

		$found = $this->match_ids( $key, $settings, $op, $value );
		sort( $found );
		$want = $this->ids_of( $expected );
		sort( $want );
		$this->assertSame( $want, $found, "$key $op" );

		if ( null !== $control ) {
			$this->assertSame( array(), $this->match_ids( $key, $settings, $op, $control ), "$key $op control" );
		} else {
			// Flags and negations have no value that matches nothing: restrict to a site that doesn't qualify.
			$outside = array_values( array_diff( $this->all, $want ) );
			$this->assertNotSame( array(), $outside );
			$this->assertSame( array(), $this->match_ids( $key, $settings, $op, $value, array_slice( $outside, 0, 1 ) ), "$key $op control" );
		}
	}

	public function test_a_filter_only_matches_sites_inside_the_candidate_list(): void {
		$all = $this->match_ids( 'site.name', array(), 'contains', 'Site', $this->all );
		sort( $all );
		$expected = $this->all;
		sort( $expected );
		$this->assertSame( $expected, $all );
		$this->assertSame( array( $this->ids['beta'] ), $this->match_ids( 'site.name', array(), 'contains', 'Site', array( $this->ids['beta'] ) ) );
	}

	public function test_invalid_flag_values_are_dropped_not_run(): void {
		$set    = $this->column_set( 'network-sites', 'site.spam' );
		$filter = FilterSet::from( array( 'rules' => array( array( 'column' => 'c', 'op' => 'eq', 'value' => '1 OR 1=1' ) ) ) );
		$this->assertSame( '', $filter->compile( $this->sites_screen, $set )['where'] );
	}

	public function test_a_filtered_list_is_not_served_from_the_plain_query_cache(): void {
		$set = $this->column_set( 'network-sites', 'site.public' );
		$base = array(
			'number'     => 50,
			'network_id' => get_current_network_id(),
			'fields'     => 'ids',
			'site__in'   => $this->all,
			'orderby'    => 'id',
			'order'      => 'ASC',
		);
		$this->assertSame( $this->all, get_sites( $base ), 'primes the cache for the plain query' );

		$screen = new NetworkSiteScreen( $this->registry, $this->sets );
		$screen->register();
		set_current_screen( 'sites-network' );
		$_GET[ FilterSet::QUERY_VAR ] = (string) wp_json_encode(
			array(
				'match' => 'all',
				'rules' => array( array( 'column' => 'c', 'op' => 'eq', 'value' => '0' ) ),
			)
		);
		$args = apply_filters( 'ms_sites_list_table_query_args', $base );
		$this->assertSame( array( $this->ids['beta'] ), get_sites( $args ) );
		$this->assertSame( 1, get_sites( array_merge( $args, array( 'count' => true, 'number' => 0 ) ) ), 'the count query is filtered too' );
		unset( $_GET[ FilterSet::QUERY_VAR ] );
		$this->assertSame( $set->id, $screen->active_set()?->id );
	}

	public function test_other_site_queries_on_the_screen_are_left_alone(): void {
		$this->column_set( 'network-sites', 'site.public' );
		$screen = new NetworkSiteScreen( $this->registry, $this->sets );
		$screen->register();
		set_current_screen( 'sites-network' );
		$_GET[ FilterSet::QUERY_VAR ] = (string) wp_json_encode(
			array(
				'match' => 'all',
				'rules' => array( array( 'column' => 'c', 'op' => 'eq', 'value' => '0' ) ),
			)
		);
		$this->assertCount( 4, get_sites( array( 'fields' => 'ids', 'site__in' => $this->all ) ) );
		unset( $_GET[ FilterSet::QUERY_VAR ] );
	}

	public function test_matcher_returns_only_sites_that_match_and_honours_the_limit(): void {
		$set    = $this->column_set( 'network-sites', 'site.domain' );
		$filter = FilterSet::from( array( 'rules' => array( array( 'column' => 'c', 'op' => 'contains', 'value' => '.example.org' ) ) ) );
		$found  = Matcher::ids( $this->sites_screen, $set, $filter, $this->all );
		sort( $found );
		$want = $this->all;
		sort( $want );
		$this->assertSame( $want, $found );
		$this->assertCount( 2, Matcher::ids( $this->sites_screen, $set, $filter, $this->all, 2 ) );
		$this->assertSame( array(), Matcher::ids( $this->sites_screen, $set, $filter, array() ) );
	}
}
