<?php

namespace CorinthianPro\Tests;

use Corinthian\Columns\Config;
use Corinthian\Columns\Editable;
use Corinthian\Columns\Filterable;
use Corinthian\Columns\Registry;
use Corinthian\Columns\Sortable;
use CorinthianPro\Integrations\RankMath\RankMathIntegration;
use Corinthian\Screens\PostTypeScreen;
use Corinthian\Sets\SetRepository;
use WP_Error;
use WP_Query;
use WP_UnitTestCase;

class RankMathTest extends WP_UnitTestCase {

	private const KEYS = array(
		'seo_score',
		'focus_keyword',
		'seo_title',
		'meta_description',
		'robots',
		'canonical_url',
		'pillar_content',
		'primary_category',
		'schema_type',
	);

	private Registry $registry;
	private PostTypeScreen $screen;

	/** @var array<string,int> */
	private array $n = array();
	/** @var array<string,int> */
	private array $cat = array();

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( 'RankMath\\Helper', false ) ) {
			// Stand-in for Rank Math's variable replacer, which only exists inside a running Rank Math.
			eval( 'namespace RankMath; class Helper { public static function replace_vars( $content, $args = array() ) { return str_replace( array( "%title%", "%sep%", "%sitename%" ), array( $args->post_title, "-", "Site" ), $content ); } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		}
		add_filter( 'corinthian_rankmath_active', '__return_true' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->registry = new Registry();
		RankMathIntegration::add_types( $this->registry );
		$this->screen = new PostTypeScreen( $this->registry, new SetRepository(), get_post_type_object( 'post' ) );

		foreach ( array( 'one', 'two', 'three', 'unused' ) as $name ) {
			$this->cat[ $name ] = self::factory()->category->create( array( 'name' => 'Cat ' . $name ) );
		}
		foreach ( array( 'A', 'B', 'C', 'D', 'E' ) as $i => $name ) {
			$this->n[ $name ] = self::factory()->post->create(
				array(
					'post_title' => "Post $name",
					'post_name'  => 'rm-' . strtolower( $name ),
					'post_date'  => '2024-01-0' . ( $i + 1 ) . ' 10:00:00',
				)
			);
		}

		$a = $this->n['A'];
		update_post_meta( $a, 'rank_math_seo_score', '90' );
		update_post_meta( $a, 'rank_math_focus_keyword', 'alpha,beta' );
		update_post_meta( $a, 'rank_math_title', '%title% %sep% %sitename%' );
		update_post_meta( $a, 'rank_math_description', 'Desc alpha' );
		update_post_meta( $a, 'rank_math_robots', array( 'noindex', 'nofollow' ) );
		update_post_meta( $a, 'rank_math_canonical_url', 'https://example.com/a' );
		update_post_meta( $a, 'rank_math_pillar_content', 'on' );
		update_post_meta( $a, 'rank_math_primary_category', (string) $this->cat['one'] );
		update_post_meta( $a, 'rank_math_schema_Article', array( '@type' => 'Article' ) );
		wp_set_post_categories( $a, array( $this->cat['one'], $this->cat['two'] ) );

		$b = $this->n['B'];
		update_post_meta( $b, 'rank_math_seo_score', '60' );
		update_post_meta( $b, 'rank_math_focus_keyword', 'gamma' );
		update_post_meta( $b, 'rank_math_title', 'Custom B' );
		update_post_meta( $b, 'rank_math_description', 'Desc beta' );
		update_post_meta( $b, 'rank_math_robots', array( 'index', 'nofollow' ) );
		update_post_meta( $b, 'rank_math_pillar_content', 'off' );
		update_post_meta( $b, 'rank_math_primary_category', (string) $this->cat['two'] );
		update_post_meta( $b, 'rank_math_schema_BlogPosting', array( '@type' => 'BlogPosting' ) );
		wp_set_post_categories( $b, array( $this->cat['one'], $this->cat['two'] ) );

		$c = $this->n['C'];
		update_post_meta( $c, 'rank_math_seo_score', '30' );
		update_post_meta( $c, 'rank_math_focus_keyword', 'alpha' );
		update_post_meta( $c, 'rank_math_robots', array( 'index' ) );
		update_post_meta( $c, 'rank_math_schema_Product', array( '@type' => 'Product' ) );

		update_post_meta( $this->n['E'], 'rank_math_seo_score', '0' );
	}

	private function type( string $key ): object {
		$type = $this->registry->get( 'rankmath.' . $key );
		$this->assertNotNull( $type, "rankmath.$key is registered" );
		return $type;
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private function cfg( string $key, array $settings = array() ): Config {
		return new Config( 'c1', 'rankmath.' . $key, 'x', null, 'px', $settings );
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
		$this->assertContains( $op, array_column( $type->filter_operators( $config, $this->screen ), 'op' ), "rankmath.$key offers $op" );
		$sql = $type->filter_condition( $config, $op, $value, $this->screen, 'clf0' );
		$this->assertNotNull( $sql, "rankmath.$key $op was rejected" );

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
	 * @param list<string> $expected
	 */
	private function expect( array $expected, string $key, string $op, mixed $value, array $settings = array() ): void {
		$this->assertSame( $expected, $this->filtered( $key, $op, $value, $settings ), "rankmath.$key $op " . wp_json_encode( $value ) );
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

	private function as_subscriber(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
	}

	public function test_loader_registers_every_type_only_when_rank_math_is_active(): void {
		$this->assertCount( 9, self::KEYS );
		foreach ( self::KEYS as $key ) {
			$this->assertNotNull( $this->registry->get( 'rankmath.' . $key ), $key );
		}

		remove_filter( 'corinthian_rankmath_active', '__return_true' );
		add_filter( 'corinthian_rankmath_active', '__return_false' );
		$empty = new Registry();
		RankMathIntegration::add_types( $empty );
		$this->assertNull( $empty->get( 'rankmath.seo_score' ) );

		RankMathIntegration::register();
		$this->assertNotFalse( has_action( 'corinthian_register_types', array( RankMathIntegration::class, 'add_types' ) ) );
	}

	public function test_default_detection_is_false_without_rank_math(): void {
		remove_filter( 'corinthian_rankmath_active', '__return_true' );
		$this->assertSame( defined( 'RANK_MATH_VERSION' ), RankMathIntegration::is_active() );
	}

	public function test_post_type_support_follows_rank_math_metabox_switch(): void {
		$page = new PostTypeScreen( $this->registry, new SetRepository(), get_post_type_object( 'page' ) );
		$type = $this->type( 'seo_score' );
		$this->assertTrue( $type->supports( $this->screen ) );
		$this->assertTrue( $type->supports( $page ), 'no switch stored: public types are handled' );

		update_option(
			'rank-math-options-titles',
			array(
				'pt_post_add_meta_box' => 'off',
				'pt_page_add_meta_box' => 'on',
			)
		);
		$this->assertFalse( $type->supports( $this->screen ) );
		$this->assertTrue( $type->supports( $page ) );
		$this->assertCount( 0, array_filter( $this->registry->for_screen( $this->screen ), static fn( $t ) => str_starts_with( $t->key(), 'rankmath.' ) ) );

		register_post_type( 'cln_private', array( 'public' => false ) );
		$private = new PostTypeScreen( $this->registry, new SetRepository(), get_post_type_object( 'cln_private' ) );
		$this->assertFalse( $type->supports( $private ) );
		unregister_post_type( 'cln_private' );
	}

	public function test_seo_score_render_has_number_and_text_label(): void {
		$a = $this->render( 'seo_score', 'A' );
		$this->assertStringContainsString( '>90<', $a );
		$this->assertStringContainsString( '>Good<', $a );
		$this->assertStringContainsString( 'corinthian-rm-score--good', $a );
		$this->assertStringContainsString( '>OK<', $this->render( 'seo_score', 'B' ) );
		$this->assertStringContainsString( '>Poor<', $this->render( 'seo_score', 'C' ) );
		$zero = $this->render( 'seo_score', 'E' );
		$this->assertStringContainsString( '>0<', $zero );
		$this->assertStringContainsString( '>Poor<', $zero );
		$this->assertStringContainsString( '&#8212;', $this->render( 'seo_score', 'D' ) );
		$this->assertSame( 90, $this->type( 'seo_score' )->value( $this->n['A'], $this->cfg( 'seo_score' ), $this->screen ) );
	}

	public function test_seo_score_sort(): void {
		$this->assertSame( array( 'A', 'B', 'C', 'E', 'D' ), $this->sorted( 'seo_score', 'DESC' ) );
		$this->assertSame( array( 'E', 'C', 'B', 'A', 'D' ), $this->sorted( 'seo_score', 'ASC' ) );
	}

	public function test_seo_score_filters(): void {
		$this->expect( array( 'A', 'B' ), 'seo_score', 'gt', '50' );
		$this->expect( array( 'C', 'E' ), 'seo_score', 'lt', '50' );
		$this->expect( array( 'B', 'C' ), 'seo_score', 'between', array( '30', '60' ) );
		$this->expect( array( 'B', 'C' ), 'seo_score', 'between', array( '60', '30' ) );
		$this->expect( array( 'D' ), 'seo_score', 'empty', null );
		$this->expect( array(), 'seo_score', 'gt', '95' );
		$this->expect( array(), 'seo_score', 'between', array( '91', '99' ) );
		$this->assertNull( $this->type( 'seo_score' )->filter_condition( $this->cfg( 'seo_score' ), 'gt', 'abc', $this->screen, 'clf0' ) );
		$this->assertNull( $this->type( 'seo_score' )->filter_condition( $this->cfg( 'seo_score' ), 'contains', '1', $this->screen, 'clf0' ) );
	}

	public function test_focus_keyword_render_marks_the_primary(): void {
		$this->assertSame( '<strong>alpha</strong>, beta', $this->render( 'focus_keyword', 'A' ) );
		$this->assertSame( '<strong>gamma</strong>', $this->render( 'focus_keyword', 'B' ) );
		$this->assertStringContainsString( '&#8212;', $this->render( 'focus_keyword', 'D' ) );
	}

	public function test_focus_keyword_sort_uses_the_first_keyword(): void {
		$this->assertSame( array( 'A', 'C', 'B', 'D', 'E' ), $this->sorted( 'focus_keyword', 'ASC' ) );
		$this->assertSame( array( 'B', 'C', 'A', 'E', 'D' ), $this->sorted( 'focus_keyword', 'DESC' ) );
	}

	public function test_focus_keyword_filters(): void {
		$this->expect( array( 'A', 'C' ), 'focus_keyword', 'contains', 'alpha' );
		$this->expect( array( 'A' ), 'focus_keyword', 'contains', 'beta' );
		$this->expect( array( 'D', 'E' ), 'focus_keyword', 'empty', null );
		$this->expect( array(), 'focus_keyword', 'contains', 'nomatch' );
		$this->assertNull( $this->type( 'focus_keyword' )->filter_condition( $this->cfg( 'focus_keyword' ), 'contains', '', $this->screen, 'clf0' ) );
	}

	public function test_focus_keyword_edit(): void {
		$this->assertTrue( $this->save( 'focus_keyword', 'D', ' Alpha , beta ,alpha,, gamma ' ) );
		$this->assertSame( 'Alpha,beta,gamma', $this->meta( 'D', 'rank_math_focus_keyword' ) );
		$this->assertSame( 'Alpha, beta, gamma', $this->type( 'focus_keyword' )->edit_value( $this->n['D'], $this->cfg( 'focus_keyword' ), $this->screen ) );

		$bad = $this->save( 'focus_keyword', 'D', 'a,b,c,d,e,f' );
		$this->assertWPError( $bad );
		$this->assertSame( 'corinthian_invalid', $bad->get_error_code() );
		$this->assertWPError( $this->save( 'focus_keyword', 'D', array( 'x' ) ) );
		$this->assertWPError( $this->save( 'focus_keyword', 'D', '<b></b>' ) );
		$this->assertSame( 'Alpha,beta,gamma', $this->meta( 'D', 'rank_math_focus_keyword' ), 'rejected edits leave the value alone' );

		$this->assertTrue( $this->save( 'focus_keyword', 'D', '' ) );
		$this->assertFalse( metadata_exists( 'post', $this->n['D'], 'rank_math_focus_keyword' ) );
		$this->assertTrue( $this->save( 'focus_keyword', 'A', null, 'clear' ) );
		$this->assertFalse( metadata_exists( 'post', $this->n['A'], 'rank_math_focus_keyword' ) );
	}

	public function test_focus_keyword_edit_needs_the_meta_capability(): void {
		$this->as_subscriber();
		$set = $this->save( 'focus_keyword', 'A', 'hacked' );
		$this->assertWPError( $set );
		$this->assertSame( 'corinthian_protected', $set->get_error_code() );
		$this->assertWPError( $this->save( 'focus_keyword', 'A', null, 'clear' ) );
		$this->assertSame( 'alpha,beta', $this->meta( 'A', 'rank_math_focus_keyword' ) );
	}

	public function test_seo_title_render_shows_template_and_rendered_title(): void {
		$html = $this->render( 'seo_title', 'A' );
		$this->assertStringContainsString( '%title% %sep% %sitename%', $html );
		$this->assertStringContainsString( 'Post A - Site', $html );
		$plain = $this->render( 'seo_title', 'B' );
		$this->assertStringContainsString( 'Custom B', $plain );
		$this->assertStringNotContainsString( '<br>', $plain, 'nothing to expand' );
		$this->assertStringContainsString( '&#8212;', $this->render( 'seo_title', 'D' ) );
	}

	public function test_seo_title_filters(): void {
		$this->expect( array( 'B' ), 'seo_title', 'contains', 'Custom' );
		$this->expect( array( 'A' ), 'seo_title', 'contains', '%title%' );
		$this->expect( array( 'C', 'D', 'E' ), 'seo_title', 'empty', null );
		$this->expect( array(), 'seo_title', 'contains', 'nomatch' );
	}

	public function test_seo_title_edit(): void {
		$this->assertTrue( $this->save( 'seo_title', 'C', "  <b>Hello</b>\n  %category% %sep%  " ) );
		$this->assertSame( 'Hello %category% %sep%', $this->meta( 'C', 'rank_math_title' ) );
		$this->assertSame( 'Hello %category% %sep%', $this->type( 'seo_title' )->edit_value( $this->n['C'], $this->cfg( 'seo_title' ), $this->screen ) );

		$this->assertWPError( $this->save( 'seo_title', 'C', array( 'x' ) ) );
		$this->assertWPError( $this->save( 'seo_title', 'C', '<script>x</script>' ) );
		$this->assertSame( 'Hello %category% %sep%', $this->meta( 'C', 'rank_math_title' ) );

		$this->assertTrue( $this->save( 'seo_title', 'C', null, 'clear' ) );
		$this->assertFalse( metadata_exists( 'post', $this->n['C'], 'rank_math_title' ) );

		$this->as_subscriber();
		$this->assertSame( 'corinthian_protected', $this->save( 'seo_title', 'B', 'x' )->get_error_code() );
		$this->assertSame( 'Custom B', $this->meta( 'B', 'rank_math_title' ) );
	}

	public function test_meta_description_render_and_filters(): void {
		$this->assertSame( 'Desc alpha', $this->render( 'meta_description', 'A' ) );
		$this->assertStringContainsString( '&#8212;', $this->render( 'meta_description', 'D' ) );
		$this->expect( array( 'A', 'B' ), 'meta_description', 'contains', 'Desc' );
		$this->expect( array( 'B' ), 'meta_description', 'contains', 'beta' );
		$this->expect( array( 'C', 'D', 'E' ), 'meta_description', 'empty', null );
		$this->expect( array(), 'meta_description', 'contains', 'nomatch' );
	}

	public function test_meta_description_edit(): void {
		$field = $this->type( 'meta_description' )->edit_field( $this->cfg( 'meta_description' ), $this->screen );
		$this->assertSame( 'textarea', $field['input'] );
		$this->assertTrue( $this->save( 'meta_description', 'D', " Line <i>one</i>\nline two " ) );
		$this->assertSame( "Line one\nline two", $this->meta( 'D', 'rank_math_description' ) );
		$this->assertWPError( $this->save( 'meta_description', 'D', array() ) );
		$this->assertWPError( $this->save( 'meta_description', 'D', '<p></p>' ) );
		$this->assertSame( "Line one\nline two", $this->meta( 'D', 'rank_math_description' ) );
		$this->assertTrue( $this->save( 'meta_description', 'D', null, 'clear' ) );
		$this->assertFalse( metadata_exists( 'post', $this->n['D'], 'rank_math_description' ) );

		$this->as_subscriber();
		$this->assertSame( 'corinthian_protected', $this->save( 'meta_description', 'A', 'x' )->get_error_code() );
		$this->assertSame( 'Desc alpha', $this->meta( 'A', 'rank_math_description' ) );
	}

	public function test_robots_render_and_filters(): void {
		$this->assertSame( 'noindex, nofollow', $this->render( 'robots', 'A' ) );
		$this->assertSame( 'index, nofollow', $this->render( 'robots', 'B' ) );
		$this->assertStringContainsString( '&#8212;', $this->render( 'robots', 'D' ) );

		$this->expect( array( 'A' ), 'robots', 'in', array( 'noindex' ) );
		$this->expect( array( 'A', 'B' ), 'robots', 'in', array( 'nofollow' ) );
		$this->expect( array( 'A', 'B' ), 'robots', 'in', array( 'noindex', 'nofollow' ) );
		$this->expect( array( 'B', 'C', 'D', 'E' ), 'robots', 'not_in', array( 'noindex' ) );
		$this->expect( array( 'C', 'D', 'E' ), 'robots', 'not_in', array( 'nofollow' ) );
		$this->expect( array(), 'robots', 'in', array( 'noarchive' ) );
		$this->assertNull( $this->type( 'robots' )->filter_condition( $this->cfg( 'robots' ), 'in', array( 'index' ), $this->screen, 'clf0' ) );
	}

	public function test_robots_edit_round_trip_and_bulk_modes(): void {
		$field = $this->type( 'robots' )->edit_field( $this->cfg( 'robots' ), $this->screen );
		$this->assertSame( 'multiselect', $field['input'] );
		$this->assertSame( array( 'set', 'clear', 'append', 'remove' ), $field['bulk'] );

		$this->assertTrue( $this->save( 'robots', 'D', array( 'nofollow', 'noindex' ) ) );
		$this->assertSame( array( 'noindex', 'nofollow' ), $this->meta( 'D', 'rank_math_robots' ) );
		$this->assertSame( array( 'noindex', 'nofollow' ), $this->type( 'robots' )->edit_value( $this->n['D'], $this->cfg( 'robots' ), $this->screen ) );

		$this->assertTrue( $this->save( 'robots', 'D', array() ) );
		$this->assertSame( array( 'index' ), $this->meta( 'D', 'rank_math_robots' ) );

		$this->assertTrue( $this->save( 'robots', 'D', array( 'nofollow' ), 'append' ) );
		$this->assertSame( array( 'index', 'nofollow' ), $this->meta( 'D', 'rank_math_robots' ) );
		$this->assertTrue( $this->save( 'robots', 'D', array( 'noindex', 'nofollow' ), 'append' ) );
		$this->assertSame( array( 'noindex', 'nofollow' ), $this->meta( 'D', 'rank_math_robots' ), 'noindex replaces index' );

		$this->assertTrue( $this->save( 'robots', 'D', array( 'noindex' ), 'remove' ) );
		$this->assertSame( array( 'index', 'nofollow' ), $this->meta( 'D', 'rank_math_robots' ), 'removing noindex restores index' );
		$this->assertTrue( $this->save( 'robots', 'D', array( 'nofollow' ), 'remove' ) );
		$this->assertSame( array( 'index' ), $this->meta( 'D', 'rank_math_robots' ) );

		$this->assertTrue( $this->save( 'robots', 'E', array( 'noindex' ), 'remove' ) );
		$this->assertFalse( metadata_exists( 'post', $this->n['E'], 'rank_math_robots' ), 'remove on a post with no robots setting writes nothing' );

		$this->assertTrue( $this->save( 'robots', 'D', null, 'clear' ) );
		$this->assertFalse( metadata_exists( 'post', $this->n['D'], 'rank_math_robots' ) );
	}

	public function test_robots_rejects_bad_input_and_needs_the_capability(): void {
		$this->assertWPError( $this->save( 'robots', 'A', array( 'noindex', 'bogus' ) ) );
		$this->assertWPError( $this->save( 'robots', 'A', array( array( 'noindex' ) ) ) );
		$this->assertWPError( $this->save( 'robots', 'A', 5 ) );
		$this->assertWPError( $this->save( 'robots', 'A', array( 'nofollow' ), 'toggle' ) );
		$this->assertSame( array( 'noindex', 'nofollow' ), $this->meta( 'A', 'rank_math_robots' ) );

		$this->as_subscriber();
		foreach ( array( 'set', 'append', 'remove', 'clear' ) as $mode ) {
			$result = $this->save( 'robots', 'A', array( 'noarchive' ), $mode );
			$this->assertWPError( $result, $mode );
			$this->assertSame( 'corinthian_protected', $result->get_error_code(), $mode );
		}
		$this->assertSame( array( 'noindex', 'nofollow' ), $this->meta( 'A', 'rank_math_robots' ) );
	}

	public function test_canonical_url_render_and_filter(): void {
		$this->assertStringContainsString( 'href="https://example.com/a"', $this->render( 'canonical_url', 'A' ) );
		$this->assertStringContainsString( '&#8212;', $this->render( 'canonical_url', 'B' ) );
		$this->expect( array( 'B', 'C', 'D', 'E' ), 'canonical_url', 'empty', null );
		foreach ( array( 'B', 'C', 'D', 'E' ) as $name ) {
			update_post_meta( $this->n[ $name ], 'rank_math_canonical_url', 'https://example.com/' . $name );
		}
		$this->expect( array(), 'canonical_url', 'empty', null );
	}

	public function test_canonical_url_edit(): void {
		$this->assertTrue( $this->save( 'canonical_url', 'B', ' https://example.com/x?y=1&z=2 ' ) );
		$this->assertSame( 'https://example.com/x?y=1&z=2', $this->meta( 'B', 'rank_math_canonical_url' ) );

		foreach ( array( 'javascript:alert(1)', 'example.com/page', '/relative', 'ftp://example.com/a', 'http://', array( 'https://example.com' ) ) as $bad ) {
			$result = $this->save( 'canonical_url', 'B', $bad );
			$this->assertWPError( $result, wp_json_encode( $bad ) );
			$this->assertSame( 'corinthian_invalid', $result->get_error_code() );
		}
		$this->assertSame( 'https://example.com/x?y=1&z=2', $this->meta( 'B', 'rank_math_canonical_url' ) );

		$this->assertTrue( $this->save( 'canonical_url', 'B', '' ) );
		$this->assertFalse( metadata_exists( 'post', $this->n['B'], 'rank_math_canonical_url' ) );

		$this->as_subscriber();
		$this->assertSame( 'corinthian_protected', $this->save( 'canonical_url', 'A', 'https://example.com/z' )->get_error_code() );
		$this->assertSame( 'https://example.com/a', $this->meta( 'A', 'rank_math_canonical_url' ) );
	}

	public function test_pillar_content_render_sort_and_filter(): void {
		$this->assertStringContainsString( 'Pillar', $this->render( 'pillar_content', 'A' ) );
		$this->assertStringContainsString( '&#8212;', $this->render( 'pillar_content', 'B' ) );
		$this->assertStringContainsString( '&#8212;', $this->render( 'pillar_content', 'D' ) );

		$this->assertSame( array( 'A', 'B', 'E', 'D', 'C' ), $this->sorted( 'pillar_content', 'DESC' ) );
		$this->assertSame( array( 'B', 'A', 'C', 'D', 'E' ), $this->sorted( 'pillar_content', 'ASC' ) );

		$this->expect( array( 'A' ), 'pillar_content', 'eq', '1' );
		$this->expect( array( 'B', 'C', 'D', 'E' ), 'pillar_content', 'eq', '0' );
		foreach ( array( 'B', 'C', 'D', 'E' ) as $name ) {
			update_post_meta( $this->n[ $name ], 'rank_math_pillar_content', 'on' );
		}
		$this->expect( array(), 'pillar_content', 'eq', '0' );
		$this->assertNull( $this->type( 'pillar_content' )->filter_condition( $this->cfg( 'pillar_content' ), 'eq', 'maybe', $this->screen, 'clf0' ) );
	}

	public function test_pillar_content_edit(): void {
		$this->assertSame( 'toggle', $this->type( 'pillar_content' )->edit_field( $this->cfg( 'pillar_content' ), $this->screen )['input'] );
		$this->assertTrue( $this->save( 'pillar_content', 'D', '1' ) );
		$this->assertSame( 'on', $this->meta( 'D', 'rank_math_pillar_content' ) );
		$this->assertSame( '1', $this->type( 'pillar_content' )->edit_value( $this->n['D'], $this->cfg( 'pillar_content' ), $this->screen ) );
		$this->assertTrue( $this->save( 'pillar_content', 'D', '0' ) );
		$this->assertSame( 'off', $this->meta( 'D', 'rank_math_pillar_content' ) );
		$this->assertSame( '0', $this->type( 'pillar_content' )->edit_value( $this->n['D'], $this->cfg( 'pillar_content' ), $this->screen ) );

		$this->assertWPError( $this->save( 'pillar_content', 'D', 'maybe' ) );
		$this->assertWPError( $this->save( 'pillar_content', 'D', array( '1' ) ) );
		$this->assertSame( 'off', $this->meta( 'D', 'rank_math_pillar_content' ) );

		$this->assertTrue( $this->save( 'pillar_content', 'D', null, 'clear' ) );
		$this->assertFalse( metadata_exists( 'post', $this->n['D'], 'rank_math_pillar_content' ) );

		$this->as_subscriber();
		$this->assertSame( 'corinthian_protected', $this->save( 'pillar_content', 'A', '0' )->get_error_code() );
		$this->assertSame( 'on', $this->meta( 'A', 'rank_math_pillar_content' ) );
	}

	public function test_primary_category_render_and_filter(): void {
		$this->assertSame( 'Cat one', $this->render( 'primary_category', 'A' ) );
		$this->assertSame( 'Cat two', $this->render( 'primary_category', 'B' ) );
		$this->assertStringContainsString( '&#8212;', $this->render( 'primary_category', 'D' ) );

		$this->expect( array( 'A' ), 'primary_category', 'eq', (string) $this->cat['one'] );
		$this->expect( array( 'B' ), 'primary_category', 'eq', (string) $this->cat['two'] );
		$this->expect( array(), 'primary_category', 'eq', (string) $this->cat['unused'] );
		$this->assertNull( $this->type( 'primary_category' )->filter_condition( $this->cfg( 'primary_category' ), 'eq', 'abc', $this->screen, 'clf0' ) );
	}

	public function test_primary_category_edit(): void {
		$field = $this->type( 'primary_category' )->edit_field( $this->cfg( 'primary_category' ), $this->screen );
		$this->assertSame( 'select', $field['input'] );
		$this->assertArrayHasKey( (string) $this->cat['one'], $field['options'] );

		$this->assertTrue( $this->save( 'primary_category', 'A', (string) $this->cat['two'] ) );
		$this->assertSame( (string) $this->cat['two'], $this->meta( 'A', 'rank_math_primary_category' ) );
		$this->assertSame( (string) $this->cat['two'], $this->type( 'primary_category' )->edit_value( $this->n['A'], $this->cfg( 'primary_category' ), $this->screen ) );

		$not_assigned = $this->save( 'primary_category', 'A', (string) $this->cat['three'] );
		$this->assertWPError( $not_assigned );
		$this->assertSame( 'corinthian_invalid', $not_assigned->get_error_code() );
		$this->assertWPError( $this->save( 'primary_category', 'A', 'abc' ) );
		$this->assertWPError( $this->save( 'primary_category', 'A', '' ) );
		$this->assertWPError( $this->save( 'primary_category', 'A', array( $this->cat['one'] ) ) );
		$this->assertSame( (string) $this->cat['two'], $this->meta( 'A', 'rank_math_primary_category' ) );

		$this->assertTrue( $this->save( 'primary_category', 'A', null, 'clear' ) );
		$this->assertFalse( metadata_exists( 'post', $this->n['A'], 'rank_math_primary_category' ) );

		$this->as_subscriber();
		$this->assertSame( 'corinthian_protected', $this->save( 'primary_category', 'B', (string) $this->cat['one'] )->get_error_code() );
		$this->assertSame( (string) $this->cat['two'], $this->meta( 'B', 'rank_math_primary_category' ) );
	}

	public function test_primary_category_follows_the_taxonomy_setting(): void {
		register_taxonomy( 'cln_section', 'post', array( 'hierarchical' => true ) );
		$term = self::factory()->term->create(
			array(
				'taxonomy' => 'cln_section',
				'name'     => 'Section',
			)
		);
		wp_set_object_terms( $this->n['D'], array( $term ), 'cln_section' );
		$settings = array( 'taxonomy' => 'cln_section' );

		$this->assertTrue( $this->save( 'primary_category', 'D', (string) $term, 'set', $settings ) );
		$this->assertSame( (string) $term, $this->meta( 'D', 'rank_math_primary_cln_section' ) );
		$this->assertFalse( metadata_exists( 'post', $this->n['D'], 'rank_math_primary_category' ) );
		$this->assertSame( 'Section', $this->render( 'primary_category', 'D', $settings ) );
		$this->expect( array( 'D' ), 'primary_category', 'eq', (string) $term, $settings );
		unregister_taxonomy( 'cln_section' );
	}

	public function test_schema_type_render(): void {
		$this->assertSame( 'Article', $this->render( 'schema_type', 'A' ) );
		$this->assertSame( 'Article (BlogPosting)', $this->render( 'schema_type', 'B' ) );
		$this->assertSame( 'Product', $this->render( 'schema_type', 'C' ) );
		$this->assertStringContainsString( '&#8212;', $this->render( 'schema_type', 'D' ) );

		update_post_meta( $this->n['E'], 'rank_math_schema_Review', array( '@type' => array( 'Review', 'JobPosting' ) ) );
		$this->assertSame( 'Review, Job Posting', $this->render( 'schema_type', 'E' ) );
		$this->assertSame( array( 'Review', 'JobPosting' ), $this->type( 'schema_type' )->value( $this->n['E'], $this->cfg( 'schema_type' ), $this->screen ) );
	}

	public function test_schema_type_falls_back_to_the_post_type_default(): void {
		update_option(
			'rank-math-options-titles',
			array(
				'pt_post_default_rich_snippet' => 'article',
				'pt_post_default_article_type' => 'BlogPosting',
			)
		);
		$default = $this->render( 'schema_type', 'D' );
		$this->assertStringContainsString( 'Article (BlogPosting)', $default );
		$this->assertStringContainsString( '(default)', $default );
		$this->assertSame( 'Article', $this->render( 'schema_type', 'A' ), 'an explicit schema wins' );

		update_post_meta( $this->n['D'], 'rank_math_rich_snippet', 'off' );
		$this->assertStringContainsString( '&#8212;', $this->render( 'schema_type', 'D' ), 'schema switched off for the post' );
	}

	public function test_schema_type_filters(): void {
		$this->expect( array( 'A' ), 'schema_type', 'in', array( 'Article' ) );
		$this->expect( array( 'B' ), 'schema_type', 'in', array( 'BlogPosting' ) );
		$this->expect( array( 'A', 'C' ), 'schema_type', 'in', array( 'Article', 'Product' ) );
		$this->expect( array( 'B', 'C', 'D', 'E' ), 'schema_type', 'not_in', array( 'Article' ) );
		$this->expect( array( 'D', 'E' ), 'schema_type', 'not_in', array( 'Article', 'BlogPosting', 'Product' ) );
		$this->expect( array(), 'schema_type', 'in', array( 'Recipe' ) );
		$this->assertNull( $this->type( 'schema_type' )->filter_condition( $this->cfg( 'schema_type' ), 'in', array(), $this->screen, 'clf0' ) );
		$this->assertNull( $this->type( 'schema_type' )->filter_condition( $this->cfg( 'schema_type' ), 'contains', array( 'Article' ), $this->screen, 'clf0' ) );

		$options = $this->type( 'schema_type' )->filter_operators( $this->cfg( 'schema_type' ), $this->screen )[0]['options'];
		$this->assertSame( 'Article (BlogPosting)', $options['BlogPosting'] );
		$this->assertSame( 'Article', $options['Article'] );
	}

	public function test_schema_type_is_display_and_filter_only(): void {
		$this->assertNotInstanceOf( Editable::class, $this->type( 'schema_type' ) );
		$this->assertNotInstanceOf( Editable::class, $this->type( 'seo_score' ) );
		$this->assertNotInstanceOf( Sortable::class, $this->type( 'schema_type' ) );
	}

	public function test_two_rules_on_one_column_do_not_collide(): void {
		$type = $this->type( 'focus_keyword' );
		$one  = $type->filter_condition( $this->cfg( 'focus_keyword' ), 'contains', 'alpha', $this->screen, 'clf0' );
		$two  = $type->filter_condition( $this->cfg( 'focus_keyword' ), 'contains', 'beta', $this->screen, 'clf1' );
		$cb   = static function ( $clauses ) use ( $one, $two ) {
			$clauses['join']  .= ' ' . $one['join'] . ' ' . $two['join'];
			$clauses['where'] .= ' AND ' . $one['where'] . ' AND ' . $two['where'];
			return $clauses;
		};
		add_filter( 'posts_clauses', $cb );
		$query = new WP_Query(
			array(
				'post__in'         => array_values( $this->n ),
				'post_status'      => 'any',
				'fields'           => 'ids',
				'nopaging'         => true,
				'suppress_filters' => false,
			)
		);
		remove_filter( 'posts_clauses', $cb );
		$this->assertSame( '', (string) $GLOBALS['wpdb']->last_error );
		$this->assertSame( array( $this->n['A'] ), array_map( 'intval', $query->posts ) );
	}
}
