<?php

namespace CorinthianPro\Tests;

use Corinthian\Columns\Config;
use Corinthian\Columns\Editable;
use Corinthian\Columns\Filterable;
use Corinthian\Columns\Registry;
use Corinthian\Columns\Sortable;
use CorinthianPro\Integrations\Yoast\ScoreType;
use CorinthianPro\Integrations\Yoast\YoastIntegration;
use Corinthian\Screens\PostTypeScreen;
use Corinthian\Sets\SetRepository;
use WP_Error;
use WP_Query;
use WP_UnitTestCase;

/**
 * Runs against a real Yoast SEO: its classes, options and capability rules, and
 * the wp_yoast_indexable table its own watchers keep in step with post meta.
 */
class YoastTest extends WP_UnitTestCase {

	private const KEYS = array(
		'seo_score',
		'readability_score',
		'focus_keyphrase',
		'seo_title',
		'meta_description',
		'noindex',
		'nofollow',
		'canonical',
		'cornerstone',
		'primary_category',
		'schema_page_type',
		'schema_article_type',
		'internal_links_out',
		'internal_links_in',
	);

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
		$main = WP_PLUGIN_DIR . '/wordpress-seo/wp-seo.php';
		if ( defined( 'WPSEO_VERSION' ) ) {
			self::$loaded = true;
			self::truncate_yoast_tables();
			return;
		}
		if ( ! file_exists( $main ) ) {
			return;
		}
		// Yoast hooks itself to plugins_loaded, which has already run, so boot it by hand.
		define( 'WPSEO_FILE', $main );
		require_once $main;
		wpseo_init();
		// The options singleton adds its filters once, so build it here where the suite's per-test hook restore can't discard them.
		YoastIntegration::call( 'WPSEO_Options::get_instance' );
		self::$loaded = true;
		self::truncate_yoast_tables();
	}

	public static function tear_down_after_class(): void {
		self::truncate_yoast_tables();
		parent::tear_down_after_class();
	}

	/**
	 * Yoast's tables are real ones that outlive the per-test rollback and the
	 * core tables the test suite resets, so rows from an earlier run would
	 * collide with this run's post and term ids.
	 */
	private static function truncate_yoast_tables(): void {
		global $wpdb;
		if ( ! self::$loaded ) {
			return;
		}
		foreach ( array( 'indexable', 'indexable_hierarchy', 'primary_term', 'seo_links' ) as $table ) {
			$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}yoast_{$table}" ); // phpcs:ignore WordPress.DB
		}
	}

	public function set_up(): void {
		parent::set_up();
		if ( self::$loaded && ! self::$hooks_kept ) {
			// The suite snapshots hooks once per run, so the hooks Yoast added when it booted would be wiped after the first test.
			$this->_backup_hooks();
			self::$hooks_kept = true;
		}
		if ( ! self::$loaded ) {
			$this->markTestSkipped( 'Yoast SEO is not installed in this environment.' );
		}
		get_role( 'administrator' )->add_cap( 'wpseo_manage_options' );
		get_role( 'editor' )->add_cap( 'wpseo_edit_advanced_metadata' );
		$this->reset_options();
		add_filter( 'corinthian_yoast_active', '__return_true' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->registry = new Registry();
		YoastIntegration::add_types( $this->registry );
		$this->screen = new PostTypeScreen( $this->registry, new SetRepository(), get_post_type_object( 'post' ) );

		foreach ( array( 'one', 'two', 'three', 'unused' ) as $name ) {
			$this->cat[ $name ] = self::factory()->category->create( array( 'name' => 'Cat ' . $name ) );
		}
		// Yoast only maintains indexables on production environment types. It fails to
		// index a term that has no posts, so this goes on after the terms exist.
		add_filter( 'Yoast\WP\SEO\should_index_indexables', '__return_true' );
		foreach ( array( 'A', 'B', 'C', 'D', 'E' ) as $i => $name ) {
			$this->n[ $name ] = self::factory()->post->create(
				array(
					'post_title' => "Post $name",
					'post_name'  => 'yo-' . strtolower( $name ),
					'post_date'  => '2024-01-0' . ( $i + 1 ) . ' 10:00:00',
				)
			);
		}

		wp_set_post_categories( $this->n['A'], array( $this->cat['one'], $this->cat['two'] ) );
		$this->seed(
			'A',
			array(
				'linkdex'              => '90',
				'content_score'        => '80',
				'focuskw'              => 'alpha',
				'title'                => '%%title%% %%sep%% %%sitename%%',
				'metadesc'             => 'Desc alpha',
				'meta-robots-noindex'  => '1',
				'meta-robots-nofollow' => '1',
				'canonical'            => 'https://example.com/a',
				'is_cornerstone'       => '1',
				'primary_category'     => (string) $this->cat['one'],
				'schema_page_type'     => 'ItemPage',
				'schema_article_type'  => 'NewsArticle',
			)
		);
		wp_set_post_categories( $this->n['B'], array( $this->cat['one'], $this->cat['two'] ) );
		$this->seed(
			'B',
			array(
				'linkdex'             => '60',
				'content_score'       => '50',
				'focuskw'             => 'gamma',
				'title'               => 'Custom B',
				'metadesc'            => 'Desc beta',
				'meta-robots-noindex' => '2',
				'canonical'           => 'https://other.test/b',
				'is_cornerstone'      => '1',
				'primary_category'    => (string) $this->cat['two'],
				'schema_page_type'    => 'AboutPage',
				'schema_article_type' => 'BlogPosting',
			)
		);
		$this->seed(
			'C',
			array(
				'linkdex'             => '30',
				'content_score'       => '20',
				'focuskw'             => 'alpha two',
				'schema_article_type' => 'Article',
			)
		);
		$this->seed( 'E', array( 'linkdex' => '5' ) );

		$this->seed_links( 'A', 3, 0 );
		$this->seed_links( 'B', 1, 2 );
		$this->seed_links( 'C', 0, 1 );
		$this->seed_links( 'D', null, null );
		$GLOBALS['wpdb']->delete(
			YoastIntegration::indexable_table(),
			array(
				'object_type' => 'post',
				'object_id'   => $this->n['E'],
			)
		);
		wp_cache_flush();
	}

	public function tear_down(): void {
		get_role( 'administrator' )->remove_cap( 'wpseo_manage_options' );
		get_role( 'editor' )->remove_cap( 'wpseo_edit_advanced_metadata' );
		get_role( 'editor' )->remove_cap( 'wpseo_manage_options' );
		if ( self::$loaded ) {
			$this->reset_options();
		}
		parent::tear_down();
	}

	private function reset_options(): void {
		foreach ( array( 'disableadvanced_meta', 'enable_cornerstone_content', 'keyword_analysis_active', 'display-metabox-pt-page' ) as $name ) {
			$this->set_option( $name, true );
		}
	}

	private function set_option( string $name, mixed $value ): void {
		YoastIntegration::call( 'WPSEO_Options::set', $name, $value );
	}

	/**
	 * @param array<string,string> $values meta name (without prefix) => value
	 */
	private function seed( string $post, array $values ): void {
		foreach ( $values as $name => $value ) {
			update_post_meta( $this->n[ $post ], YoastIntegration::META_PREFIX . $name, $value );
		}
	}

	private function seed_links( string $post, ?int $out, ?int $in ): void {
		global $wpdb;
		$table = YoastIntegration::indexable_table();
		$id    = $this->n[ $post ];
		$found = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE object_type = 'post' AND object_id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( 1, $found, "Yoast built the indexable row for post $post when it was created" );
		$wpdb->update(
			$table,
			array(
				'link_count'          => $out,
				'incoming_link_count' => $in,
			),
			array(
				'object_type' => 'post',
				'object_id'   => $id,
			)
		);
	}

	private function type( string $key ): object {
		$type = $this->registry->get( 'yoast.' . $key );
		$this->assertNotNull( $type, "yoast.$key is registered" );
		return $type;
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private function cfg( string $key, array $settings = array() ): Config {
		return new Config( 'c1', 'yoast.' . $key, 'x', null, 'px', $settings );
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
		$this->assertContains( $op, array_column( $type->filter_operators( $config, $this->screen ), 'op' ), "yoast.$key offers $op" );
		$sql = $type->filter_condition( $config, $op, $value, $this->screen, 'clf0' );
		$this->assertNotNull( $sql, "yoast.$key $op was rejected" );

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
		$this->assertSame( $expected, $this->filtered( $key, $op, $value, $settings ), "yoast.$key $op " . wp_json_encode( $value ) );
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

	private function meta( string $post, string $name ): mixed {
		wp_cache_flush();
		return get_post_meta( $this->n[ $post ], YoastIntegration::META_PREFIX . $name, true );
	}

	private function has_meta( string $post, string $name ): bool {
		wp_cache_flush();
		return metadata_exists( 'post', $this->n[ $post ], YoastIntegration::META_PREFIX . $name );
	}

	/**
	 * Does what Yoast's own meta watcher does when the request ends, then reads
	 * the post's indexable row.
	 *
	 * @return array<string,mixed>
	 */
	private function indexable( string $post ): array {
		global $wpdb;
		$watcher = YoastIntegration::service( 'Yoast\WP\SEO\Integrations\Watchers\Indexable_Post_Meta_Watcher' );
		$this->assertNotNull( $watcher, 'Yoast registered its post meta watcher' );
		call_user_func( array( $watcher, 'update_indexables' ) );
		$table = YoastIntegration::indexable_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE object_type = 'post' AND object_id = %d", $this->n[ $post ] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertIsArray( $row, "post $post has an indexable" );
		return $row;
	}

	private function as_subscriber(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
	}

	private function as_editor(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
	}

	public function test_loader_registers_every_type_only_when_yoast_is_active(): void {
		$this->assertCount( 14, self::KEYS );
		foreach ( self::KEYS as $key ) {
			$this->assertNotNull( $this->registry->get( 'yoast.' . $key ), $key );
		}

		remove_filter( 'corinthian_yoast_active', '__return_true' );
		add_filter( 'corinthian_yoast_active', '__return_false' );
		$empty = new Registry();
		YoastIntegration::add_types( $empty );
		$this->assertNull( $empty->get( 'yoast.seo_score' ) );

		YoastIntegration::register();
		$this->assertNotFalse( has_action( 'corinthian_register_types', array( YoastIntegration::class, 'add_types' ) ) );
	}

	public function test_detection_follows_the_loaded_plugin(): void {
		remove_filter( 'corinthian_yoast_active', '__return_true' );
		$this->assertTrue( YoastIntegration::is_active(), 'Yoast is loaded in this run' );
	}

	public function test_post_type_support_follows_yoasts_metabox_switch(): void {
		$page = new PostTypeScreen( $this->registry, new SetRepository(), get_post_type_object( 'page' ) );
		$type = $this->type( 'seo_score' );
		$this->assertTrue( $type->supports( $this->screen ) );
		$this->assertTrue( $type->supports( $page ) );

		$this->set_option( 'display-metabox-pt-page', false );
		$this->assertFalse( $type->supports( $page ) );
		$this->assertTrue( $type->supports( $this->screen ) );

		register_post_type( 'cln_private', array( 'public' => false ) );
		$private = new PostTypeScreen( $this->registry, new SetRepository(), get_post_type_object( 'cln_private' ) );
		$this->assertFalse( $type->supports( $private ) );
		unregister_post_type( 'cln_private' );
	}

	public function test_schema_article_type_needs_author_support(): void {
		$page    = new PostTypeScreen( $this->registry, new SetRepository(), get_post_type_object( 'page' ) );
		$article = $this->type( 'schema_article_type' );
		$this->assertTrue( $article->supports( $this->screen ) );
		$this->assertTrue( $article->supports( $page ), 'pages support author' );
		remove_post_type_support( 'page', 'author' );
		$this->assertFalse( $article->supports( $page ) );
		$this->assertTrue( $this->type( 'schema_page_type' )->supports( $page ), 'the page type applies to every type' );
		add_post_type_support( 'page', 'author' );
	}

	public function test_seo_score_render_has_number_and_text_label(): void {
		$a = $this->render( 'seo_score', 'A' );
		$this->assertStringContainsString( '>90<', $a );
		$this->assertStringContainsString( '>Good<', $a );
		$this->assertStringContainsString( 'corinthian-yoast-score--good', $a );
		$this->assertStringContainsString( '>OK<', $this->render( 'seo_score', 'B' ) );
		$this->assertStringContainsString( '>Needs improvement<', $this->render( 'seo_score', 'C' ) );
		$low = $this->render( 'seo_score', 'E' );
		$this->assertStringContainsString( '>5<', $low );
		$this->assertStringContainsString( '>Needs improvement<', $low );
		$this->assertStringContainsString( '&#8212;', $this->render( 'seo_score', 'D' ) );
		$this->assertSame( 90, $this->type( 'seo_score' )->value( $this->n['A'], $this->cfg( 'seo_score' ), $this->screen ) );
	}

	public function test_score_bands_match_yoasts_ranks(): void {
		$this->assertSame( 'good', ScoreType::level( 71 )[0] );
		$this->assertSame( 'ok', ScoreType::level( 70 )[0] );
		$this->assertSame( 'ok', ScoreType::level( 41 )[0] );
		$this->assertSame( 'bad', ScoreType::level( 40 )[0] );
		$ranges = ( new \ReflectionClass( 'WPSEO_Rank' ) )->getProperty( 'ranges' );
		$ranges->setAccessible( true );
		$this->assertSame(
			array(
				'start' => 71,
				'end'   => 100,
			),
			$ranges->getValue()['good']
		);
	}

	public function test_seo_score_sort_and_filters(): void {
		$this->assertSame( array( 'A', 'B', 'C', 'E', 'D' ), $this->sorted( 'seo_score', 'DESC' ) );
		$this->assertSame( array( 'E', 'C', 'B', 'A', 'D' ), $this->sorted( 'seo_score', 'ASC' ) );
		$this->expect( array( 'A', 'B' ), 'seo_score', 'gt', '50' );
		$this->expect( array( 'C', 'E' ), 'seo_score', 'lt', '50' );
		$this->expect( array( 'B', 'C' ), 'seo_score', 'between', array( '30', '60' ) );
		$this->expect( array( 'D' ), 'seo_score', 'empty', null );
		$this->expect( array(), 'seo_score', 'gt', '95' );
		$this->expect( array(), 'seo_score', 'between', array( '91', '99' ) );
		$this->assertNull( $this->type( 'seo_score' )->filter_condition( $this->cfg( 'seo_score' ), 'gt', 'abc', $this->screen, 'clf0' ) );
		$this->assertNull( $this->type( 'seo_score' )->filter_condition( $this->cfg( 'seo_score' ), 'contains', '1', $this->screen, 'clf0' ) );
	}

	public function test_readability_score_render_sort_and_filters(): void {
		$a = $this->render( 'readability_score', 'A' );
		$this->assertStringContainsString( '>80<', $a );
		$this->assertStringContainsString( '>Good<', $a );
		$this->assertStringContainsString( '>OK<', $this->render( 'readability_score', 'B' ) );
		$this->assertStringContainsString( '>Needs improvement<', $this->render( 'readability_score', 'C' ) );
		$this->assertStringContainsString( '&#8212;', $this->render( 'readability_score', 'D' ) );

		$this->assertSame( array( 'A', 'B', 'C', 'E', 'D' ), $this->sorted( 'readability_score', 'DESC' ) );
		$this->assertSame( array( 'C', 'B', 'A', 'D', 'E' ), $this->sorted( 'readability_score', 'ASC' ) );
		$this->expect( array( 'A', 'B' ), 'readability_score', 'gt', '40' );
		$this->expect( array( 'C' ), 'readability_score', 'lt', '40' );
		$this->expect( array( 'B', 'C' ), 'readability_score', 'between', array( '20', '50' ) );
		$this->expect( array( 'D', 'E' ), 'readability_score', 'empty', null );
		$this->expect( array(), 'readability_score', 'gt', '95' );
	}

	public function test_scores_and_link_counts_are_read_only(): void {
		foreach ( array( 'seo_score', 'readability_score', 'internal_links_out', 'internal_links_in' ) as $key ) {
			$this->assertNotInstanceOf( Editable::class, $this->type( $key ), $key );
		}
	}

	public function test_focus_keyphrase_render_sort_and_filters(): void {
		$this->assertSame( 'alpha', $this->render( 'focus_keyphrase', 'A' ) );
		$this->assertStringContainsString( '&#8212;', $this->render( 'focus_keyphrase', 'D' ) );
		$this->assertSame( array( 'A', 'C', 'B', 'D', 'E' ), $this->sorted( 'focus_keyphrase', 'ASC' ) );
		$this->assertSame( array( 'B', 'C', 'A', 'E', 'D' ), $this->sorted( 'focus_keyphrase', 'DESC' ) );
		$this->expect( array( 'A', 'C' ), 'focus_keyphrase', 'contains', 'alpha' );
		$this->expect( array( 'B' ), 'focus_keyphrase', 'contains', 'gamma' );
		$this->expect( array( 'D', 'E' ), 'focus_keyphrase', 'empty', null );
		$this->expect( array(), 'focus_keyphrase', 'contains', 'nomatch' );
		$this->assertNull( $this->type( 'focus_keyphrase' )->filter_condition( $this->cfg( 'focus_keyphrase' ), 'contains', '', $this->screen, 'clf0' ) );
	}

	public function test_focus_keyphrase_edit_reaches_the_indexable(): void {
		$this->assertTrue( $this->save( 'focus_keyphrase', 'D', '  <b>New</b>   phrase ' ) );
		$this->assertSame( 'New phrase', $this->meta( 'D', 'focuskw' ) );
		$this->assertSame( 'New phrase', $this->type( 'focus_keyphrase' )->edit_value( $this->n['D'], $this->cfg( 'focus_keyphrase' ), $this->screen ) );
		$this->assertSame( 'New phrase', $this->indexable( 'D' )['primary_focus_keyword'] );

		$this->assertWPError( $this->save( 'focus_keyphrase', 'D', array( 'x' ) ) );
		$this->assertWPError( $this->save( 'focus_keyphrase', 'D', '<b></b>' ) );
		$this->assertSame( 'New phrase', $this->meta( 'D', 'focuskw' ), 'rejected edits leave the value alone' );

		$this->assertTrue( $this->save( 'focus_keyphrase', 'D', '' ) );
		$this->assertFalse( $this->has_meta( 'D', 'focuskw' ) );
		$this->assertNull( $this->indexable( 'D' )['primary_focus_keyword'] );
		$this->assertTrue( $this->save( 'focus_keyphrase', 'A', null, 'clear' ) );
		$this->assertFalse( $this->has_meta( 'A', 'focuskw' ) );
		$this->assertWPError( $this->save( 'focus_keyphrase', 'A', 'x', 'append' ) );
	}

	public function test_focus_keyphrase_edit_needs_the_post_capability_and_the_feature(): void {
		$this->as_subscriber();
		$result = $this->save( 'focus_keyphrase', 'A', 'hacked' );
		$this->assertWPError( $result );
		$this->assertSame( 'corinthian_protected', $result->get_error_code() );
		$this->assertWPError( $this->save( 'focus_keyphrase', 'A', null, 'clear' ) );
		$this->assertSame( 'alpha', $this->meta( 'A', 'focuskw' ) );

		$this->as_editor();
		$this->set_option( 'keyword_analysis_active', false );
		$off = $this->save( 'focus_keyphrase', 'A', 'blocked' );
		$this->assertWPError( $off );
		$this->assertSame( 'corinthian_disabled', $off->get_error_code() );
		$this->set_option( 'keyword_analysis_active', true );
		$this->assertTrue( $this->save( 'focus_keyphrase', 'A', 'allowed' ), 'control: the feature on' );
		$this->assertSame( 'allowed', $this->meta( 'A', 'focuskw' ) );
	}

	public function test_seo_title_render_shows_template_and_rendered_title(): void {
		$html = $this->render( 'seo_title', 'A' );
		$this->assertStringContainsString( '%%title%% %%sep%% %%sitename%%', $html );
		$this->assertStringContainsString( '<br>', $html, 'Yoast expanded the variables' );
		$this->assertStringContainsString( 'Post A', $html );
		$plain = $this->render( 'seo_title', 'B' );
		$this->assertStringContainsString( 'Custom B', $plain );
		$this->assertStringNotContainsString( '<br>', $plain, 'nothing to expand' );
		$this->assertStringContainsString( '&#8212;', $this->render( 'seo_title', 'D' ) );
	}

	public function test_seo_title_sort_and_filters(): void {
		$this->assertSame( array( 'A', 'B', 'C', 'D', 'E' ), $this->sorted( 'seo_title', 'ASC' ) );
		$this->assertSame( array( 'B', 'A', 'E', 'D', 'C' ), $this->sorted( 'seo_title', 'DESC' ) );
		$this->expect( array( 'B' ), 'seo_title', 'contains', 'Custom' );
		$this->expect( array( 'A' ), 'seo_title', 'contains', '%%title%%' );
		$this->expect( array( 'C', 'D', 'E' ), 'seo_title', 'empty', null );
		$this->expect( array(), 'seo_title', 'contains', 'nomatch' );
	}

	public function test_seo_title_edit_reaches_the_indexable(): void {
		$this->assertTrue( $this->save( 'seo_title', 'C', "  <b>Hello</b>\n  %%category%% %%sep%%  " ) );
		$this->assertSame( 'Hello %%category%% %%sep%%', $this->meta( 'C', 'title' ) );
		$this->assertSame( 'Hello %%category%% %%sep%%', $this->type( 'seo_title' )->edit_value( $this->n['C'], $this->cfg( 'seo_title' ), $this->screen ) );
		$this->assertSame( 'Hello %%category%% %%sep%%', $this->indexable( 'C' )['title'] );

		$this->assertWPError( $this->save( 'seo_title', 'C', array( 'x' ) ) );
		$this->assertWPError( $this->save( 'seo_title', 'C', '<script>x</script>' ) );
		$this->assertSame( 'Hello %%category%% %%sep%%', $this->meta( 'C', 'title' ) );

		$this->assertTrue( $this->save( 'seo_title', 'C', null, 'clear' ) );
		$this->assertFalse( $this->has_meta( 'C', 'title' ) );
		$this->assertNull( $this->indexable( 'C' )['title'] );

		$this->as_subscriber();
		$this->assertSame( 'corinthian_protected', $this->save( 'seo_title', 'B', 'x' )->get_error_code() );
		$this->assertSame( 'Custom B', $this->meta( 'B', 'title' ) );
	}

	public function test_meta_description_render_filters_and_edit(): void {
		$this->assertSame( 'Desc alpha', $this->render( 'meta_description', 'A' ) );
		$this->assertStringContainsString( '&#8212;', $this->render( 'meta_description', 'D' ) );
		$this->expect( array( 'A', 'B' ), 'meta_description', 'contains', 'Desc' );
		$this->expect( array( 'B' ), 'meta_description', 'contains', 'beta' );
		$this->expect( array( 'C', 'D', 'E' ), 'meta_description', 'empty', null );
		$this->expect( array(), 'meta_description', 'contains', 'nomatch' );
		$this->assertSame( array( 'A', 'B', 'C', 'D', 'E' ), $this->sorted( 'meta_description', 'ASC' ) );

		$field = $this->type( 'meta_description' )->edit_field( $this->cfg( 'meta_description' ), $this->screen );
		$this->assertSame( 'textarea', $field['input'] );
		$this->assertTrue( $this->save( 'meta_description', 'D', " Line <i>one</i>\nline two " ) );
		$this->assertSame( 'Line one line two', $this->meta( 'D', 'metadesc' ), 'Yoast folds line breaks' );
		$this->assertSame( 'Line one line two', $this->indexable( 'D' )['description'] );
		$this->assertWPError( $this->save( 'meta_description', 'D', array() ) );
		$this->assertWPError( $this->save( 'meta_description', 'D', '<p></p>' ) );
		$this->assertSame( 'Line one line two', $this->meta( 'D', 'metadesc' ) );
		$this->assertTrue( $this->save( 'meta_description', 'D', null, 'clear' ) );
		$this->assertFalse( $this->has_meta( 'D', 'metadesc' ) );
		$this->assertNull( $this->indexable( 'D' )['description'] );

		$this->as_subscriber();
		$this->assertSame( 'corinthian_protected', $this->save( 'meta_description', 'A', 'x' )->get_error_code() );
		$this->assertSame( 'Desc alpha', $this->meta( 'A', 'metadesc' ) );
	}

	public function test_noindex_render_sort_and_filters(): void {
		$this->assertStringContainsString( '<strong>Noindex</strong>', $this->render( 'noindex', 'A' ) );
		$this->assertSame( 'Index', $this->render( 'noindex', 'B' ) );
		$this->assertStringContainsString( '&#8212;', $this->render( 'noindex', 'C' ), 'no row: the post type default applies' );

		$this->assertSame( array( 'A', 'B', 'E', 'D', 'C' ), $this->sorted( 'noindex', 'DESC' ) );
		$this->assertSame( array( 'B', 'A', 'C', 'D', 'E' ), $this->sorted( 'noindex', 'ASC' ) );

		$this->expect( array( 'A' ), 'noindex', 'in', array( 'noindex' ) );
		$this->expect( array( 'B' ), 'noindex', 'in', array( 'index' ) );
		$this->expect( array( 'C', 'D', 'E' ), 'noindex', 'in', array( 'default' ) );
		$this->expect( array( 'A', 'B' ), 'noindex', 'in', array( 'noindex', 'index' ) );
		$this->expect( array( 'B', 'C', 'D', 'E' ), 'noindex', 'not_in', array( 'noindex' ) );
		$this->expect( array(), 'noindex', 'not_in', array( 'noindex', 'index', 'default' ) );
		$this->assertNull( $this->type( 'noindex' )->filter_condition( $this->cfg( 'noindex' ), 'in', array( 'bogus' ), $this->screen, 'clf0' ) );
	}

	public function test_noindex_edit_reaches_the_indexable(): void {
		$field = $this->type( 'noindex' )->edit_field( $this->cfg( 'noindex' ), $this->screen );
		$this->assertSame( 'select', $field['input'] );
		$this->assertSame( array( 'set', 'clear' ), $field['bulk'] );

		$this->assertTrue( $this->save( 'noindex', 'C', 'noindex' ) );
		$this->assertSame( '1', $this->meta( 'C', 'meta-robots-noindex' ) );
		$this->assertSame( 'noindex', $this->type( 'noindex' )->edit_value( $this->n['C'], $this->cfg( 'noindex' ), $this->screen ) );
		$this->assertEquals( 1, $this->indexable( 'C' )['is_robots_noindex'] );

		$this->assertTrue( $this->save( 'noindex', 'C', 'index' ) );
		$this->assertSame( '2', $this->meta( 'C', 'meta-robots-noindex' ) );
		$row = $this->indexable( 'C' );
		$this->assertNotNull( $row['is_robots_noindex'] );
		$this->assertEquals( 0, $row['is_robots_noindex'] );

		$this->assertTrue( $this->save( 'noindex', 'C', 'default' ) );
		$this->assertFalse( $this->has_meta( 'C', 'meta-robots-noindex' ), 'Yoast does not store its default' );
		$this->assertNull( $this->indexable( 'C' )['is_robots_noindex'] );
		$this->assertSame( 'default', $this->type( 'noindex' )->edit_value( $this->n['C'], $this->cfg( 'noindex' ), $this->screen ) );

		$this->assertTrue( $this->save( 'noindex', 'A', null, 'clear' ) );
		$this->assertFalse( $this->has_meta( 'A', 'meta-robots-noindex' ) );
		$this->assertNull( $this->indexable( 'A' )['is_robots_noindex'] );

		foreach ( array( 'maybe', array( 'noindex' ), '1', '2' ) as $bad ) {
			$result = $this->save( 'noindex', 'B', $bad );
			$this->assertWPError( $result, wp_json_encode( $bad ) );
			$this->assertSame( 'corinthian_invalid', $result->get_error_code() );
		}
		$this->assertWPError( $this->save( 'noindex', 'B', 'noindex', 'append' ) );
		$this->assertSame( '2', $this->meta( 'B', 'meta-robots-noindex' ), 'rejected edits leave the value alone' );
	}

	public function test_nofollow_render_sort_filters_and_edit(): void {
		$this->assertStringContainsString( 'Nofollow', $this->render( 'nofollow', 'A' ) );
		$this->assertStringContainsString( '&#8212;', $this->render( 'nofollow', 'B' ) );
		$this->assertSame( array( 'A', 'E', 'D', 'C', 'B' ), $this->sorted( 'nofollow', 'DESC' ) );
		$this->expect( array( 'A' ), 'nofollow', 'eq', '1' );
		$this->expect( array( 'B', 'C', 'D', 'E' ), 'nofollow', 'eq', '0' );
		$this->assertNull( $this->type( 'nofollow' )->filter_condition( $this->cfg( 'nofollow' ), 'eq', 'maybe', $this->screen, 'clf0' ) );

		$this->assertSame( 'toggle', $this->type( 'nofollow' )->edit_field( $this->cfg( 'nofollow' ), $this->screen )['input'] );
		$this->assertTrue( $this->save( 'nofollow', 'B', true ) );
		$this->assertSame( '1', $this->meta( 'B', 'meta-robots-nofollow' ) );
		$this->assertEquals( 1, $this->indexable( 'B' )['is_robots_nofollow'] );
		$this->expect( array( 'A', 'B' ), 'nofollow', 'eq', '1' );

		$this->assertTrue( $this->save( 'nofollow', 'B', '0' ) );
		$this->assertFalse( $this->has_meta( 'B', 'meta-robots-nofollow' ) );
		$this->assertEquals( 0, $this->indexable( 'B' )['is_robots_nofollow'] );
		$this->assertTrue( $this->save( 'nofollow', 'A', null, 'clear' ) );
		$this->assertEquals( 0, $this->indexable( 'A' )['is_robots_nofollow'] );
		$this->expect( array(), 'nofollow', 'eq', '1' );

		$this->assertWPError( $this->save( 'nofollow', 'C', 'sometimes' ) );
		$this->assertWPError( $this->save( 'nofollow', 'C', array( '1' ) ) );
		$this->assertFalse( $this->has_meta( 'C', 'meta-robots-nofollow' ) );
	}

	public function test_canonical_render_sort_filters(): void {
		$this->assertStringContainsString( 'href="https://example.com/a"', $this->render( 'canonical', 'A' ) );
		$this->assertStringContainsString( '&#8212;', $this->render( 'canonical', 'C' ) );
		$this->assertSame( array( 'A', 'B', 'C', 'D', 'E' ), $this->sorted( 'canonical', 'ASC' ) );
		$this->expect( array( 'A' ), 'canonical', 'contains', 'example.com' );
		$this->expect( array( 'B' ), 'canonical', 'contains', 'other.test' );
		$this->expect( array( 'C', 'D', 'E' ), 'canonical', 'empty', null );
		$this->expect( array(), 'canonical', 'contains', 'nomatch' );
	}

	public function test_canonical_edit_reaches_the_indexable(): void {
		$this->assertTrue( $this->save( 'canonical', 'C', ' https://example.com/x?y=1&z=2 ' ) );
		$this->assertSame( 'https://example.com/x?y=1&z=2', $this->meta( 'C', 'canonical' ) );
		$this->assertSame( 'https://example.com/x?y=1&z=2', $this->indexable( 'C' )['canonical'] );

		foreach ( array( 'javascript:alert(1)', 'example.com/page', '/relative', 'ftp://example.com/a', 'http://', array( 'https://example.com' ) ) as $bad ) {
			$result = $this->save( 'canonical', 'C', $bad );
			$this->assertWPError( $result, wp_json_encode( $bad ) );
			$this->assertSame( 'corinthian_invalid', $result->get_error_code() );
		}
		$this->assertSame( 'https://example.com/x?y=1&z=2', $this->meta( 'C', 'canonical' ) );

		$this->assertTrue( $this->save( 'canonical', 'C', '' ) );
		$this->assertFalse( $this->has_meta( 'C', 'canonical' ) );
		$this->assertNull( $this->indexable( 'C' )['canonical'] );
		$this->assertTrue( $this->save( 'canonical', 'A', null, 'clear' ) );
		$this->assertFalse( $this->has_meta( 'A', 'canonical' ) );
	}

	public function test_cornerstone_render_sort_filters(): void {
		$this->assertStringContainsString( 'Cornerstone', $this->render( 'cornerstone', 'A' ) );
		$this->assertStringContainsString( '&#8212;', $this->render( 'cornerstone', 'C' ) );
		$this->assertSame( array( 'B', 'A', 'E', 'D', 'C' ), $this->sorted( 'cornerstone', 'DESC' ) );
		$this->assertSame( array( 'A', 'B', 'C', 'D', 'E' ), $this->sorted( 'cornerstone', 'ASC' ) );
		$this->expect( array( 'A', 'B' ), 'cornerstone', 'eq', '1' );
		$this->expect( array( 'C', 'D', 'E' ), 'cornerstone', 'eq', '0' );
		delete_post_meta( $this->n['A'], YoastIntegration::META_PREFIX . 'is_cornerstone' );
		delete_post_meta( $this->n['B'], YoastIntegration::META_PREFIX . 'is_cornerstone' );
		$this->expect( array(), 'cornerstone', 'eq', '1' );
	}

	public function test_cornerstone_edit_reaches_the_indexable(): void {
		$this->assertTrue( $this->save( 'cornerstone', 'C', '1' ) );
		$this->assertSame( '1', $this->meta( 'C', 'is_cornerstone' ) );
		$this->assertEquals( 1, $this->indexable( 'C' )['is_cornerstone'] );
		$this->assertTrue( $this->save( 'cornerstone', 'C', false ) );
		$this->assertFalse( $this->has_meta( 'C', 'is_cornerstone' ) );
		$this->assertEquals( 0, $this->indexable( 'C' )['is_cornerstone'] );
		$this->assertTrue( $this->save( 'cornerstone', 'A', null, 'clear' ) );
		$this->assertEquals( 0, $this->indexable( 'A' )['is_cornerstone'] );
		$this->assertWPError( $this->save( 'cornerstone', 'C', 'kinda' ) );

		$this->as_editor();
		$this->set_option( 'enable_cornerstone_content', false );
		$off = $this->save( 'cornerstone', 'C', '1' );
		$this->assertWPError( $off );
		$this->assertSame( 'corinthian_disabled', $off->get_error_code() );
		$this->assertFalse( $this->has_meta( 'C', 'is_cornerstone' ) );
		$this->set_option( 'enable_cornerstone_content', true );
		$this->assertTrue( $this->save( 'cornerstone', 'C', '1' ), 'control: the feature on' );
	}

	public function test_primary_category_render_and_filters(): void {
		$this->assertSame( 'Cat one', $this->render( 'primary_category', 'A' ) );
		$this->assertSame( 'Cat two', $this->render( 'primary_category', 'B' ) );
		$this->assertStringContainsString( '&#8212;', $this->render( 'primary_category', 'C' ) );
		$this->expect( array( 'A' ), 'primary_category', 'eq', (string) $this->cat['one'] );
		$this->expect( array( 'B' ), 'primary_category', 'eq', (string) $this->cat['two'] );
		$this->expect( array(), 'primary_category', 'eq', (string) $this->cat['unused'] );
		$this->assertNull( $this->type( 'primary_category' )->filter_condition( $this->cfg( 'primary_category' ), 'eq', array( 1, 2 ), $this->screen, 'clf0' ) );
	}

	public function test_primary_category_edit_reaches_the_primary_term_table(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'yoast_primary_term';
		$term  = fn( string $post ) => $wpdb->get_var( $wpdb->prepare( "SELECT term_id FROM {$table} WHERE post_id = %d AND taxonomy = 'category'", $this->n[ $post ] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertTrue( $this->save( 'primary_category', 'A', (string) $this->cat['two'] ) );
		$this->assertSame( (string) $this->cat['two'], $this->meta( 'A', 'primary_category' ) );
		$this->assertSame( (string) $this->cat['two'], (string) $term( 'A' ), 'Yoast primary term row follows the edit' );
		$this->assertSame( $this->cat['two'], $this->type( 'primary_category' )->value( $this->n['A'], $this->cfg( 'primary_category' ), $this->screen ) );

		foreach ( array( (string) $this->cat['unused'], 'abc', '', '0', array( '1' ) ) as $bad ) {
			$result = $this->save( 'primary_category', 'A', $bad );
			$this->assertWPError( $result, wp_json_encode( $bad ) );
			$this->assertSame( 'corinthian_invalid', $result->get_error_code() );
		}
		$this->assertWPError( $this->save( 'primary_category', 'C', (string) $this->cat['one'] ), 'C is not in that category' );
		$this->assertSame( (string) $this->cat['two'], (string) $term( 'A' ) );

		$this->assertTrue( $this->save( 'primary_category', 'A', null, 'clear' ) );
		$this->assertFalse( $this->has_meta( 'A', 'primary_category' ) );
		$this->assertNull( $term( 'A' ), 'clearing removes the Yoast primary term row' );
	}

	public function test_primary_category_follows_the_taxonomy_setting(): void {
		register_taxonomy( 'cln_topic', 'post', array( 'hierarchical' => true ) );
		$topic = self::factory()->term->create(
			array(
				'taxonomy' => 'cln_topic',
				'name'     => 'Topic',
			)
		);
		wp_set_object_terms( $this->n['C'], array( $topic ), 'cln_topic' );
		$settings = array( 'taxonomy' => 'cln_topic' );

		$this->assertTrue( $this->save( 'primary_category', 'C', (string) $topic, 'set', $settings ) );
		$this->assertSame( (string) $topic, $this->meta( 'C', 'primary_cln_topic' ) );
		$this->assertFalse( $this->has_meta( 'C', 'primary_category' ) );
		$this->assertSame( 'Topic', $this->render( 'primary_category', 'C', $settings ) );
		$this->expect( array( 'C' ), 'primary_category', 'eq', (string) $topic, $settings );
		$this->assertWPError( $this->save( 'primary_category', 'A', (string) $topic, 'set', $settings ) );
		unregister_taxonomy( 'cln_topic' );
	}

	public function test_schema_page_type_render_sort_filters_and_edit(): void {
		$this->assertSame( 'ItemPage', $this->render( 'schema_page_type', 'A' ) );
		$default = $this->render( 'schema_page_type', 'D' );
		$this->assertStringContainsString( '<em>WebPage</em>', $default, 'Yoast default for posts' );
		$this->assertStringContainsString( '(default)', $default );

		$this->assertSame( array( 'B', 'A', 'C', 'D', 'E' ), $this->sorted( 'schema_page_type', 'ASC' ) );
		$this->expect( array( 'A' ), 'schema_page_type', 'in', array( 'ItemPage' ) );
		$this->expect( array( 'A', 'B' ), 'schema_page_type', 'in', array( 'ItemPage', 'AboutPage' ) );
		$this->expect( array( 'C', 'D', 'E' ), 'schema_page_type', 'in', array( 'default' ) );
		$this->expect( array( 'B', 'C', 'D', 'E' ), 'schema_page_type', 'not_in', array( 'ItemPage' ) );
		$this->expect( array(), 'schema_page_type', 'in', array( 'FAQPage' ) );
		$this->assertNull( $this->type( 'schema_page_type' )->filter_condition( $this->cfg( 'schema_page_type' ), 'in', array( 'Bogus' ), $this->screen, 'clf0' ) );

		$this->assertTrue( $this->save( 'schema_page_type', 'C', 'FAQPage' ) );
		$this->assertSame( 'FAQPage', $this->meta( 'C', 'schema_page_type' ) );
		$this->assertSame( 'FAQPage', $this->indexable( 'C' )['schema_page_type'] );
		$this->expect( array( 'C' ), 'schema_page_type', 'in', array( 'FAQPage' ) );
		$this->assertTrue( $this->save( 'schema_page_type', 'C', 'default' ) );
		$this->assertFalse( $this->has_meta( 'C', 'schema_page_type' ) );
		$this->assertNull( $this->indexable( 'C' )['schema_page_type'] );

		foreach ( array( 'Bogus', 'NewsArticle', array( 'FAQPage' ) ) as $bad ) {
			$result = $this->save( 'schema_page_type', 'C', $bad );
			$this->assertWPError( $result, wp_json_encode( $bad ) );
			$this->assertSame( 'corinthian_invalid', $result->get_error_code() );
		}
		$this->assertTrue( $this->save( 'schema_page_type', 'A', null, 'clear' ) );
		$this->assertFalse( $this->has_meta( 'A', 'schema_page_type' ) );
	}

	public function test_schema_article_type_render_filters_and_edit(): void {
		$this->assertSame( 'NewsArticle', $this->render( 'schema_article_type', 'A' ) );
		$this->assertStringContainsString( '<em>Article</em>', $this->render( 'schema_article_type', 'D' ) );
		$this->expect( array( 'A' ), 'schema_article_type', 'in', array( 'NewsArticle' ) );
		$this->expect( array( 'B' ), 'schema_article_type', 'in', array( 'BlogPosting' ) );
		$this->expect( array( 'D', 'E' ), 'schema_article_type', 'in', array( 'default' ) );
		$this->expect( array( 'A', 'C', 'D', 'E' ), 'schema_article_type', 'not_in', array( 'BlogPosting' ) );
		$this->expect( array(), 'schema_article_type', 'in', array( 'Report' ) );

		$this->assertTrue( $this->save( 'schema_article_type', 'D', 'TechArticle' ) );
		$this->assertSame( 'TechArticle', $this->indexable( 'D' )['schema_article_type'] );
		$this->assertTrue( $this->save( 'schema_article_type', 'D', 'default' ) );
		$this->assertNull( $this->indexable( 'D' )['schema_article_type'] );

		foreach ( array( 'ItemPage', 'Bogus', array( 'Report' ) ) as $bad ) {
			$this->assertSame( 'corinthian_invalid', $this->save( 'schema_article_type', 'D', $bad )->get_error_code(), wp_json_encode( $bad ) );
		}
		add_filter(
			'wpseo_schema_article_types',
			static fn( $types ) => array_intersect_key(
				$types,
				array(
					'Article' => 1,
					'None'    => 1,
				)
			)
		);
		$this->assertSame( 'corinthian_invalid', $this->save( 'schema_article_type', 'D', 'TechArticle' )->get_error_code(), 'Yoast narrows the list with its own filter' );
		$this->assertTrue( $this->save( 'schema_article_type', 'D', 'None' ) );
	}

	public function test_internal_link_counts_render_sort_and_filters(): void {
		$this->assertSame( '3', $this->render( 'internal_links_out', 'A' ) );
		$this->assertSame( '0', $this->render( 'internal_links_out', 'C' ) );
		$this->assertStringContainsString( '&#8212;', $this->render( 'internal_links_out', 'D' ), 'indexed with no count yet' );
		$this->assertStringContainsString( '&#8212;', $this->render( 'internal_links_out', 'E' ), 'no indexable row' );
		$this->assertSame( '2', $this->render( 'internal_links_in', 'B' ) );
		$this->assertSame( 3, $this->type( 'internal_links_out' )->value( $this->n['A'], $this->cfg( 'internal_links_out' ), $this->screen ) );

		$this->assertSame( array( 'A', 'B', 'C', 'E', 'D' ), $this->sorted( 'internal_links_out', 'DESC' ) );
		$this->assertSame( array( 'C', 'B', 'A', 'D', 'E' ), $this->sorted( 'internal_links_out', 'ASC' ) );
		$this->assertSame( array( 'B', 'C', 'A', 'E', 'D' ), $this->sorted( 'internal_links_in', 'DESC' ) );

		$this->expect( array( 'C' ), 'internal_links_out', 'eq', '0' );
		$this->expect( array( 'A', 'B' ), 'internal_links_out', 'gt', '0' );
		$this->expect( array( 'B', 'C' ), 'internal_links_out', 'lt', '2' );
		$this->expect( array( 'A', 'B' ), 'internal_links_out', 'between', array( '1', '3' ) );
		$this->expect( array( 'D', 'E' ), 'internal_links_out', 'empty', null );
		$this->expect( array(), 'internal_links_out', 'gt', '5' );
		$this->expect( array( 'B' ), 'internal_links_in', 'gt', '1' );
		$this->expect( array( 'A' ), 'internal_links_in', 'eq', '0' );
		$this->expect( array(), 'internal_links_in', 'between', array( '5', '9' ) );
		$this->assertNull( $this->type( 'internal_links_in' )->filter_condition( $this->cfg( 'internal_links_in' ), 'contains', 'x', $this->screen, 'clf0' ) );
	}

	public function test_internal_link_counts_come_from_yoasts_own_link_builder(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$target = $this->n['D'];
		$source = $this->n['E'];
		wp_update_post(
			array(
				'ID'           => $source,
				'post_content' => '<p><a href="' . get_permalink( $target ) . '">internal</a> <a href="https://elsewhere.test/x">external</a></p>',
			)
		);
		$watcher = YoastIntegration::service( 'Yoast\WP\SEO\Integrations\Watchers\Indexable_Post_Watcher' );
		$this->assertNotNull( $watcher );
		call_user_func( array( $watcher, 'build_indexable' ), $source );
		wp_cache_flush();

		$this->assertSame( 1, $this->type( 'internal_links_out' )->value( $source, $this->cfg( 'internal_links_out' ), $this->screen ) );
		$this->assertSame( 1, $this->type( 'internal_links_in' )->value( $target, $this->cfg( 'internal_links_in' ), $this->screen ) );
	}

	public function test_advanced_fields_need_yoasts_advanced_capability(): void {
		$advanced = array(
			array( 'noindex', 'noindex', 'meta-robots-noindex', '1' ),
			array( 'nofollow', '1', 'meta-robots-nofollow', '1' ),
			array( 'canonical', 'https://example.com/z', 'canonical', 'https://example.com/z' ),
			array( 'schema_page_type', 'FAQPage', 'schema_page_type', 'FAQPage' ),
			array( 'schema_article_type', 'Report', 'schema_article_type', 'Report' ),
		);
		$author   = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_update_post(
			array(
				'ID'          => $this->n['D'],
				'post_author' => $author,
			)
		);
		wp_set_current_user( $author );

		foreach ( $advanced as list( $key, $input, $name ) ) {
			$result = $this->save( $key, 'D', $input );
			$this->assertWPError( $result, "$key: an author lacks wpseo_edit_advanced_metadata" );
			$this->assertSame( 'corinthian_protected', $result->get_error_code(), $key );
			$this->assertFalse( $this->has_meta( 'D', $name ), $key );
			$this->assertWPError( $this->save( $key, 'D', null, 'clear' ), "$key clear" );
		}
		$this->assertTrue( $this->save( 'seo_title', 'D', 'Author title' ), 'control: general fields need only edit_post' );
		$this->assertTrue( $this->save( 'meta_description', 'D', 'Author desc' ) );
		$this->assertTrue( $this->save( 'focus_keyphrase', 'D', 'author kw' ) );

		$this->set_option( 'disableadvanced_meta', false );
		foreach ( $advanced as list( $key, $input, $name, $stored ) ) {
			$this->assertTrue( $this->save( $key, 'D', $input ), "$key: control, Yoast's advanced switch off" );
			$this->assertSame( $stored, $this->meta( 'D', $name ), $key );
		}
	}

	public function test_advanced_fields_allowed_for_roles_holding_the_capability(): void {
		$this->as_editor();
		$this->assertTrue( $this->save( 'noindex', 'C', 'noindex' ), 'an editor holds wpseo_edit_advanced_metadata' );
		$this->assertTrue( $this->save( 'canonical', 'C', 'https://example.com/e' ) );

		get_role( 'editor' )->remove_cap( 'wpseo_edit_advanced_metadata' );
		$this->as_editor();
		$this->assertWPError( $this->save( 'noindex', 'C', 'index' ), 'an editor without it' );
		$this->assertSame( '1', $this->meta( 'C', 'meta-robots-noindex' ) );

		get_role( 'editor' )->add_cap( 'wpseo_manage_options' );
		$this->as_editor();
		$this->assertTrue( $this->save( 'noindex', 'C', 'index' ), 'wpseo_manage_options counts as holding every Yoast capability' );
	}

	public function test_a_user_who_cannot_edit_the_post_is_refused_every_field(): void {
		$this->as_subscriber();
		$this->set_option( 'disableadvanced_meta', false );
		$inputs = array(
			'seo_title'        => 'x',
			'noindex'          => 'noindex',
			'nofollow'         => '1',
			'canonical'        => 'https://example.com/z',
			'cornerstone'      => '1',
			'schema_page_type' => 'FAQPage',
			'primary_category' => (string) $this->cat['one'],
		);
		foreach ( $inputs as $key => $input ) {
			$result = $this->save( $key, 'A', $input );
			$this->assertWPError( $result, $key );
			$this->assertSame( 'corinthian_protected', $result->get_error_code(), $key );
		}
		$this->assertSame( '1', $this->meta( 'A', 'meta-robots-noindex' ) );
		$this->assertSame( (string) $this->cat['one'], $this->meta( 'A', 'primary_category' ) );
	}

	public function test_two_rules_on_one_column_do_not_collide(): void {
		$type   = $this->type( 'focus_keyphrase' );
		$config = $this->cfg( 'focus_keyphrase' );
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
