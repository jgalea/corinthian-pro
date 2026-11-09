<?php

namespace CorinthianPro\Tests;

use Corinthian\Columns\Config;
use Corinthian\Columns\Filterable;
use Corinthian\Columns\Registry;
use Corinthian\Columns\Types\TypeLoader;
use Corinthian\Screens\PostTypeScreen;
use Corinthian\Sets\SetRepository;
use WP_Query;
use WP_UnitTestCase;

class PostFiltersTest extends WP_UnitTestCase {

	private const FILTERABLE = array(
		'id',
		'slug',
		'status',
		'menu_order',
		'comment_count',
		'template',
		'comment_status',
		'ping_status',
		'last_modified_by',
		'attachments_count',
		'children_count',
		'revisions_count',
		'published_date',
		'modified_date',
		'author',
		'taxonomy',
		'format',
		'sticky',
		'parent',
		'word_count',
		'reading_time',
		'excerpt',
		'featured_image',
		'has_featured_image',
	);

	private const DISPLAY_ONLY = array(
		'permalink',
		'shortlink',
		'guid',
		'relative_path',
		'edit_lock',
		'type',
	);

	private Registry $registry;
	private PostTypeScreen $screen;
	private PostTypeScreen $pages;

	/** @var array<string,int> */
	private array $n = array();
	/** @var array<string,int> */
	private array $pg = array();
	private int $alice;
	private int $bob;
	private int $zeta;
	private int $alpha;
	private int $mid;
	private int $zzz;
	private int $unused;
	private string $tz_before;

	public function set_up(): void {
		parent::set_up();
		$this->tz_before = (string) get_option( 'timezone_string' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		register_taxonomy(
			'corinthian_topic',
			'post',
			array(
				'label'     => 'Topics',
				'public'    => true,
				'show_ui'   => true,
				'query_var' => false,
			)
		);

		$this->registry = new Registry();
		TypeLoader::register( $this->registry );
		$this->screen = new PostTypeScreen( $this->registry, new SetRepository(), get_post_type_object( 'post' ) );
		$this->pages  = new PostTypeScreen( $this->registry, new SetRepository(), get_post_type_object( 'page' ) );

		$this->alice = self::factory()->user->create( array( 'display_name' => 'Alice' ) );
		$this->bob   = self::factory()->user->create( array( 'display_name' => 'Bob' ) );

		$this->n['A'] = self::factory()->post->create(
			array(
				'post_title'   => 'Alpha',
				'post_name'    => 'slug-alpha',
				'post_content' => '<p>one two three</p>',
				'post_excerpt' => 'hello world',
				'post_date'    => '2024-01-10 10:00:00',
				'post_author'  => $this->alice,
				'menu_order'   => 5,
			)
		);
		$this->n['B'] = self::factory()->post->create(
			array(
				'post_title'   => 'Beta',
				'post_name'    => 'slug-beta',
				'post_content' => 'one two three four five six seven eight nine ten',
				'post_date'    => '2024-02-10 10:00:00',
				'post_author'  => $this->bob,
			)
		);
		$this->n['C'] = self::factory()->post->create(
			array(
				'post_title'   => 'Gamma',
				'post_name'    => 'gamma',
				'post_status'  => 'draft',
				'post_content' => str_repeat( 'word ', 450 ),
				'post_date'    => '2024-03-10 10:00:00',
				'post_author'  => $this->alice,
			)
		);
		$this->n['D'] = self::factory()->post->create(
			array(
				'post_title'   => 'Empty',
				'post_status'  => 'draft',
				'post_name'    => '',
				'post_content' => '',
				'post_date'    => '2024-04-10 10:00:00',
			)
		);

		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_author' => 0 ), array( 'ID' => $this->n['D'] ) );
		foreach ( array( 'B', 'C', 'D' ) as $name ) {
			$wpdb->update( $wpdb->posts, array( 'post_excerpt' => '' ), array( 'ID' => $this->n[ $name ] ) );
		}
		$wpdb->update( $wpdb->posts, array( 'comment_count' => 2 ), array( 'ID' => $this->n['A'] ) );
		$wpdb->update( $wpdb->posts, array( 'comment_count' => 7 ), array( 'ID' => $this->n['B'] ) );
		$wpdb->update( $wpdb->posts, array( 'comment_status' => 'closed' ), array( 'ID' => $this->n['B'] ) );
		$wpdb->update( $wpdb->posts, array( 'comment_status' => 'closed' ), array( 'ID' => $this->n['D'] ) );
		$wpdb->update( $wpdb->posts, array( 'ping_status' => 'closed' ), array( 'ID' => $this->n['B'] ) );
		$this->set_modified( array( 'A' => 2, 'B' => 10, 'C' => 40, 'D' => 100 ) );

		$attachment = self::factory()->attachment->create_object( 'cln-a.png', 0, array( 'post_mime_type' => 'image/png' ) );
		set_post_thumbnail( $this->n['A'], $attachment );
		update_post_meta( $this->n['B'], '_thumbnail_id', 0 );
		set_post_format( $this->n['A'], 'aside' );
		set_post_format( $this->n['B'], 'quote' );
		stick_post( $this->n['B'] );

		update_post_meta( $this->n['B'], '_wp_page_template', 'tpl.php' );
		update_post_meta( $this->n['C'], '_wp_page_template', 'default' );
		update_post_meta( $this->n['D'], '_wp_page_template', '' );
		update_post_meta( $this->n['A'], '_edit_last', $this->alice );
		update_post_meta( $this->n['B'], '_edit_last', $this->bob );

		foreach ( array( 'Zeta', 'Alpha', 'Zzz', 'Mid', 'Unused' ) as $name ) {
			wp_insert_term( $name, 'corinthian_topic' );
		}
		$this->zeta   = (int) get_term_by( 'name', 'Zeta', 'corinthian_topic' )->term_id;
		$this->alpha  = (int) get_term_by( 'name', 'Alpha', 'corinthian_topic' )->term_id;
		$this->zzz    = (int) get_term_by( 'name', 'Zzz', 'corinthian_topic' )->term_id;
		$this->mid    = (int) get_term_by( 'name', 'Mid', 'corinthian_topic' )->term_id;
		$this->unused = (int) get_term_by( 'name', 'Unused', 'corinthian_topic' )->term_id;
		wp_set_object_terms( $this->n['A'], array( $this->zeta ), 'corinthian_topic' );
		wp_set_object_terms( $this->n['B'], array( $this->alpha ), 'corinthian_topic' );
		wp_set_object_terms( $this->n['C'], array( $this->zzz, $this->mid ), 'corinthian_topic' );

		foreach ( array( 'A', 'A', 'B' ) as $i => $post ) {
			self::factory()->attachment->create_object( "cln-child-$i.png", $this->n[ $post ], array( 'post_mime_type' => 'image/png' ) );
		}
		foreach ( array( 1, 2 ) as $v ) {
			wp_insert_post(
				array(
					'post_type'   => 'revision',
					'post_status' => 'inherit',
					'post_parent' => $this->n['A'],
					'post_title'  => 'rev',
					'post_name'   => $this->n['A'] . '-revision-v' . $v,
				)
			);
		}

		delete_post_meta( $this->n['D'], '_corinthian_word_count' );

		$this->pg['P']  = self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'Parent' ) );
		$this->pg['Q']  = self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'Child', 'post_parent' => $this->pg['P'] ) );
		$this->pg['Q2'] = self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'Child 2', 'post_parent' => $this->pg['P'] ) );
		$this->pg['R']  = self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'Solo' ) );
	}

	public function tear_down(): void {
		update_option( 'timezone_string', $this->tz_before );
		unregister_taxonomy( 'corinthian_topic' );
		parent::tear_down();
	}

	/**
	 * @param array<string,int> $days_ago
	 */
	private function set_modified( array $days_ago ): void {
		global $wpdb;
		foreach ( $days_ago as $name => $days ) {
			$wpdb->update( $wpdb->posts, array( 'post_modified' => wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ), array( 'ID' => $this->n[ $name ] ) );
		}
	}

	/**
	 * Runs a real WP_Query with the compiled join and where appended, as
	 * PostTypeScreen::maybe_filter does, and returns the matching post names.
	 *
	 * @param array<string,mixed> $settings
	 * @return list<string>|null null when the rule is rejected
	 */
	private function query_names( string $key, string $op, mixed $value, array $settings = array(), bool $pages = false ): ?array {
		$screen = $pages ? $this->pages : $this->screen;
		$type   = $this->registry->get( 'post.' . $key );
		$this->assertInstanceOf( Filterable::class, $type, "post.$key is filterable" );
		$config = new Config( 'c1', 'post.' . $key, 'x', null, 'px', $settings );
		$this->assertContains( $op, array_column( $type->filter_operators( $config, $screen ), 'op' ), "post.$key offers $op" );

		$sql = $type->filter_condition( $config, $op, $value, $screen, 'clf0' );
		if ( null === $sql ) {
			return null;
		}
		$ids      = $pages ? $this->pg : $this->n;
		$callback = static function ( $clauses ) use ( $sql ) {
			$clauses['join']  .= ' ' . $sql['join'];
			$clauses['where'] .= ' AND ' . $sql['where'];
			return $clauses;
		};
		add_filter( 'posts_clauses', $callback );
		$query = new WP_Query(
			array(
				'post_type'        => $pages ? 'page' : 'post',
				'post_status'      => 'any',
				'post__in'         => array_values( $ids ),
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'nopaging'         => true,
				'suppress_filters' => false,
			)
		);
		remove_filter( 'posts_clauses', $callback );
		$this->assertSame( '', (string) $GLOBALS['wpdb']->last_error, 'SQL ran without errors' );

		$flip = array_flip( $ids );
		return array_map( static fn( $id ) => $flip[ (int) $id ], $query->posts );
	}

	/**
	 * @param list<string>        $expected
	 * @param array<string,mixed> $settings
	 */
	private function expect( array $expected, string $key, string $op, mixed $value, array $settings = array(), bool $pages = false ): void {
		$got = $this->query_names( $key, $op, $value, $settings, $pages );
		$this->assertNotNull( $got, "post.$key $op was rejected" );
		$this->assertSame( $expected, $got, "post.$key $op " . wp_json_encode( $value ) );
	}

	public function test_every_filterable_type_is_registered_and_display_types_are_not(): void {
		foreach ( self::FILTERABLE as $key ) {
			$this->assertInstanceOf( Filterable::class, $this->registry->get( 'post.' . $key ), $key );
		}
		foreach ( self::DISPLAY_ONLY as $key ) {
			$this->assertNotInstanceOf( Filterable::class, $this->registry->get( 'post.' . $key ), $key );
		}
	}

	public function test_select_operators_carry_real_options(): void {
		$config = static fn( string $key, array $settings = array() ) => new Config( 'c1', 'post.' . $key, 'x', null, 'px', $settings );
		$options = function ( string $key, array $settings = array() ) use ( $config ): array {
			$ops = $this->registry->get( 'post.' . $key )->filter_operators( $config( $key, $settings ), $this->screen );
			$in  = array_values( array_filter( $ops, static fn( $o ) => 'in' === $o['op'] ) );
			return $in ? $in[0]['options'] : array();
		};

		$this->assertArrayHasKey( 'publish', $options( 'status' ) );
		$this->assertArrayHasKey( 'draft', $options( 'status' ) );
		$this->assertArrayNotHasKey( 'auto-draft', $options( 'status' ) );
		$this->assertSame( 'Alice', $options( 'author' )[ (string) $this->alice ] );
		$this->assertSame( 'Bob', $options( 'last_modified_by' )[ (string) $this->bob ] );
		$this->assertArrayHasKey( 'aside', $options( 'format' ) );
		$this->assertArrayHasKey( 'standard', $options( 'format' ) );
		$this->assertSame( 'Zeta', $options( 'taxonomy', array( 'taxonomy' => 'corinthian_topic' ) )[ (string) $this->zeta ] );
		$this->assertSame( array( 'open', 'closed' ), array_keys( $options( 'comment_status' ) ) );
		$this->assertSame( array( 'open', 'closed' ), array_keys( $options( 'ping_status' ) ) );
		$this->assertArrayHasKey( 'default', $options( 'template' ) );
		foreach ( array( 'sticky', 'has_featured_image' ) as $key ) {
			$ops = $this->registry->get( 'post.' . $key )->filter_operators( $config( $key ), $this->screen );
			$this->assertSame( array( '1', '0' ), array_map( 'strval', array_keys( $ops[0]['options'] ) ), $key );
		}
	}

	public function test_text_fields(): void {
		$this->expect( array( 'A', 'B' ), 'slug', 'contains', 'slug' );
		$this->expect( array( 'C', 'D' ), 'slug', 'not_contains', 'slug' );
		$this->expect( array( 'C' ), 'slug', 'eq', 'gamma' );
		$this->expect( array( 'A', 'B', 'D' ), 'slug', 'neq', 'gamma' );
		$this->expect( array( 'B' ), 'slug', 'starts', 'slug-b' );
		$this->expect( array( 'D' ), 'slug', 'empty', null );
		$this->expect( array( 'A', 'B', 'C' ), 'slug', 'not_empty', null );
		$this->expect( array( 'A' ), 'excerpt', 'contains', 'hello' );
		$this->expect( array( 'B', 'C', 'D' ), 'excerpt', 'empty', null );
		$this->expect( array( 'A' ), 'excerpt', 'starts', 'hello w' );
		$this->expect( array( 'B', 'C', 'D' ), 'excerpt', 'not_contains', 'hello' );

		// Negative controls: wildcards are literal, injection is inert, no match returns nothing.
		$this->expect( array(), 'slug', 'contains', 'zzzz-none' );
		$this->expect( array(), 'slug', 'contains', '%' );
		$this->expect( array(), 'slug', 'eq', "' OR 1=1 -- " );
		$this->expect( array(), 'excerpt', 'eq', 'hello' );
		$this->assertNull( $this->query_names( 'slug', 'contains', '' ) );
		$this->assertNull( $this->query_names( 'slug', 'eq', array( 'x' ) ) );
	}

	public function test_numbers_and_counts(): void {
		$a = $this->n['A'];
		$this->expect( array( 'A' ), 'id', 'eq', (string) $a );
		$this->expect( array( 'B', 'C', 'D' ), 'id', 'gt', (string) $a );
		$this->expect( array( 'A', 'B' ), 'id', 'between', array( (string) $a, (string) $this->n['B'] ) );
		$this->expect( array( 'B', 'C', 'D' ), 'id', 'neq', (string) $a );

		$this->expect( array( 'A' ), 'menu_order', 'eq', '5' );
		$this->expect( array( 'A' ), 'menu_order', 'gt', '0' );
		$this->expect( array( 'B', 'C', 'D' ), 'menu_order', 'empty', null );
		$this->expect( array( 'A' ), 'menu_order', 'between', array( '1', '9' ) );

		$this->expect( array( 'B' ), 'comment_count', 'gt', '2' );
		$this->expect( array( 'A', 'C', 'D' ), 'comment_count', 'lt', '7' );
		$this->expect( array( 'A', 'B' ), 'comment_count', 'between', array( '2', '7' ) );
		$this->expect( array( 'C', 'D' ), 'comment_count', 'empty', null );
		$this->expect( array( 'B', 'C', 'D' ), 'comment_count', 'neq', '2' );

		$this->expect( array( 'A' ), 'attachments_count', 'eq', '2' );
		$this->expect( array( 'A', 'B' ), 'attachments_count', 'gt', '0' );
		$this->expect( array( 'C', 'D' ), 'attachments_count', 'empty', null );
		$this->expect( array( 'A' ), 'revisions_count', 'eq', '2' );
		$this->expect( array( 'B', 'C', 'D' ), 'revisions_count', 'lt', '1' );
		$this->expect( array( 'P' ), 'children_count', 'eq', '2', array(), true );
		$this->expect( array( 'Q', 'Q2', 'R' ), 'children_count', 'empty', null, array(), true );
		$this->expect( array( 'P' ), 'children_count', 'gt', '0', array(), true );

		// Negative controls.
		$this->expect( array(), 'id', 'eq', '999999999' );
		$this->expect( array(), 'menu_order', 'gt', '100' );
		$this->expect( array(), 'comment_count', 'eq', '99' );
		$this->expect( array(), 'attachments_count', 'gt', '5' );
		$this->expect( array(), 'revisions_count', 'eq', '5' );
		$this->expect( array(), 'children_count', 'gt', '5', array(), true );
		$this->assertNull( $this->query_names( 'comment_count', 'gt', 'abc' ) );
		$this->assertNull( $this->query_names( 'comment_count', 'between', array( '1' ) ) );
	}

	public function test_word_count_and_reading_time(): void {
		$this->expect( array( 'B' ), 'word_count', 'eq', '10' );
		$this->expect( array( 'B', 'C', 'D' ), 'word_count', 'neq', '3' );
		$this->expect( array( 'C' ), 'word_count', 'gt', '10' );
		$this->expect( array( 'A' ), 'word_count', 'lt', '10' );
		$this->expect( array( 'A', 'B' ), 'word_count', 'between', array( '3', '10' ) );
		$this->expect( array( 'D' ), 'word_count', 'empty', null );
		$this->expect( array(), 'word_count', 'gt', '1000' );

		// 200 words per minute: A and B are 1 min, C (450 words) is 3 min, D has no stored count.
		$this->expect( array( 'A', 'B' ), 'reading_time', 'eq', '1' );
		$this->expect( array( 'C' ), 'reading_time', 'eq', '3' );
		$this->expect( array( 'C', 'D' ), 'reading_time', 'neq', '1' );
		$this->expect( array( 'C' ), 'reading_time', 'gt', '1' );
		$this->expect( array( 'A', 'B' ), 'reading_time', 'lt', '2' );
		$this->expect( array( 'A', 'B', 'C' ), 'reading_time', 'between', array( '1', '3' ) );
		$this->expect( array( 'D' ), 'reading_time', 'empty', null );
		$this->expect( array( 'C' ), 'reading_time', 'eq', '5', array( 'words_per_minute' => 100 ) );
		$this->expect( array(), 'reading_time', 'between', array( '4', '9' ) );
		$this->expect( array(), 'reading_time', 'eq', '2' );
		$this->assertNull( $this->query_names( 'reading_time', 'eq', '0' ) );
	}

	public function test_dates(): void {
		$this->expect( array( 'A' ), 'published_date', 'before', '2024-02-10' );
		$this->expect( array( 'C', 'D' ), 'published_date', 'after', '2024-02-10' );
		$this->expect( array( 'A', 'B', 'C', 'D' ), 'published_date', 'older_than_days', '30' );
		$this->expect( array( 'A', 'B', 'C', 'D' ), 'modified_date', 'after', '2000-01-01' );
		$this->expect( array( 'A' ), 'modified_date', 'within_days', '5' );
		$this->expect( array( 'A', 'B' ), 'modified_date', 'within_days', '20' );
		$this->expect( array( 'C', 'D' ), 'modified_date', 'older_than_days', '30' );
		$this->expect( array( 'D' ), 'modified_date', 'before', wp_date( 'Y-m-d', time() - 60 * DAY_IN_SECONDS ) );

		// Negative controls.
		$this->expect( array(), 'published_date', 'before', '2024-01-01' );
		$this->expect( array(), 'published_date', 'within_days', '1' );
		$this->expect( array(), 'modified_date', 'within_days', '1' );
		$this->expect( array(), 'modified_date', 'after', wp_date( 'Y-m-d', time() + 5 * DAY_IN_SECONDS ) );
		$this->assertNull( $this->query_names( 'published_date', 'before', 'not-a-date' ) );
		$this->assertNull( $this->query_names( 'published_date', 'before', '2024-02-31' ) );
		$this->assertNull( $this->query_names( 'modified_date', 'within_days', '-3' ) );
	}

	public function test_dates_follow_the_site_timezone(): void {
		update_option( 'timezone_string', 'Pacific/Kiritimati' );
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_modified' => wp_date( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ), array( 'ID' => $this->n['A'] ) );
		$this->set_modified( array( 'B' => 10, 'C' => 40, 'D' => 100 ) );

		// A local clock 14 hours ahead of UTC: a post saved an hour ago still counts as within a day.
		$this->expect( array( 'A' ), 'modified_date', 'within_days', '1' );
		$this->expect( array( 'A' ), 'modified_date', 'after', wp_date( 'Y-m-d', time() - 2 * DAY_IN_SECONDS ) );
		$this->expect( array(), 'modified_date', 'older_than_days', '1000' );
	}

	public function test_select_fields(): void {
		$this->expect( array( 'A', 'B' ), 'status', 'in', array( 'publish' ) );
		$this->expect( array( 'C', 'D' ), 'status', 'in', array( 'draft' ) );
		$this->expect( array( 'C', 'D' ), 'status', 'not_in', array( 'publish' ) );
		$this->expect( array( 'A', 'B', 'C', 'D' ), 'status', 'in', array( 'publish', 'draft' ) );
		$this->expect( array(), 'status', 'in', array( 'future' ) );

		$this->expect( array( 'A', 'C' ), 'author', 'in', array( (string) $this->alice ) );
		$this->expect( array( 'B', 'D' ), 'author', 'not_in', array( (string) $this->alice ) );
		$this->expect( array( 'A', 'B', 'C' ), 'author', 'in', array( (string) $this->alice, (string) $this->bob ) );
		$this->expect( array(), 'author', 'in', array( '999999' ) );

		$this->expect( array( 'A' ), 'format', 'in', array( 'aside' ) );
		$this->expect( array( 'B' ), 'format', 'in', array( 'quote' ) );
		$this->expect( array( 'C', 'D' ), 'format', 'in', array( 'standard' ) );
		$this->expect( array( 'A', 'B' ), 'format', 'not_in', array( 'standard' ) );
		$this->expect( array( 'B', 'C', 'D' ), 'format', 'not_in', array( 'aside' ) );
		$this->expect( array( 'A', 'C', 'D' ), 'format', 'in', array( 'aside', 'standard' ) );
		$this->expect( array(), 'format', 'in', array( 'video' ) );
		$this->assertNull( $this->query_names( 'format', 'in', array( 'bogus' ) ) );

		$this->expect( array( 'B' ), 'template', 'in', array( 'tpl.php' ) );
		$this->expect( array( 'A', 'C', 'D' ), 'template', 'in', array( 'default' ) );
		$this->expect( array( 'A', 'C', 'D' ), 'template', 'not_in', array( 'tpl.php' ) );
		$this->expect( array( 'B' ), 'template', 'not_in', array( 'default' ) );
		$this->expect( array(), 'template', 'in', array( 'missing.php' ) );

		$this->expect( array( 'B', 'D' ), 'comment_status', 'in', array( 'closed' ) );
		$this->expect( array( 'A', 'C' ), 'comment_status', 'not_in', array( 'closed' ) );
		$this->expect( array( 'B' ), 'ping_status', 'in', array( 'closed' ) );
		$this->expect( array( 'A', 'C', 'D' ), 'ping_status', 'in', array( 'open' ) );
		$this->expect( array(), 'comment_status', 'in', array( 'bogus' ) );

		$this->expect( array( 'A' ), 'last_modified_by', 'in', array( (string) $this->alice ) );
		$this->expect( array( 'B', 'C', 'D' ), 'last_modified_by', 'not_in', array( (string) $this->alice ) );
		$this->expect( array(), 'last_modified_by', 'in', array( '999999' ) );
		$this->assertNull( $this->query_names( 'status', 'in', array() ) );
	}

	public function test_taxonomy_uses_exists_and_never_duplicates_posts(): void {
		$s = array( 'taxonomy' => 'corinthian_topic' );
		$this->expect( array( 'A' ), 'taxonomy', 'in', array( (string) $this->zeta ), $s );
		$this->expect( array( 'B', 'C' ), 'taxonomy', 'in', array( (string) $this->zzz, (string) $this->alpha ), $s );
		// C has two matching terms and still appears once.
		$this->expect( array( 'C' ), 'taxonomy', 'in', array( (string) $this->zzz, (string) $this->mid ), $s );
		$this->expect( array( 'B', 'C', 'D' ), 'taxonomy', 'not_in', array( (string) $this->zeta ), $s );
		$this->expect( array( 'A', 'B', 'D' ), 'taxonomy', 'not_in', array( (string) $this->mid ), $s );
		$this->expect( array( 'D' ), 'taxonomy', 'empty', null, $s );
		$this->expect( array( 'A', 'B', 'C' ), 'taxonomy', 'not_empty', null, $s );
		$this->expect( array(), 'taxonomy', 'in', array( (string) $this->unused ), $s );
		$this->assertNull( $this->query_names( 'taxonomy', 'in', array( '0' ), $s ) );
	}

	public function test_boolean_fields(): void {
		$this->expect( array( 'B' ), 'sticky', 'eq', '1' );
		$this->expect( array( 'A', 'C', 'D' ), 'sticky', 'eq', '0' );
		$this->expect( array( 'A' ), 'has_featured_image', 'eq', '1' );
		$this->expect( array( 'B', 'C', 'D' ), 'has_featured_image', 'eq', '0' );
		$this->expect( array( 'A' ), 'featured_image', 'not_empty', null );
		$this->expect( array( 'B', 'C', 'D' ), 'featured_image', 'empty', null );

		// Negative controls: nothing sticky, no thumbnails.
		update_option( 'sticky_posts', array() );
		$this->expect( array(), 'sticky', 'eq', '1' );
		$this->expect( array( 'A', 'B', 'C', 'D' ), 'sticky', 'eq', '0' );
		delete_post_thumbnail( $this->n['A'] );
		$this->expect( array(), 'has_featured_image', 'eq', '1' );
		$this->expect( array(), 'featured_image', 'not_empty', null );
		$this->assertNull( $this->query_names( 'sticky', 'eq', 'maybe' ) );
	}

	public function test_parent(): void {
		$this->expect( array( 'Q', 'Q2' ), 'parent', 'eq', (string) $this->pg['P'], array(), true );
		$this->expect( array( 'P', 'R' ), 'parent', 'empty', null, array(), true );
		$this->expect( array(), 'parent', 'eq', (string) $this->pg['R'], array(), true );
		$this->assertNull( $this->query_names( 'parent', 'eq', '0', array(), true ) );
	}

	public function test_two_rules_of_one_type_use_distinct_aliases(): void {
		$word = $this->registry->get( 'post.word_count' );
		$cfg  = new Config( 'c1', 'post.word_count', 'x' );
		$one  = $word->filter_condition( $cfg, 'gt', '2', $this->screen, 'clf0' );
		$two  = $word->filter_condition( $cfg, 'lt', '11', $this->screen, 'clf1' );
		$cb   = static function ( $clauses ) use ( $one, $two ) {
			$clauses['join']  .= ' ' . $one['join'] . ' ' . $two['join'];
			$clauses['where'] .= ' AND (' . $one['where'] . ') AND (' . $two['where'] . ')';
			return $clauses;
		};
		add_filter( 'posts_clauses', $cb );
		$query = new WP_Query(
			array(
				'post_type'        => 'post',
				'post_status'      => 'any',
				'post__in'         => array_values( $this->n ),
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);
		remove_filter( 'posts_clauses', $cb );
		$this->assertSame( array( $this->n['A'], $this->n['B'] ), array_map( 'intval', $query->posts ) );
	}
}
