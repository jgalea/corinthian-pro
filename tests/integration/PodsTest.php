<?php

namespace CorinthianPro\Tests;

use Corinthian\Columns\Config;
use Corinthian\Columns\Registry;
use CorinthianPro\Integrations\Pods\PodsFieldType;
use CorinthianPro\Integrations\Pods\PodsIntegration;
use Corinthian\Plugin;
use Corinthian\Screens\CommentScreen;
use Corinthian\Screens\PostTypeScreen;
use Corinthian\Screens\Screen;
use Corinthian\Screens\TermScreen;
use Corinthian\Screens\UserScreen;
use Corinthian\Sets\SetRepository;
use WP_Error;
use WP_Query;
use WP_UnitTestCase;
use WP_User_Query;

class PodsTest extends WP_UnitTestCase {

	private const ASC  = array( 'p2', 'p1', 'p3', 'p4', 'p5' );
	private const DESC = array( 'p3', 'p1', 'p2', 'p5', 'p4' );

	private Registry $registry;
	private PodsFieldType $type;
	private Screen $posts;
	private Screen $pages;
	private Screen $users;
	private Screen $terms;

	/** @var array<string,int> p1..p5 are the posts under test */
	private array $p = array();

	/** @var array<string,int> t1..t3 are posts the relational fields point to */
	private array $t = array();

	/** @var array<string,int> */
	private array $c = array();

	/** @var array<string,int> */
	private array $u = array();

	private int $a1    = 0;
	private int $a2    = 0;
	private int $img2  = 0;
	private int $admin = 0;
	private int $seq   = 0;

	/** @var array<string,mixed> */
	private static array $snapshot = array();

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		if ( ! function_exists( 'pods_api' ) ) {
			$file = WP_PLUGIN_DIR . '/pods/init.php';
			if ( ! is_file( $file ) ) {
				self::markTestSkipped( 'Pods is not installed. Run: wp plugin install pods' );
			}
			require_once $file;
		}
		global $pods_init, $wpdb;
		// Pods boots on plugins_loaded, which has already run by the time this class loads.
		$pods_init->plugins_loaded();
		if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}podsrel'" ) ) {
			$pods_init->setup();
		}

		self::make_pods_with_retry();

		$property = new \ReflectionProperty( Registry::class, 'types' );
		$property->setAccessible( true );
		self::$snapshot = $property->getValue( Plugin::instance()->registry );
	}

	public static function tear_down_after_class(): void {
		// Creating a table-based pod runs DDL, which commits, so the pods outlive the per-test rollback.
		foreach ( array( 'post', 'page', 'user', 'category' ) as $name ) {
			$pod = pods_api()->load_pod( array( 'name' => $name ) );
			if ( $pod ) {
				pods_api()->delete_pod( array( 'id' => $pod['id'] ) );
			}
		}
		parent::tear_down_after_class();
	}

	public function tear_down(): void {
		$property = new \ReflectionProperty( Registry::class, 'types' );
		$property->setAccessible( true );
		$property->setValue( Plugin::instance()->registry, self::$snapshot );
		parent::tear_down();
	}

	/**
	 * The shared test database deadlocks on Pods' transient flush when other suites run at once.
	 */
	private static function make_pods_with_retry(): void {
		for ( $try = 0; $try < 6; $try++ ) {
			try {
				self::make_pods();
				return;
			} catch ( \Exception $e ) {
				usleep( 400000 );
			}
		}
		self::make_pods();
	}

	private static function has_field( string $pod, string $field ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT f.ID FROM {$wpdb->posts} f INNER JOIN {$wpdb->posts} p ON p.ID = f.post_parent WHERE f.post_type = '_pods_field' AND f.post_name = %s AND p.post_type = '_pods_pod' AND p.post_name = %s",
				$field,
				$pod
			)
		);
	}

	/**
	 * @param array<string,mixed> $args
	 */
	private static function ensure_pod( \PodsAPI $api, array $args ): void {
		global $wpdb;
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = '_pods_pod' AND post_name = %s", (string) $args['name'] ) );
		if ( ! $exists ) {
			$api->save_pod( $args );
		}
	}

	private static function make_pods(): void {
		$api     = pods_api();
		$choices = "a|Alpha\nb|Beta\nc|Gamma\nd|Delta";
		$simple  = static fn( string $format, string $type ): array => array(
			'type'               => 'pick',
			'pick_object'        => 'custom-simple',
			'pick_custom'        => $choices,
			'pick_format_type'   => $type,
			'pick_format_single' => $format,
			'pick_format_multi'  => 'checkbox',
		);
		$rel     = static fn( string $object, string $val, string $type ): array => array(
			'type'             => 'pick',
			'pick_object'      => $object,
			'pick_val'         => $val,
			'pick_format_type' => $type,
		);

		self::ensure_pod(
			$api,
			array(
				'create_extend' => 'extend',
				'name'          => 'post',
				'type'          => 'post_type',
				'storage'       => 'meta',
			)
		);
		$fields = array(
			'cln_text'          => array(
				'type'            => 'text',
				'text_max_length' => 12,
			),
			'cln_required_text' => array(
				'type'     => 'text',
				'required' => 1,
			),
			'cln_paragraph'     => array( 'type' => 'paragraph' ),
			'cln_website'       => array( 'type' => 'website' ),
			'cln_email'         => array( 'type' => 'email' ),
			'cln_number'        => array(
				'type'            => 'number',
				'number_decimals' => 0,
				'number_min'      => 0,
				'number_max'      => 1000,
			),
			'cln_decimal'       => array(
				'type'            => 'number',
				'number_decimals' => 2,
			),
			'cln_color'         => array( 'type' => 'color' ),
			'cln_date'          => array( 'type' => 'date' ),
			'cln_datetime'      => array( 'type' => 'datetime' ),
			'cln_time'          => array( 'type' => 'time' ),
			'cln_boolean'       => array( 'type' => 'boolean' ),
			'cln_select'        => $simple( 'dropdown', 'single' ),
			'cln_radio'         => $simple( 'radio', 'single' ),
			'cln_multi'         => $simple( 'dropdown', 'multi' ),
			'cln_rel'           => $rel( 'post_type', 'post', 'single' ),
			'cln_rels'          => $rel( 'post_type', 'post', 'multi' ),
			'cln_img'           => array(
				'type'             => 'file',
				'file_format_type' => 'single',
				'file_type'        => 'images',
			),
			'cln_files'         => array(
				'type'             => 'file',
				'file_format_type' => 'multi',
			),
			'cln_user_rel'      => $rel( 'user', '', 'single' ),
			'cln_tax_rels'      => $rel( 'taxonomy', 'category', 'multi' ),
			'cln_wysiwyg'       => array( 'type' => 'wysiwyg' ),
			'cln_repeat'        => array(
				'type'       => 'text',
				'repeatable' => 1,
			),
			'cln_heading'       => array( 'type' => 'heading' ),
			'cln_password'      => array( 'type' => 'password' ),
			'cln_admin_only'    => array(
				'type'       => 'text',
				'admin_only' => 1,
			),
			'cln_role_only'     => array(
				'type'          => 'text',
				'restrict_role' => 1,
				'roles_allowed' => array( 'administrator' ),
			),
			'cln_cap_only'      => array(
				'type'                => 'text',
				'restrict_capability' => 1,
				'capability_allowed'  => 'manage_options',
			),
		);
		foreach ( $fields as $name => $args ) {
			if ( self::has_field( 'post', $name ) ) {
				continue;
			}
			$id = $api->save_field(
				array(
					'pod'   => 'post',
					'name'  => $name,
					'label' => ucfirst( str_replace( '_', ' ', substr( $name, 4 ) ) ),
				) + $args
			);
			self::assertNotEmpty( $id, "$name field is created" );
		}

		self::ensure_pod(
			$api,
			array(
				'create_extend' => 'extend',
				'name'          => 'page',
				'type'          => 'post_type',
				'storage'       => 'table',
			)
		);
		if ( ! self::has_field( 'page', 'cln_ptext' ) ) {
			$api->save_field(
				array(
					'pod'  => 'page',
					'name' => 'cln_ptext',
					'type' => 'text',
				)
			);
		}
		foreach ( array(
			'user'     => array( 'cln_user_text', 'cln_user_number' ),
			'category' => array( 'cln_term_text', 'cln_term_number' ),
		) as $pod => $names ) {
			self::ensure_pod(
			$api,
				array(
					'create_extend' => 'extend',
					'name'          => $pod,
					'type'          => 'user' === $pod ? 'user' : 'taxonomy',
					'storage'       => 'meta',
				)
			);
			foreach ( $names as $i => $field_name ) {
				if ( ! self::has_field( $pod, $field_name ) ) {
					$api->save_field(
						array(
							'pod'  => $pod,
							'name' => $field_name,
							'type' => 0 === $i ? 'text' : 'number',
						)
					);
				}
			}
		}
	}

	public function set_up(): void {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );

		$this->registry = new Registry();
		PodsIntegration::register();
		do_action( 'corinthian_register_types', $this->registry );
		$type = $this->registry->get( 'pods.field' );
		$this->assertInstanceOf( PodsFieldType::class, $type );
		$this->type = $type;

		$sets        = new SetRepository();
		$this->posts = new PostTypeScreen( $this->registry, $sets, get_post_type_object( 'post' ) );
		$this->pages = new PostTypeScreen( $this->registry, $sets, get_post_type_object( 'page' ) );
		$this->users = new UserScreen( $this->registry, $sets );
		$taxonomy    = get_taxonomy( 'category' );
		$this->assertNotFalse( $taxonomy );
		$this->terms = new TermScreen( $this->registry, $sets, $taxonomy );

		$this->seed();
	}

	private static function ymd( int $days ): string {
		return wp_date( 'Y-m-d', time() + $days * DAY_IN_SECONDS );
	}

	private function attachment( string $file, string $mime, string $title ): int {
		return (int) wp_insert_attachment(
			array(
				'post_title'     => $title,
				'post_status'    => 'inherit',
				'post_mime_type' => $mime,
			),
			$file
		);
	}

	private function seed(): void {
		foreach ( array(
			't1' => 'Target One',
			't2' => 'Target Two',
			't3' => 'Target Three',
		) as $k => $title ) {
			$this->t[ $k ] = self::factory()->post->create( array( 'post_title' => $title ) );
		}
		foreach ( array( 'c1', 'c2', 'c3' ) as $k ) {
			$this->c[ $k ] = self::factory()->category->create( array( 'name' => 'Cat ' . $k ) );
		}
		$this->c['tag'] = self::factory()->tag->create( array( 'name' => 'A tag' ) );
		$this->u['u1']  = self::factory()->user->create(
			array(
				'display_name' => 'Una User',
				'role'         => 'editor',
			)
		);
		$this->u['u2']  = self::factory()->user->create(
			array(
				'display_name' => 'Two User',
				'role'         => 'editor',
			)
		);
		$this->a1       = $this->attachment( 'cln-one.jpg', 'image/jpeg', 'Image One' );
		$this->a2       = $this->attachment( 'cln-two.pdf', 'application/pdf', 'Doc Two' );
		$this->img2     = $this->attachment( 'cln-two.png', 'image/png', 'Image Two' );

		foreach ( array( 'p1', 'p2', 'p3', 'p4', 'p5' ) as $i => $k ) {
			$this->p[ $k ] = self::factory()->post->create( array( 'post_title' => 'Post ' . ( $i + 1 ) ) );
		}

		$t = $this->t;
		$c = $this->c;
		$u = $this->u;

		$rows = array(
			'text'      => array( 'banana', 'apple', 'cherry' ),
			'paragraph' => array( "b line\nsecond", 'a line', 'c line' ),
			'website'   => array( 'https://b.example.com', 'https://a.example.com', 'https://c.example.com' ),
			'email'     => array( 'b@example.com', 'a@example.com', 'c@example.com' ),
			'number'    => array( '10', '9', '100' ),
			'decimal'   => array( '1.50', '0.25', '12.00' ),
			'color'     => array( '#bbbbbb', '#aaaaaa', '#cccccc' ),
			'date'      => array( self::ymd( -10 ), self::ymd( -400 ), self::ymd( -3 ) ),
			'datetime'  => array( self::ymd( -10 ) . ' 10:00:00', self::ymd( -400 ) . ' 08:00:00', self::ymd( -3 ) . ' 23:30:00' ),
			'time'      => array( '10:30:00', '09:15:00', '18:45:00' ),
			'boolean'   => array( 1, 0, 1 ),
			'select'    => array( 'b', 'a', 'c' ),
			'radio'     => array( 'b', 'a', 'c' ),
			'multi'     => array( array( 'a', 'b' ), array( 'c' ), array( 'b', 'c' ) ),
			'rel'       => array( $t['t1'], $t['t2'], $t['t1'] ),
			'rels'      => array( array( $t['t1'], $t['t2'] ), array( $t['t2'] ), array( $t['t3'] ) ),
			'img'       => array( $this->a1, $this->img2, $this->a1 ),
			'files'     => array( array( $this->a2 ), array( $this->a1 ), array( $this->a2, $this->a1 ) ),
			'user_rel'  => array( $u['u1'], $u['u2'], $u['u1'] ),
			'tax_rels'  => array( array( $c['c1'], $c['c2'] ), array( $c['c2'] ), array( $c['c3'] ) ),
			'wysiwyg'   => array( '<p>b <strong>body</strong></p>', '<p>a body</p>', '<p>c body</p>' ),
		);
		foreach ( array( 'p1', 'p2', 'p3' ) as $i => $k ) {
			$data = array();
			foreach ( $rows as $name => $values ) {
				$data[ 'cln_' . $name ] = $values[ $i ];
			}
			$saved = pods( 'post', $this->p[ $k ] )->save( $data );
			$this->assertSame( $this->p[ $k ], (int) $saved, "$k is seeded through Pods" );
		}
	}

	private function cfg( string $name ): Config {
		return new Config( 'c' . ( ++$this->seq ), 'pods.field', 'x', null, 'px', array( 'field' => 'cln_' . $name ) );
	}

	private function label( int $id ): string {
		$flip = array_flip( $this->p );
		return $flip[ $id ] ?? 'other';
	}

	private function render( string $name, string $post = 'p1' ): string {
		return $this->type->render( $this->p[ $post ], $this->cfg( $name ), $this->posts );
	}

	/**
	 * @return list<string>
	 */
	private function sorted( string $name, string $order ): array {
		global $wpdb;
		$config   = $this->cfg( $name );
		$type     = $this->type;
		$screen   = $this->posts;
		$callback = static function ( $clauses ) use ( $type, $config, $order, $screen, $wpdb ) {
			$clauses            = $type->sort_clauses( $clauses, $config, $order, $screen );
			$clauses['orderby'] = trim( $clauses['orderby'] . ", {$wpdb->posts}.ID {$order}", ', ' );
			return $clauses;
		};
		add_filter( 'posts_clauses', $callback );
		$query = new WP_Query(
			array(
				'post__in'         => array_values( $this->p ),
				'post_status'      => 'any',
				'fields'           => 'ids',
				'nopaging'         => true,
				'suppress_filters' => false,
			)
		);
		remove_filter( 'posts_clauses', $callback );
		$this->assertSame( '', (string) $wpdb->last_error, "$name sort SQL runs without errors" );
		return array_map( fn( $id ) => $this->label( (int) $id ), $query->posts );
	}

	/**
	 * @param list<string>|null $subset
	 * @return list<string>|null null when the rule is rejected
	 */
	private function filtered( string $name, string $op, mixed $value, ?array $subset = null ): ?array {
		global $wpdb;
		$config = $this->cfg( $name );
		$this->assertContains( $op, array_column( $this->type->filter_operators( $config, $this->posts ), 'op' ), "$name offers $op" );
		$sql = $this->type->filter_condition( $config, $op, $value, $this->posts, 'clf0' );
		if ( null === $sql ) {
			return null;
		}
		$callback = static function ( $clauses ) use ( $sql ) {
			$clauses['join']  .= ' ' . $sql['join'];
			$clauses['where'] .= ' AND ' . $sql['where'];
			return $clauses;
		};
		add_filter( 'posts_clauses', $callback );
		$ids   = $subset ? array_map( fn( $k ) => $this->p[ $k ], $subset ) : array_values( $this->p );
		$query = new WP_Query(
			array(
				'post__in'         => $ids,
				'post_status'      => 'any',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'nopaging'         => true,
				'suppress_filters' => false,
			)
		);
		remove_filter( 'posts_clauses', $callback );
		$this->assertSame( '', (string) $wpdb->last_error, "$name $op SQL runs without errors" );
		return array_map( fn( $id ) => $this->label( (int) $id ), $query->posts );
	}

	/**
	 * @param list<string>      $expected
	 * @param list<string>|null $subset
	 */
	private function expect( array $expected, string $name, string $op, mixed $value = null, ?array $subset = null ): void {
		$this->assertSame( $expected, $this->filtered( $name, $op, $value, $subset ), "$name $op " . wp_json_encode( $value ) );
	}

	/**
	 * @return list<string>
	 */
	private function ops( string $name, ?Screen $screen = null ): array {
		return array_column( $this->type->filter_operators( $this->cfg( $name ), $screen ?? $this->posts ), 'op' );
	}

	// Loader and schema

	public function test_loader_registers_the_type_and_supports_posts_users_and_terms(): void {
		$this->assertSame( 'pods.field', $this->type->key() );
		$this->assertTrue( $this->type->supports( $this->posts ) );
		$this->assertTrue( $this->type->supports( $this->users ) );
		$this->assertTrue( $this->type->supports( $this->terms ) );
		$comments = new CommentScreen( $this->registry, new SetRepository() );
		$this->assertFalse( $this->type->supports( $comments ) );
		$this->assertArrayHasKey( 'pods.field', $this->registry->for_screen( $this->posts ) );
		$this->assertArrayNotHasKey( 'pods.field', $this->registry->for_screen( $comments ) );
	}

	public function test_settings_schema_lists_the_fields_for_each_screen(): void {
		$schema  = $this->type->settings_schema( $this->posts );
		$options = $schema[0]['options'];
		$this->assertSame( 'field', $schema[0]['name'] );
		$this->assertSame( 'select', $schema[0]['type'] );
		$this->assertStringEndsWith( 'Text', $options['cln_text'] );
		$this->assertStringEndsWith( 'Date', $options['cln_date'] );
		$this->assertArrayHasKey( 'cln_repeat', $options );
		$this->assertArrayNotHasKey( 'cln_heading', $options, 'Headings hold no value' );
		$this->assertArrayNotHasKey( 'cln_user_text', $options );
		$this->assertArrayNotHasKey( 'cln_ptext', $options );

		$this->assertSame( array( 'cln_user_text', 'cln_user_number' ), array_keys( $this->type->settings_schema( $this->users )[0]['options'] ) );
		$this->assertSame( array( 'cln_term_text', 'cln_term_number' ), array_keys( $this->type->settings_schema( $this->terms )[0]['options'] ) );
		$this->assertSame( array( 'cln_ptext' ), array_keys( $this->type->settings_schema( $this->pages )[0]['options'] ) );
		$other = new PostTypeScreen( $this->registry, new SetRepository(), get_post_type_object( 'attachment' ) );
		$this->assertSame( array(), $this->type->settings_schema( $other )[0]['options'] );
	}

	// Render

	public function test_render_text_kinds(): void {
		$this->assertStringContainsString( 'banana', $this->render( 'text' ) );
		$this->assertStringContainsString( 'b line', $this->render( 'paragraph' ) );
		$this->assertStringContainsString( '<a href="https://b.example.com"', $this->render( 'website' ) );
		$this->assertStringContainsString( 'rel="noopener noreferrer"', $this->render( 'website' ) );
		$this->assertStringContainsString( '<a href="mailto:b@example.com">b@example.com</a>', $this->render( 'email' ) );
		$this->assertStringContainsString( '10', $this->render( 'number' ) );
		$this->assertStringContainsString( '1.5', $this->render( 'decimal' ) );
		$this->assertSame( 'b body', $this->render( 'wysiwyg' ), 'Markup is stripped' );
		$this->assertStringContainsString( 'background:#bbbbbb', $this->render( 'color' ) );
	}

	public function test_render_escapes_stored_values(): void {
		update_post_meta( $this->p['p1'], 'cln_text', '<script>alert(1)</script>' );
		$html = $this->render( 'text' );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_render_choice_kinds_show_labels(): void {
		$this->assertSame( 'Beta', $this->render( 'select' ) );
		$this->assertSame( 'Beta', $this->render( 'radio' ) );
		$this->assertSame( 'Alpha, Beta', $this->render( 'multi' ) );
	}

	public function test_render_boolean_uses_an_icon_with_an_aria_label(): void {
		$this->assertStringContainsString( 'dashicons-yes', $this->render( 'boolean', 'p1' ) );
		$this->assertStringContainsString( 'aria-label="Yes"', $this->render( 'boolean', 'p1' ) );
		$this->assertStringContainsString( 'dashicons-no-alt', $this->render( 'boolean', 'p2' ) );
		$this->assertStringContainsString( 'aria-label="No"', $this->render( 'boolean', 'p2' ) );
	}

	public function test_render_dates_use_the_site_formats(): void {
		$utc = new \DateTimeZone( 'UTC' );
		$day = wp_date( (string) get_option( 'date_format' ), time() - 10 * DAY_IN_SECONDS );
		$this->assertSame( $day, $this->render( 'date' ) );
		$this->assertSame( $day . ' ' . wp_date( (string) get_option( 'time_format' ), strtotime( '10:00' ), $utc ), $this->render( 'datetime' ) );
		$this->assertSame( wp_date( (string) get_option( 'time_format' ), strtotime( '10:30' ), $utc ), $this->render( 'time' ) );
	}

	public function test_render_media_kinds(): void {
		$this->assertStringContainsString( '<img', $this->render( 'img' ) );
		$files = $this->render( 'files', 'p3' );
		$this->assertStringContainsString( '>Doc Two</a>', $files );
		$this->assertStringContainsString( '<img', $files, 'An image in a file list shows its thumbnail' );
	}

	public function test_render_relational_kinds(): void {
		$post = $this->render( 'rel' );
		$this->assertStringContainsString( '>Target One</a>', $post );
		$this->assertStringContainsString( 'post=' . $this->t['t1'], $post );
		$multi = $this->render( 'rels' );
		$this->assertStringContainsString( 'Target One', $multi );
		$this->assertStringContainsString( 'Target Two', $multi );
		$this->assertSame( 'Una User', $this->render( 'user_rel' ) );
		$this->assertSame( 'Cat c1, Cat c2', $this->render( 'tax_rels' ) );
	}

	public function test_render_empty_values_show_a_dash_and_unknown_fields_fail_soft(): void {
		foreach ( array( 'text', 'number', 'multi', 'date', 'img', 'rel', 'tax_rels' ) as $name ) {
			$this->assertStringContainsString( '&#8212;', $this->render( $name, 'p4' ), $name );
		}
		$ghost = new Config( 'g', 'pods.field', 'x', null, 'px', array( 'field' => 'nope' ) );
		$this->assertStringContainsString( '&#8212;', $this->type->render( $this->p['p1'], $ghost, $this->posts ) );
		$this->assertNull( $this->type->value( $this->p['p1'], $ghost, $this->posts ) );
		$this->assertSame( array(), $this->type->filter_operators( $ghost, $this->posts ) );
		$this->assertNull( $this->type->filter_condition( $ghost, 'empty', null, $this->posts, 'a' ) );
		$this->assertFalse( $this->type->sorts( $ghost, $this->posts ) );
	}

	// Sort

	public function test_sort_orders_ascending_and_descending_with_empty_values_last(): void {
		foreach ( array( 'text', 'paragraph', 'website', 'email', 'number', 'decimal', 'color', 'date', 'datetime', 'time', 'boolean', 'select', 'radio', 'wysiwyg' ) as $name ) {
			$this->assertTrue( $this->type->sorts( $this->cfg( $name ), $this->posts ), "$name sorts" );
			$this->assertSame( self::ASC, $this->sorted( $name, 'ASC' ), "$name ASC" );
			$this->assertSame( self::DESC, $this->sorted( $name, 'DESC' ), "$name DESC" );
		}
	}

	public function test_numbers_sort_numerically_not_as_text(): void {
		$this->assertSame( array( 'p2', 'p1', 'p3' ), array_slice( $this->sorted( 'number', 'ASC' ), 0, 3 ), '9 < 10 < 100' );
	}

	public function test_a_cleared_date_sorts_with_the_empty_values(): void {
		$this->assertTrue( $this->type->save( $this->p['p2'], $this->cfg( 'date' ), $this->posts, null, 'clear' ) );
		$this->assertSame( array( 'p1', 'p3', 'p2', 'p4', 'p5' ), $this->sorted( 'date', 'ASC' ), 'Pods stores 0000-00-00 for no date' );
	}

	public function test_unsortable_kinds_leave_the_clauses_unchanged(): void {
		$clauses = array(
			'join'    => '',
			'where'   => '',
			'orderby' => 'x',
			'groupby' => '',
		);
		foreach ( array( 'multi', 'img', 'files', 'rel', 'rels', 'user_rel', 'tax_rels', 'repeat' ) as $name ) {
			$config = $this->cfg( $name );
			$this->assertFalse( $this->type->sorts( $config, $this->posts ), "$name does not sort" );
			$this->assertSame( $clauses, $this->type->sort_clauses( $clauses, $config, 'ASC', $this->posts ), $name );
		}
		$page = new Config( 'pg', 'pods.field', 'x', null, 'px', array( 'field' => 'cln_ptext' ) );
		$this->assertFalse( $this->type->sorts( $page, $this->pages ) );
		$this->assertSame( $clauses, $this->type->sort_clauses( $clauses, $page, 'ASC', $this->pages ), 'A table-based pod is not in the meta table' );
	}

	// Filters

	public function test_text_filters(): void {
		$this->expect( array( 'p1' ), 'text', 'contains', 'an' );
		$this->expect( array( 'p2', 'p3' ), 'text', 'contains', 'e' );
		$this->expect( array(), 'text', 'contains', 'zzz' );
		$this->expect( array( 'p2', 'p3', 'p4', 'p5' ), 'text', 'not_contains', 'an' );
		$this->expect( array(), 'text', 'not_contains', 'a', array( 'p1', 'p2' ) );
		$this->expect( array( 'p2' ), 'text', 'eq', 'apple' );
		$this->expect( array(), 'text', 'eq', 'zzz' );
		$this->expect( array( 'p1', 'p3', 'p4', 'p5' ), 'text', 'neq', 'apple' );
		$this->expect( array( 'p3' ), 'text', 'starts', 'ch' );
		$this->expect( array(), 'text', 'starts', 'zzz' );
		$this->expect( array( 'p1' ), 'text', 'ends', 'ana' );
		$this->expect( array(), 'text', 'ends', 'zzz' );
		$this->expect( array( 'p4', 'p5' ), 'text', 'empty' );
		$this->expect( array( 'p1', 'p2', 'p3' ), 'text', 'not_empty' );
		$this->expect( array(), 'text', 'not_empty', null, array( 'p4', 'p5' ) );
		$this->expect( array(), 'text', 'empty', null, array( 'p1', 'p2' ) );
		$this->assertNull( $this->filtered( 'text', 'contains', '' ), 'An empty needle is rejected' );
		$this->expect( array( 'p1' ), 'email', 'starts', 'b@' );
		$this->expect( array( 'p3' ), 'website', 'contains', 'c.example' );
		$this->expect( array( 'p2' ), 'paragraph', 'starts', 'a line' );
	}

	public function test_text_filter_treats_like_wildcards_literally(): void {
		update_post_meta( $this->p['p5'], 'cln_text', '100%' );
		$this->expect( array( 'p5' ), 'text', 'contains', '%' );
		$this->expect( array( 'p5' ), 'text', 'ends', '0%' );
		$this->expect( array(), 'text', 'contains', '_', array( 'p1', 'p2', 'p3', 'p5' ) );
	}

	public function test_number_filters(): void {
		$this->expect( array( 'p1' ), 'number', 'eq', '10' );
		$this->expect( array(), 'number', 'eq', '7' );
		$this->expect( array( 'p2', 'p3', 'p4', 'p5' ), 'number', 'neq', '10' );
		$this->expect( array( 'p1', 'p3' ), 'number', 'gt', '9' );
		$this->expect( array( 'p1', 'p3' ), 'number', 'gte', '10' );
		$this->expect( array( 'p2' ), 'number', 'lt', '10' );
		$this->expect( array( 'p1', 'p2' ), 'number', 'lte', '10' );
		$this->expect( array( 'p1', 'p2' ), 'number', 'between', array( '9', '10' ) );
		$this->expect( array( 'p1', 'p2' ), 'number', 'between', array( '10', '9' ) );
		$this->expect( array(), 'number', 'between', array( '200', '300' ) );
		$this->expect( array( 'p4', 'p5' ), 'number', 'empty' );
		$this->expect( array( 'p1', 'p2', 'p3' ), 'number', 'not_empty' );
		$this->expect( array( 'p1', 'p3' ), 'decimal', 'gt', '1' );
		$this->expect( array( 'p2' ), 'decimal', 'lt', '1' );
		$this->assertNull( $this->filtered( 'number', 'gt', 'abc' ), 'A non-number is rejected' );
		$this->assertNull( $this->filtered( 'number', 'between', array( '1' ) ), 'A range needs two bounds' );
	}

	public function test_date_filters(): void {
		$this->expect( array( 'p1' ), 'date', 'eq', self::ymd( -10 ) );
		$this->expect( array(), 'date', 'eq', self::ymd( -100 ) );
		$this->expect( array( 'p1', 'p2' ), 'date', 'before', self::ymd( -5 ) );
		$this->expect( array( 'p3' ), 'date', 'after', self::ymd( -5 ) );
		$this->expect( array( 'p1' ), 'date', 'between', array( self::ymd( -20 ), self::ymd( -5 ) ) );
		$this->expect( array( 'p3' ), 'date', 'within_days', '5' );
		$this->expect( array( 'p1', 'p3' ), 'date', 'within_days', '30' );
		$this->expect( array(), 'date', 'within_days', '1' );
		$this->expect( array( 'p2' ), 'date', 'older_than_days', '30' );
		$this->expect( array(), 'date', 'older_than_days', '500' );
		$this->expect( array( 'p4', 'p5' ), 'date', 'empty' );
		$this->expect( array( 'p1', 'p2', 'p3' ), 'date', 'not_empty' );
		$this->assertNull( $this->filtered( 'date', 'before', '03/02/2025' ), 'A non-ISO date is rejected' );
	}

	public function test_a_cleared_date_counts_as_empty_not_as_the_oldest_date(): void {
		$this->assertTrue( $this->type->save( $this->p['p2'], $this->cfg( 'date' ), $this->posts, null, 'clear' ) );
		$this->assertSame( '0000-00-00', get_post_meta( $this->p['p2'], 'cln_date', true ) );
		$this->expect( array( 'p2', 'p4', 'p5' ), 'date', 'empty' );
		$this->expect( array( 'p1', 'p3' ), 'date', 'not_empty' );
		$this->expect( array( 'p1' ), 'date', 'before', self::ymd( -5 ) );
		$this->expect( array( 'p1' ), 'date', 'older_than_days', '5' );
		$this->assertTrue( $this->type->save( $this->p['p1'], $this->cfg( 'datetime' ), $this->posts, null, 'clear' ) );
		$this->expect( array( 'p2' ), 'datetime', 'before', self::ymd( -5 ) );
	}

	public function test_datetime_filters_include_the_whole_day(): void {
		$this->expect( array( 'p3' ), 'datetime', 'eq', self::ymd( -3 ) );
		$this->expect( array( 'p1', 'p2' ), 'datetime', 'before', self::ymd( -5 ) );
		$this->expect( array( 'p3' ), 'datetime', 'after', self::ymd( -5 ) );
		$this->expect( array(), 'datetime', 'after', self::ymd( -3 ) );
		$this->expect( array( 'p1', 'p3' ), 'datetime', 'within_days', '30' );
		$this->expect( array( 'p2' ), 'datetime', 'older_than_days', '30' );
		$this->expect( array( 'p4', 'p5' ), 'datetime', 'empty' );
	}

	public function test_time_filters(): void {
		$this->expect( array( 'p2' ), 'time', 'before', '10:00' );
		$this->expect( array( 'p1', 'p3' ), 'time', 'after', '10:00' );
		$this->expect( array( 'p3' ), 'time', 'after', '18:00:00' );
		$this->expect( array(), 'time', 'before', '09:00' );
		$this->expect( array( 'p4', 'p5' ), 'time', 'empty' );
		$this->assertNull( $this->filtered( 'time', 'before', '25:00' ) );
	}

	public function test_choice_filters_for_single_and_list_values(): void {
		$this->expect( array( 'p1', 'p2' ), 'select', 'in', array( 'a', 'b' ) );
		$this->expect( array( 'p1', 'p3', 'p4', 'p5' ), 'select', 'not_in', array( 'a' ) );
		$this->expect( array(), 'select', 'in', array( 'd' ) );
		$this->expect( array( 'p4', 'p5' ), 'select', 'empty' );
		$this->expect( array( 'p1', 'p2', 'p3' ), 'select', 'not_empty' );
		$this->expect( array( 'p1', 'p3' ), 'radio', 'in', array( 'b', 'c' ) );
		$this->assertNull( $this->filtered( 'select', 'in', array( 'zzz' ) ), 'An unknown choice is rejected' );

		$this->expect( array( 'p1', 'p3' ), 'multi', 'in', array( 'b' ) );
		$this->expect( array( 'p1', 'p2', 'p3' ), 'multi', 'in', array( 'a', 'c' ) );
		$this->expect( array( 'p2', 'p4', 'p5' ), 'multi', 'not_in', array( 'b' ) );
		$this->expect( array(), 'multi', 'in', array( 'd' ) );
		$this->expect( array( 'p4', 'p5' ), 'multi', 'empty' );
		$this->expect( array( 'p1', 'p2', 'p3' ), 'multi', 'not_empty' );
	}

	public function test_a_multi_choice_object_matches_once_even_with_several_matching_rows(): void {
		$this->assertSame( array( 'p1', 'p2', 'p3' ), $this->filtered( 'multi', 'in', array( 'a', 'b', 'c' ) ), 'p1 and p3 hold two matching rows each but appear once' );
	}

	public function test_boolean_filter(): void {
		$this->expect( array( 'p1', 'p3' ), 'boolean', 'eq', '1' );
		$this->expect( array( 'p2', 'p4', 'p5' ), 'boolean', 'eq', '0' );
		$this->expect( array(), 'boolean', 'eq', '1', array( 'p2', 'p4' ) );
		$this->assertNull( $this->filtered( 'boolean', 'eq', 'maybe' ) );
	}

	public function test_relational_filters_match_ids_in_single_and_list_values(): void {
		$t = $this->t;
		$this->expect( array( 'p1', 'p3' ), 'rel', 'contains', (string) $t['t1'] );
		$this->expect( array( 'p2', 'p4', 'p5' ), 'rel', 'not_contains', (string) $t['t1'] );
		$this->expect( array(), 'rel', 'contains', (string) $t['t3'] );
		$this->expect( array( 'p1', 'p2' ), 'rels', 'contains', (string) $t['t2'] );
		$this->expect( array( 'p3' ), 'rels', 'contains', (string) $t['t3'] );
		$this->expect( array( 'p1', 'p3' ), 'user_rel', 'contains', (string) $this->u['u1'] );
		$this->expect( array( 'p1', 'p2' ), 'tax_rels', 'contains', (string) $this->c['c2'] );
		$this->expect( array( 'p3', 'p4', 'p5' ), 'tax_rels', 'not_contains', (string) $this->c['c2'] );
		$this->expect( array( 'p4', 'p5' ), 'rel', 'empty' );
		$this->expect( array( 'p1', 'p2', 'p3' ), 'rels', 'not_empty' );
		$this->expect( array(), 'tax_rels', 'not_empty', null, array( 'p4', 'p5' ) );
		$this->assertNull( $this->filtered( 'rel', 'contains', 'abc' ) );
		$this->assertNull( $this->filtered( 'rel', 'contains', '0' ) );
	}

	public function test_relational_filter_does_not_match_a_longer_id(): void {
		delete_post_meta( $this->p['p5'], 'cln_rels' );
		add_post_meta( $this->p['p5'], 'cln_rels', '12' );
		add_post_meta( $this->p['p5'], 'cln_rels', '123' );
		$this->expect( array( 'p5' ), 'rels', 'contains', '12', array( 'p5' ) );
		$this->expect( array( 'p5' ), 'rels', 'contains', '123', array( 'p5' ) );
		$this->expect( array(), 'rels', 'contains', '1', array( 'p5' ) );
		$this->expect( array(), 'rels', 'contains', '23', array( 'p5' ) );
	}

	public function test_files_and_repeatable_fields_only_filter_on_empty(): void {
		foreach ( array( 'img', 'files' ) as $name ) {
			$this->assertSame( array( 'empty', 'not_empty' ), $this->ops( $name ), $name );
			$this->expect( array( 'p4', 'p5' ), $name, 'empty' );
			$this->expect( array( 'p1', 'p2', 'p3' ), $name, 'not_empty' );
		}
		$this->assertSame( array( 'empty', 'not_empty' ), $this->ops( 'repeat' ), 'A repeatable field keeps serialized rows' );
		$this->assertNull( $this->type->filter_condition( $this->cfg( 'text' ), 'zzz', 'x', $this->posts, 'a' ), 'An unknown operator is rejected' );
	}

	public function test_two_rules_on_one_column_do_not_collide(): void {
		global $wpdb;
		$config = $this->cfg( 'number' );
		$a      = $this->type->filter_condition( $config, 'gt', '9', $this->posts, 'clf0' );
		$b      = $this->type->filter_condition( $config, 'lt', '100', $this->posts, 'clf1' );
		$this->assertNotNull( $a );
		$this->assertNotNull( $b );
		$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE ID IN (" . implode( ',', $this->p ) . ") AND {$a['where']} AND {$b['where']} ORDER BY ID" );
		$this->assertSame( '', (string) $wpdb->last_error );
		$this->assertSame( array( (string) $this->p['p1'] ), $ids );
	}

	// Table-based pods

	public function test_a_table_based_pod_renders_but_is_read_only(): void {
		$page   = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$config = new Config( 'pg', 'pods.field', 'x', null, 'px', array( 'field' => 'cln_ptext' ) );
		$this->assertSame( $page, (int) pods( 'page', $page )->save( 'cln_ptext', 'in the pod table' ) );
		$this->assertSame( array(), get_post_meta( $page, 'cln_ptext', false ), 'The value is in the pod table, not postmeta' );

		$this->assertSame( 'in the pod table', $this->type->render( $page, $config, $this->pages ) );
		$this->assertSame( array(), $this->type->filter_operators( $config, $this->pages ) );
		$this->assertNull( $this->type->filter_condition( $config, 'empty', null, $this->pages, 'a' ) );
		$shape = $this->type->edit_field( $config, $this->pages );
		$this->assertTrue( $shape['readonly'] );
		$this->assertNull( $this->type->edit_value( $page, $config, $this->pages ) );
		$result = $this->type->save( $page, $config, $this->pages, 'nope' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'corinthian_readonly', $result->get_error_code() );
		$this->assertSame( 'in the pod table', pods( 'page', $page )->field( 'cln_ptext' ) );
	}

	// Users and terms

	public function test_users_render_sort_filter_and_edit(): void {
		global $wpdb;
		$users = array();
		foreach ( array(
			'u_b'    => array( 'banana', '10' ),
			'u_a'    => array( 'apple', '9' ),
			'u_c'    => array( 'cherry', '100' ),
			'u_none' => array( '', '' ),
		) as $k => $v ) {
			$users[ $k ] = self::factory()->user->create();
			if ( '' !== $v[0] ) {
				pods( 'user', $users[ $k ] )->save(
					array(
						'cln_user_text'   => $v[0],
						'cln_user_number' => $v[1],
					)
				);
			}
		}
		$flip   = array_flip( $users );
		$text   = new Config( 'ut', 'pods.field', 'x', null, 'px', array( 'field' => 'cln_user_text' ) );
		$number = new Config( 'un', 'pods.field', 'x', null, 'px', array( 'field' => 'cln_user_number' ) );

		$this->assertSame( 'banana', get_user_meta( $users['u_b'], 'cln_user_text', true ) );
		$this->assertSame( 'banana', $this->type->render( $users['u_b'], $text, $this->users ) );

		$ordered = function ( Config $config, string $order ) use ( $users, $flip, $wpdb ): array {
			$callback = function ( WP_User_Query $query ) use ( $config, $order, $wpdb ) {
				$clauses              = $this->type->sort_clauses(
					array(
						'join'    => '',
						'where'   => '',
						'orderby' => '',
						'groupby' => '',
					),
					$config,
					$order,
					$this->users
				);
				$query->query_from   .= ' ' . $clauses['join'];
				$query->query_orderby = 'GROUP BY ' . $clauses['groupby'] . ' ORDER BY ' . $clauses['orderby'] . ", {$wpdb->users}.ID {$order}";
			};
			add_action( 'pre_user_query', $callback );
			$query = new WP_User_Query(
				array(
					'include' => array_values( $users ),
					'fields'  => 'ID',
				)
			);
			remove_action( 'pre_user_query', $callback );
			$this->assertSame( '', (string) $wpdb->last_error );
			return array_map( static fn( $id ) => $flip[ (int) $id ], $query->get_results() );
		};
		$this->assertSame( array( 'u_a', 'u_b', 'u_c', 'u_none' ), $ordered( $text, 'ASC' ) );
		$this->assertSame( array( 'u_c', 'u_b', 'u_a', 'u_none' ), $ordered( $text, 'DESC' ) );
		$this->assertSame( array( 'u_a', 'u_b', 'u_c', 'u_none' ), $ordered( $number, 'ASC' ) );
		$this->assertSame( array( 'u_c', 'u_b', 'u_a', 'u_none' ), $ordered( $number, 'DESC' ) );

		$filter = function ( Config $config, string $op, mixed $value ) use ( $users, $flip, $wpdb ): array {
			$sql = $this->type->filter_condition( $config, $op, $value, $this->users, 'clf0' );
			$this->assertNotNull( $sql );
			$callback = static function ( WP_User_Query $query ) use ( $sql ) {
				$query->query_from  .= ' ' . $sql['join'];
				$query->query_where .= ' AND ' . $sql['where'];
			};
			add_action( 'pre_user_query', $callback );
			$query = new WP_User_Query(
				array(
					'include' => array_values( $users ),
					'fields'  => 'ID',
					'orderby' => 'ID',
				)
			);
			remove_action( 'pre_user_query', $callback );
			$this->assertSame( '', (string) $wpdb->last_error );
			return array_map( static fn( $id ) => $flip[ (int) $id ], $query->get_results() );
		};
		$this->assertSame( array( 'u_a' ), $filter( $text, 'contains', 'pple' ) );
		$this->assertSame( array( 'u_b', 'u_c' ), $filter( $number, 'gt', '9' ) );
		$this->assertSame( array(), $filter( $text, 'contains', 'zzz' ) );
		$this->assertSame( array( 'u_none' ), $filter( $text, 'empty', null ) );

		$this->assertTrue( $this->type->save( $users['u_a'], $text, $this->users, 'kiwi' ) );
		$this->assertSame( 'kiwi', get_user_meta( $users['u_a'], 'cln_user_text', true ) );
		$this->assertSame( 'kiwi', $this->type->edit_value( $users['u_a'], $text, $this->users ) );
		$this->assertInstanceOf( WP_Error::class, $this->type->save( $users['u_a'], $number, $this->users, 'abc' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$denied = $this->type->save( $users['u_a'], $text, $this->users, 'nope' );
		$this->assertInstanceOf( WP_Error::class, $denied );
		$this->assertSame( 'corinthian_protected', $denied->get_error_code() );
		$this->assertSame( 'kiwi', get_user_meta( $users['u_a'], 'cln_user_text', true ) );
	}

	public function test_terms_render_sort_filter_and_edit(): void {
		global $wpdb;
		$terms = array();
		foreach ( array(
			't_b'    => array( 'banana', '10' ),
			't_a'    => array( 'apple', '9' ),
			't_c'    => array( 'cherry', '100' ),
			't_none' => array( '', '' ),
		) as $k => $v ) {
			$terms[ $k ] = self::factory()->category->create( array( 'name' => $k ) );
			if ( '' !== $v[0] ) {
				pods( 'category', $terms[ $k ] )->save(
					array(
						'cln_term_text'   => $v[0],
						'cln_term_number' => $v[1],
					)
				);
			}
		}
		$flip   = array_flip( $terms );
		$text   = new Config( 'tt', 'pods.field', 'x', null, 'px', array( 'field' => 'cln_term_text' ) );
		$number = new Config( 'tn', 'pods.field', 'x', null, 'px', array( 'field' => 'cln_term_number' ) );

		$this->assertSame( 'cherry', get_term_meta( $terms['t_c'], 'cln_term_text', true ) );
		$this->assertSame( 'cherry', $this->type->render( $terms['t_c'], $text, $this->terms ) );

		$run  = function ( callable $callback, string $orderby ) use ( $terms, $flip, $wpdb ): array {
			add_filter( 'terms_clauses', $callback, 10, 3 );
			$found = get_terms(
				array(
					'taxonomy'   => 'category',
					'include'    => array_values( $terms ),
					'hide_empty' => false,
					'fields'     => 'ids',
					'orderby'    => $orderby,
				)
			);
			remove_filter( 'terms_clauses', $callback, 10 );
			$this->assertSame( '', (string) $wpdb->last_error );
			return array_map( static fn( $id ) => $flip[ (int) $id ], (array) $found );
		};
		$sort = function ( Config $config, string $order ) use ( $run ): array {
			return $run(
				function ( $clauses ) use ( $config, $order ) {
					$n                  = $this->type->sort_clauses(
						array(
							'join'    => (string) $clauses['join'],
							'where'   => (string) $clauses['where'],
							'orderby' => '',
							'groupby' => '',
						),
						$config,
						$order,
						$this->terms
					);
					$clauses['join']    = $n['join'];
					$clauses['orderby'] = 'GROUP BY ' . $n['groupby'] . ' ORDER BY ' . $n['orderby'] . ", t.term_id {$order}";
					$clauses['order']   = '';
					return $clauses;
				},
				'none'
			);
		};
		$this->assertSame( array( 't_a', 't_b', 't_c', 't_none' ), $sort( $text, 'ASC' ) );
		$this->assertSame( array( 't_c', 't_b', 't_a', 't_none' ), $sort( $text, 'DESC' ) );
		$this->assertSame( array( 't_a', 't_b', 't_c', 't_none' ), $sort( $number, 'ASC' ) );

		$filter = function ( Config $config, string $op, mixed $value ) use ( $run ): array {
			$sql = $this->type->filter_condition( $config, $op, $value, $this->terms, 'clf0' );
			$this->assertNotNull( $sql );
			return $run(
				static function ( $clauses ) use ( $sql ) {
					$clauses['join']  .= ' ' . $sql['join'];
					$clauses['where'] .= ' AND ' . $sql['where'];
					return $clauses;
				},
				'term_id'
			);
		};
		$this->assertSame( array( 't_b' ), $filter( $text, 'contains', 'nan' ) );
		$this->assertSame( array( 't_b', 't_c' ), $filter( $number, 'gt', '9' ) );
		$this->assertSame( array(), $filter( $text, 'eq', 'zzz' ) );
		$this->assertSame( array( 't_none' ), $filter( $text, 'empty', null ) );

		$this->assertTrue( $this->type->save( $terms['t_a'], $text, $this->terms, 'kiwi' ) );
		$this->assertSame( 'kiwi', get_term_meta( $terms['t_a'], 'cln_term_text', true ) );
		$this->assertInstanceOf( WP_Error::class, $this->type->save( $terms['t_a'], $number, $this->terms, 'abc' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$denied = $this->type->save( $terms['t_a'], $text, $this->terms, 'nope' );
		$this->assertInstanceOf( WP_Error::class, $denied );
		$this->assertSame( 'kiwi', get_term_meta( $terms['t_a'], 'cln_term_text', true ) );
	}

	// Edit

	/**
	 * @return array<string,array{0:mixed,1:list<string>,2:mixed,3:mixed}> name => [ valid input, stored meta rows, editor value, invalid input ]
	 */
	private function edit_cases(): array {
		$t = $this->t;
		$c = $this->c;
		$u = $this->u;
		$s = static fn( $v ): string => (string) $v;
		return array(
			'text'      => array( 'New <b>x</b>', array( 'New x' ), 'New x', 'this value is way too long' ),
			'paragraph' => array( "l1\nl2", array( "l1\nl2" ), "l1\nl2", array( 'x' ) ),
			'website'   => array( 'https://example.com/x', array( 'https://example.com/x' ), 'https://example.com/x', 'not a url' ),
			'email'     => array( 'ok@example.com', array( 'ok@example.com' ), 'ok@example.com', 'nope' ),
			'number'    => array( '42', array( '42' ), '42', '5000' ),
			'decimal'   => array( '3.25', array( '3.25' ), '3.25', '1.234' ),
			'color'     => array( '#ff0000', array( '#ff0000' ), '#ff0000', 'red' ),
			'date'      => array( '2025-02-03', array( '2025-02-03' ), '2025-02-03', '03/02/2025' ),
			'datetime'  => array( '2025-02-03T14:30', array( '2025-02-03 14:30:00' ), '2025-02-03T14:30', 'tomorrow-ish' ),
			'time'      => array( '14:30', array( '14:30:00' ), '14:30', '25:00' ),
			'boolean'   => array( '0', array( '0' ), '0', array( 'x' ) ),
			'select'    => array( 'd', array( 'd' ), 'd', 'zzz' ),
			'radio'     => array( 'c', array( 'c' ), 'c', 'zzz' ),
			'multi'     => array( array( 'a', 'c' ), array( 'a', 'c' ), array( 'a', 'c' ), array( 'a', 'zzz' ) ),
			'rel'       => array( $s( $t['t3'] ), array( $s( $t['t3'] ) ), $s( $t['t3'] ), '999999' ),
			'rels'      => array( array( $s( $t['t2'] ), $s( $t['t3'] ) ), array( $s( $t['t2'] ), $s( $t['t3'] ) ), array( $s( $t['t2'] ), $s( $t['t3'] ) ), array( '999999' ) ),
			'img'       => array( $s( $this->a1 ), array( $s( $this->a1 ) ), $s( $this->a1 ), $s( $this->a2 ) ),
			'files'     => array( array( $s( $this->a2 ), $s( $this->a1 ) ), array( $s( $this->a2 ), $s( $this->a1 ) ), array( $s( $this->a2 ), $s( $this->a1 ) ), array( '999999' ) ),
			'user_rel'  => array( $s( $u['u2'] ), array( $s( $u['u2'] ) ), $s( $u['u2'] ), '999999' ),
			'tax_rels'  => array( array( $s( $c['c2'] ), $s( $c['c3'] ) ), array( $s( $c['c2'] ), $s( $c['c3'] ) ), array( $s( $c['c2'] ), $s( $c['c3'] ) ), array( $s( $c['tag'] ) ) ),
		);
	}

	/**
	 * @return list<string>
	 */
	private function rows( int $id, string $name ): array {
		return array_map( 'strval', get_post_meta( $id, 'cln_' . $name, false ) );
	}

	public function test_every_kind_in_the_edit_table_is_editable(): void {
		foreach ( array_keys( $this->edit_cases() ) as $name ) {
			$shape = $this->type->edit_field( $this->cfg( $name ), $this->posts );
			$this->assertArrayNotHasKey( 'readonly', $shape, $name );
		}
	}

	public function test_edit_round_trips_through_pods_for_every_editable_kind(): void {
		foreach ( $this->edit_cases() as $name => $case ) {
			[ $input, $stored, $shown ] = $case;
			$config                     = $this->cfg( $name );
			$id                         = $this->p['p5'];
			$result                     = $this->type->save( $id, $config, $this->posts, $input );
			$this->assertTrue( $result, "$name saves: " . ( $result instanceof WP_Error ? $result->get_error_message() : '' ) );
			$this->assertSame( $stored, $this->rows( $id, $name ), "$name stored rows" );
			$this->assertEquals( $shown, $this->type->edit_value( $id, $config, $this->posts ), "$name editor value (read back through Pods)" );
		}
	}

	public function test_edit_rejects_invalid_values_and_leaves_the_stored_value(): void {
		foreach ( $this->edit_cases() as $name => $case ) {
			$config = $this->cfg( $name );
			$id     = $this->p['p1'];
			$before = $this->rows( $id, $name );
			$result = $this->type->save( $id, $config, $this->posts, $case[3] );
			$this->assertInstanceOf( WP_Error::class, $result, "$name rejects " . wp_json_encode( $case[3] ) );
			$this->assertSame( 'corinthian_invalid', $result->get_error_code(), $name );
			$this->assertSame( $before, $this->rows( $id, $name ), "$name unchanged after a rejected edit" );
		}
	}

	public function test_edit_refuses_a_user_without_the_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		foreach ( $this->edit_cases() as $name => $case ) {
			$id     = $this->p['p1'];
			$before = $this->rows( $id, $name );
			$result = $this->type->save( $id, $this->cfg( $name ), $this->posts, $case[0] );
			$this->assertInstanceOf( WP_Error::class, $result, "$name refused" );
			$this->assertSame( 'corinthian_protected', $result->get_error_code(), $name );
			$this->assertSame( $before, $this->rows( $id, $name ), "$name untouched" );
		}
		$clear = $this->type->save( $this->p['p1'], $this->cfg( 'text' ), $this->posts, '', 'clear' );
		$this->assertInstanceOf( WP_Error::class, $clear );
		$this->assertSame( array( 'banana' ), $this->rows( $this->p['p1'], 'text' ) );
	}

	public function test_edit_follows_the_object_capability_per_post(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$other = $this->p['p1'];
		$mine  = self::factory()->post->create( array( 'post_author' => $author ) );
		$this->assertInstanceOf( WP_Error::class, $this->type->save( $other, $this->cfg( 'text' ), $this->posts, 'x' ), "Authors cannot edit others' posts" );
		$this->assertTrue( $this->type->save( $mine, $this->cfg( 'text' ), $this->posts, 'mine' ), 'The allowed control saves' );
		$this->assertSame( array( 'mine' ), $this->rows( $mine, 'text' ) );
	}

	public function test_edit_follows_the_meta_capability(): void {
		$config = $this->cfg( 'text' );
		add_filter( 'auth_post_meta_cln_text', '__return_false' );
		$result = $this->type->save( $this->p['p1'], $config, $this->posts, 'blocked' );
		$this->assertInstanceOf( WP_Error::class, $result, 'An administrator is refused when the site denies the meta capability' );
		$this->assertSame( 'corinthian_protected', $result->get_error_code() );
		$this->assertSame( array( 'banana' ), $this->rows( $this->p['p1'], 'text' ) );

		remove_filter( 'auth_post_meta_cln_text', '__return_false' );
		$this->assertTrue( $this->type->save( $this->p['p1'], $config, $this->posts, 'allowed' ), 'The control saves once the denial is gone' );
		$this->assertSame( array( 'allowed' ), $this->rows( $this->p['p1'], 'text' ) );
	}

	public function test_edit_clear_empties_the_value(): void {
		foreach ( array( 'text', 'number', 'select', 'multi', 'boolean', 'date', 'img', 'rel', 'rels', 'tax_rels' ) as $name ) {
			$config = $this->cfg( $name );
			$id     = $this->p['p1'];
			$this->assertTrue( $this->type->save( $id, $config, $this->posts, null, 'clear' ), $name );
			$rows = array_filter( $this->rows( $id, $name ), static fn( $v ) => ! in_array( $v, array( '', '0', '0000-00-00' ), true ) );
			$this->assertSame( array(), array_values( $rows ), "$name cleared" );
		}
		$this->expect( array( 'p1', 'p4', 'p5' ), 'text', 'empty' );
		$this->expect( array( 'p1', 'p4', 'p5' ), 'rels', 'empty' );
		$this->expect( array( 'p1', 'p4', 'p5' ), 'multi', 'empty', null, array( 'p1', 'p4', 'p5' ) );
	}

	public function test_edit_bulk_append_and_remove_on_list_fields(): void {
		$t  = $this->t;
		$id = $this->p['p2'];
		$this->assertTrue( $this->type->save( $id, $this->cfg( 'multi' ), $this->posts, array( 'a', 'b' ), 'append' ) );
		$this->assertEqualsCanonicalizing( array( 'c', 'a', 'b' ), $this->rows( $id, 'multi' ) );
		$this->assertTrue( $this->type->save( $id, $this->cfg( 'multi' ), $this->posts, array( 'a' ), 'remove' ) );
		$this->assertEqualsCanonicalizing( array( 'c', 'b' ), $this->rows( $id, 'multi' ) );
		$this->assertInstanceOf( WP_Error::class, $this->type->save( $id, $this->cfg( 'multi' ), $this->posts, array( 'zzz' ), 'append' ) );

		$this->assertTrue( $this->type->save( $id, $this->cfg( 'rels' ), $this->posts, array( (string) $t['t3'] ), 'append' ) );
		$this->assertEqualsCanonicalizing( array( (string) $t['t2'], (string) $t['t3'] ), $this->rows( $id, 'rels' ) );
		$this->assertTrue( $this->type->save( $id, $this->cfg( 'rels' ), $this->posts, array( (string) $t['t2'] ), 'remove' ) );
		$this->assertSame( array( (string) $t['t3'] ), $this->rows( $id, 'rels' ) );

		$this->assertTrue( $this->type->save( $id, $this->cfg( 'tax_rels' ), $this->posts, array( (string) $this->c['c3'] ), 'append' ) );
		$this->assertEqualsCanonicalizing( array( (string) $this->c['c2'], (string) $this->c['c3'] ), $this->rows( $id, 'tax_rels' ) );

		$this->assertInstanceOf( WP_Error::class, $this->type->save( $id, $this->cfg( 'text' ), $this->posts, 'x', 'append' ), 'Single values do not append' );
	}

	public function test_edit_field_shapes(): void {
		$shape = fn( string $name ): array => $this->type->edit_field( $this->cfg( $name ), $this->posts );
		$this->assertSame( 'text', $shape( 'text' )['input'] );
		$this->assertSame( 'textarea', $shape( 'paragraph' )['input'] );
		$this->assertSame( 'number', $shape( 'number' )['input'] );
		$this->assertSame( 'toggle', $shape( 'boolean' )['input'] );
		$this->assertSame( 'date', $shape( 'date' )['input'] );
		$this->assertSame( 'datetime', $shape( 'datetime' )['input'] );
		$this->assertSame( 'media', $shape( 'img' )['input'] );
		$this->assertSame( array( 'set', 'clear' ), $shape( 'select' )['bulk'] );
		$this->assertSame( 'select', $shape( 'select' )['input'] );
		$this->assertSame(
			array(
				'a' => 'Alpha',
				'b' => 'Beta',
				'c' => 'Gamma',
				'd' => 'Delta',
			),
			$shape( 'select' )['options']
		);
		$this->assertSame( 'multiselect', $shape( 'multi' )['input'] );
		$this->assertSame( array( 'set', 'clear', 'append', 'remove' ), $shape( 'multi' )['bulk'] );
		$this->assertSame( 'Target One', $shape( 'rel' )['options'][ (string) $this->t['t1'] ] );
		$this->assertArrayHasKey( (string) $this->c['c1'], $shape( 'tax_rels' )['options'] );
		$this->assertArrayNotHasKey( (string) $this->c['tag'], $shape( 'tax_rels' )['options'], 'Only the field taxonomy is offered' );
		$this->assertArrayHasKey( (string) $this->u['u1'], $shape( 'user_rel' )['options'] );
	}

	public function test_relation_options_are_capped_at_200(): void {
		for ( $i = 0; $i < 205; $i++ ) {
			self::factory()->post->create( array( 'post_title' => 'Bulk ' . $i ) );
		}
		$this->assertCount( 200, $this->type->edit_field( $this->cfg( 'rel' ), $this->posts )['options'] );
	}

	public function test_read_only_kinds_cannot_be_edited(): void {
		foreach ( array( 'wysiwyg', 'repeat' ) as $name ) {
			$config = $this->cfg( $name );
			$shape  = $this->type->edit_field( $config, $this->posts );
			$this->assertTrue( $shape['readonly'], $name );
			$this->assertSame( array(), $shape['bulk'], $name );
			$result = $this->type->save( $this->p['p1'], $config, $this->posts, 'x' );
			$this->assertInstanceOf( WP_Error::class, $result, $name );
			$this->assertSame( 'corinthian_readonly', $result->get_error_code(), $name );
			$this->assertNull( $this->type->edit_value( $this->p['p1'], $config, $this->posts ), $name );
		}
	}

	public function test_required_fields_cannot_be_cleared_or_emptied(): void {
		$config = $this->cfg( 'required_text' );
		$this->assertTrue( $this->type->save( $this->p['p1'], $config, $this->posts, 'kept' ) );
		foreach ( array(
			'set'   => '',
			'clear' => null,
		) as $mode => $value ) {
			$result = $this->type->save( $this->p['p1'], $config, $this->posts, $value, $mode );
			$this->assertInstanceOf( WP_Error::class, $result, $mode );
			$this->assertSame( 'corinthian_invalid', $result->get_error_code(), $mode );
		}
		$this->assertSame( array( 'kept' ), $this->rows( $this->p['p1'], 'required_text' ) );
	}

	public function test_edit_with_a_missing_field_fails_cleanly(): void {
		$config = new Config( 'g', 'pods.field', 'x', null, 'px', array() );
		$result = $this->type->save( $this->p['p1'], $config, $this->posts, 'x' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'corinthian_no_key', $result->get_error_code() );
		$this->assertTrue( $this->type->edit_field( $config, $this->posts )['readonly'] );
	}

	public function test_a_field_of_another_pod_is_not_reachable_from_a_post_screen(): void {
		$config = new Config( 'g', 'pods.field', 'x', null, 'px', array( 'field' => 'cln_user_text' ) );
		$this->assertNull( $this->type->value( $this->p['p1'], $config, $this->posts ) );
		$result = $this->type->save( $this->p['p1'], $config, $this->posts, 'x' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'corinthian_no_key', $result->get_error_code() );
	}

	// Security regressions

	public function test_password_fields_are_unavailable_everywhere(): void {
		$id = $this->p['p1'];
		update_post_meta( $id, 'cln_password', 'hunter2' );

		$text = $this->cfg( 'text' );
		$this->assertSame( 'banana', $this->type->value( $id, $text, $this->posts ), 'Control: a plain text field reads' );
		$this->assertTrue( $this->type->sorts( $text, $this->posts ), 'Control: a plain text field sorts' );
		$this->assertNotSame( array(), $this->ops( 'text' ), 'Control: a plain text field filters' );

		$password = $this->cfg( 'password' );
		$this->assertNull( $this->type->value( $id, $password, $this->posts ), 'CSV-style value read returns null' );
		$this->assertStringNotContainsString( 'hunter2', $this->type->render( $id, $password, $this->posts ) );
		$this->assertFalse( $this->type->sorts( $password, $this->posts ) );
		$this->assertSame( array(), $this->ops( 'password' ), 'No filter operators are offered' );
		$this->assertNull( $this->type->filter_condition( $password, 'empty', null, $this->posts, 'a' ) );
		$this->assertTrue( $this->type->edit_field( $password, $this->posts )['readonly'] );
		$result = $this->type->save( $id, $password, $this->posts, 'changed' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'hunter2', get_post_meta( $id, 'cln_password', true ) );
	}

	public function test_pods_field_permissions_apply(): void {
		$id = $this->p['p1'];
		foreach ( array( 'admin_only', 'role_only', 'cap_only' ) as $name ) {
			update_post_meta( $id, 'cln_' . $name, 'classified' );
		}
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );

		foreach ( array( 'admin_only', 'role_only', 'cap_only' ) as $name ) {
			$config = $this->cfg( $name );

			wp_set_current_user( $this->admin );
			$this->assertSame( 'classified', $this->type->value( $id, $config, $this->posts ), "$name reads for a permitted user" );
			$this->assertTrue( $this->type->sorts( $config, $this->posts ), "$name sorts for a permitted user" );
			$this->assertNotSame( array(), $this->ops( $name ), "$name filters for a permitted user" );
			$this->assertTrue( $this->type->save( $id, $config, $this->posts, 'by admin' ), "$name saves for a permitted user" );
			$this->assertSame( array( 'by admin' ), $this->rows( $id, $name ) );

			wp_set_current_user( $editor );
			$this->assertNull( $this->type->value( $id, $config, $this->posts ), "$name hidden from a restricted user" );
			$this->assertFalse( $this->type->sorts( $config, $this->posts ), "$name does not sort" );
			$this->assertSame( array(), $this->ops( $name ), "$name offers no filters" );
			$result = $this->type->save( $id, $config, $this->posts, 'by editor' );
			$this->assertInstanceOf( WP_Error::class, $result, "$name refuses edits" );
			$this->assertSame( array( 'by admin' ), $this->rows( $id, $name ), "$name untouched" );
		}
	}

	public function test_relation_and_media_values_must_be_readable_by_the_editor(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$mine   = self::factory()->post->create( array( 'post_author' => $author ) );
		$secret = self::factory()->post->create(
			array(
				'post_status' => 'private',
				'post_author' => $this->admin,
			)
		);
		$draft  = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => $this->admin,
			)
		);
		$file   = (int) wp_insert_attachment(
			array(
				'post_title'     => 'Private File',
				'post_status'    => 'private',
				'post_author'    => $this->admin,
				'post_mime_type' => 'application/pdf',
			),
			'cln-private.pdf'
		);

		wp_set_current_user( $author );
		$this->assertTrue( $this->type->save( $mine, $this->cfg( 'rel' ), $this->posts, (string) $this->t['t1'] ), 'Control: a published post links' );
		$this->assertTrue( $this->type->save( $mine, $this->cfg( 'files' ), $this->posts, array( (string) $this->a2 ) ), 'Control: a public attachment links' );
		foreach ( array( $secret, $draft ) as $blocked ) {
			$result = $this->type->save( $mine, $this->cfg( 'rel' ), $this->posts, (string) $blocked );
			$this->assertInstanceOf( WP_Error::class, $result, 'Unreadable post is rejected' );
			$this->assertSame( 'corinthian_invalid', $result->get_error_code() );
		}
		$result = $this->type->save( $mine, $this->cfg( 'files' ), $this->posts, array( (string) $file ) );
		$this->assertInstanceOf( WP_Error::class, $result, 'Unreadable attachment is rejected' );
		$this->assertSame( array( (string) $this->t['t1'] ), $this->rows( $mine, 'rel' ) );

		wp_set_current_user( $this->admin );
		$this->assertTrue( $this->type->save( $mine, $this->cfg( 'rel' ), $this->posts, (string) $secret ), 'Control: a user who can read the private post links it' );
		$this->assertTrue( $this->type->save( $mine, $this->cfg( 'rel' ), $this->posts, (string) $draft ), 'Control: a user who can edit the draft links it' );
		$this->assertTrue( $this->type->save( $mine, $this->cfg( 'files' ), $this->posts, array( (string) $file ) ), 'Control: a user who can read the private file links it' );
	}
}
