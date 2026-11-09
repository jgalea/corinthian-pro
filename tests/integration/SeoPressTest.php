<?php

namespace CorinthianPro\Tests;

use Corinthian\Columns\Config;
use Corinthian\Columns\Editable;
use Corinthian\Columns\Filterable;
use Corinthian\Columns\Registry;
use Corinthian\Columns\Sortable;
use CorinthianPro\Integrations\SeoPress\SeoPressIntegration;
use Corinthian\Screens\PostTypeScreen;
use Corinthian\Sets\SetRepository;
use WP_Error;
use WP_Query;
use WP_UnitTestCase;

/**
 * Runs against a real SEOPress, including its Advanced > Security role
 * restrictions, which drop writes from blocked roles without any error.
 */
class SeoPressTest extends WP_UnitTestCase {

	private const KEYS = array(
		'title',
		'meta_description',
		'target_keywords',
		'noindex',
		'nofollow',
		'canonical',
		'primary_category',
	);

	private const SECURITY_OPTION = 'seopress_advanced_option_name';

	private static bool $loaded = false;
	private static bool $hooks_kept = false;

	private Registry $registry;
	private PostTypeScreen $screen;

	/** @var array<string,int> */
	private array $n = array();
	/** @var array<string,int> */
	private array $cat = array();

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		$main = WP_PLUGIN_DIR . '/wp-seopress/seopress.php';
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			self::$loaded = true;
			return;
		}
		if ( ! file_exists( $main ) ) {
			return;
		}
		// SEOPress hooks itself to plugins_loaded, which has already run, so boot it by hand.
		require_once $main;
		// The kernel only wires its hooks while current_filter() is plugins_loaded.
		$GLOBALS['wp_current_filter'][] = 'plugins_loaded';
		\SEOPress\Core\Kernel::handleHooksPlugin();
		array_pop( $GLOBALS['wp_current_filter'] );
		seopress_plugins_loaded( '' );
		self::$loaded = true;
	}

	public function set_up(): void {
		parent::set_up();
		if ( self::$loaded && ! self::$hooks_kept ) {
			// The suite snapshots hooks once per run, so the hooks SEOPress added when it booted would be wiped after the first test.
			$this->_backup_hooks();
			self::$hooks_kept = true;
		}
		if ( ! self::$loaded ) {
			$this->markTestSkipped( 'SEOPress is not installed in this environment.' );
		}
		add_filter( 'corinthian_seopress_active', '__return_true' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->registry = new Registry();
		SeoPressIntegration::add_types( $this->registry );
		$this->screen = new PostTypeScreen( $this->registry, new SetRepository(), get_post_type_object( 'post' ) );

		foreach ( array( 'one', 'two', 'unused' ) as $name ) {
			$this->cat[ $name ] = self::factory()->category->create( array( 'name' => 'Cat ' . $name ) );
		}
		foreach ( array( 'A', 'B', 'C', 'D', 'E' ) as $i => $name ) {
			$this->n[ $name ] = self::factory()->post->create(
				array(
					'post_title' => "Post $name",
					'post_name'  => 'sp-' . strtolower( $name ),
					'post_date'  => '2024-01-0' . ( $i + 1 ) . ' 10:00:00',
				)
			);
		}

		wp_set_post_categories( $this->n['A'], array( $this->cat['one'], $this->cat['two'] ) );
		$this->seed(
			'A',
			array(
				'_seopress_titles_title'       => 'Alpha title',
				'_seopress_titles_desc'        => 'Desc alpha',
				'_seopress_analysis_target_kw' => 'alpha,beta',
				'_seopress_robots_index'       => 'yes',
				'_seopress_robots_follow'      => 'yes',
				'_seopress_robots_canonical'   => 'https://example.com/a',
				'_seopress_robots_primary_cat' => (string) $this->cat['one'],
			)
		);
		wp_set_post_categories( $this->n['B'], array( $this->cat['one'], $this->cat['two'] ) );
		$this->seed(
			'B',
			array(
				'_seopress_titles_title'       => 'Gamma title',
				'_seopress_titles_desc'        => 'Desc beta',
				'_seopress_analysis_target_kw' => 'gamma',
				'_seopress_robots_canonical'   => 'https://other.test/b',
				'_seopress_robots_primary_cat' => (string) $this->cat['two'],
			)
		);
		$this->seed( 'C', array( '_seopress_analysis_target_kw' => 'alpha two' ) );
		wp_cache_flush();
	}

	/**
	 * @param array<string,string> $values
	 */
	private function seed( string $post, array $values ): void {
		foreach ( $values as $key => $value ) {
			update_post_meta( $this->n[ $post ], $key, $value );
		}
	}

	private function type( string $key ): object {
		$type = $this->registry->get( 'seopress.' . $key );
		$this->assertNotNull( $type, "seopress.$key is registered" );
		return $type;
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private function cfg( string $key, array $settings = array() ): Config {
		return new Config( 'c1', 'seopress.' . $key, 'x', null, 'px', $settings );
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private function render( string $key, string $post, array $settings = array() ): string {
		return $this->type( $key )->render( $this->n[ $post ], $this->cfg( $key, $settings ), $this->screen );
	}

	/**
	 * Runs the rule through a real WP_Query, as the list screen does.
	 *
	 * @param array<string,mixed> $settings
	 * @return list<string>
	 */
	private function filtered( string $key, string $op, mixed $value, array $settings = array() ): array {
		$type = $this->type( $key );
		$this->assertInstanceOf( Filterable::class, $type );
		$config = $this->cfg( $key, $settings );
		$this->assertContains( $op, array_column( $type->filter_operators( $config, $this->screen ), 'op' ), "seopress.$key offers $op" );
		$sql = $type->filter_condition( $config, $op, $value, $this->screen, 'clf0' );
		$this->assertNotNull( $sql, "seopress.$key $op was rejected" );

		$callback = static function ( $clauses ) use ( $sql ) {
			$clauses['join']  .= ' ' . $sql['join'];
			$clauses['where'] .= ' AND ' . $sql['where'];
			return $clauses;
		};
		add_filter( 'posts_clauses', $callback );
		$query = new WP_Query(
			array(
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

		$flip = array_flip( $this->n );
		return array_map( static fn( $id ) => $flip[ (int) $id ], $query->posts );
	}

	/**
	 * @param list<string>        $expected
	 * @param array<string,mixed> $settings
	 */
	private function expect( array $expected, string $key, string $op, mixed $value, array $settings = array() ): void {
		$this->assertSame( $expected, $this->filtered( $key, $op, $value, $settings ), "seopress.$key $op " . wp_json_encode( $value ) );
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
		$flip = array_flip( $this->n );
		return array_map( static fn( $id ) => $flip[ (int) $id ], $query->posts );
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private function save( string $key, string $post, mixed $value, string $mode = 'set', array $settings = array() ): bool|WP_Error {
		$type = $this->type( $key );
		$this->assertInstanceOf( Editable::class, $type );
		return $type->save( $this->n[ $post ], $this->cfg( $key, $settings ), $this->screen, $value, $mode );
	}

	private function meta( string $post, string $key ): mixed {
		wp_cache_flush();
		return get_post_meta( $this->n[ $post ], $key, true );
	}

	private function has_meta( string $post, string $key ): bool {
		wp_cache_flush();
		return metadata_exists( 'post', $this->n[ $post ], $key );
	}

	private function as_subscriber(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
	}

	/**
	 * @param list<string> $areas 'GLOBAL' and/or 'CONTENT_ANALYSIS'
	 */
	private function block_editors( array $areas ): void {
		$option = array();
		foreach ( $areas as $area ) {
			$name            = 'GLOBAL' === $area ? 'seopress_advanced_security_metaboxe_role' : 'seopress_advanced_security_metaboxe_ca_role';
			$option[ $name ] = array( 'editor' => '1' );
		}
		update_option( self::SECURITY_OPTION, $option );
	}

	public function test_loader_registers_every_type_only_when_seopress_is_active(): void {
		$this->assertCount( 7, self::KEYS );
		foreach ( self::KEYS as $key ) {
			$this->assertNotNull( $this->registry->get( 'seopress.' . $key ), $key );
		}

		remove_filter( 'corinthian_seopress_active', '__return_true' );
		add_filter( 'corinthian_seopress_active', '__return_false' );
		$empty = new Registry();
		SeoPressIntegration::add_types( $empty );
		$this->assertNull( $empty->get( 'seopress.title' ) );

		SeoPressIntegration::register();
		$this->assertNotFalse( has_action( 'corinthian_register_types', array( SeoPressIntegration::class, 'add_types' ) ) );
	}

	public function test_detection_follows_the_loaded_plugin(): void {
		remove_filter( 'corinthian_seopress_active', '__return_true' );
		$this->assertTrue( SeoPressIntegration::is_active(), 'SEOPress is loaded in this run' );
	}

	public function test_post_type_support_follows_seopresss_metabox_list(): void {
		$page = new PostTypeScreen( $this->registry, new SetRepository(), get_post_type_object( 'page' ) );
		$type = $this->type( 'title' );
		$this->assertTrue( $type->supports( $this->screen ) );
		$this->assertTrue( $type->supports( $page ) );

		add_filter(
			'seopress_metaboxe_seo',
			static function ( $types ) {
				unset( $types['page'] );
				return $types;
			}
		);
		$this->assertFalse( $type->supports( $page ) );
		$this->assertTrue( $type->supports( $this->screen ) );

		register_post_type( 'cln_private', array( 'public' => false ) );
		$private = new PostTypeScreen( $this->registry, new SetRepository(), get_post_type_object( 'cln_private' ) );
		$this->assertFalse( $type->supports( $private ) );
		unregister_post_type( 'cln_private' );
	}

	public function test_title_render_sort_filters_and_edit(): void {
		$this->assertSame( 'Alpha title', $this->render( 'title', 'A' ) );
		$this->assertStringContainsString( '&#8212;', $this->render( 'title', 'C' ) );
		$this->assertSame( array( 'A', 'B', 'C', 'D', 'E' ), $this->sorted( 'title', 'ASC' ) );
		$this->assertSame( array( 'B', 'A', 'E', 'D', 'C' ), $this->sorted( 'title', 'DESC' ) );
		$this->expect( array( 'A' ), 'title', 'contains', 'Alpha' );
		$this->expect( array( 'A', 'B' ), 'title', 'contains', 'title' );
		$this->expect( array( 'C', 'D', 'E' ), 'title', 'empty', null );
		$this->expect( array(), 'title', 'contains', 'nomatch' );

		$this->assertTrue( $this->save( 'title', 'C', "  <b>Hello</b>\n  %%sitetitle%%  " ) );
		$this->assertSame( 'Hello %%sitetitle%%', $this->meta( 'C', '_seopress_titles_title' ) );
		$this->assertSame( 'Hello %%sitetitle%%', $this->type( 'title' )->edit_value( $this->n['C'], $this->cfg( 'title' ), $this->screen ) );
		$this->assertWPError( $this->save( 'title', 'C', array( 'x' ) ) );
		$this->assertWPError( $this->save( 'title', 'C', '<script>x</script>' ) );
		$this->assertWPError( $this->save( 'title', 'C', 'x', 'append' ) );
		$this->assertSame( 'Hello %%sitetitle%%', $this->meta( 'C', '_seopress_titles_title' ), 'rejected edits leave the value alone' );
		$this->assertTrue( $this->save( 'title', 'C', '' ) );
		$this->assertFalse( $this->has_meta( 'C', '_seopress_titles_title' ) );
		$this->assertTrue( $this->save( 'title', 'A', null, 'clear' ) );
		$this->assertFalse( $this->has_meta( 'A', '_seopress_titles_title' ) );
	}

	public function test_meta_description_render_sort_filters_and_edit(): void {
		$this->assertSame( 'Desc alpha', $this->render( 'meta_description', 'A' ) );
		$this->assertStringContainsString( '&#8212;', $this->render( 'meta_description', 'D' ) );
		$this->assertSame( array( 'A', 'B', 'C', 'D', 'E' ), $this->sorted( 'meta_description', 'ASC' ) );
		$this->expect( array( 'A', 'B' ), 'meta_description', 'contains', 'Desc' );
		$this->expect( array( 'B' ), 'meta_description', 'contains', 'beta' );
		$this->expect( array( 'C', 'D', 'E' ), 'meta_description', 'empty', null );
		$this->expect( array(), 'meta_description', 'contains', 'nomatch' );

		$this->assertSame( 'textarea', $this->type( 'meta_description' )->edit_field( $this->cfg( 'meta_description' ), $this->screen )['input'] );
		$this->assertTrue( $this->save( 'meta_description', 'D', " Line <i>one</i>\nline two " ) );
		$this->assertSame( "Line one\nline two", $this->meta( 'D', '_seopress_titles_desc' ) );
		$this->assertWPError( $this->save( 'meta_description', 'D', array() ) );
		$this->assertWPError( $this->save( 'meta_description', 'D', '<p></p>' ) );
		$this->assertSame( "Line one\nline two", $this->meta( 'D', '_seopress_titles_desc' ) );
		$this->assertTrue( $this->save( 'meta_description', 'D', null, 'clear' ) );
		$this->assertFalse( $this->has_meta( 'D', '_seopress_titles_desc' ) );
	}

	public function test_target_keywords_render_sort_filters_and_edit(): void {
		$this->assertSame( 'alpha, beta', $this->render( 'target_keywords', 'A' ) );
		$this->assertStringContainsString( '&#8212;', $this->render( 'target_keywords', 'D' ) );
		$this->assertSame( array( 'C', 'A', 'B', 'D', 'E' ), $this->sorted( 'target_keywords', 'ASC' ) );
		$this->expect( array( 'A', 'C' ), 'target_keywords', 'contains', 'alpha' );
		$this->expect( array( 'A' ), 'target_keywords', 'contains', 'beta' );
		$this->expect( array( 'D', 'E' ), 'target_keywords', 'empty', null );
		$this->expect( array(), 'target_keywords', 'contains', 'nomatch' );

		$this->assertTrue( $this->save( 'target_keywords', 'D', ' Alpha , beta ,, ALPHA, gamma ' ) );
		$this->assertSame( 'Alpha,beta,gamma', $this->meta( 'D', '_seopress_analysis_target_kw' ) );
		$this->assertSame( 'Alpha, beta, gamma', $this->type( 'target_keywords' )->edit_value( $this->n['D'], $this->cfg( 'target_keywords' ), $this->screen ) );
		$this->assertWPError( $this->save( 'target_keywords', 'D', array( 'x' ) ) );
		$this->assertWPError( $this->save( 'target_keywords', 'D', '<b></b>' ) );
		$this->assertSame( 'Alpha,beta,gamma', $this->meta( 'D', '_seopress_analysis_target_kw' ) );
		$this->assertTrue( $this->save( 'target_keywords', 'D', ' , ' ) );
		$this->assertFalse( $this->has_meta( 'D', '_seopress_analysis_target_kw' ) );
		$this->assertTrue( $this->save( 'target_keywords', 'A', null, 'clear' ) );
		$this->assertFalse( $this->has_meta( 'A', '_seopress_analysis_target_kw' ) );
	}

	public function test_noindex_render_sort_filters_and_edit(): void {
		$this->assertStringContainsString( 'Noindex', $this->render( 'noindex', 'A' ) );
		$this->assertStringContainsString( '&#8212;', $this->render( 'noindex', 'B' ) );
		$this->assertSame( array( 'A', 'E', 'D', 'C', 'B' ), $this->sorted( 'noindex', 'DESC' ) );
		$this->expect( array( 'A' ), 'noindex', 'eq', '1' );
		$this->expect( array( 'B', 'C', 'D', 'E' ), 'noindex', 'eq', '0' );
		$this->assertNull( $this->type( 'noindex' )->filter_condition( $this->cfg( 'noindex' ), 'eq', 'maybe', $this->screen, 'clf0' ) );

		$this->assertSame( 'toggle', $this->type( 'noindex' )->edit_field( $this->cfg( 'noindex' ), $this->screen )['input'] );
		$this->assertTrue( $this->save( 'noindex', 'B', true ) );
		$this->assertSame( 'yes', $this->meta( 'B', '_seopress_robots_index' ), "SEOPress's 'yes' on the index key means noindex" );
		$this->assertSame( '1', $this->type( 'noindex' )->edit_value( $this->n['B'], $this->cfg( 'noindex' ), $this->screen ) );
		$this->expect( array( 'A', 'B' ), 'noindex', 'eq', '1' );

		$this->assertTrue( $this->save( 'noindex', 'B', '0' ) );
		$this->assertFalse( $this->has_meta( 'B', '_seopress_robots_index' ) );
		$this->assertTrue( $this->save( 'noindex', 'A', null, 'clear' ) );
		$this->assertFalse( $this->has_meta( 'A', '_seopress_robots_index' ) );
		$this->expect( array(), 'noindex', 'eq', '1' );

		$this->assertWPError( $this->save( 'noindex', 'C', 'sometimes' ) );
		$this->assertWPError( $this->save( 'noindex', 'C', array( '1' ) ) );
		$this->assertFalse( $this->has_meta( 'C', '_seopress_robots_index' ) );
	}

	public function test_nofollow_render_sort_filters_and_edit(): void {
		$this->assertStringContainsString( 'Nofollow', $this->render( 'nofollow', 'A' ) );
		$this->assertStringContainsString( '&#8212;', $this->render( 'nofollow', 'B' ) );
		$this->assertSame( array( 'A', 'E', 'D', 'C', 'B' ), $this->sorted( 'nofollow', 'DESC' ) );
		$this->expect( array( 'A' ), 'nofollow', 'eq', '1' );
		$this->expect( array( 'B', 'C', 'D', 'E' ), 'nofollow', 'eq', '0' );

		$this->assertTrue( $this->save( 'nofollow', 'B', '1' ) );
		$this->assertSame( 'yes', $this->meta( 'B', '_seopress_robots_follow' ) );
		$this->expect( array( 'A', 'B' ), 'nofollow', 'eq', '1' );
		$this->assertTrue( $this->save( 'nofollow', 'B', false ) );
		$this->assertTrue( $this->save( 'nofollow', 'A', null, 'clear' ) );
		$this->expect( array(), 'nofollow', 'eq', '1' );
		$this->assertWPError( $this->save( 'nofollow', 'C', 'sometimes' ) );
		$this->assertFalse( $this->has_meta( 'C', '_seopress_robots_follow' ) );
	}

	public function test_canonical_render_sort_filters_and_edit(): void {
		$this->assertStringContainsString( 'href="https://example.com/a"', $this->render( 'canonical', 'A' ) );
		$this->assertStringContainsString( '&#8212;', $this->render( 'canonical', 'C' ) );
		$this->assertSame( array( 'A', 'B', 'C', 'D', 'E' ), $this->sorted( 'canonical', 'ASC' ) );
		$this->expect( array( 'A' ), 'canonical', 'contains', 'example.com' );
		$this->expect( array( 'C', 'D', 'E' ), 'canonical', 'empty', null );
		$this->expect( array(), 'canonical', 'contains', 'nomatch' );

		$this->assertTrue( $this->save( 'canonical', 'C', ' https://example.com/x?y=1&z=2 ' ) );
		$this->assertSame( 'https://example.com/x?y=1&z=2', $this->meta( 'C', '_seopress_robots_canonical' ) );
		foreach ( array( 'javascript:alert(1)', 'example.com/page', '/relative', 'ftp://example.com/a', 'http://', array( 'https://example.com' ) ) as $bad ) {
			$result = $this->save( 'canonical', 'C', $bad );
			$this->assertWPError( $result, wp_json_encode( $bad ) );
			$this->assertSame( 'corinthian_invalid', $result->get_error_code() );
		}
		$this->assertSame( 'https://example.com/x?y=1&z=2', $this->meta( 'C', '_seopress_robots_canonical' ) );
		$this->assertTrue( $this->save( 'canonical', 'C', '' ) );
		$this->assertFalse( $this->has_meta( 'C', '_seopress_robots_canonical' ) );
	}

	public function test_primary_category_render_filters_and_edit(): void {
		$this->assertSame( 'Cat one', $this->render( 'primary_category', 'A' ) );
		$this->assertSame( 'Cat two', $this->render( 'primary_category', 'B' ) );
		$this->assertStringContainsString( '&#8212;', $this->render( 'primary_category', 'C' ) );
		$this->expect( array( 'A' ), 'primary_category', 'eq', (string) $this->cat['one'] );
		$this->expect( array( 'B' ), 'primary_category', 'eq', (string) $this->cat['two'] );
		$this->expect( array(), 'primary_category', 'eq', (string) $this->cat['unused'] );

		$this->assertTrue( $this->save( 'primary_category', 'A', (string) $this->cat['two'] ) );
		$this->assertSame( (string) $this->cat['two'], $this->meta( 'A', '_seopress_robots_primary_cat' ), 'categories on posts use the legacy key' );
		foreach ( array( (string) $this->cat['unused'], 'abc', '', '0', array( '1' ) ) as $bad ) {
			$result = $this->save( 'primary_category', 'A', $bad );
			$this->assertWPError( $result, wp_json_encode( $bad ) );
			$this->assertSame( 'corinthian_invalid', $result->get_error_code() );
		}
		$this->assertWPError( $this->save( 'primary_category', 'C', (string) $this->cat['one'] ), 'C is not in that category' );
		$this->assertSame( (string) $this->cat['two'], $this->meta( 'A', '_seopress_robots_primary_cat' ) );
		$this->assertTrue( $this->save( 'primary_category', 'A', null, 'clear' ) );
		$this->assertFalse( $this->has_meta( 'A', '_seopress_robots_primary_cat' ) );
	}

	public function test_primary_category_uses_seopresss_key_for_other_taxonomies(): void {
		register_taxonomy(
			'cln_topic',
			'post',
			array(
				'hierarchical' => true,
				'public'       => true,
				'show_ui'      => true,
			)
		);
		$topic = self::factory()->term->create(
			array(
				'taxonomy' => 'cln_topic',
				'name'     => 'Topic',
			)
		);
		wp_set_object_terms( $this->n['C'], array( $topic ), 'cln_topic' );
		$settings = array( 'taxonomy' => 'cln_topic' );

		$this->assertTrue( $this->save( 'primary_category', 'C', (string) $topic, 'set', $settings ) );
		$this->assertSame( (string) $topic, $this->meta( 'C', '_seopress_robots_primary_term_cln_topic' ) );
		$this->assertFalse( $this->has_meta( 'C', '_seopress_robots_primary_cat' ) );
		$this->assertSame( 'Topic', $this->render( 'primary_category', 'C', $settings ) );
		$this->expect( array( 'C' ), 'primary_category', 'eq', (string) $topic, $settings );
		unregister_taxonomy( 'cln_topic' );
	}

	public function test_a_role_blocked_by_seopress_is_refused_where_seopress_drops_the_write(): void {
		$this->block_editors( array( 'GLOBAL' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		update_post_meta( $this->n['A'], '_seopress_titles_title', 'Dropped silently' );
		$this->assertSame( 'Alpha title', $this->meta( 'A', '_seopress_titles_title' ), "SEOPress itself discards this role's write without an error" );

		$inputs = array(
			'title'            => 'x',
			'meta_description' => 'x',
			'noindex'          => '1',
			'nofollow'         => '1',
			'canonical'        => 'https://example.com/z',
			'primary_category' => (string) $this->cat['two'],
		);
		foreach ( $inputs as $key => $input ) {
			$result = $this->save( $key, 'A', $input );
			$this->assertWPError( $result, $key );
			$this->assertSame( 'corinthian_protected', $result->get_error_code(), $key );
			$this->assertWPError( $this->save( $key, 'A', null, 'clear' ), "$key clear" );
		}
		$this->assertSame( 'Alpha title', $this->meta( 'A', '_seopress_titles_title' ) );
		$this->assertSame( 'yes', $this->meta( 'A', '_seopress_robots_index' ) );
		$this->assertSame( (string) $this->cat['one'], $this->meta( 'A', '_seopress_robots_primary_cat' ) );
		$this->assertTrue( $this->save( 'target_keywords', 'A', 'kept' ), 'control: the content analysis area is not blocked' );

		update_option( self::SECURITY_OPTION, array() );
		$this->assertTrue( $this->save( 'title', 'A', 'Editor title' ), 'control: the same editor with no restriction' );
		$this->assertSame( 'Editor title', $this->meta( 'A', '_seopress_titles_title' ) );
	}

	public function test_target_keywords_follow_the_content_analysis_restriction(): void {
		$this->block_editors( array( 'CONTENT_ANALYSIS' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$result = $this->save( 'target_keywords', 'A', 'x' );
		$this->assertWPError( $result );
		$this->assertSame( 'corinthian_protected', $result->get_error_code() );
		$this->assertWPError( $this->save( 'target_keywords', 'A', null, 'clear' ) );
		$this->assertSame( 'alpha,beta', $this->meta( 'A', '_seopress_analysis_target_kw' ) );
		$this->assertTrue( $this->save( 'title', 'A', 'Allowed' ), 'control: the metabox area is not blocked' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( $this->save( 'target_keywords', 'A', 'admin' ), 'control: administrators are never blocked' );
	}

	public function test_a_user_who_cannot_edit_the_post_is_refused_every_field(): void {
		$this->as_subscriber();
		$inputs = array(
			'title'            => 'x',
			'meta_description' => 'x',
			'target_keywords'  => 'x',
			'noindex'          => '1',
			'nofollow'         => '1',
			'canonical'        => 'https://example.com/z',
			'primary_category' => (string) $this->cat['two'],
		);
		foreach ( $inputs as $key => $input ) {
			$result = $this->save( $key, 'A', $input );
			$this->assertWPError( $result, $key );
			$this->assertSame( 'corinthian_protected', $result->get_error_code(), $key );
			$this->assertWPError( $this->save( $key, 'A', null, 'clear' ), "$key clear" );
		}
		$this->assertSame( 'Alpha title', $this->meta( 'A', '_seopress_titles_title' ) );
		$this->assertSame( 'alpha,beta', $this->meta( 'A', '_seopress_analysis_target_kw' ) );
		$this->assertSame( 'yes', $this->meta( 'A', '_seopress_robots_follow' ) );
	}

	public function test_two_rules_on_one_column_do_not_collide(): void {
		$type   = $this->type( 'target_keywords' );
		$config = $this->cfg( 'target_keywords' );
		$one    = $type->filter_condition( $config, 'contains', 'alpha', $this->screen, 'clf0' );
		$two    = $type->filter_condition( $config, 'contains', 'two', $this->screen, 'clf1' );
		$this->assertNotNull( $one );
		$this->assertNotNull( $two );
		$callback = static function ( $clauses ) use ( $one, $two ) {
			$clauses['where'] .= ' AND ' . $one['where'] . ' AND ' . $two['where'];
			return $clauses;
		};
		add_filter( 'posts_clauses', $callback );
		$query = new WP_Query(
			array(
				'post__in'         => array_values( $this->n ),
				'post_status'      => 'any',
				'fields'           => 'ids',
				'nopaging'         => true,
				'suppress_filters' => false,
			)
		);
		remove_filter( 'posts_clauses', $callback );
		$this->assertSame( array( $this->n['C'] ), array_map( 'intval', $query->posts ) );
	}
}
