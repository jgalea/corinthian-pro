<?php

namespace CorinthianPro\Tests;

use Corinthian\Columns\Config;
use Corinthian\Columns\Editable;
use Corinthian\Columns\Registry;
use Corinthian\Columns\Types\TypeLoader;
use Corinthian\Screens\PostTypeScreen;
use Corinthian\Sets\SetRepository;
use WP_Error;
use WP_UnitTestCase;

class PostEditTest extends WP_UnitTestCase {

	private Registry $registry;
	private PostTypeScreen $posts;
	private PostTypeScreen $pages;
	private int $admin;
	private int $editor;
	private int $author;
	private int $contributor;
	private int $post;
	private int $page;

	/** @var array<int,int> */
	private array $saved = array();

	public function set_up(): void {
		parent::set_up();
		$this->admin       = self::factory()->user->create( array( 'role' => 'administrator', 'display_name' => 'Ada' ) );
		$this->editor      = self::factory()->user->create( array( 'role' => 'editor', 'display_name' => 'Ed' ) );
		$this->author      = self::factory()->user->create( array( 'role' => 'author', 'display_name' => 'Au' ) );
		$this->contributor = self::factory()->user->create( array( 'role' => 'contributor', 'display_name' => 'Co' ) );
		wp_set_current_user( $this->admin );

		$this->registry = new Registry();
		TypeLoader::register( $this->registry );
		$this->posts = new PostTypeScreen( $this->registry, new SetRepository(), get_post_type_object( 'post' ) );
		$this->pages = new PostTypeScreen( $this->registry, new SetRepository(), get_post_type_object( 'page' ) );

		$this->post = self::factory()->post->create( array( 'post_title' => 'Alpha', 'post_author' => $this->author, 'post_status' => 'draft' ) );
		$this->page = self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'Gamma' ) );

		$this->saved = array();
		add_action(
			'save_post',
			function ( $id ) {
				$this->saved[ (int) $id ] = ( $this->saved[ (int) $id ] ?? 0 ) + 1;
			}
		);
	}

	public function tear_down(): void {
		remove_theme_support( 'post-formats' );
		parent::tear_down();
	}

	private function type( string $key ): Editable {
		$type = $this->registry->get( $key );
		$this->assertInstanceOf( Editable::class, $type, $key );
		return $type;
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private function save( string $key, int $id, mixed $value, string $mode = 'set', array $settings = array(), ?PostTypeScreen $screen = null ): bool|WP_Error {
		$config = new Config( 'c', $key, 'Column', null, 'px', $settings );
		return $this->type( $key )->save( $id, $config, $screen ?? $this->posts, $value, $mode );
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private function read( string $key, int $id, array $settings = array(), ?PostTypeScreen $screen = null ): mixed {
		$config = new Config( 'c', $key, 'Column', null, 'px', $settings );
		return $this->type( $key )->edit_value( $id, $config, $screen ?? $this->posts );
	}

	private function assertRejected( bool|WP_Error $result ): void {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( '', $result->get_error_message() );
	}

	public function test_every_target_type_is_editable_with_an_editor_shape(): void {
		$expected = array(
			'post.slug'           => 'text',
			'post.excerpt'        => 'textarea',
			'post.status'         => 'select',
			'post.author'         => 'select',
			'post.published_date' => 'datetime',
			'post.comment_status' => 'toggle',
			'post.ping_status'    => 'toggle',
			'post.sticky'         => 'toggle',
			'post.parent'         => 'select',
			'post.menu_order'     => 'number',
			'post.template'       => 'select',
			'post.format'         => 'select',
			'post.taxonomy'       => 'multiselect',
			'post.featured_image' => 'media',
		);
		foreach ( $expected as $key => $input ) {
			$field = $this->type( $key )->edit_field( new Config( 'c', $key, 'C', null, 'px', array( 'taxonomy' => 'post_tag' ) ), $this->pages );
			$this->assertSame( $input, $field['input'], $key );
			$this->assertContains( 'set', $field['bulk'], $key );
			$this->assertContains( 'clear' === $key ? 'clear' : 'set', $field['bulk'] );
		}
		$this->assertNotInstanceOf( Editable::class, $this->registry->get( 'post.modified_date' ) );
		$taxonomy = $this->type( 'post.taxonomy' )->edit_field( new Config( 'c', 'post.taxonomy', 'C' ), $this->posts );
		$this->assertSame( array( 'set', 'append', 'remove', 'clear' ), $taxonomy['bulk'] );
	}

	public function test_slug_saves_is_made_unique_and_clears(): void {
		$this->assertTrue( $this->save( 'post.slug', $this->post, 'Hello World!' ) );
		$this->assertSame( 'hello-world', $this->read( 'post.slug', $this->post ) );

		$other = self::factory()->post->create( array( 'post_title' => 'Beta', 'post_status' => 'publish' ) );
		$this->assertTrue( $this->save( 'post.slug', $other, 'hello-world' ) );
		$this->assertNotSame( 'hello-world', get_post( $other )->post_name );
		$this->assertStringStartsWith( 'hello-world', get_post( $other )->post_name );

		$this->assertRejected( $this->save( 'post.slug', $this->post, '!!!' ) );
		$this->assertRejected( $this->save( 'post.slug', $this->post, array( 'x' ) ) );
		$this->assertSame( 'hello-world', get_post( $this->post )->post_name );

		$this->assertTrue( $this->save( 'post.slug', $this->post, null, 'clear' ) );
		$this->assertSame( '', get_post( $this->post )->post_name );
	}

	public function test_excerpt_saves_strips_unsafe_markup_for_restricted_users_and_rejects_arrays(): void {
		$this->assertTrue( $this->save( 'post.excerpt', $this->post, 'A short summary.' ) );
		$this->assertSame( 'A short summary.', $this->read( 'post.excerpt', $this->post ) );
		$this->assertRejected( $this->save( 'post.excerpt', $this->post, array( 'a' ) ) );
		$this->assertRejected( $this->save( 'post.excerpt', $this->post, 'x', 'append' ) );

		wp_set_current_user( $this->author );
		$this->assertTrue( $this->save( 'post.excerpt', $this->post, 'Hi <script>alert(1)</script><b>there</b>' ) );
		$this->assertStringNotContainsString( '<script', get_post( $this->post )->post_excerpt );

		wp_set_current_user( $this->admin );
		$this->assertTrue( $this->save( 'post.excerpt', $this->post, null, 'clear' ) );
		$this->assertSame( '', $this->read( 'post.excerpt', $this->post ) );

		$this->assertRejected( $this->save( 'post.excerpt', $this->page, 'Pages have none', 'set', array(), $this->pages ) );
	}

	public function test_status_saves_and_rejects_bad_values(): void {
		$this->assertTrue( $this->save( 'post.status', $this->post, 'pending' ) );
		$this->assertSame( 'pending', $this->read( 'post.status', $this->post ) );
		$this->assertTrue( $this->save( 'post.status', $this->post, 'publish' ) );
		$this->assertSame( 'publish', get_post_status( $this->post ) );

		$this->assertRejected( $this->save( 'post.status', $this->post, 'bogus' ) );
		$this->assertRejected( $this->save( 'post.status', $this->post, 'trash' ) );
		$this->assertRejected( $this->save( 'post.status', $this->post, 'auto-draft' ) );
		$this->assertRejected( $this->save( 'post.status', $this->post, 'future' ) );
		$this->assertSame( 'publish', get_post_status( $this->post ) );

		$this->assertTrue( $this->save( 'post.published_date', $this->post, gmdate( 'Y-m-d H:i', time() + 10 * DAY_IN_SECONDS ) ) );
		$this->assertSame( 'future', get_post_status( $this->post ) );
		$this->assertTrue( $this->save( 'post.status', $this->post, 'draft' ) );
		$this->assertTrue( $this->save( 'post.status', $this->post, 'future' ) );
		$this->assertSame( 'future', get_post_status( $this->post ) );
	}

	public function test_contributor_cannot_publish_but_can_set_draft_and_pending(): void {
		$mine = self::factory()->post->create( array( 'post_author' => $this->contributor, 'post_status' => 'draft' ) );
		wp_set_current_user( $this->contributor );

		$this->assertRejected( $this->save( 'post.status', $mine, 'publish' ) );
		$this->assertRejected( $this->save( 'post.status', $mine, 'private' ) );
		$this->assertSame( 'draft', get_post_status( $mine ) );

		$options = $this->type( 'post.status' )->edit_field( new Config( 'c', 'post.status', 'S' ), $this->posts )['options'];
		$this->assertArrayNotHasKey( 'publish', $options );
		$this->assertArrayHasKey( 'pending', $options );

		$this->assertTrue( $this->save( 'post.status', $mine, 'pending' ) );
		$this->assertSame( 'pending', get_post_status( $mine ) );
	}

	public function test_author_changes_need_edit_others_posts_and_a_valid_user(): void {
		$this->assertTrue( $this->save( 'post.author', $this->post, (string) $this->editor ) );
		$this->assertSame( $this->editor, $this->read( 'post.author', $this->post ) );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertRejected( $this->save( 'post.author', $this->post, $subscriber ) );
		$this->assertRejected( $this->save( 'post.author', $this->post, 999999 ) );
		$this->assertRejected( $this->save( 'post.author', $this->post, 'abc' ) );
		$this->assertRejected( $this->save( 'post.author', $this->post, null, 'clear' ) );
		$this->assertSame( $this->editor, (int) get_post( $this->post )->post_author );

		$options = $this->type( 'post.author' )->edit_field( new Config( 'c', 'post.author', 'A' ), $this->posts )['options'];
		$this->assertArrayHasKey( (string) $this->author, $options );
		$this->assertArrayNotHasKey( (string) $subscriber, $options );

		$mine = self::factory()->post->create( array( 'post_author' => $this->author ) );
		wp_set_current_user( $this->author );
		$this->assertRejected( $this->save( 'post.author', $mine, $this->admin ) );
		$this->assertSame( $this->author, (int) get_post( $mine )->post_author );
		$this->assertSame( array(), $this->type( 'post.author' )->edit_field( new Config( 'c', 'post.author', 'A' ), $this->posts )['options'] );
	}

	public function test_published_date_saves_local_and_gmt_and_rejects_bad_input(): void {
		update_option( 'timezone_string', 'Europe/Malta' );
		$this->assertTrue( $this->save( 'post.published_date', $this->post, '2024-07-01 15:30' ) );
		$post = get_post( $this->post );
		$this->assertSame( '2024-07-01 15:30:00', $post->post_date );
		$this->assertSame( '2024-07-01 13:30:00', $post->post_date_gmt );
		$this->assertSame( '2024-07-01 15:30', $this->read( 'post.published_date', $this->post ) );

		$this->assertTrue( $this->save( 'post.published_date', $this->post, '2024-08-02T09:05' ) );
		$this->assertSame( '2024-08-02 09:05', $this->read( 'post.published_date', $this->post ) );

		foreach ( array( 'yesterday', '2024-13-45 10:00', '2024-07-01', '2024-02-30 10:00', '' ) as $bad ) {
			$this->assertRejected( $this->save( 'post.published_date', $this->post, $bad ) );
		}
		$this->assertRejected( $this->save( 'post.published_date', $this->post, null, 'clear' ) );
		$this->assertSame( '2024-08-02 09:05', $this->read( 'post.published_date', $this->post ) );
		update_option( 'timezone_string', '' );
	}

	public function test_comment_and_ping_status_toggle(): void {
		foreach ( array( 'post.comment_status' => 'comment_status', 'post.ping_status' => 'ping_status' ) as $key => $field ) {
			$this->assertTrue( $this->save( $key, $this->post, false ) );
			$this->assertSame( 'closed', get_post( $this->post )->$field );
			$this->assertFalse( $this->read( $key, $this->post ) );
			$this->assertTrue( $this->save( $key, $this->post, true ) );
			$this->assertSame( 'open', get_post( $this->post )->$field );
			$this->assertTrue( $this->read( $key, $this->post ) );
			$this->assertTrue( $this->save( $key, $this->post, null, 'clear' ) );
			$this->assertSame( 'closed', get_post( $this->post )->$field );
			$this->assertRejected( $this->save( $key, $this->post, array( 1 ) ) );
		}
		$this->assertRejected( $this->save( 'post.comment_status', $this->post, true, 'append' ) );
	}

	public function test_sticky_toggles_for_posts_only_and_needs_edit_others_posts(): void {
		$this->assertTrue( $this->save( 'post.sticky', $this->post, true ) );
		$this->assertTrue( is_sticky( $this->post ) );
		$this->assertTrue( $this->read( 'post.sticky', $this->post ) );
		$this->assertTrue( $this->save( 'post.sticky', $this->post, null, 'clear' ) );
		$this->assertFalse( is_sticky( $this->post ) );
		$this->assertTrue( $this->save( 'post.sticky', $this->post, '1' ) );
		$this->assertTrue( $this->save( 'post.sticky', $this->post, '0' ) );
		$this->assertFalse( is_sticky( $this->post ) );

		$this->assertRejected( $this->save( 'post.sticky', $this->page, true, 'set', array(), $this->pages ) );

		$mine = self::factory()->post->create( array( 'post_author' => $this->author ) );
		wp_set_current_user( $this->author );
		$this->assertRejected( $this->save( 'post.sticky', $mine, true ) );
		$this->assertFalse( is_sticky( $mine ) );
		stick_post( $mine );
		$this->assertRejected( $this->save( 'post.sticky', $mine, null, 'clear' ) );
		$this->assertTrue( is_sticky( $mine ) );
	}

	public function test_parent_saves_and_blocks_self_descendants_and_other_types(): void {
		$child      = self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'Child', 'post_parent' => $this->page ) );
		$grandchild = self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'Grandchild', 'post_parent' => $child ) );
		$other      = self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'Other' ) );

		$this->assertTrue( $this->save( 'post.parent', $other, (string) $this->page, 'set', array(), $this->pages ) );
		$this->assertSame( $this->page, $this->read( 'post.parent', $other, array(), $this->pages ) );

		$this->assertRejected( $this->save( 'post.parent', $this->page, $this->page, 'set', array(), $this->pages ) );
		$this->assertRejected( $this->save( 'post.parent', $this->page, $grandchild, 'set', array(), $this->pages ) );
		$this->assertRejected( $this->save( 'post.parent', $this->page, $child, 'set', array(), $this->pages ) );
		$this->assertRejected( $this->save( 'post.parent', $other, $this->post, 'set', array(), $this->pages ) );
		$this->assertRejected( $this->save( 'post.parent', $other, 999999, 'set', array(), $this->pages ) );
		$this->assertRejected( $this->save( 'post.parent', $other, 'abc', 'set', array(), $this->pages ) );
		$this->assertSame( 0, (int) get_post( $this->page )->post_parent );
		$this->assertSame( $this->page, $this->read( 'post.parent', $other, array(), $this->pages ) );

		$this->assertTrue( $this->save( 'post.parent', $other, 0, 'set', array(), $this->pages ) );
		$this->assertSame( 0, $this->read( 'post.parent', $other, array(), $this->pages ) );
		$this->assertTrue( $this->save( 'post.parent', $child, null, 'clear', array(), $this->pages ) );
		$this->assertSame( 0, (int) get_post( $child )->post_parent );

		$options = $this->type( 'post.parent' )->edit_field( new Config( 'c', 'post.parent', 'P' ), $this->pages )['options'];
		$this->assertArrayHasKey( '0', $options );
		$this->assertArrayHasKey( (string) $this->page, $options );
		$this->assertRejected( $this->save( 'post.parent', $this->post, $this->page ) );
	}

	public function test_menu_order_saves_and_rejects_non_integers(): void {
		$this->assertTrue( $this->save( 'post.menu_order', $this->page, '7', 'set', array(), $this->pages ) );
		$this->assertSame( 7, $this->read( 'post.menu_order', $this->page, array(), $this->pages ) );
		$this->assertTrue( $this->save( 'post.menu_order', $this->page, -3, 'set', array(), $this->pages ) );
		$this->assertSame( -3, (int) get_post( $this->page )->menu_order );
		foreach ( array( 'abc', '1.5', '', '1e3' ) as $bad ) {
			$this->assertRejected( $this->save( 'post.menu_order', $this->page, $bad, 'set', array(), $this->pages ) );
		}
		$this->assertTrue( $this->save( 'post.menu_order', $this->page, null, 'clear', array(), $this->pages ) );
		$this->assertSame( 0, (int) get_post( $this->page )->menu_order );
		$this->assertRejected( $this->save( 'post.menu_order', $this->post, 3 ) );
	}

	public function test_template_saves_from_the_theme_list_and_rejects_unknown(): void {
		add_filter(
			'theme_templates',
			static function ( $templates ) {
				$templates['wide.php'] = 'Wide';
				return $templates;
			}
		);
		$options = $this->type( 'post.template' )->edit_field( new Config( 'c', 'post.template', 'T' ), $this->pages )['options'];
		$this->assertSame( 'Wide', $options['wide.php'] );
		$this->assertArrayHasKey( 'default', $options );

		$this->assertTrue( $this->save( 'post.template', $this->page, 'wide.php', 'set', array(), $this->pages ) );
		$this->assertSame( 'wide.php', $this->read( 'post.template', $this->page, array(), $this->pages ) );
		$this->assertRejected( $this->save( 'post.template', $this->page, 'evil.php', 'set', array(), $this->pages ) );
		$this->assertSame( 'wide.php', get_page_template_slug( $this->page ) );

		$this->assertTrue( $this->save( 'post.template', $this->page, null, 'clear', array(), $this->pages ) );
		$this->assertSame( 'default', $this->read( 'post.template', $this->page, array(), $this->pages ) );
		$this->assertTrue( $this->save( 'post.template', $this->page, 'wide.php', 'set', array(), $this->pages ) );
		$this->assertTrue( $this->save( 'post.template', $this->page, 'default', 'set', array(), $this->pages ) );
		$this->assertSame( 'default', $this->read( 'post.template', $this->page, array(), $this->pages ) );
	}

	public function test_format_saves_supported_formats_only(): void {
		$this->assertSame( array( 'standard' ), array_keys( $this->type( 'post.format' )->edit_field( new Config( 'c', 'post.format', 'F' ), $this->posts )['options'] ) );
		add_theme_support( 'post-formats', array( 'aside', 'gallery' ) );
		$options = $this->type( 'post.format' )->edit_field( new Config( 'c', 'post.format', 'F' ), $this->posts )['options'];
		$this->assertSame( array( 'standard', 'aside', 'gallery' ), array_keys( $options ) );

		$this->assertTrue( $this->save( 'post.format', $this->post, 'aside' ) );
		$this->assertSame( 'aside', $this->read( 'post.format', $this->post ) );
		$this->assertRejected( $this->save( 'post.format', $this->post, 'video' ) );
		$this->assertRejected( $this->save( 'post.format', $this->post, 'nonsense' ) );
		$this->assertSame( 'aside', $this->read( 'post.format', $this->post ) );
		$this->assertTrue( $this->save( 'post.format', $this->post, null, 'clear' ) );
		$this->assertSame( 'standard', $this->read( 'post.format', $this->post ) );
		$this->assertRejected( $this->save( 'post.format', $this->page, 'aside', 'set', array(), $this->pages ) );
	}

	public function test_taxonomy_set_append_remove_and_clear(): void {
		$a        = self::factory()->term->create( array( 'taxonomy' => 'post_tag', 'name' => 'Alpha' ) );
		$b        = self::factory()->term->create( array( 'taxonomy' => 'post_tag', 'name' => 'Beta' ) );
		$c        = self::factory()->term->create( array( 'taxonomy' => 'post_tag', 'name' => 'Gamma' ) );
		$settings = array( 'taxonomy' => 'post_tag' );

		$this->assertTrue( $this->save( 'post.taxonomy', $this->post, array( $a, $b ), 'set', $settings ) );
		$this->assertEqualsCanonicalizing( array( $a, $b ), $this->read( 'post.taxonomy', $this->post, $settings ) );

		$this->assertTrue( $this->save( 'post.taxonomy', $this->post, array( (string) $c ), 'append', $settings ) );
		$this->assertEqualsCanonicalizing( array( $a, $b, $c ), $this->read( 'post.taxonomy', $this->post, $settings ) );

		$this->assertTrue( $this->save( 'post.taxonomy', $this->post, array( $a, $c ), 'remove', $settings ) );
		$this->assertSame( array( $b ), $this->read( 'post.taxonomy', $this->post, $settings ) );

		$this->assertTrue( $this->save( 'post.taxonomy', $this->post, array( $c ), 'set', $settings ) );
		$this->assertSame( array( $c ), $this->read( 'post.taxonomy', $this->post, $settings ) );

		$this->assertTrue( $this->save( 'post.taxonomy', $this->post, null, 'clear', $settings ) );
		$this->assertSame( array(), $this->read( 'post.taxonomy', $this->post, $settings ) );
		$this->assertSame( array(), wp_get_object_terms( $this->post, 'post_tag', array( 'fields' => 'ids' ) ) );

		$options = $this->type( 'post.taxonomy' )->edit_field( new Config( 'c', 'post.taxonomy', 'T', null, 'px', $settings ), $this->posts )['options'];
		$this->assertSame( 'Beta', $options[ (string) $b ] );
	}

	public function test_taxonomy_rejects_unknown_terms_other_taxonomies_and_unavailable_taxonomies(): void {
		$tag      = self::factory()->term->create( array( 'taxonomy' => 'post_tag', 'name' => 'Alpha' ) );
		$cat      = self::factory()->term->create( array( 'taxonomy' => 'category', 'name' => 'Cat' ) );
		$settings = array( 'taxonomy' => 'post_tag' );
		$this->assertTrue( $this->save( 'post.taxonomy', $this->post, array( $tag ), 'set', $settings ) );

		$this->assertRejected( $this->save( 'post.taxonomy', $this->post, array( 999999 ), 'set', $settings ) );
		$this->assertRejected( $this->save( 'post.taxonomy', $this->post, array( $cat ), 'append', $settings ) );
		$this->assertRejected( $this->save( 'post.taxonomy', $this->post, array( array( 1 ) ), 'set', $settings ) );
		$this->assertRejected( $this->save( 'post.taxonomy', $this->post, array( $tag ), 'set', array( 'taxonomy' => 'nav_menu' ) ) );
		$this->assertRejected( $this->save( 'post.taxonomy', $this->post, array( $tag ), 'set', array( 'taxonomy' => 'post_format' ) ) );
		$this->assertRejected( $this->save( 'post.taxonomy', $this->post, array( $tag ), 'set', array() ) );
		$this->assertSame( array( $tag ), $this->read( 'post.taxonomy', $this->post, $settings ) );
	}

	public function test_taxonomy_term_creation_needs_the_setting_and_both_caps(): void {
		$settings = array( 'taxonomy' => 'post_tag' );

		$this->assertRejected( $this->save( 'post.taxonomy', $this->post, array( 'Brand New' ), 'set', $settings ) );
		$this->assertNull( term_exists( 'Brand New', 'post_tag' ) );

		$on = array( 'taxonomy' => 'post_tag', 'allow_create' => true );
		$this->assertTrue( $this->save( 'post.taxonomy', $this->post, array( 'Brand New' ), 'set', $on ) );
		$ids = $this->read( 'post.taxonomy', $this->post, $settings );
		$this->assertCount( 1, $ids );
		$this->assertSame( 'Brand New', get_term( $ids[0] )->name );

		$this->assertTrue( $this->save( 'post.taxonomy', $this->post, array( 'Brand New' ), 'append', $on ) );
		$this->assertCount( 1, $this->read( 'post.taxonomy', $this->post, $settings ), 'an existing name is reused, not duplicated' );
		$this->assertRejected( $this->save( 'post.taxonomy', $this->post, array( 'Nope' ), 'remove', $on ) );

		$mine = self::factory()->post->create( array( 'post_author' => $this->author ) );
		wp_set_current_user( $this->author );
		$this->assertTrue( current_user_can( get_taxonomy( 'post_tag' )->cap->assign_terms ) );
		$this->assertFalse( current_user_can( get_taxonomy( 'post_tag' )->cap->edit_terms ) );
		$this->assertRejected( $this->save( 'post.taxonomy', $mine, array( 'Author Made' ), 'set', $on ) );
		$this->assertNull( term_exists( 'Author Made', 'post_tag' ) );
		$this->assertTrue( $this->save( 'post.taxonomy', $mine, array( $ids[0] ), 'set', $on ) );
	}

	public function test_featured_image_saves_images_only_and_clears(): void {
		$image = self::factory()->attachment->create_object( 'cln-a.png', 0, array( 'post_mime_type' => 'image/png', 'post_type' => 'attachment' ) );
		$pdf   = self::factory()->attachment->create_object( 'cln-a.pdf', 0, array( 'post_mime_type' => 'application/pdf', 'post_type' => 'attachment' ) );

		$this->assertTrue( $this->save( 'post.featured_image', $this->post, (string) $image ) );
		$this->assertSame( $image, $this->read( 'post.featured_image', $this->post ) );
		$this->assertTrue( $this->save( 'post.featured_image', $this->post, $image ), 'saving the same image again is not an error' );

		$this->assertRejected( $this->save( 'post.featured_image', $this->post, $pdf ) );
		$this->assertRejected( $this->save( 'post.featured_image', $this->post, $this->page ) );
		$this->assertRejected( $this->save( 'post.featured_image', $this->post, 999999 ) );
		$this->assertRejected( $this->save( 'post.featured_image', $this->post, 'abc' ) );
		$this->assertSame( $image, (int) get_post_thumbnail_id( $this->post ) );

		$this->assertTrue( $this->save( 'post.featured_image', $this->post, null, 'clear' ) );
		$this->assertSame( 0, (int) get_post_thumbnail_id( $this->post ) );
		$this->assertTrue( $this->save( 'post.featured_image', $this->post, null, 'clear' ), 'clearing an empty image is not an error' );
	}

	public function test_a_user_who_cannot_edit_the_post_is_refused_by_every_type(): void {
		$theirs = self::factory()->post->create( array( 'post_author' => $this->editor, 'post_status' => 'publish' ) );
		wp_set_current_user( $this->author );
		$cases = array(
			'post.slug'           => 'x',
			'post.excerpt'        => 'x',
			'post.status'         => 'draft',
			'post.published_date' => '2024-01-01 10:00',
			'post.comment_status' => false,
			'post.menu_order'     => 1,
			'post.sticky'         => true,
		);
		foreach ( $cases as $key => $value ) {
			$this->assertRejected( $this->save( $key, $theirs, $value ), $key );
		}
		$this->assertSame( 'publish', get_post_status( $theirs ) );
	}

	public function test_writes_go_through_core_hooks_once_per_save(): void {
		$cases = array(
			'post.slug'           => array( 'hooked-slug', 'set' ),
			'post.excerpt'        => array( 'Hooked excerpt', 'set' ),
			'post.status'         => array( 'pending', 'set' ),
			'post.author'         => array( $this->editor, 'set' ),
			'post.published_date' => array( '2024-03-04 05:06', 'set' ),
			'post.comment_status' => array( false, 'set' ),
			'post.ping_status'    => array( false, 'set' ),
			'post.template'       => array( 'default', 'set' ),
		);
		foreach ( $cases as $key => $case ) {
			$this->saved = array();
			$this->assertTrue( $this->save( $key, $this->post, $case[0], $case[1] ), $key );
			$this->assertSame( 1, $this->saved[ $this->post ] ?? 0, "save_post fired once for {$key}" );
		}

		$this->saved = array();
		$this->assertTrue( $this->save( 'post.menu_order', $this->page, 4, 'set', array(), $this->pages ) );
		$this->assertSame( 1, $this->saved[ $this->page ] ?? 0 );
		$this->saved = array();
		$parent      = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->assertTrue( $this->save( 'post.parent', $this->page, $parent, 'set', array(), $this->pages ) );
		$this->assertSame( 1, $this->saved[ $this->page ] ?? 0 );
	}

	public function test_updates_fire_core_post_updated_and_create_a_revision(): void {
		$updated = 0;
		add_action(
			'post_updated',
			static function () use ( &$updated ) {
				++$updated;
			}
		);
		$revisions = count( wp_get_post_revisions( $this->post ) );
		$this->assertTrue( $this->save( 'post.excerpt', $this->post, 'First excerpt' ) );
		$this->assertTrue( $this->save( 'post.excerpt', $this->post, 'Second excerpt' ) );
		$this->assertSame( 2, $updated );
		$this->assertGreaterThan( $revisions, count( wp_get_post_revisions( $this->post ) ) );
	}

	public function test_taxonomy_and_thumbnail_writes_fire_their_core_hooks(): void {
		$set   = 0;
		$meta  = 0;
		$tag   = self::factory()->term->create( array( 'taxonomy' => 'post_tag' ) );
		$image = self::factory()->attachment->create_object( 'cln-h.png', 0, array( 'post_mime_type' => 'image/png', 'post_type' => 'attachment' ) );
		add_action(
			'set_object_terms',
			static function () use ( &$set ) {
				++$set;
			}
		);
		add_action(
			'added_post_meta',
			static function ( $id, $post_id, $key ) use ( &$meta ) {
				if ( '_thumbnail_id' === $key ) {
					++$meta;
				}
			},
			10,
			3
		);
		$this->assertTrue( $this->save( 'post.taxonomy', $this->post, array( $tag ), 'set', array( 'taxonomy' => 'post_tag' ) ) );
		$this->assertTrue( $this->save( 'post.featured_image', $this->post, $image ) );
		$this->assertSame( 1, $set );
		$this->assertSame( 1, $meta );
	}
}
