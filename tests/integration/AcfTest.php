<?php

namespace CorinthianPro\Tests;

use Corinthian\Columns\Config;
use Corinthian\Columns\Registry;
use Corinthian\Import\AdminColumnsImporter;
use CorinthianPro\Integrations\Acf\AcfFieldType;
use CorinthianPro\Integrations\Acf\AcfIntegration;
use Corinthian\Plugin;
use Corinthian\Screens\PostTypeScreen;
use Corinthian\Screens\Screen;
use Corinthian\Screens\TermScreen;
use Corinthian\Screens\UserScreen;
use Corinthian\Sets\SetRepository;
use WP_Error;
use WP_Query;
use WP_UnitTestCase;
use WP_User_Query;

class AcfTest extends WP_UnitTestCase {

	private const ASC  = array( 'p2', 'p1', 'p3', 'p4', 'p5' );
	private const DESC = array( 'p3', 'p1', 'p2', 'p5', 'p4' );

	private Registry $registry;
	private AcfFieldType $type;
	private Screen $posts;
	private Screen $users;
	private Screen $terms;

	/** @var array<string,int> p1..p5 are the posts under test */
	private array $p = array();

	/** @var array<string,int> t1..t3 are posts the relational fields point to */
	private array $t = array();

	/** @var array<string,int> c1..c3 categories, c4 unused, tag a post tag */
	private array $c = array();

	/** @var array<string,int> */
	private array $u = array();

	private int $a1    = 0;
	private int $a2    = 0;
	private int $admin = 0;
	private int $seq   = 0;

	/** @var array<string,mixed> */
	private static array $snapshot = array();

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		if ( ! function_exists( 'acf_get_field' ) ) {
			$file = WP_PLUGIN_DIR . '/advanced-custom-fields/acf.php';
			if ( ! is_file( $file ) ) {
				self::markTestSkipped( 'Advanced Custom Fields is not installed. Run: wp plugin install advanced-custom-fields' );
			}
			require_once $file;
		}
		if ( ! did_action( 'acf/include_field_types' ) ) {
			// ACF boots on init, which has already run by the time this class loads. Boot it now,
			// before WP_UnitTestCase snapshots the hooks, so its field types keep their value hooks.
			acf()->init();
		}
		self::assertNotFalse( acf_get_field_type( 'relationship' ), 'ACF field types are loaded' );

		$location = static fn( string $param, string $value ) => array(
			array(
				array(
					'param'    => $param,
					'operator' => '==',
					'value'    => $value,
				),
			),
		);
		$field    = static fn( string $type, array $extra = array() ) => array(
			'key'   => 'field_cln_' . ( $extra['name'] ?? $type ),
			'label' => ucfirst( str_replace( '_', ' ', $extra['name'] ?? $type ) ),
			'name'  => 'cln_' . ( $extra['name'] ?? $type ),
			'type'  => $type,
		) + $extra;
		$choices  = array(
			'a' => 'Alpha',
			'b' => 'Beta',
			'c' => 'Gamma',
			'd' => 'Delta',
		);

		acf_add_local_field_group(
			array(
				'key'      => 'group_cln_posts',
				'title'    => 'Cln Group',
				'location' => $location( 'post_type', 'post' ),
				'fields'   => array(
					$field( 'text', array( 'maxlength' => 12 ) ),
					$field(
						'text',
						array(
							'name'     => 'required_text',
							'required' => 1,
						)
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
					$field( 'select', array( 'choices' => $choices ) ),
					$field(
						'select',
						array(
							'name'     => 'select_multi',
							'choices'  => $choices,
							'multiple' => 1,
						)
					),
					$field( 'radio', array( 'choices' => $choices ) ),
					$field( 'button_group', array( 'choices' => $choices ) ),
					$field( 'checkbox', array( 'choices' => $choices ) ),
					$field( 'true_false' ),
					$field( 'date_picker', array( 'display_format' => 'd/m/Y' ) ),
					$field( 'date_time_picker', array( 'display_format' => 'd/m/Y g:i a' ) ),
					$field( 'time_picker', array( 'display_format' => 'g:i a' ) ),
					$field( 'color_picker' ),
					$field( 'image' ),
					$field( 'file' ),
					$field( 'gallery' ),
					$field( 'link' ),
					$field( 'post_object', array( 'post_type' => array( 'post', 'page' ) ) ),
					$field( 'relationship', array( 'max' => 3 ) ),
					$field( 'page_link' ),
					$field(
						'taxonomy',
						array(
							'taxonomy'   => 'category',
							'field_type' => 'checkbox',
						)
					),
					$field( 'user' ),
					$field( 'wysiwyg' ),
					$field( 'oembed' ),
					$field( 'repeater' ),
					$field( 'flexible_content' ),
					$field(
						'group',
						array(
							'sub_fields' => array(
								array(
									'key'   => 'field_cln_group_sub',
									'label' => 'Sub',
									'name'  => 'sub',
									'type'  => 'text',
								),
							),
						)
					),
					$field( 'tab', array( 'name' => 'a_tab' ) ),
				),
			)
		);
		acf_add_local_field_group(
			array(
				'key'      => 'group_cln_users',
				'title'    => 'Cln Users',
				'location' => $location( 'user_form', 'edit' ),
				'fields'   => array(
					$field( 'text', array( 'name' => 'user_text' ) ),
					$field( 'number', array( 'name' => 'user_number' ) ),
				),
			)
		);
		acf_add_local_field_group(
			array(
				'key'      => 'group_cln_terms',
				'title'    => 'Cln Terms',
				'location' => $location( 'taxonomy', 'category' ),
				'fields'   => array(
					$field( 'text', array( 'name' => 'term_text' ) ),
					$field( 'number', array( 'name' => 'term_number' ) ),
				),
			)
		);

		$property = new \ReflectionProperty( Registry::class, 'types' );
		$property->setAccessible( true );
		self::$snapshot = $property->getValue( Plugin::instance()->registry );
	}

	public function tear_down(): void {
		$property = new \ReflectionProperty( Registry::class, 'types' );
		$property->setAccessible( true );
		$property->setValue( Plugin::instance()->registry, self::$snapshot );
		parent::tear_down();
	}

	public function set_up(): void {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );

		$this->registry = new Registry();
		AcfIntegration::register();
		do_action( 'corinthian_register_types', $this->registry );
		$type = $this->registry->get( 'acf.field' );
		$this->assertInstanceOf( AcfFieldType::class, $type );
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
		return wp_date( 'Ymd', time() + $days * DAY_IN_SECONDS );
	}

	private static function dt( int $days, string $time ): string {
		return wp_date( 'Y-m-d', time() + $days * DAY_IN_SECONDS ) . ' ' . $time;
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
		foreach ( array( 'c1', 'c2', 'c3', 'c4' ) as $k ) {
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
		$image2         = $this->attachment( 'cln-two.png', 'image/png', 'Image Two' );

		foreach ( array( 'p1', 'p2', 'p3', 'p4', 'p5' ) as $i => $k ) {
			$this->p[ $k ] = self::factory()->post->create( array( 'post_title' => 'Post ' . ( $i + 1 ) ) );
		}

		$t = $this->t;
		$c = $this->c;
		$u = $this->u;

		$rows = array(
			'text'             => array( 'banana', 'apple', 'cherry' ),
			'textarea'         => array( "b line\nsecond", 'a line', 'c line' ),
			'number'           => array( '10', '9', '100' ),
			'range'            => array( '20', '5', '50' ),
			'email'            => array( 'b@example.com', 'a@example.com', 'c@example.com' ),
			'url'              => array( 'https://b.example.com', 'https://a.example.com', 'https://c.example.com' ),
			'select'           => array( 'b', 'a', 'c' ),
			'radio'            => array( 'b', 'a', 'c' ),
			'button_group'     => array( 'b', 'a', 'c' ),
			'select_multi'     => array( array( 'a', 'b' ), array( 'c' ), array( 'b', 'c' ) ),
			'checkbox'         => array( array( 'a', 'b' ), array( 'c' ), array( 'b', 'c' ) ),
			'true_false'       => array( 1, 0, 1 ),
			'date_picker'      => array( self::ymd( -10 ), self::ymd( -400 ), self::ymd( -3 ) ),
			'date_time_picker' => array( self::dt( -10, '10:00:00' ), self::dt( -400, '08:00:00' ), self::dt( -3, '23:30:00' ) ),
			'time_picker'      => array( '10:30:00', '09:15:00', '18:45:00' ),
			'color_picker'     => array( '#bbbbbb', '#aaaaaa', '#cccccc' ),
			'image'            => array( $this->a1, $image2, $this->a1 ),
			'file'             => array( $this->a2, $this->a1, $this->a2 ),
			'link'             => array(
				array(
					'title'  => 'Link B',
					'url'    => 'https://b.example.com/',
					'target' => '',
				),
				array(
					'title'  => 'Link A',
					'url'    => 'https://a.example.com/',
					'target' => '',
				),
				array(
					'title'  => 'Link C',
					'url'    => 'https://c.example.com/',
					'target' => '',
				),
			),
			'post_object'      => array( $t['t1'], $t['t2'], $t['t1'] ),
			'page_link'        => array( $t['t1'], $t['t2'], $t['t1'] ),
			'relationship'     => array( array( $t['t1'], $t['t2'] ), array( $t['t2'] ), array( $t['t3'] ) ),
			'taxonomy'         => array( array( $c['c1'], $c['c2'] ), array( $c['c2'] ), array( $c['c3'] ) ),
			'user'             => array( $u['u1'], $u['u2'], $u['u1'] ),
			'wysiwyg'          => array( '<p>b <strong>body</strong></p>', '<p>a body</p>', '<p>c body</p>' ),
			'oembed'           => array( 'https://b.example.com/v', 'https://a.example.com/v', 'https://c.example.com/v' ),
		);
		foreach ( $rows as $name => $values ) {
			foreach ( array( 'p1', 'p2', 'p3' ) as $i => $k ) {
				update_field( 'field_cln_' . $name, $values[ $i ], $this->p[ $k ] );
			}
			update_field( 'field_cln_' . $name, in_array( $name, array( 'select_multi', 'checkbox', 'relationship', 'taxonomy' ), true ) ? array() : '', $this->p['p4'] );
		}

		// Pro-only types: written the way ACF Pro stores them.
		$raw = array(
			'gallery'          => array( array( (string) $this->a1, (string) $image2 ), array( (string) $this->a1 ), array( (string) $image2 ), array() ),
			'repeater'         => array( '3', '0', '1', '0' ),
			'flexible_content' => array( array( 'hero', 'text' ), array(), array( 'hero' ), array() ),
		);
		foreach ( $raw as $name => $values ) {
			foreach ( array( 'p1', 'p2', 'p3', 'p4' ) as $i => $k ) {
				update_post_meta( $this->p[ $k ], 'cln_' . $name, $values[ $i ] );
				update_post_meta( $this->p[ $k ], '_cln_' . $name, 'field_cln_' . $name );
			}
		}
		update_field( 'field_cln_group', array( 'field_cln_group_sub' => 'hello' ), $this->p['p1'] );
	}

	private function cfg( string $name ): Config {
		return new Config( 'c' . ( ++$this->seq ), 'acf.field', 'x', null, 'px', array( 'field_key' => 'field_cln_' . $name ) );
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

	public function test_loader_registers_the_type_and_supports_posts_users_and_terms(): void {
		$this->assertSame( 'acf.field', $this->type->key() );
		$this->assertTrue( $this->type->supports( $this->posts ) );
		$this->assertTrue( $this->type->supports( $this->users ) );
		$this->assertTrue( $this->type->supports( $this->terms ) );
		$this->assertArrayHasKey( 'acf.field', $this->registry->for_screen( $this->posts ) );
	}

	public function test_settings_schema_lists_the_fields_for_each_screen(): void {
		$schema = $this->type->settings_schema( $this->posts );
		$this->assertSame( 'field_key', $schema[0]['name'] );
		$this->assertSame( 'select', $schema[0]['type'] );
		$this->assertSame( 'Cln Group: Text', $schema[0]['options']['field_cln_text'] );
		$this->assertSame( 'Cln Group: Date picker', $schema[0]['options']['field_cln_date_picker'] );
		$this->assertArrayHasKey( 'field_cln_group', $schema[0]['options'] );
		$this->assertArrayNotHasKey( 'field_cln_a_tab', $schema[0]['options'], 'Tabs hold no value' );
		$this->assertArrayNotHasKey( 'field_cln_user_text', $schema[0]['options'] );

		$users = $this->type->settings_schema( $this->users )[0]['options'];
		$this->assertSame( array( 'field_cln_user_text', 'field_cln_user_number' ), array_keys( $users ) );
		$this->assertSame( 'Cln Users: User text', $users['field_cln_user_text'] );

		$terms = $this->type->settings_schema( $this->terms )[0]['options'];
		$this->assertSame( array( 'field_cln_term_text', 'field_cln_term_number' ), array_keys( $terms ) );

		$pages = new PostTypeScreen( $this->registry, new SetRepository(), get_post_type_object( 'page' ) );
		$this->assertSame( array(), $this->type->settings_schema( $pages )[0]['options'] );
	}

	public function test_render_text_kinds(): void {
		$this->assertStringContainsString( 'banana', $this->render( 'text' ) );
		$this->assertStringContainsString( 'b line', $this->render( 'textarea' ) );
		$this->assertStringContainsString( '10', $this->render( 'number' ) );
		$this->assertStringContainsString( '<a href="mailto:b@example.com">b@example.com</a>', $this->render( 'email' ) );
		$this->assertStringContainsString( '<a href="https://b.example.com"', $this->render( 'url' ) );
		$this->assertStringContainsString( 'rel="noopener noreferrer"', $this->render( 'url' ) );
		$this->assertSame( 'b body', $this->render( 'wysiwyg' ), 'Markup is stripped' );
		$this->assertStringContainsString( '<a href="https://b.example.com/v"', $this->render( 'oembed' ) );
	}

	public function test_render_escapes_stored_values(): void {
		update_field( 'field_cln_text', '<script>alert(1)</script>', $this->p['p1'] );
		$html = $this->render( 'text' );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_render_choice_kinds_show_labels(): void {
		$this->assertSame( 'Beta', $this->render( 'select' ) );
		$this->assertSame( 'Beta', $this->render( 'radio' ) );
		$this->assertSame( 'Beta', $this->render( 'button_group' ) );
		$this->assertSame( 'Alpha, Beta', $this->render( 'select_multi' ) );
		$this->assertSame( 'Alpha, Beta', $this->render( 'checkbox' ) );
	}

	public function test_render_true_false_uses_an_icon_with_an_aria_label(): void {
		$this->assertStringContainsString( 'dashicons-yes', $this->render( 'true_false', 'p1' ) );
		$this->assertStringContainsString( 'aria-label="Yes"', $this->render( 'true_false', 'p1' ) );
		$this->assertStringContainsString( 'dashicons-no-alt', $this->render( 'true_false', 'p2' ) );
		$this->assertStringContainsString( 'aria-label="No"', $this->render( 'true_false', 'p2' ) );
	}

	public function test_render_dates_use_the_field_display_format(): void {
		$this->assertSame( wp_date( 'd/m/Y', time() - 10 * DAY_IN_SECONDS ), $this->render( 'date_picker' ) );
		$this->assertSame( wp_date( 'd/m/Y', time() - 10 * DAY_IN_SECONDS ) . ' 10:00 am', $this->render( 'date_time_picker' ) );
		$this->assertSame( '10:30 am', $this->render( 'time_picker' ) );
		$this->assertSame( '6:45 pm', $this->render( 'time_picker', 'p3' ) );
	}

	public function test_render_media_kinds(): void {
		$this->assertStringContainsString( '<img', $this->render( 'image' ) );
		$file = $this->render( 'file' );
		$this->assertStringContainsString( '<a href="', $file );
		$this->assertStringContainsString( 'Doc Two', $file );

		$gallery = $this->render( 'gallery' );
		$this->assertStringContainsString( '2 images', $gallery );
		$this->assertStringContainsString( '<img', $gallery );
		$this->assertStringContainsString( '1 image<', $this->render( 'gallery', 'p2' ) );

		$link = $this->render( 'link' );
		$this->assertStringContainsString( '<a href="https://b.example.com/"', $link );
		$this->assertStringContainsString( '>Link B</a>', $link );
	}

	public function test_render_relational_kinds(): void {
		$one = $this->render( 'post_object' );
		$this->assertStringContainsString( '>Target One</a>', $one );
		$this->assertStringContainsString( '>Target One</a>', $this->render( 'page_link' ) );

		$many = $this->render( 'relationship' );
		$this->assertStringContainsString( 'Target One', $many );
		$this->assertStringContainsString( 'Target Two', $many );
		$this->assertStringNotContainsString( '+', $many );

		$ids = array();
		for ( $i = 1; $i <= 7; $i++ ) {
			$ids[] = self::factory()->post->create( array( 'post_title' => "Rel $i" ) );
		}
		update_field( 'field_cln_relationship', $ids, $this->p['p1'] );
		$capped = $this->render( 'relationship' );
		$this->assertSame( 5, substr_count( $capped, '<a ' ), 'Five links shown' );
		$this->assertStringContainsString( '+2', $capped );
		$this->assertStringNotContainsString( 'Rel 6', $capped );

		$this->assertSame( 'Cat c1, Cat c2', $this->render( 'taxonomy' ) );
		$this->assertSame( 'Una User', $this->render( 'user' ) );
	}

	public function test_render_color_and_structured_kinds(): void {
		$color = $this->render( 'color_picker' );
		$this->assertStringContainsString( 'background:#bbbbbb', $color );
		$this->assertStringContainsString( '#bbbbbb', wp_strip_all_tags( $color ) );

		update_field( 'field_cln_color_picker', 'red;background:url(x)', $this->p['p1'] );
		$this->assertStringNotContainsString( 'background:red', $this->render( 'color_picker' ), 'Unrecognised colours get no swatch' );

		$this->assertSame( '3 rows', $this->render( 'repeater' ) );
		$this->assertSame( '1 row', $this->render( 'repeater', 'p3' ) );
		$this->assertSame( '2 rows', $this->render( 'flexible_content' ) );
		$this->assertSame( '1 field set', $this->render( 'group' ) );
	}

	public function test_render_empty_values_show_a_dash_and_unknown_fields_fail_soft(): void {
		foreach ( array( 'text', 'select', 'image', 'post_object', 'relationship', 'taxonomy', 'repeater' ) as $name ) {
			$this->assertStringContainsString( '&#8212;', $this->render( $name, 'p5' ), "$name renders a dash when unset" );
		}
		$missing = new Config( 'x', 'acf.field', 'x', null, 'px', array( 'field_key' => 'field_nope' ) );
		$this->assertStringContainsString( '&#8212;', $this->type->render( $this->p['p1'], $missing, $this->posts ) );
		$this->assertNull( $this->type->value( $this->p['p1'], $missing, $this->posts ) );
		$this->assertSame( array(), $this->type->filter_operators( $missing, $this->posts ) );
	}

	public function test_sort_orders_ascending_and_descending_with_empty_values_last(): void {
		$sortable = array( 'text', 'textarea', 'email', 'url', 'number', 'range', 'select', 'radio', 'button_group', 'true_false', 'date_picker', 'date_time_picker', 'time_picker', 'color_picker', 'wysiwyg', 'oembed' );
		foreach ( $sortable as $name ) {
			$this->assertSame( self::ASC, $this->sorted( $name, 'ASC' ), "$name ASC" );
			$this->assertSame( self::DESC, $this->sorted( $name, 'DESC' ), "$name DESC" );
		}
	}

	public function test_numbers_sort_numerically_not_as_text(): void {
		$this->assertSame( array( 'p2', 'p1', 'p3' ), array_slice( $this->sorted( 'number', 'ASC' ), 0, 3 ), '9 < 10 < 100' );
	}

	public function test_unsortable_kinds_leave_the_clauses_unchanged(): void {
		$clauses = array(
			'join'    => '',
			'where'   => '',
			'orderby' => '',
			'groupby' => '',
		);
		$kinds   = array( 'select_multi', 'checkbox', 'image', 'file', 'gallery', 'link', 'post_object', 'relationship', 'page_link', 'taxonomy', 'user', 'repeater', 'flexible_content', 'group' );
		foreach ( $kinds as $name ) {
			$this->assertSame( $clauses, $this->type->sort_clauses( $clauses, $this->cfg( $name ), 'ASC', $this->posts ), "$name is not sortable" );
		}
	}

	public function test_text_filters(): void {
		$this->expect( array( 'p1' ), 'text', 'contains', 'an' );
		$this->expect( array( 'p2', 'p3' ), 'text', 'contains', 'e' );
		$this->expect( array( 'p2' ), 'text', 'eq', 'apple' );
		$this->expect( array( 'p4', 'p5' ), 'text', 'empty' );
		$this->expect( array( 'p1', 'p2', 'p3' ), 'text', 'not_empty' );
		$this->expect( array(), 'text', 'contains', 'zzz' );
		$this->expect( array(), 'text', 'not_empty', null, array( 'p4', 'p5' ) );
		$this->assertNull( $this->filtered( 'text', 'contains', '' ) );
		$this->expect( array( 'p1', 'p2', 'p3' ), 'textarea', 'contains', 'line' );
		$this->expect( array( 'p1', 'p2', 'p3' ), 'email', 'contains', '@example' );
		foreach ( array( 'textarea', 'email', 'url', 'color_picker', 'wysiwyg', 'oembed' ) as $name ) {
			$this->expect( array( 'p4', 'p5' ), $name, 'empty' );
			$this->expect( array(), $name, 'contains', 'zzz-none' );
		}
	}

	public function test_number_filters(): void {
		foreach ( array( 'number', 'range' ) as $name ) {
			$this->expect( array( 'p4', 'p5' ), $name, 'empty' );
		}
		$this->expect( array( 'p1', 'p3' ), 'number', 'gt', '9' );
		$this->expect( array( 'p2' ), 'number', 'lt', '10' );
		$this->expect( array( 'p1', 'p2' ), 'number', 'between', array( '9', '10' ) );
		$this->expect( array( 'p1', 'p2' ), 'number', 'between', array( '10', '9' ), null );
		$this->expect( array( 'p3' ), 'number', 'eq', '100' );
		$this->expect( array(), 'number', 'gt', '1000' );
		$this->expect( array( 'p1', 'p3' ), 'range', 'gt', '10' );
		$this->assertNull( $this->filtered( 'number', 'gt', 'abc' ) );
		$this->assertNull( $this->filtered( 'number', 'between', array( '1' ) ) );
	}

	public function test_date_filters_use_the_acf_storage_formats(): void {
		$day = static fn( int $d ): string => wp_date( 'Y-m-d', time() + $d * DAY_IN_SECONDS );
		foreach ( array( 'date_picker', 'date_time_picker' ) as $name ) {
			$this->expect( array( 'p2' ), $name, 'before', $day( -100 ) );
			$this->expect( array( 'p1', 'p2' ), $name, 'before', $day( -3 ) );
			$this->expect( array( 'p3' ), $name, 'after', $day( -4 ) );
			$this->expect( array(), $name, 'after', $day( -3 ), null );
			$this->expect( array( 'p1', 'p3' ), $name, 'within_days', '30' );
			$this->expect( array( 'p3' ), $name, 'within_days', '5' );
			$this->expect( array(), $name, 'within_days', '1' );
			$this->expect( array(), $name, 'before', $day( -1000 ) );
			$this->expect( array( 'p4', 'p5' ), $name, 'empty' );
			$this->expect( array( 'p1', 'p2', 'p3' ), $name, 'not_empty' );
			$this->assertNull( $this->filtered( $name, 'before', '03/05/2024' ) );
			$this->assertNull( $this->filtered( $name, 'before', '2024-02-31' ) );
			$this->assertNull( $this->filtered( $name, 'within_days', 'x' ) );
		}
	}

	public function test_time_filters(): void {
		$this->expect( array( 'p2' ), 'time_picker', 'before', '10:00' );
		$this->expect( array( 'p3' ), 'time_picker', 'after', '10:30' );
		$this->expect( array(), 'time_picker', 'after', '23:00' );
		$this->expect( array( 'p4', 'p5' ), 'time_picker', 'empty' );
		$this->assertNull( $this->filtered( 'time_picker', 'before', '25:00' ) );
	}

	public function test_choice_filters_for_single_value_fields(): void {
		foreach ( array( 'select', 'radio', 'button_group' ) as $name ) {
			$this->expect( array( 'p2' ), $name, 'in', array( 'a' ) );
			$this->expect( array( 'p2', 'p3' ), $name, 'in', array( 'a', 'c' ) );
			$this->expect( array( 'p1', 'p3', 'p4', 'p5' ), $name, 'not_in', array( 'a' ) );
			$this->expect( array(), $name, 'in', array( 'd' ) );
			$this->expect( array( 'p4', 'p5' ), $name, 'empty' );
			$this->assertNull( $this->filtered( $name, 'in', array( 'zzz' ) ), 'Values outside the choices are rejected' );
		}
		$options = $this->type->filter_operators( $this->cfg( 'select' ), $this->posts );
		$this->assertSame(
			array(
				'a' => 'Alpha',
				'b' => 'Beta',
				'c' => 'Gamma',
				'd' => 'Delta',
			),
			$options[0]['options']
		);
	}

	public function test_choice_filters_match_serialized_arrays(): void {
		foreach ( array( 'checkbox', 'select_multi' ) as $name ) {
			$this->expect( array( 'p1', 'p3' ), $name, 'in', array( 'b' ) );
			$this->expect( array( 'p2', 'p3' ), $name, 'in', array( 'c' ) );
			$this->expect( array( 'p1', 'p2', 'p3' ), $name, 'in', array( 'a', 'c' ) );
			$this->expect( array( 'p2', 'p4', 'p5' ), $name, 'not_in', array( 'b' ) );
			$this->expect( array(), $name, 'in', array( 'd' ) );
			$this->expect( array( 'p4', 'p5' ), $name, 'empty' );
			$this->expect( array( 'p1', 'p2', 'p3' ), $name, 'not_empty' );
		}
	}

	public function test_true_false_filter(): void {
		$this->expect( array( 'p1', 'p3' ), 'true_false', 'eq', '1' );
		$this->expect( array( 'p2', 'p4', 'p5' ), 'true_false', 'eq', '0' );
		$this->expect( array(), 'true_false', 'eq', '0', array( 'p1', 'p3' ) );
		$this->assertNull( $this->filtered( 'true_false', 'eq', 'maybe' ) );
	}

	public function test_relational_filters_match_ids_in_single_and_serialized_values(): void {
		$t = $this->t;
		foreach ( array( 'post_object', 'page_link' ) as $name ) {
			$this->expect( array( 'p1', 'p3' ), $name, 'contains', (string) $t['t1'] );
			$this->expect( array( 'p2' ), $name, 'contains', (string) $t['t2'] );
			$this->expect( array(), $name, 'contains', (string) $t['t3'] );
			$this->expect( array( 'p4', 'p5' ), $name, 'empty' );
		}
		$this->expect( array( 'p1' ), 'relationship', 'contains', (string) $t['t1'] );
		$this->expect( array( 'p1', 'p2' ), 'relationship', 'contains', (string) $t['t2'] );
		$this->expect( array( 'p3' ), 'relationship', 'contains', (string) $t['t3'] );
		$this->expect( array(), 'relationship', 'contains', '999999' );
		$this->expect( array( 'p4', 'p5' ), 'relationship', 'empty' );

		$c = $this->c;
		$this->expect( array( 'p1' ), 'taxonomy', 'contains', (string) $c['c1'] );
		$this->expect( array( 'p1', 'p2' ), 'taxonomy', 'contains', (string) $c['c2'] );
		$this->expect( array( 'p3' ), 'taxonomy', 'contains', (string) $c['c3'] );
		$this->expect( array(), 'taxonomy', 'contains', (string) $c['c4'] );
		$this->expect( array( 'p4', 'p5' ), 'taxonomy', 'empty' );

		$u = $this->u;
		$this->expect( array( 'p1', 'p3' ), 'user', 'contains', (string) $u['u1'] );
		$this->expect( array( 'p2' ), 'user', 'contains', (string) $u['u2'] );
		$this->expect( array(), 'user', 'contains', '999999' );

		$this->assertNull( $this->filtered( 'relationship', 'contains', 'abc' ) );
		$this->assertNull( $this->filtered( 'relationship', 'contains', '0' ) );
	}

	public function test_relational_filter_does_not_match_a_longer_id(): void {
		$long = (int) ( $this->t['t1'] . '9' );
		$this->assertNotSame( $long, $this->t['t1'] );
		$this->expect( array(), 'relationship', 'contains', (string) $long );
		$this->expect( array(), 'post_object', 'contains', (string) $long );
	}

	public function test_media_and_structured_filters_use_empty_and_not_empty(): void {
		foreach ( array( 'image', 'file', 'link' ) as $name ) {
			$this->expect( array( 'p1', 'p2', 'p3' ), $name, 'not_empty' );
			$this->expect( array( 'p4', 'p5' ), $name, 'empty' );
			$this->expect( array(), $name, 'not_empty', null, array( 'p4', 'p5' ) );
		}
		$this->expect( array( 'p1', 'p2', 'p3' ), 'gallery', 'not_empty' );
		$this->expect( array( 'p4', 'p5' ), 'gallery', 'empty' );
		$this->expect( array( 'p1', 'p3' ), 'repeater', 'not_empty' );
		$this->expect( array( 'p2', 'p4', 'p5' ), 'repeater', 'empty' );
		$this->expect( array( 'p1', 'p3' ), 'flexible_content', 'not_empty' );
		$this->expect( array( 'p2', 'p4', 'p5' ), 'flexible_content', 'empty' );
		$this->assertSame( array(), $this->type->filter_operators( $this->cfg( 'group' ), $this->posts ) );
		$this->assertNull( $this->type->filter_condition( $this->cfg( 'group' ), 'empty', null, $this->posts, 'clf0' ) );
	}

	public function test_filter_operators_are_unique_per_rule_alias(): void {
		$config = $this->cfg( 'text' );
		$a      = $this->type->filter_condition( $config, 'eq', 'apple', $this->posts, 'clf0' );
		$b      = $this->type->filter_condition( $config, 'eq', 'banana', $this->posts, 'clf1' );
		$this->assertStringContainsString( 'AS clf0 ', (string) $a['join'] );
		$this->assertStringContainsString( 'AS clf1 ', (string) $b['join'] );
	}

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
			update_field( 'field_cln_user_text', $v[0], 'user_' . $users[ $k ] );
			update_field( 'field_cln_user_number', $v[1], 'user_' . $users[ $k ] );
		}
		$flip   = array_flip( $users );
		$text   = $this->cfg( 'user_text' );
		$number = $this->cfg( 'user_number' );

		$this->assertSame( 'banana', $this->type->render( $users['u_b'], $text, $this->users ) );
		$this->assertSame( 'banana', get_user_meta( $users['u_b'], 'cln_user_text', true ) );

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
			update_field( 'field_cln_term_text', $v[0], 'term_' . $terms[ $k ] );
			update_field( 'field_cln_term_number', $v[1], 'term_' . $terms[ $k ] );
		}
		$flip   = array_flip( $terms );
		$text   = $this->cfg( 'term_text' );
		$number = $this->cfg( 'term_number' );

		$this->assertSame( 'cherry', $this->type->render( $terms['t_c'], $text, $this->terms ) );
		$this->assertSame( 'cherry', get_term_meta( $terms['t_c'], 'cln_term_text', true ) );

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

		$this->assertTrue( $this->type->save( $terms['t_a'], $text, $this->terms, 'kiwi' ) );
		$this->assertSame( 'kiwi', get_term_meta( $terms['t_a'], 'cln_term_text', true ) );
		$this->assertInstanceOf( WP_Error::class, $this->type->save( $terms['t_a'], $number, $this->terms, 'abc' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$denied = $this->type->save( $terms['t_a'], $text, $this->terms, 'nope' );
		$this->assertInstanceOf( WP_Error::class, $denied );
		$this->assertSame( 'kiwi', get_term_meta( $terms['t_a'], 'cln_term_text', true ) );
	}

	/**
	 * @return array<string,array{0:string,1:mixed,2:mixed,3:mixed}> name => [ valid input, stored meta, editor value, invalid input ]
	 */
	private function edit_cases(): array {
		$t = $this->t;
		$c = $this->c;
		$u = $this->u;
		return array(
			'text'             => array( 'New <b>x</b>', 'New x', 'New x', 'this value is way too long' ),
			'textarea'         => array( "l1\nl2", "l1\nl2", "l1\nl2", array( 'x' ) ),
			'number'           => array( '42', '42', '42', '5000' ),
			'range'            => array( '60', '60', '60', 'abc' ),
			'email'            => array( 'ok@example.com', 'ok@example.com', 'ok@example.com', 'nope' ),
			'url'              => array( 'https://example.com/x', 'https://example.com/x', 'https://example.com/x', 'not a url' ),
			'select'           => array( 'd', 'd', 'd', 'zzz' ),
			'radio'            => array( 'c', 'c', 'c', 'zzz' ),
			'button_group'     => array( 'a', 'a', 'a', 'zzz' ),
			'select_multi'     => array( array( 'a', 'c' ), array( 'a', 'c' ), array( 'a', 'c' ), array( 'a', 'zzz' ) ),
			'checkbox'         => array( array( 'b', 'd' ), array( 'b', 'd' ), array( 'b', 'd' ), array( 'zzz' ) ),
			'true_false'       => array( '0', '0', '0', array( 'x' ) ),
			'date_picker'      => array( '2025-02-03', '20250203', '2025-02-03', '03/02/2025' ),
			'date_time_picker' => array( '2025-02-03T14:30', '2025-02-03 14:30:00', '2025-02-03T14:30', 'tomorrow-ish' ),
			'time_picker'      => array( '14:30', '14:30:00', '14:30:00', '25:00' ),
			'color_picker'     => array( '#ff0000', '#ff0000', '#ff0000', 'red' ),
			'image'            => array( (string) $this->a1, (string) $this->a1, (string) $this->a1, (string) $t['t1'] ),
			'file'             => array( (string) $this->a2, (string) $this->a2, (string) $this->a2, '999999' ),
			'post_object'      => array( (string) $t['t3'], (string) $t['t3'], (string) $t['t3'], '999999' ),
			'page_link'        => array( (string) $t['t2'], (string) $t['t2'], (string) $t['t2'], 'abc' ),
			'relationship'     => array( array( (string) $t['t2'], (string) $t['t3'] ), array( (string) $t['t2'], (string) $t['t3'] ), array( (string) $t['t2'], (string) $t['t3'] ), array( '999999' ) ),
			'taxonomy'         => array( array( (string) $c['c2'], (string) $c['c3'] ), array( $c['c2'], $c['c3'] ), array( (string) $c['c2'], (string) $c['c3'] ), array( (string) $c['tag'] ) ),
			'user'             => array( (string) $u['u2'], (string) $u['u2'], (string) $u['u2'], '999999' ),
		);
	}

	public function test_edit_round_trips_through_update_field_for_every_editable_kind(): void {
		foreach ( $this->edit_cases() as $name => $case ) {
			[ $input, $stored, $shown ] = $case;
			$config                     = $this->cfg( $name );
			$id                         = $this->p['p5'];
			$result                     = $this->type->save( $id, $config, $this->posts, $input );
			$this->assertTrue( $result, "$name saves: " . ( $result instanceof WP_Error ? $result->get_error_message() : '' ) );
			$meta = get_post_meta( $id, 'cln_' . $name, true );
			$this->assertEquals( $stored, $meta, "$name stored value" );
			$this->assertSame( 'field_cln_' . $name, get_post_meta( $id, '_cln_' . $name, true ), "$name keeps ACF's field reference (update_field ran)" );
			$this->assertEquals( $shown, $this->type->edit_value( $id, $config, $this->posts ), "$name editor value" );
		}
	}

	public function test_edit_rejects_invalid_values_and_leaves_the_stored_value(): void {
		foreach ( $this->edit_cases() as $name => $case ) {
			$config = $this->cfg( $name );
			$id     = $this->p['p1'];
			$before = get_post_meta( $id, 'cln_' . $name, true );
			$result = $this->type->save( $id, $config, $this->posts, $case[3] );
			$this->assertInstanceOf( WP_Error::class, $result, "$name rejects " . wp_json_encode( $case[3] ) );
			$this->assertSame( 'corinthian_invalid', $result->get_error_code(), $name );
			$this->assertSame( $before, get_post_meta( $id, 'cln_' . $name, true ), "$name unchanged after a rejected edit" );
		}
	}

	public function test_edit_refuses_a_user_without_the_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		foreach ( $this->edit_cases() as $name => $case ) {
			$id     = $this->p['p1'];
			$before = get_post_meta( $id, 'cln_' . $name, true );
			$result = $this->type->save( $id, $this->cfg( $name ), $this->posts, $case[0] );
			$this->assertInstanceOf( WP_Error::class, $result, "$name refused" );
			$this->assertSame( 'corinthian_protected', $result->get_error_code(), $name );
			$this->assertSame( $before, get_post_meta( $id, 'cln_' . $name, true ), "$name untouched" );
		}
		$clear = $this->type->save( $this->p['p1'], $this->cfg( 'text' ), $this->posts, '', 'clear' );
		$this->assertInstanceOf( WP_Error::class, $clear );
		$this->assertSame( 'banana', get_post_meta( $this->p['p1'], 'cln_text', true ) );
	}

	public function test_edit_follows_the_meta_capability_for_protected_and_per_post_rules(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$other = $this->p['p1'];
		$mine  = self::factory()->post->create( array( 'post_author' => $author ) );
		$this->assertInstanceOf( WP_Error::class, $this->type->save( $other, $this->cfg( 'text' ), $this->posts, 'x' ), "Authors cannot edit others' posts" );
		$this->assertTrue( $this->type->save( $mine, $this->cfg( 'text' ), $this->posts, 'mine' ) );
		$this->assertSame( 'mine', get_post_meta( $mine, 'cln_text', true ) );
	}

	public function test_edit_clear_empties_the_value(): void {
		foreach ( array( 'text', 'number', 'select', 'true_false', 'date_picker', 'image', 'post_object', 'checkbox', 'relationship', 'taxonomy' ) as $name ) {
			$config = $this->cfg( $name );
			$id     = $this->p['p1'];
			$this->assertTrue( $this->type->save( $id, $config, $this->posts, null, 'clear' ), $name );
			$stored = get_post_meta( $id, 'cln_' . $name, true );
			$this->assertTrue( '' === $stored || array() === $stored, "$name cleared" );
		}
		$this->expect( array( 'p1', 'p4', 'p5' ), 'text', 'empty' );
	}

	public function test_edit_bulk_append_and_remove_on_multi_value_fields(): void {
		$t     = $this->t;
		$id    = $this->p['p2'];
		$check = $this->cfg( 'checkbox' );
		$this->assertTrue( $this->type->save( $id, $check, $this->posts, array( 'a', 'b' ), 'append' ) );
		$this->assertEqualsCanonicalizing( array( 'c', 'a', 'b' ), get_post_meta( $id, 'cln_checkbox', true ) );
		$this->assertTrue( $this->type->save( $id, $check, $this->posts, array( 'c', 'a' ), 'remove' ) );
		$this->assertSame( array( 'b' ), get_post_meta( $id, 'cln_checkbox', true ) );
		$this->assertInstanceOf( WP_Error::class, $this->type->save( $id, $check, $this->posts, array( 'zzz' ), 'append' ) );

		$rel = $this->cfg( 'relationship' );
		$this->assertTrue( $this->type->save( $id, $rel, $this->posts, array( $t['t1'], $t['t3'] ), 'append' ) );
		$this->assertEqualsCanonicalizing( array( (string) $t['t2'], (string) $t['t1'], (string) $t['t3'] ), get_post_meta( $id, 'cln_relationship', true ) );
		$over = $this->type->save( $id, $rel, $this->posts, array( $this->p['p1'] ), 'append' );
		$this->assertInstanceOf( WP_Error::class, $over, 'The field maximum of 3 is enforced' );
		$this->assertTrue( $this->type->save( $id, $rel, $this->posts, array( $t['t2'] ), 'remove' ) );
		$this->assertEqualsCanonicalizing( array( (string) $t['t1'], (string) $t['t3'] ), get_post_meta( $id, 'cln_relationship', true ) );

		$text = $this->type->save( $id, $this->cfg( 'text' ), $this->posts, 'x', 'append' );
		$this->assertInstanceOf( WP_Error::class, $text, 'Single-value fields only take set and clear' );
		$this->assertSame( 'corinthian_bad_mode', $text->get_error_code() );
	}

	public function test_edit_field_shapes(): void {
		$shape = fn( string $name ): array => $this->type->edit_field( $this->cfg( $name ), $this->posts );
		$this->assertSame( 'text', $shape( 'text' )['input'] );
		$this->assertSame( 'textarea', $shape( 'textarea' )['input'] );
		$this->assertSame( 'number', $shape( 'number' )['input'] );
		$this->assertSame( 'toggle', $shape( 'true_false' )['input'] );
		$this->assertSame( 'date', $shape( 'date_picker' )['input'] );
		$this->assertSame( 'media', $shape( 'image' )['input'] );
		$this->assertSame( 'media', $shape( 'file' )['input'] );
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
		$this->assertSame( 'multiselect', $shape( 'checkbox' )['input'] );
		$this->assertSame( 'multiselect', $shape( 'select_multi' )['input'] );
		$this->assertSame( array( 'set', 'clear', 'append', 'remove' ), $shape( 'checkbox' )['bulk'] );
		$this->assertSame( array( 'set', 'clear', 'append', 'remove' ), $shape( 'relationship' )['bulk'] );
		$this->assertSame( array( 'set', 'clear' ), $shape( 'text' )['bulk'] );
		$this->assertSame( 'select', $shape( 'post_object' )['input'] );
		$this->assertSame( 'Target One', $shape( 'post_object' )['options'][ (string) $this->t['t1'] ] );
		$this->assertSame( 'multiselect', $shape( 'taxonomy' )['input'] );
		$this->assertSame( 'Cat c1', $shape( 'taxonomy' )['options'][ (string) $this->c['c1'] ] );
		$this->assertArrayHasKey( (string) $this->u['u1'], $shape( 'user' )['options'] );
	}

	public function test_relation_options_are_capped_at_200(): void {
		$parent = self::factory()->post->create( array( 'post_title' => 'zz bulk' ) );
		for ( $i = 0; $i < 205; $i++ ) {
			self::factory()->post->create(
				array(
					'post_title'  => 'Bulk ' . $i,
					'post_parent' => $parent,
				)
			);
		}
		$options = $this->type->edit_field( $this->cfg( 'post_object' ), $this->posts )['options'];
		$this->assertCount( 200, $options );
	}

	public function test_read_only_kinds_cannot_be_edited(): void {
		foreach ( array( 'repeater', 'group', 'flexible_content', 'gallery', 'oembed', 'wysiwyg' ) as $name ) {
			$config = $this->cfg( $name );
			$field  = $this->type->edit_field( $config, $this->posts );
			$this->assertTrue( $field['readonly'], "$name is read-only" );
			$this->assertSame( array(), $field['bulk'], $name );
			$result = $this->type->save( $this->p['p1'], $config, $this->posts, 'x' );
			$this->assertInstanceOf( WP_Error::class, $result, $name );
			$this->assertSame( 'corinthian_readonly', $result->get_error_code(), $name );
			$this->assertNull( $this->type->edit_value( $this->p['p1'], $config, $this->posts ), $name );
		}
	}

	public function test_required_fields_cannot_be_cleared_or_emptied(): void {
		$config = $this->cfg( 'required_text' );
		update_field( 'field_cln_required_text', 'keep', $this->p['p1'] );
		$this->assertInstanceOf( WP_Error::class, $this->type->save( $this->p['p1'], $config, $this->posts, '' ) );
		$this->assertInstanceOf( WP_Error::class, $this->type->save( $this->p['p1'], $config, $this->posts, null, 'clear' ) );
		$this->assertSame( 'keep', get_post_meta( $this->p['p1'], 'cln_required_text', true ) );
		$this->assertTrue( $this->type->save( $this->p['p1'], $config, $this->posts, 'changed' ) );
	}

	public function test_edit_with_a_missing_field_key_fails_cleanly(): void {
		$config = new Config( 'x', 'acf.field', 'x', null, 'px', array() );
		$this->assertInstanceOf( WP_Error::class, $this->type->save( $this->p['p1'], $config, $this->posts, 'x' ) );
	}

	public function test_imported_post_meta_column_with_a_field_key_renders_the_field(): void {
		$field_key = 'field_cln_imported';
		$name      = 'cln_imported_name';
		acf_add_local_field_group(
			array(
				'key'      => 'group_cln_import',
				'title'    => 'Import',
				'location' => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'post',
						),
					),
				),
				'fields'   => array(
					array(
						'key'     => $field_key,
						'label'   => 'Imported',
						'name'    => $name,
						'type'    => 'select',
						'choices' => array( 'x' => 'Ex label' ),
					),
				),
			)
		);
		wp_insert_post(
			array(
				'post_type'    => 'acf-field',
				'post_status'  => 'publish',
				'post_name'    => $field_key,
				'post_title'   => 'Imported',
				'post_excerpt' => $name,
				'post_content' => maybe_serialize( array( 'type' => 'select' ) ),
			)
		);
		update_field( $field_key, 'x', $this->p['p1'] );

		$plugin   = Plugin::instance();
		$importer = new AdminColumnsImporter( $plugin );

		$before = $importer->map_column(
			array(
				'type'  => $field_key,
				'name'  => 'imp',
				'label' => '',
			)
		);
		$this->assertSame( 'post.meta', $before['type'] ?? null, 'Without the integration the stored value is shown' );

		$plugin->registry->add( new AcfFieldType() );
		$mapped = $importer->map_column(
			array(
				'type'  => $field_key,
				'name'  => 'imp',
				'label' => 'Mine',
			)
		);
		$this->assertNotNull( $mapped );
		$this->assertSame( 'acf.field', $mapped['type'] );
		$this->assertSame( $field_key, $mapped['settings']['field_key'] );
		$this->assertSame( 'Mine', $mapped['label'] );

		$config = Config::from_array( $mapped );
		$type   = $plugin->registry->get( $config->type );
		$this->assertNotNull( $type );
		$this->assertSame( 'Ex label', $type->render( $this->p['p1'], $config, $this->posts ) );
		$this->assertStringContainsString( '&#8212;', $type->render( $this->p['p2'], $config, $this->posts ) );
	}

	public function test_meta_guard_is_restored_even_when_the_check_throws(): void {
		$this->assertNotFalse( has_filter( 'map_meta_cap', 'acf_map_post_meta_caps' ), 'Control: ACF guard is active before the check.' );
		$thrower = static function () {
			throw new \RuntimeException( 'boom' );
		};
		add_filter( 'map_meta_cap', $thrower );
		try {
			\Corinthian\Columns\MetaCaps::can( 'edit_post_meta', $this->p['p1'], 'anything' );
			$this->fail( 'The check should have thrown.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		} finally {
			remove_filter( 'map_meta_cap', $thrower );
		}
		$this->assertNotFalse( has_filter( 'map_meta_cap', 'acf_map_post_meta_caps' ), 'ACF guard must be back after an exception.' );
	}
}
