<?php

namespace CorinthianPro\Tests;

use Corinthian\Columns\Config;
use Corinthian\Columns\Filterable;
use Corinthian\Columns\Registry;
use Corinthian\Columns\Sortable;
use CorinthianPro\Integrations\Wpra\WpraApi;
use CorinthianPro\Integrations\Wpra\WpraIntegration;
use Corinthian\Screens\PostTypeScreen;
use Corinthian\Sets\SetRepository;
use RebelCode\Aggregator\Core\ImportedPost;
use RebelCode\Aggregator\Core\Source;
use WP_Query;
use WP_UnitTestCase;

/**
 * Runs against a real WP RSS Aggregator 5: its sources table, its post type and
 * the _wpra_* meta its importer writes.
 */
class WpraTest extends WP_UnitTestCase {

	private const KEYS = array( 'source', 'link', 'imported', 'author', 'source_site', 'media' );

	private Registry $registry;
	private PostTypeScreen $screen;
	private int $admin = 0;

	/** @var array<string,int> items a..f */
	private array $n = array();
	/** @var array<string,int> sources s1..s3 */
	private array $s = array();

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		if ( ! defined( 'WPRA_VERSION' ) ) {
			$file = WP_PLUGIN_DIR . '/wp-rss-aggregator/wp-rss-aggregator.php';
			if ( ! is_file( $file ) ) {
				self::markTestSkipped( 'WP RSS Aggregator is not installed. Run: wp plugin install wp-rss-aggregator' );
			}
			// WordPress has already run plugins_loaded and init. Run only WPRA's callbacks for them, not every plugin's again.
			global $wp_filter;
			$saved = array();
			foreach ( array( 'plugins_loaded', 'init' ) as $hook ) {
				$saved[ $hook ]     = $wp_filter[ $hook ] ?? null;
				$wp_filter[ $hook ] = new \WP_Hook();
			}
			require_once $file;
			do_action( 'plugins_loaded' );
			do_action( 'init' );
			foreach ( $saved as $hook => $original ) {
				if ( null === $original ) {
					unset( $wp_filter[ $hook ] );
				} else {
					$wp_filter[ $hook ] = $original;
				}
			}
		}
	}

	public function set_up(): void {
		parent::set_up();
		update_option( 'date_format', 'Y-m-d' );
		update_option( 'time_format', 'H:i' );
		update_option( 'timezone_string', 'UTC' );
		get_role( 'administrator' )->add_cap( WpraApi::SEE_AGGREGATOR );
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		set_current_screen( 'edit-wprss_feed_item' );
		WpraApi::forget_sources();

		$this->registry = new Registry();
		WpraIntegration::add_types( $this->registry );
		$this->screen = new PostTypeScreen( $this->registry, new SetRepository(), get_post_type_object( WpraApi::ITEM_POST_TYPE ) );

		$store = wpra()->get( 'importer' )->sources;
		foreach ( array(
			's1' => 'Alpha Feed',
			's2' => 'Beta Feed',
			's3' => 'Gamma <b>Feed</b>',
		) as $name => $title ) {
			$saved            = $store->save( new Source( null, $title ) );
			$this->s[ $name ] = (int) $saved->get()->id;
		}

		$now = time();
		$iso = static fn( int $ts ): string => gmdate( DATE_ATOM, $ts );

		$this->n['a'] = $this->item(
			'A',
			array( $this->s['s2'] ),
			array(
				ImportedPost::URL           => 'https://example.com/news/a-article?utm=1',
				ImportedPost::IMPORT_DATE   => '2031-03-10T09:00:00+00:00',
				ImportedPost::AUTHOR_NAME   => 'Zed Writer',
				ImportedPost::SOURCE_URL    => 'https://beta.example.org/',
				ImportedPost::ENCLOSURE_URL => array( 'https://cdn.example.com/audio/a.mp3' ),
			)
		);
		$this->n['b'] = $this->item(
			'B',
			array( $this->s['s1'] ),
			array(
				ImportedPost::URL         => 'https://example.org/b',
				ImportedPost::IMPORT_DATE => '2031-03-12T09:00:00+00:00',
				ImportedPost::AUTHOR_NAME => 'Amy Author',
				ImportedPost::SOURCE_URL  => 'https://alpha.example.org/',
			)
		);
		$this->n['c'] = $this->item(
			'C',
			array( $this->s['s3'] ),
			array(
				ImportedPost::URL         => 'https://example.net/c',
				ImportedPost::IMPORT_DATE => '2031-03-12T05:00:00+00:00',
			)
		);
		$this->n['d'] = $this->item(
			'D',
			array( $this->s['s1'], $this->s['s2'] ),
			array(
				ImportedPost::URL         => 'https://www.example.com/d',
				ImportedPost::IMPORT_DATE => '2031-03-15T00:00:00+00:00',
				ImportedPost::AUTHOR_NAME => 'Bob Reporter',
				ImportedPost::AUDIO_URL   => array( 'https://cdn.example.com/audio/d.mp3' ),
			)
		);
		$this->n['e'] = $this->item( 'E', array(), array( ImportedPost::IMPORT_DATE => '2020-01-01T10:00:00+00:00' ) );
		$this->n['f'] = $this->item(
			'F',
			array( 999999 ),
			array(
				ImportedPost::URL         => 'https://example.com/f',
				ImportedPost::IMPORT_DATE => $iso( $now - 2 * DAY_IN_SECONDS ),
				ImportedPost::SOURCE_NAME => 'Gone Feed',
			)
		);
	}

	public function tear_down(): void {
		set_current_screen( 'front' );
		remove_all_filters( 'corinthian_wpra_post_types' );
		remove_all_filters( 'corinthian_wpra_active' );
		WpraApi::forget_sources();
		parent::tear_down();
	}

	/**
	 * @param list<int>           $sources
	 * @param array<string,mixed> $meta
	 */
	private function item( string $title, array $sources, array $meta, string $status = 'publish' ): int {
		$id = self::factory()->post->create(
			array(
				'post_type'   => WpraApi::ITEM_POST_TYPE,
				'post_title'  => 'Item ' . $title,
				'post_status' => $status,
				'post_author' => $this->admin,
			)
		);
		foreach ( $sources as $source ) {
			add_post_meta( $id, ImportedPost::SOURCE, $source );
		}
		foreach ( $meta as $key => $value ) {
			foreach ( (array) $value as $one ) {
				add_post_meta( $id, $key, $one );
			}
		}
		return $id;
	}

	private function type( string $key ): object {
		$type = $this->registry->get( 'wpra.' . $key );
		$this->assertNotNull( $type, "wpra.$key is registered" );
		return $type;
	}

	private function cfg( string $key ): Config {
		return new Config( 'c1', 'wpra.' . $key, 'x' );
	}

	private function render( string $key, string $item ): string {
		return $this->type( $key )->render( $this->n[ $item ], $this->cfg( $key ), $this->screen );
	}

	/**
	 * @param array<int|string> $ids
	 * @return list<string>
	 */
	private function names( array $ids ): array {
		$flip = array_flip( $this->n );
		return array_map( static fn( $id ) => $flip[ (int) $id ], $ids );
	}

	/**
	 * Runs the rule through a real WP_Query, as the list screen does.
	 *
	 * @return list<string>
	 */
	private function filtered( string $key, string $op, mixed $value ): array {
		$type = $this->type( $key );
		$this->assertInstanceOf( Filterable::class, $type );
		$this->assertContains( $op, array_column( $type->filter_operators( $this->cfg( $key ), $this->screen ), 'op' ), "wpra.$key offers $op" );
		$sql = $type->filter_condition( $this->cfg( $key ), $op, $value, $this->screen, 'clf0' );
		$this->assertNotNull( $sql, "wpra.$key $op was rejected" );

		$callback = static function ( $clauses ) use ( $sql ) {
			$clauses['join']  .= ' ' . $sql['join'];
			$clauses['where'] .= ' AND ' . $sql['where'];
			return $clauses;
		};
		add_filter( 'posts_clauses', $callback );
		$query = new WP_Query(
			array(
				'post_type'        => WpraApi::ITEM_POST_TYPE,
				'post__in'         => array_values( $this->n ),
				'post_status'      => 'any',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'nopaging'         => true,
				'suppress_filters' => false,
			)
		);
		remove_filter( 'posts_clauses', $callback );
		$this->assertSame( '', (string) $GLOBALS['wpdb']->last_error, 'SQL ran without errors' );
		return $this->names( $query->posts );
	}

	/**
	 * @param list<string> $expected
	 */
	private function expect( array $expected, string $key, string $op, mixed $value ): void {
		$this->assertSame( $expected, $this->filtered( $key, $op, $value ), "wpra.$key $op " . wp_json_encode( $value ) );
	}

	/**
	 * @return list<string>
	 */
	private function sorted( string $key, string $order ): array {
		$type = $this->type( $key );
		$this->assertInstanceOf( Sortable::class, $type );
		$config   = $this->cfg( $key );
		$callback = function ( $clauses ) use ( $type, $config, $order ) {
			global $wpdb;
			$clauses            = $type->sort_clauses( $clauses, $config, $order, $this->screen );
			$clauses['orderby'] = trim( ( $clauses['orderby'] ?? '' ) . ", {$wpdb->posts}.ID {$order}", ', ' );
			return $clauses;
		};
		add_filter( 'posts_clauses', $callback );
		$query = new WP_Query(
			array(
				'post_type'        => WpraApi::ITEM_POST_TYPE,
				'post__in'         => array_values( $this->n ),
				'post_status'      => 'any',
				'orderby'          => 'none',
				'fields'           => 'ids',
				'nopaging'         => true,
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);
		remove_filter( 'posts_clauses', $callback );
		$this->assertSame( '', (string) $GLOBALS['wpdb']->last_error, 'SQL ran without errors' );
		return $this->names( $query->posts );
	}

	public function test_loader_registers_every_type_only_when_wpra_is_active(): void {
		foreach ( self::KEYS as $key ) {
			$this->assertNotNull( $this->registry->get( 'wpra.' . $key ), "wpra.$key" );
		}
		add_filter( 'corinthian_wpra_active', '__return_false' );
		$off = new Registry();
		WpraIntegration::add_types( $off );
		$this->assertSame( array(), $off->for_screen( $this->screen ) );
	}

	public function test_types_belong_to_the_feed_items_screen_only(): void {
		$this->assertSame( self::KEYS, array_map( static fn( $k ) => substr( $k, 5 ), array_keys( $this->registry->for_screen( $this->screen ) ) ) );

		$posts = new PostTypeScreen( $this->registry, new SetRepository(), get_post_type_object( 'post' ) );
		$this->assertSame( array(), $this->registry->for_screen( $posts ) );

		add_filter(
			'corinthian_wpra_post_types',
			static fn( array $types ): array => array_merge( $types, array( 'post' ) )
		);
		$this->assertCount( count( self::KEYS ), $this->registry->for_screen( $posts ), 'a site importing into posts can opt in' );
	}

	public function test_source_render_links_to_the_filtered_list_and_names_come_from_wpra(): void {
		$html = $this->render( 'source', 'a' );
		$this->assertStringContainsString( 'post_type=wprss_feed_item', $html );
		$this->assertStringContainsString( 'wpra_source=' . $this->s['s2'], str_replace( '&#038;', '&', $html ) );
		$this->assertStringContainsString( '>Beta Feed</a>', $html );

		$both = $this->render( 'source', 'd' );
		$this->assertStringContainsString( '>Alpha Feed</a>', $both );
		$this->assertStringContainsString( '>Beta Feed</a>', $both );

		$this->assertStringContainsString( '&lt;b&gt;Feed&lt;/b&gt;', $this->render( 'source', 'c' ), 'names are escaped' );
		$this->assertStringContainsString( '>Gone Feed</a>', $this->render( 'source', 'f' ), 'a deleted source falls back to the name saved on the item' );
		$this->assertStringContainsString( '&#8212;', $this->render( 'source', 'e' ) );
		$this->assertSame( array( 'Alpha Feed', 'Beta Feed' ), $this->type( 'source' )->value( $this->n['d'], $this->cfg( 'source' ), $this->screen ) );
	}

	public function test_source_sort(): void {
		$this->assertSame( array( 'b', 'd', 'a', 'c', 'e', 'f' ), $this->sorted( 'source', 'ASC' ) );
		$this->assertSame( array( 'c', 'a', 'd', 'b', 'f', 'e' ), $this->sorted( 'source', 'DESC' ) );
	}

	public function test_source_filter(): void {
		$this->expect( array( 'b', 'd' ), 'source', 'in', (string) $this->s['s1'] );
		$this->expect( array( 'a', 'd' ), 'source', 'in', array( (string) $this->s['s2'] ) );
		$this->expect( array( 'a', 'b', 'c', 'd' ), 'source', 'in', array( (string) $this->s['s1'], (string) $this->s['s2'], (string) $this->s['s3'] ) );
		$this->expect( array( 'a', 'c', 'e', 'f' ), 'source', 'not_in', array( (string) $this->s['s1'] ) );
	}

	public function test_source_filter_offers_the_sources_to_those_who_may_see_them(): void {
		$ops = $this->type( 'source' )->filter_operators( $this->cfg( 'source' ), $this->screen );
		$this->assertSame( (string) $this->s['s1'], (string) array_key_first( array_filter( $ops[0]['options'], static fn( $name ) => 'Alpha Feed' === $name ) ) );
		$this->assertCount( 3, $ops[0]['options'] );
	}

	public function test_original_link_render_sort_and_filters(): void {
		$html = $this->render( 'link', 'a' );
		$this->assertStringContainsString( 'href="https://example.com/news/a-article?utm=1"', $html );
		$this->assertStringContainsString( '>example.com/news/a-article?utm=1</a>', $html );
		$this->assertStringContainsString( '>example.com/d</a>', $this->render( 'link', 'd' ), 'scheme and www are not shown' );
		$this->assertStringContainsString( '&#8212;', $this->render( 'link', 'e' ) );

		$this->assertSame( array( 'f', 'a', 'c', 'b', 'd', 'e' ), $this->sorted( 'link', 'ASC' ) );
		$this->assertSame( array( 'd', 'b', 'c', 'a', 'f', 'e' ), $this->sorted( 'link', 'DESC' ) );
		$this->expect( array( 'b' ), 'link', 'contains', 'example.org' );
		$this->expect( array( 'a', 'c', 'd', 'e', 'f' ), 'link', 'not_contains', 'example.org' );
		$this->expect( array( 'b' ), 'link', 'eq', 'https://example.org/b' );
		$this->expect( array( 'a', 'f' ), 'link', 'starts', 'https://example.com' );
		$this->expect( array( 'e' ), 'link', 'empty', null );
		$this->expect( array( 'a', 'b', 'c', 'd', 'f' ), 'link', 'not_empty', null );
	}

	public function test_a_javascript_link_is_not_rendered_as_a_link(): void {
		update_post_meta( $this->n['e'], ImportedPost::URL, 'javascript:alert(1)' );
		$html = $this->render( 'link', 'e' );
		$this->assertStringNotContainsString( '<a ', $html );
		$this->assertStringNotContainsString( 'href', $html );
	}

	public function test_import_date_render_sort_and_filters(): void {
		$this->assertSame( '2031-03-10 09:00', $this->render( 'imported', 'a' ) );
		$this->assertStringContainsString( '&#8212;', $this->renderEmptyDate() );

		$this->assertSame( array( 'e', 'f', 'a', 'c', 'b', 'd' ), $this->sorted( 'imported', 'ASC' ) );
		$this->assertSame( array( 'd', 'b', 'c', 'a', 'f', 'e' ), $this->sorted( 'imported', 'DESC' ) );
		$this->expect( array( 'a', 'e', 'f' ), 'imported', 'before', '2031-03-12' );
		$this->expect( array( 'd' ), 'imported', 'after', '2031-03-12' );
		$this->expect( array( 'f' ), 'imported', 'within_days', '5' );
		$this->expect( array( 'e' ), 'imported', 'older_than_days', '365' );
		$this->assertNull( $this->type( 'imported' )->filter_condition( $this->cfg( 'imported' ), 'before', '2031-13-40', $this->screen, 'x' ) );
	}

	private function renderEmptyDate(): string {
		$id = self::factory()->post->create( array( 'post_type' => WpraApi::ITEM_POST_TYPE ) );
		return $this->type( 'imported' )->render( $id, $this->cfg( 'imported' ), $this->screen );
	}

	public function test_import_date_filter_follows_the_site_timezone_for_day_boundaries(): void {
		update_option( 'timezone_string', 'Pacific/Auckland' );
		// Midnight on 12 March in Auckland (UTC+13 in March 2031) is 11 March 11:00 UTC.
		$this->expect( array( 'a', 'e', 'f' ), 'imported', 'before', '2031-03-12' );
		update_post_meta( $this->n['c'], ImportedPost::IMPORT_DATE, '2031-03-11T10:59:59+00:00' );
		$this->expect( array( 'a', 'c', 'e', 'f' ), 'imported', 'before', '2031-03-12' );
	}

	public function test_feed_author_render_sort_and_filters(): void {
		$this->assertSame( 'Zed Writer', $this->render( 'author', 'a' ) );
		$this->assertStringContainsString( '&#8212;', $this->render( 'author', 'c' ) );

		$this->assertSame( array( 'b', 'd', 'a', 'c', 'e', 'f' ), $this->sorted( 'author', 'ASC' ) );
		$this->assertSame( array( 'a', 'd', 'b', 'f', 'e', 'c' ), $this->sorted( 'author', 'DESC' ) );
		$this->expect( array( 'b' ), 'author', 'contains', 'Author' );
		$this->expect( array( 'c', 'e', 'f' ), 'author', 'empty', null );
		$this->expect( array( 'a', 'b', 'd' ), 'author', 'not_empty', null );
	}

	public function test_source_website_render_and_filter(): void {
		$html = $this->render( 'source_site', 'a' );
		$this->assertStringContainsString( 'href="https://beta.example.org/"', $html );
		$this->assertStringContainsString( '>beta.example.org</a>', $html );
		$this->expect( array( 'b' ), 'source_site', 'contains', 'alpha' );
		$this->assertSame( array( 'b', 'a', 'c', 'd', 'e', 'f' ), $this->sorted( 'source_site', 'ASC' ) );
	}

	public function test_media_render_and_filter_cover_audio_and_enclosures(): void {
		$this->assertStringContainsString( '>a.mp3</a>', $this->render( 'media', 'a' ) );
		$this->assertStringContainsString( 'href="https://cdn.example.com/audio/d.mp3"', $this->render( 'media', 'd' ) );
		$this->assertStringContainsString( '&#8212;', $this->render( 'media', 'b' ) );
		$this->expect( array( 'a', 'd' ), 'media', 'not_empty', null );
		$this->expect( array( 'b', 'c', 'e', 'f' ), 'media', 'empty', null );
		$this->assertNull( $this->type( 'media' )->filter_condition( $this->cfg( 'media' ), 'contains', 'x', $this->screen, 'x' ) );
	}

	public function test_a_user_who_cannot_read_an_item_gets_none_of_its_data(): void {
		$private = $this->item(
			'Private',
			array( $this->s['s1'] ),
			array(
				ImportedPost::URL         => 'https://example.com/private',
				ImportedPost::AUTHOR_NAME => 'Hidden Person',
				ImportedPost::IMPORT_DATE => '2031-03-20T00:00:00+00:00',
			),
			'private'
		);
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		wp_set_current_user( $this->admin );
		foreach ( array( 'source', 'link', 'imported', 'author' ) as $key ) {
			$this->assertNotNull( $this->type( $key )->value( $private, $this->cfg( $key ), $this->screen ), "Control: the owner sees $key." );
		}

		wp_set_current_user( $subscriber );
		foreach ( self::KEYS as $key ) {
			$this->assertNull( $this->type( $key )->value( $private, $this->cfg( $key ), $this->screen ), "$key must not leak." );
			$this->assertStringContainsString( '&#8212;', $this->type( $key )->render( $private, $this->cfg( $key ), $this->screen ) );
		}
	}

	public function test_source_names_are_offered_only_to_users_who_may_see_the_aggregator(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );

		wp_set_current_user( $this->admin );
		$this->assertCount( 3, WpraApi::source_options(), 'Control: administrators hold see_aggregator.' );

		wp_set_current_user( $editor );
		$this->assertFalse( current_user_can( WpraApi::SEE_AGGREGATOR ), 'Control: editors do not.' );
		$this->assertSame( array(), WpraApi::source_options() );
		$ops = $this->type( 'source' )->filter_operators( $this->cfg( 'source' ), $this->screen );
		$this->assertSame( array(), $ops[0]['options'] );
		$this->assertStringContainsString( '>Alpha Feed</a>', $this->type( 'source' )->render( $this->n['b'], $this->cfg( 'source' ), $this->screen ), 'WPRA shows the source of an item it lists to anyone who can list it.' );
	}
}
