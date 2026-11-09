<?php

namespace CorinthianPro\Tests;

use Corinthian\Columns\Config;
use Corinthian\Columns\Registry;
use CorinthianPro\Integrations\MetaBox\MetaBoxFieldType;
use CorinthianPro\Integrations\MetaBox\MetaBoxIntegration;
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

class MetaBoxTest extends WP_UnitTestCase {

	private const ASC  = array( 'p2', 'p1', 'p3', 'p4', 'p5' );
	private const DESC = array( 'p3', 'p1', 'p2', 'p5', 'p4' );

	/** @var array<string,list<mixed>> custom storage rows, keyed by "id:key" */
	public static array $custom = array();

	private Registry $registry;
	private MetaBoxFieldType $type;
	private Screen $posts;
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
		if ( ! function_exists( 'rwmb_meta' ) ) {
			$file = WP_PLUGIN_DIR . '/meta-box/meta-box.php';
			if ( ! is_file( $file ) ) {
				self::markTestSkipped( 'Meta Box is not installed. Run: wp plugin install meta-box' );
			}
			require_once $file;
		}

		// Stand-ins for the MB Term Meta and MB User Meta extensions: the same storage class names and
		// registry calls the extensions use, so the column reaches them through Meta Box's own API.
		if ( ! class_exists( 'RWMB_Term_Storage', false ) ) {
			eval( 'class RWMB_Term_Storage extends RWMB_Base_Storage { protected $object_type = "term"; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
			eval( 'class RWMB_User_Storage extends RWMB_Base_Storage { protected $object_type = "user"; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
			eval( 'class Cln_Term_Meta_Box extends RW_Meta_Box { protected $object_type = "term"; protected function object_hooks() {} public function register_fields() { foreach ( (array) $this->taxonomies as $t ) { foreach ( $this->fields as $f ) { rwmb_get_registry( "field" )->add( $f, $t, "term" ); } } } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
			eval( 'class Cln_User_Meta_Box extends RW_Meta_Box { protected $object_type = "user"; protected function object_hooks() {} public function register_fields() { foreach ( $this->fields as $f ) { rwmb_get_registry( "field" )->add( $f, "user", "user" ); } } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		}

		$choices = array(
			'a' => 'Alpha',
			'b' => 'Beta',
			'c' => 'Gamma',
			'd' => 'Delta',
		);
		$field   = static fn( string $type, array $extra = array() ): array => array(
			'id'   => 'cln_' . ( $extra['name'] ?? $type ),
			'name' => ucfirst( str_replace( '_', ' ', $extra['name'] ?? $type ) ),
			'type' => $type,
		) + $extra;

		$post_fields = array(
			$field( 'text', array( 'attributes' => array( 'maxlength' => 12 ) ) ),
			$field(
				'text',
				array(
					'name'     => 'required_text',
					'required' => true,
				)
			),
			array(
				'id'   => '_cln_hidden_text',
				'name' => 'Hidden text',
				'type' => 'text',
			),
			$field( 'textarea' ),
			$field(
				'number',
				array(
					'min' => 0,
					'max' => 1000,
				)
			),
			$field(
				'range',
				array(
					'min' => 0,
					'max' => 100,
				)
			),
			$field( 'email' ),
			$field( 'url' ),
			$field( 'select', array( 'options' => $choices ) ),
			$field(
				'select',
				array(
					'name'     => 'select_multi',
					'options'  => $choices,
					'multiple' => true,
				)
			),
			$field( 'radio', array( 'options' => $choices ) ),
			$field( 'button_group', array( 'options' => $choices ) ),
			$field( 'checkbox_list', array( 'options' => $choices ) ),
			$field( 'checkbox' ),
			$field( 'switch' ),
			$field( 'date' ),
			$field(
				'date',
				array(
					'name'      => 'date_ts',
					'timestamp' => true,
				)
			),
			$field(
				'date',
				array(
					'name'       => 'date_fmt',
					'js_options' => array( 'dateFormat' => 'dd/mm/yy' ),
				)
			),
			$field(
				'date',
				array(
					'name'        => 'date_save',
					'js_options'  => array( 'dateFormat' => 'dd/mm/yy' ),
					'save_format' => 'Y-m-d',
				)
			),
			$field( 'datetime' ),
			$field( 'time' ),
			$field( 'color' ),
			$field( 'single_image' ),
			$field( 'image_advanced' ),
			$field( 'file_advanced' ),
			$field(
				'post',
				array(
					'post_type'  => 'post',
					'field_type' => 'select',
				)
			),
			$field(
				'post',
				array(
					'name'       => 'post_multi',
					'post_type'  => 'post',
					'field_type' => 'select_advanced',
					'multiple'   => true,
				)
			),
			$field( 'user' ),
			$field(
				'taxonomy_advanced',
				array(
					'name'     => 'tax',
					'taxonomy' => 'category',
					'multiple' => true,
				)
			),
			$field( 'wysiwyg' ),
			$field(
				'text',
				array(
					'name'  => 'clone',
					'clone' => true,
				)
			),
			$field( 'heading', array( 'name' => 'a_heading' ) ),
		);
		$boxes       = array(
			array(
				'id'         => 'cln_box',
				'title'      => 'Cln Box',
				'post_types' => array( 'post' ),
				'fields'     => $post_fields,
			),
			array(
				'id'         => 'cln_guard_box',
				'title'      => 'Cln Guard',
				'post_types' => array( 'post' ),
				'fields'     => array(
					$field( 'password' ),
					$field( 'text', array( 'name' => 'plain' ) ),
					$field(
						'text',
						array(
							'name'     => 'ro_flag',
							'readonly' => true,
						)
					),
					$field(
						'text',
						array(
							'name'     => 'dis_flag',
							'disabled' => true,
						)
					),
					$field(
						'text',
						array(
							'name'       => 'ro_attr',
							'attributes' => array( 'readonly' => true ),
						)
					),
					$field(
						'text',
						array(
							'name'       => 'dis_attr',
							'attributes' => array( 'disabled' => true ),
						)
					),
				),
			),
			array(
				'id'         => 'cln_custom_box',
				'title'      => 'Cln Custom',
				'post_types' => array( 'post' ),
				'fields'     => array( $field( 'text', array( 'name' => 'cust' ) ) ),
			),
			array(
				'id'         => 'cln_term_box',
				'title'      => 'Cln Terms',
				'cln_object' => 'term',
				'taxonomies' => array( 'category' ),
				'fields'     => array(
					$field( 'text', array( 'name' => 'term_text' ) ),
					$field( 'number', array( 'name' => 'term_number' ) ),
				),
			),
			array(
				'id'         => 'cln_user_box',
				'title'      => 'Cln Users',
				'cln_object' => 'user',
				'fields'     => array(
					$field( 'text', array( 'name' => 'user_text' ) ),
					$field( 'number', array( 'name' => 'user_number' ) ),
				),
			),
		);

		// A storage that is not the post meta table, the way a custom-table extension plugs in.
		$custom  = new class() extends \RWMB_Base_Storage {
			public function get( $object_id, $meta_key, $args = false ) {
				$rows = MetaBoxTest::$custom[ "$object_id:$meta_key" ] ?? array();
				return ( is_array( $args ) ? ! empty( $args['single'] ) : (bool) $args ) ? ( $rows[0] ?? '' ) : $rows;
			}
			public function add( $object_id, $meta_key, $meta_value, $unique = false ) {
				MetaBoxTest::$custom[ "$object_id:$meta_key" ][] = $meta_value;
				return true;
			}
			public function update( $object_id, $meta_key, $meta_value, $prev_value = '' ) {
				MetaBoxTest::$custom[ "$object_id:$meta_key" ] = array( $meta_value );
				return true;
			}
			public function delete( $object_id, $meta_key, $meta_value = '', $delete_all = false ) {
				unset( MetaBoxTest::$custom[ "$object_id:$meta_key" ] );
				return true;
			}
		};
		$storage = static fn( $s, $object_type, $box ) => $box && 'cln_custom_box' === $box->id ? $custom : $s;
		$class   = static function ( $name, $settings ) {
			if ( isset( $settings['cln_object'] ) ) {
				return 'term' === $settings['cln_object'] ? 'Cln_Term_Meta_Box' : 'Cln_User_Meta_Box';
			}
			return $name;
		};
		add_filter( 'rwmb_get_storage', $storage, 10, 3 );
		add_filter( 'rwmb_meta_box_class_name', $class, 10, 2 );
		$registry = rwmb_get_registry( 'meta_box' );
		foreach ( $boxes as $config ) {
			$box = $registry->make( $config );
			$box->register_fields();
		}
		remove_filter( 'rwmb_get_storage', $storage, 10 );
		remove_filter( 'rwmb_meta_box_class_name', $class, 10 );

		$property = new \ReflectionProperty( Registry::class, 'types' );
		$property->setAccessible( true );
		self::$snapshot = $property->getValue( \Corinthian\Plugin::instance()->registry );
	}

	public function tear_down(): void {
		$property = new \ReflectionProperty( Registry::class, 'types' );
		$property->setAccessible( true );
		$property->setValue( \Corinthian\Plugin::instance()->registry, self::$snapshot );
		self::$custom = array();
		parent::tear_down();
	}

	public function set_up(): void {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );

		$this->registry = new Registry();
		MetaBoxIntegration::register();
		do_action( 'corinthian_register_types', $this->registry );
		$type = $this->registry->get( 'metabox.field' );
		$this->assertInstanceOf( MetaBoxFieldType::class, $type );
		$this->type = $type;

		$sets        = new SetRepository();
		$this->posts = new PostTypeScreen( $this->registry, $sets, get_post_type_object( 'post' ) );
		$this->users = new UserScreen( $this->registry, $sets );
		$taxonomy    = get_taxonomy( 'category' );
		$this->assertNotFalse( $taxonomy );
		$this->terms = new TermScreen( $this->registry, $sets, $taxonomy );

		$this->seed();
	}

	private static function ymd( int $days ): string {
		return wp_date( 'Y-m-d', time() + $days * DAY_IN_SECONDS );
	}

	private static function dmy( int $days ): string {
		return wp_date( 'd/m/Y', time() + $days * DAY_IN_SECONDS );
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

	private function set( int $post, string $name, mixed $value ): void {
		rwmb_set_meta( $post, 'cln_' . $name, $value );
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
			'text'           => array( 'banana', 'apple', 'cherry' ),
			'textarea'       => array( "b line\nsecond", 'a line', 'c line' ),
			'number'         => array( '10', '9', '100' ),
			'range'          => array( '20', '5', '50' ),
			'email'          => array( 'b@example.com', 'a@example.com', 'c@example.com' ),
			'url'            => array( 'https://b.example.com', 'https://a.example.com', 'https://c.example.com' ),
			'select'         => array( 'b', 'a', 'c' ),
			'select_multi'   => array( array( 'a', 'b' ), array( 'c' ), array( 'b', 'c' ) ),
			'radio'          => array( 'b', 'a', 'c' ),
			'button_group'   => array( 'b', 'a', 'c' ),
			'checkbox_list'  => array( array( 'a', 'b' ), array( 'c' ), array( 'b', 'c' ) ),
			'checkbox'       => array( 1, 0, 1 ),
			'switch'         => array( 1, 0, 1 ),
			'date'           => array( self::ymd( -10 ), self::ymd( -400 ), self::ymd( -3 ) ),
			'date_ts'        => array( self::ymd( -10 ), self::ymd( -400 ), self::ymd( -3 ) ),
			'date_fmt'       => array( self::dmy( -10 ), self::dmy( -400 ), self::dmy( -3 ) ),
			'date_save'      => array( self::dmy( -10 ), self::dmy( -400 ), self::dmy( -3 ) ),
			'datetime'       => array( self::ymd( -10 ) . ' 10:00', self::ymd( -400 ) . ' 08:00', self::ymd( -3 ) . ' 23:30' ),
			'time'           => array( '10:30', '09:15', '18:45' ),
			'color'          => array( '#bbbbbb', '#aaaaaa', '#cccccc' ),
			'single_image'   => array( $this->a1, $this->img2, $this->a1 ),
			'image_advanced' => array( array( $this->a1, $this->img2 ), array( $this->a1 ), array( $this->img2 ) ),
			'file_advanced'  => array( array( $this->a2 ), array( $this->a1 ), array( $this->a2, $this->a1 ) ),
			'post'           => array( $t['t1'], $t['t2'], $t['t1'] ),
			'post_multi'     => array( array( $t['t1'], $t['t2'] ), array( $t['t2'] ), array( $t['t3'] ) ),
			'user'           => array( $u['u1'], $u['u2'], $u['u1'] ),
			'tax'            => array( array( $c['c1'], $c['c2'] ), array( $c['c2'] ), array( $c['c3'] ) ),
			'wysiwyg'        => array( '<p>b <strong>body</strong></p>', '<p>a body</p>', '<p>c body</p>' ),
		);
		foreach ( $rows as $name => $values ) {
			foreach ( array( 'p1', 'p2', 'p3' ) as $i => $k ) {
				$this->set( $this->p[ $k ], $name, $values[ $i ] );
			}
		}
	}

	private function cfg( string $name ): Config {
		return new Config( 'c' . ( ++$this->seq ), 'metabox.field', 'x', null, 'px', array( 'field_id' => 'cln_' . $name ) );
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
		$this->assertSame( 'metabox.field', $this->type->key() );
		$this->assertTrue( $this->type->supports( $this->posts ) );
		$this->assertTrue( $this->type->supports( $this->users ) );
		$this->assertTrue( $this->type->supports( $this->terms ) );
		$comments = new CommentScreen( $this->registry, new SetRepository() );
		$this->assertFalse( $this->type->supports( $comments ) );
		$this->assertArrayHasKey( 'metabox.field', $this->registry->for_screen( $this->posts ) );
		$this->assertArrayNotHasKey( 'metabox.field', $this->registry->for_screen( $comments ) );
	}

	public function test_settings_schema_lists_the_fields_for_each_screen(): void {
		$schema = $this->type->settings_schema( $this->posts );
		$this->assertSame( 'field_id', $schema[0]['name'] );
		$this->assertSame( 'select', $schema[0]['type'] );
		$this->assertSame( 'Cln Box: Text', $schema[0]['options']['cln_text'] );
		$this->assertSame( 'Cln Box: Date', $schema[0]['options']['cln_date'] );
		$this->assertArrayHasKey( 'cln_cust', $schema[0]['options'] );
		$this->assertArrayNotHasKey( 'cln_a_heading', $schema[0]['options'], 'Headings hold no value' );
		$this->assertArrayNotHasKey( 'cln_user_text', $schema[0]['options'] );
		$this->assertArrayNotHasKey( 'cln_term_text', $schema[0]['options'] );

		$users = $this->type->settings_schema( $this->users )[0]['options'];
		$this->assertSame( array( 'cln_user_text', 'cln_user_number' ), array_keys( $users ) );
		$this->assertSame( 'Cln Users: User text', $users['cln_user_text'] );

		$terms = $this->type->settings_schema( $this->terms )[0]['options'];
		$this->assertSame( array( 'cln_term_text', 'cln_term_number' ), array_keys( $terms ) );

		$pages = new PostTypeScreen( $this->registry, new SetRepository(), get_post_type_object( 'page' ) );
		$this->assertSame( array(), $this->type->settings_schema( $pages )[0]['options'] );
	}

	// Render

	public function test_render_text_kinds(): void {
		$this->assertStringContainsString( 'banana', $this->render( 'text' ) );
		$this->assertStringContainsString( 'b line', $this->render( 'textarea' ) );
		$this->assertStringContainsString( '10', $this->render( 'number' ) );
		$this->assertStringContainsString( '20', $this->render( 'range' ) );
		$this->assertStringContainsString( '<a href="mailto:b@example.com">b@example.com</a>', $this->render( 'email' ) );
		$this->assertStringContainsString( '<a href="https://b.example.com"', $this->render( 'url' ) );
		$this->assertStringContainsString( 'rel="noopener noreferrer"', $this->render( 'url' ) );
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
		$this->assertSame( 'Beta', $this->render( 'button_group' ) );
		$this->assertSame( 'Alpha, Beta', $this->render( 'select_multi' ) );
		$this->assertSame( 'Alpha, Beta', $this->render( 'checkbox_list' ) );
	}

	public function test_render_checkbox_and_switch_use_an_icon_with_an_aria_label(): void {
		foreach ( array( 'checkbox', 'switch' ) as $name ) {
			$this->assertStringContainsString( 'dashicons-yes', $this->render( $name, 'p1' ) );
			$this->assertStringContainsString( 'aria-label="Yes"', $this->render( $name, 'p1' ) );
			$this->assertStringContainsString( 'dashicons-no-alt', $this->render( $name, 'p2' ) );
			$this->assertStringContainsString( 'aria-label="No"', $this->render( $name, 'p2' ) );
		}
	}

	public function test_render_dates_use_the_site_formats(): void {
		$utc = new \DateTimeZone( 'UTC' );
		$day = wp_date( (string) get_option( 'date_format' ), time() - 10 * DAY_IN_SECONDS );
		$this->assertSame( $day, $this->render( 'date' ) );
		$this->assertSame( $day, $this->render( 'date_ts' ), 'A timestamp field renders the date it stores' );
		$this->assertSame( $day, $this->render( 'date_fmt' ), 'A d/m/Y field is read in its own format' );
		$this->assertSame( $day, $this->render( 'date_save' ) );
		$this->assertSame( $day . ' ' . wp_date( (string) get_option( 'time_format' ), strtotime( '10:00' ), $utc ), $this->render( 'datetime' ) );
		$this->assertSame( wp_date( (string) get_option( 'time_format' ), strtotime( '10:30' ), $utc ), $this->render( 'time' ) );
	}

	public function test_render_media_kinds(): void {
		$this->assertStringContainsString( '<img', $this->render( 'single_image' ) );
		$gallery = $this->render( 'image_advanced' );
		$this->assertSame( 2, substr_count( $gallery, '<img' ) );
		$files = $this->render( 'file_advanced', 'p3' );
		$this->assertStringContainsString( '>Doc Two</a>', $files );
		$this->assertStringContainsString( '>Image One</a>', $files );
	}

	public function test_render_relational_kinds(): void {
		$post = $this->render( 'post' );
		$this->assertStringContainsString( '>Target One</a>', $post );
		$this->assertStringContainsString( 'post=' . $this->t['t1'], $post );
		$multi = $this->render( 'post_multi' );
		$this->assertStringContainsString( 'Target One', $multi );
		$this->assertStringContainsString( 'Target Two', $multi );
		$this->assertSame( 'Una User', $this->render( 'user' ) );
		$this->assertSame( 'Cat c1, Cat c2', $this->render( 'tax' ) );
	}

	public function test_render_empty_values_show_a_dash_and_unknown_fields_fail_soft(): void {
		foreach ( array( 'text', 'number', 'select_multi', 'date', 'single_image', 'post', 'tax' ) as $name ) {
			$this->assertStringContainsString( '&#8212;', $this->render( $name, 'p4' ), $name );
		}
		$ghost = new Config( 'g', 'metabox.field', 'x', null, 'px', array( 'field_id' => 'nope' ) );
		$this->assertStringContainsString( '&#8212;', $this->type->render( $this->p['p1'], $ghost, $this->posts ) );
		$this->assertNull( $this->type->value( $this->p['p1'], $ghost, $this->posts ) );
		$this->assertSame( array(), $this->type->filter_operators( $ghost, $this->posts ) );
		$this->assertNull( $this->type->filter_condition( $ghost, 'empty', null, $this->posts, 'a' ) );
		$this->assertFalse( $this->type->sorts( $ghost, $this->posts ) );
	}

	// Sort

	public function test_sort_orders_ascending_and_descending_with_empty_values_last(): void {
		foreach ( array( 'text', 'textarea', 'number', 'range', 'email', 'url', 'select', 'radio', 'button_group', 'color', 'checkbox', 'switch', 'date', 'date_ts', 'date_save', 'datetime', 'time', 'wysiwyg' ) as $name ) {
			$this->assertTrue( $this->type->sorts( $this->cfg( $name ), $this->posts ), "$name sorts" );
			$this->assertSame( self::ASC, $this->sorted( $name, 'ASC' ), "$name ASC" );
			$this->assertSame( self::DESC, $this->sorted( $name, 'DESC' ), "$name DESC" );
		}
	}

	public function test_numbers_sort_numerically_not_as_text(): void {
		$this->assertSame( array( 'p2', 'p1', 'p3' ), array_slice( $this->sorted( 'number', 'ASC' ), 0, 3 ), '9 < 10 < 100' );
	}

	public function test_timestamp_dates_sort_by_the_stored_number(): void {
		$this->assertSame( (string) strtotime( self::ymd( -10 ) ), get_post_meta( $this->p['p1'], 'cln_date_ts', true ) );
		$this->assertSame( self::ASC, $this->sorted( 'date_ts', 'ASC' ) );
	}

	public function test_unsortable_kinds_leave_the_clauses_unchanged(): void {
		$clauses = array(
			'join'    => '',
			'where'   => '',
			'orderby' => 'x',
			'groupby' => '',
		);
		foreach ( array( 'select_multi', 'checkbox_list', 'date_fmt', 'single_image', 'image_advanced', 'file_advanced', 'post', 'post_multi', 'user', 'tax', 'clone', 'cust' ) as $name ) {
			$config = $this->cfg( $name );
			$this->assertFalse( $this->type->sorts( $config, $this->posts ), "$name does not sort" );
			$this->assertSame( $clauses, $this->type->sort_clauses( $clauses, $config, 'ASC', $this->posts ), $name );
		}
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
		$this->expect( array( 'p3' ), 'url', 'contains', 'c.example' );
		$this->expect( array( 'p2' ), 'textarea', 'starts', 'a line' );
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
		$this->expect( array( 'p3' ), 'range', 'gt', '30' );
		$this->assertNull( $this->filtered( 'number', 'gt', 'abc' ), 'A non-number is rejected' );
		$this->assertNull( $this->filtered( 'number', 'between', array( '1' ) ), 'A range needs two bounds' );
	}

	public function test_date_filters_on_the_three_storage_forms(): void {
		foreach ( array( 'date', 'date_ts', 'date_save' ) as $name ) {
			$this->expect( array( 'p1' ), $name, 'eq', self::ymd( -10 ) );
			$this->expect( array(), $name, 'eq', self::ymd( -100 ) );
			$this->expect( array( 'p1', 'p2' ), $name, 'before', self::ymd( -5 ) );
			$this->expect( array( 'p3' ), $name, 'after', self::ymd( -5 ) );
			$this->expect( array( 'p1' ), $name, 'between', array( self::ymd( -20 ), self::ymd( -5 ) ) );
			$this->expect( array( 'p3' ), $name, 'within_days', '5' );
			$this->expect( array( 'p1', 'p3' ), $name, 'within_days', '30' );
			$this->expect( array(), $name, 'within_days', '1' );
			$this->expect( array( 'p2' ), $name, 'older_than_days', '30' );
			$this->expect( array(), $name, 'older_than_days', '500' );
			$this->expect( array( 'p4', 'p5' ), $name, 'empty' );
			$this->expect( array( 'p1', 'p2', 'p3' ), $name, 'not_empty' );
			$this->assertNull( $this->filtered( $name, 'before', '03/02/2025' ), "$name rejects a non-ISO date" );
		}
	}

	public function test_a_date_stored_in_a_non_sortable_format_only_offers_empty_filters(): void {
		$this->assertSame( array( 'empty', 'not_empty' ), $this->ops( 'date_fmt' ) );
		$this->expect( array( 'p4', 'p5' ), 'date_fmt', 'empty' );
		$this->expect( array( 'p1', 'p2', 'p3' ), 'date_fmt', 'not_empty' );
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
		$this->expect( array( 'p2' ), 'button_group', 'in', array( 'a' ) );
		$this->assertNull( $this->filtered( 'select', 'in', array( 'zzz' ) ), 'An unknown choice is rejected' );

		$this->expect( array( 'p1', 'p3' ), 'select_multi', 'in', array( 'b' ) );
		$this->expect( array( 'p1', 'p2', 'p3' ), 'select_multi', 'in', array( 'a', 'c' ) );
		$this->expect( array( 'p2', 'p4', 'p5' ), 'select_multi', 'not_in', array( 'b' ) );
		$this->expect( array(), 'select_multi', 'in', array( 'd' ) );
		$this->expect( array( 'p4', 'p5' ), 'checkbox_list', 'empty' );
		$this->expect( array( 'p1', 'p2', 'p3' ), 'checkbox_list', 'not_empty' );
		$this->expect( array( 'p1', 'p3' ), 'checkbox_list', 'in', array( 'b' ) );
	}

	public function test_a_multi_choice_object_matches_once_even_with_several_matching_rows(): void {
		$ids = $this->filtered( 'select_multi', 'in', array( 'a', 'b', 'c' ) );
		$this->assertSame( array( 'p1', 'p2', 'p3' ), $ids, 'p1 and p3 hold two matching rows each but appear once' );
	}

	public function test_bool_filters(): void {
		foreach ( array( 'checkbox', 'switch' ) as $name ) {
			$this->expect( array( 'p1', 'p3' ), $name, 'eq', '1' );
			$this->expect( array( 'p2', 'p4', 'p5' ), $name, 'eq', '0' );
			$this->expect( array(), $name, 'eq', '1', array( 'p2', 'p4' ) );
			$this->assertNull( $this->filtered( $name, 'eq', 'maybe' ) );
		}
	}

	public function test_relational_filters_match_ids_in_single_and_list_values(): void {
		$t = $this->t;
		$this->expect( array( 'p1', 'p3' ), 'post', 'contains', (string) $t['t1'] );
		$this->expect( array( 'p2', 'p4', 'p5' ), 'post', 'not_contains', (string) $t['t1'] );
		$this->expect( array(), 'post', 'contains', (string) $t['t3'] );
		$this->expect( array( 'p1', 'p2' ), 'post_multi', 'contains', (string) $t['t2'] );
		$this->expect( array( 'p3' ), 'post_multi', 'contains', (string) $t['t3'] );
		$this->expect( array( 'p1', 'p3' ), 'user', 'contains', (string) $this->u['u1'] );
		$this->expect( array( 'p1', 'p2' ), 'tax', 'contains', (string) $this->c['c2'] );
		$this->expect( array( 'p3', 'p4', 'p5' ), 'tax', 'not_contains', (string) $this->c['c2'] );
		$this->expect( array( 'p4', 'p5' ), 'post', 'empty' );
		$this->expect( array( 'p1', 'p2', 'p3' ), 'post_multi', 'not_empty' );
		$this->expect( array(), 'tax', 'not_empty', null, array( 'p4', 'p5' ) );
		$this->assertNull( $this->filtered( 'post', 'contains', 'abc' ) );
		$this->assertNull( $this->filtered( 'post', 'contains', '0' ) );
	}

	public function test_relational_filter_does_not_match_a_longer_id(): void {
		update_post_meta( $this->p['p5'], 'cln_tax', '12,123' );
		$this->expect( array( 'p5' ), 'tax', 'contains', '12', array( 'p5' ) );
		$this->expect( array( 'p5' ), 'tax', 'contains', '123', array( 'p5' ) );
		$this->expect( array(), 'tax', 'contains', '1', array( 'p5' ) );
		$this->expect( array(), 'tax', 'contains', '23', array( 'p5' ) );
	}

	public function test_media_and_structured_fields_only_filter_on_empty(): void {
		foreach ( array( 'single_image', 'image_advanced', 'file_advanced' ) as $name ) {
			$this->assertSame( array( 'empty', 'not_empty' ), $this->ops( $name ), $name );
			$this->expect( array( 'p4', 'p5' ), $name, 'empty' );
			$this->expect( array( 'p1', 'p2', 'p3' ), $name, 'not_empty' );
		}
		$this->assertSame( array( 'empty', 'not_empty' ), $this->ops( 'clone' ), 'A cloneable field keeps serialized rows' );
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

	// Users and terms

	public function test_users_render_sort_filter_and_edit(): void {
		global $wpdb;
		$users = array();
		$args  = array(
			'object_type' => 'user',
			'type'        => 'user',
		);
		foreach ( array(
			'u_b'    => array( 'banana', '10' ),
			'u_a'    => array( 'apple', '9' ),
			'u_c'    => array( 'cherry', '100' ),
			'u_none' => array( '', '' ),
		) as $k => $v ) {
			$users[ $k ] = self::factory()->user->create();
			if ( '' !== $v[0] ) {
				rwmb_set_meta( $users[ $k ], 'cln_user_text', $v[0], $args );
				rwmb_set_meta( $users[ $k ], 'cln_user_number', $v[1], $args );
			}
		}
		$flip   = array_flip( $users );
		$text   = $this->cfg( 'user_text' );
		$number = $this->cfg( 'user_number' );

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
		$args  = array(
			'object_type' => 'term',
			'type'        => 'category',
		);
		foreach ( array(
			't_b'    => array( 'banana', '10' ),
			't_a'    => array( 'apple', '9' ),
			't_c'    => array( 'cherry', '100' ),
			't_none' => array( '', '' ),
		) as $k => $v ) {
			$terms[ $k ] = self::factory()->category->create( array( 'name' => $k ) );
			if ( '' !== $v[0] ) {
				rwmb_set_meta( $terms[ $k ], 'cln_term_text', $v[0], $args );
				rwmb_set_meta( $terms[ $k ], 'cln_term_number', $v[1], $args );
			}
		}
		$flip   = array_flip( $terms );
		$text   = $this->cfg( 'term_text' );
		$number = $this->cfg( 'term_number' );

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
			'text'           => array( 'New <b>x</b>', array( 'New x' ), 'New x', 'this value is way too long' ),
			'textarea'       => array( "l1\nl2", array( "l1\nl2" ), "l1\nl2", array( 'x' ) ),
			'number'         => array( '42', array( '42' ), '42', '5000' ),
			'range'          => array( '60', array( '60' ), '60', 'abc' ),
			'email'          => array( 'ok@example.com', array( 'ok@example.com' ), 'ok@example.com', 'nope' ),
			'url'            => array( 'https://example.com/x', array( 'https://example.com/x' ), 'https://example.com/x', 'not a url' ),
			'select'         => array( 'd', array( 'd' ), 'd', 'zzz' ),
			'select_multi'   => array( array( 'a', 'c' ), array( 'a', 'c' ), array( 'a', 'c' ), array( 'a', 'zzz' ) ),
			'radio'          => array( 'c', array( 'c' ), 'c', 'zzz' ),
			'button_group'   => array( 'a', array( 'a' ), 'a', 'zzz' ),
			'checkbox_list'  => array( array( 'b', 'd' ), array( 'b', 'd' ), array( 'b', 'd' ), array( 'zzz' ) ),
			'checkbox'       => array( '0', array( '0' ), '0', array( 'x' ) ),
			'switch'         => array( '1', array( '1' ), '1', array( 'x' ) ),
			'date'           => array( '2025-02-03', array( '2025-02-03' ), '2025-02-03', '03/02/2025' ),
			'date_ts'        => array( '2025-02-03', array( (string) strtotime( '2025-02-03' ) ), '2025-02-03', 'yesterday-ish' ),
			'date_fmt'       => array( '2025-02-03', array( '03/02/2025' ), '2025-02-03', '2025-02-30' ),
			'date_save'      => array( '2025-02-03', array( '2025-02-03' ), '2025-02-03', '03/02/2025' ),
			'datetime'       => array( '2025-02-03T14:30', array( '2025-02-03 14:30' ), '2025-02-03T14:30', 'tomorrow-ish' ),
			'time'           => array( '14:30', array( '14:30' ), '14:30', '25:00' ),
			'color'          => array( '#ff0000', array( '#ff0000' ), '#ff0000', 'red' ),
			'single_image'   => array( $s( $this->a1 ), array( $s( $this->a1 ) ), $s( $this->a1 ), $s( $t['t1'] ) ),
			'image_advanced' => array( array( $s( $this->a1 ), $s( $this->img2 ) ), array( $s( $this->a1 ), $s( $this->img2 ) ), array( $s( $this->a1 ), $s( $this->img2 ) ), array( $s( $this->a2 ) ) ),
			'file_advanced'  => array( array( $s( $this->a2 ), $s( $this->a1 ) ), array( $s( $this->a2 ), $s( $this->a1 ) ), array( $s( $this->a2 ), $s( $this->a1 ) ), array( '999999' ) ),
			'post'           => array( $s( $t['t3'] ), array( $s( $t['t3'] ) ), $s( $t['t3'] ), '999999' ),
			'post_multi'     => array( array( $s( $t['t2'] ), $s( $t['t3'] ) ), array( $s( $t['t2'] ), $s( $t['t3'] ) ), array( $s( $t['t2'] ), $s( $t['t3'] ) ), array( '999999' ) ),
			'user'           => array( $s( $u['u2'] ), array( $s( $u['u2'] ) ), $s( $u['u2'] ), '999999' ),
			'tax'            => array( array( $s( $c['c2'] ), $s( $c['c3'] ) ), array( $c['c2'] . ',' . $c['c3'] ), array( $s( $c['c2'] ), $s( $c['c3'] ) ), array( $s( $c['tag'] ) ) ),
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

	public function test_edit_round_trips_through_rwmb_set_meta_for_every_editable_kind(): void {
		foreach ( $this->edit_cases() as $name => $case ) {
			[ $input, $stored, $shown ] = $case;
			$config                     = $this->cfg( $name );
			$id                         = $this->p['p5'];
			$result                     = $this->type->save( $id, $config, $this->posts, $input );
			$this->assertTrue( $result, "$name saves: " . ( $result instanceof WP_Error ? $result->get_error_message() : '' ) );
			$this->assertSame( $stored, $this->rows( $id, $name ), "$name stored rows" );
			$this->assertEquals( $shown, $this->type->edit_value( $id, $config, $this->posts ), "$name editor value" );
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

	public function test_edit_follows_the_meta_capability_for_protected_keys(): void {
		$config = new Config( 'h', 'metabox.field', 'x', null, 'px', array( 'field_id' => '_cln_hidden_text' ) );
		$result = $this->type->save( $this->p['p1'], $config, $this->posts, 'secret' );
		$this->assertInstanceOf( WP_Error::class, $result, 'An administrator still cannot write a protected key through the meta capability' );
		$this->assertSame( 'corinthian_protected', $result->get_error_code() );
		$this->assertSame( '', (string) get_post_meta( $this->p['p1'], '_cln_hidden_text', true ) );

		add_filter( 'auth_post_meta__cln_hidden_text', '__return_true' );
		$this->assertTrue( $this->type->save( $this->p['p1'], $config, $this->posts, 'allowed' ), 'The key is writable once the site authorizes it' );
		$this->assertSame( 'allowed', get_post_meta( $this->p['p1'], '_cln_hidden_text', true ) );
	}

	public function test_edit_clear_empties_the_value(): void {
		foreach ( array( 'text', 'number', 'select', 'select_multi', 'checkbox', 'date', 'date_ts', 'single_image', 'image_advanced', 'post', 'post_multi', 'tax' ) as $name ) {
			$config = $this->cfg( $name );
			$id     = $this->p['p1'];
			$this->assertTrue( $this->type->save( $id, $config, $this->posts, null, 'clear' ), $name );
			$rows = array_filter( $this->rows( $id, $name ), static fn( $v ) => '' !== $v && '0' !== $v );
			$this->assertSame( array(), array_values( $rows ), "$name cleared" );
		}
		$this->expect( array( 'p1', 'p4', 'p5' ), 'text', 'empty' );
		$this->expect( array( 'p1', 'p4', 'p5' ), 'post_multi', 'empty' );
	}

	public function test_edit_bulk_append_and_remove_on_list_fields(): void {
		$t  = $this->t;
		$id = $this->p['p2'];
		$this->assertTrue( $this->type->save( $id, $this->cfg( 'checkbox_list' ), $this->posts, array( 'a', 'b' ), 'append' ) );
		$this->assertEqualsCanonicalizing( array( 'c', 'a', 'b' ), $this->rows( $id, 'checkbox_list' ) );
		$this->assertTrue( $this->type->save( $id, $this->cfg( 'checkbox_list' ), $this->posts, array( 'a' ), 'remove' ) );
		$this->assertEqualsCanonicalizing( array( 'c', 'b' ), $this->rows( $id, 'checkbox_list' ) );
		$this->assertInstanceOf( WP_Error::class, $this->type->save( $id, $this->cfg( 'checkbox_list' ), $this->posts, array( 'zzz' ), 'append' ) );

		$this->assertTrue( $this->type->save( $id, $this->cfg( 'post_multi' ), $this->posts, array( (string) $t['t3'] ), 'append' ) );
		$this->assertEqualsCanonicalizing( array( (string) $t['t2'], (string) $t['t3'] ), $this->rows( $id, 'post_multi' ) );
		$this->assertTrue( $this->type->save( $id, $this->cfg( 'post_multi' ), $this->posts, array( (string) $t['t2'] ), 'remove' ) );
		$this->assertSame( array( (string) $t['t3'] ), $this->rows( $id, 'post_multi' ) );

		$this->assertTrue( $this->type->save( $id, $this->cfg( 'tax' ), $this->posts, array( (string) $this->c['c3'] ), 'append' ) );
		$this->assertSame( array( $this->c['c2'] . ',' . $this->c['c3'] ), $this->rows( $id, 'tax' ) );

		$this->assertInstanceOf( WP_Error::class, $this->type->save( $id, $this->cfg( 'text' ), $this->posts, 'x', 'append' ), 'Single values do not append' );
	}

	public function test_edit_field_shapes(): void {
		$shape = fn( string $name ): array => $this->type->edit_field( $this->cfg( $name ), $this->posts );
		$this->assertSame( 'text', $shape( 'text' )['input'] );
		$this->assertSame( 'textarea', $shape( 'textarea' )['input'] );
		$this->assertSame( 'number', $shape( 'number' )['input'] );
		$this->assertSame( 'toggle', $shape( 'checkbox' )['input'] );
		$this->assertSame( 'date', $shape( 'date' )['input'] );
		$this->assertSame( 'datetime', $shape( 'datetime' )['input'] );
		$this->assertSame( 'media', $shape( 'single_image' )['input'] );
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
		$this->assertSame( 'multiselect', $shape( 'select_multi' )['input'] );
		$this->assertSame( array( 'set', 'clear', 'append', 'remove' ), $shape( 'select_multi' )['bulk'] );
		$this->assertArrayHasKey( (string) $this->t['t1'], $shape( 'post' )['options'] );
		$this->assertSame( 'Target One', $shape( 'post' )['options'][ (string) $this->t['t1'] ] );
		$this->assertArrayHasKey( (string) $this->c['c1'], $shape( 'tax' )['options'] );
		$this->assertArrayNotHasKey( (string) $this->c['tag'], $shape( 'tax' )['options'], 'Only the field taxonomy is offered' );
		$this->assertArrayHasKey( (string) $this->u['u1'], $shape( 'user' )['options'] );
	}

	public function test_relation_options_are_capped_at_200(): void {
		for ( $i = 0; $i < 205; $i++ ) {
			self::factory()->post->create( array( 'post_title' => 'Bulk ' . $i ) );
		}
		$this->assertCount( 200, $this->type->edit_field( $this->cfg( 'post' ), $this->posts )['options'] );
	}

	public function test_read_only_kinds_cannot_be_edited(): void {
		foreach ( array( 'wysiwyg', 'clone' ) as $name ) {
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

	public function test_edit_with_a_missing_field_id_fails_cleanly(): void {
		$config = new Config( 'g', 'metabox.field', 'x', null, 'px', array() );
		$result = $this->type->save( $this->p['p1'], $config, $this->posts, 'x' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'corinthian_no_key', $result->get_error_code() );
		$shape = $this->type->edit_field( $config, $this->posts );
		$this->assertTrue( $shape['readonly'] );
	}

	public function test_a_term_field_is_not_reachable_from_a_post_screen(): void {
		$config = $this->cfg( 'term_text' );
		$this->assertNull( $this->type->value( $this->p['p1'], $config, $this->posts ) );
		$result = $this->type->save( $this->p['p1'], $config, $this->posts, 'x' );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	// Custom storage

	public function test_a_field_in_custom_storage_renders_and_saves_through_meta_box_only(): void {
		$id     = $this->p['p1'];
		$config = $this->cfg( 'cust' );
		rwmb_set_meta( $id, 'cln_cust', 'from custom' );
		$this->assertSame( array( 'from custom' ), self::$custom[ "$id:cln_cust" ] );
		$this->assertSame( array(), get_post_meta( $id, 'cln_cust', false ), 'Nothing lands in postmeta' );
		$this->assertSame( 'from custom', $this->type->render( $id, $config, $this->posts ) );

		$this->assertFalse( $this->type->sorts( $config, $this->posts ) );
		$this->assertSame( array(), $this->type->filter_operators( $config, $this->posts ), 'SQL cannot reach a custom table' );
		$this->assertNull( $this->type->filter_condition( $config, 'empty', null, $this->posts, 'a' ) );

		$this->assertTrue( $this->type->save( $id, $config, $this->posts, 'edited' ) );
		$this->assertSame( array( 'edited' ), self::$custom[ "$id:cln_cust" ] );
		$this->assertSame( 'edited', $this->type->edit_value( $id, $config, $this->posts ) );
		$this->assertSame( array(), get_post_meta( $id, 'cln_cust', false ) );
		$this->assertTrue( $this->type->save( $id, $config, $this->posts, null, 'clear' ) );
		$this->assertArrayNotHasKey( "$id:cln_cust", self::$custom );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$denied = $this->type->save( $id, $config, $this->posts, 'nope' );
		$this->assertInstanceOf( WP_Error::class, $denied, 'The object capability still applies without meta storage' );
		$this->assertSame( 'corinthian_protected', $denied->get_error_code() );
		$this->assertArrayNotHasKey( "$id:cln_cust", self::$custom );
	}

	// Security regressions

	public function test_password_fields_are_unavailable_everywhere(): void {
		$id = $this->p['p1'];
		update_post_meta( $id, 'cln_password', 'hunter2' );
		update_post_meta( $id, 'cln_plain', 'visible' );

		$plain = $this->cfg( 'plain' );
		$this->assertSame( 'visible', $this->type->value( $id, $plain, $this->posts ), 'Control: a plain text field reads' );
		$this->assertTrue( $this->type->sorts( $plain, $this->posts ), 'Control: a plain text field sorts' );
		$this->assertNotSame( array(), $this->ops( 'plain' ), 'Control: a plain text field filters' );

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

	public function test_readonly_and_disabled_fields_are_not_editable(): void {
		$id = $this->p['p1'];
		$this->assertTrue( $this->type->save( $id, $this->cfg( 'plain' ), $this->posts, 'changed' ), 'Control: a plain field saves' );
		$this->assertSame( 'changed', get_post_meta( $id, 'cln_plain', true ) );

		foreach ( array( 'ro_flag', 'dis_flag', 'ro_attr', 'dis_attr' ) as $name ) {
			$config = $this->cfg( $name );
			$this->assertTrue( $this->type->edit_field( $config, $this->posts )['readonly'], "$name is shown read-only" );
			$result = $this->type->save( $id, $config, $this->posts, 'changed' );
			$this->assertInstanceOf( WP_Error::class, $result, "$name refuses edits" );
			$this->assertSame( 'corinthian_readonly', $result->get_error_code(), $name );
			$this->assertSame( array(), $this->rows( $id, $name ), "$name untouched" );
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
		$this->assertTrue( $this->type->save( $mine, $this->cfg( 'post' ), $this->posts, (string) $this->t['t1'] ), 'Control: a published post links' );
		$this->assertTrue( $this->type->save( $mine, $this->cfg( 'file_advanced' ), $this->posts, array( (string) $this->a2 ) ), 'Control: a public attachment links' );
		foreach ( array( $secret, $draft ) as $blocked ) {
			$result = $this->type->save( $mine, $this->cfg( 'post' ), $this->posts, (string) $blocked );
			$this->assertInstanceOf( WP_Error::class, $result, 'Unreadable post is rejected' );
			$this->assertSame( 'corinthian_invalid', $result->get_error_code() );
		}
		$result = $this->type->save( $mine, $this->cfg( 'file_advanced' ), $this->posts, array( (string) $file ) );
		$this->assertInstanceOf( WP_Error::class, $result, 'Unreadable attachment is rejected' );
		$this->assertSame( array( (string) $this->t['t1'] ), $this->rows( $mine, 'post' ) );

		wp_set_current_user( $this->admin );
		$this->assertTrue( $this->type->save( $mine, $this->cfg( 'post' ), $this->posts, (string) $secret ), 'Control: a user who can read the private post links it' );
		$this->assertTrue( $this->type->save( $mine, $this->cfg( 'post' ), $this->posts, (string) $draft ), 'Control: a user who can edit the draft links it' );
		$this->assertTrue( $this->type->save( $mine, $this->cfg( 'file_advanced' ), $this->posts, array( (string) $file ) ), 'Control: a user who can read the private file links it' );
	}
}
