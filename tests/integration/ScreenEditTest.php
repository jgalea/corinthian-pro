<?php

namespace CorinthianPro\Tests;

use Corinthian\Columns\Config;
use Corinthian\Columns\Editable;
use Corinthian\Columns\Registry;
use Corinthian\Columns\Types\ScreenTypeLoader;
use Corinthian\Columns\Types\TypeLoader;
use Corinthian\Screens\CommentScreen;
use Corinthian\Screens\MediaScreen;
use Corinthian\Screens\Screen;
use Corinthian\Screens\TermScreen;
use Corinthian\Screens\UserScreen;
use Corinthian\Sets\SetRepository;
use WP_Error;
use WP_UnitTestCase;

class ScreenEditTest extends WP_UnitTestCase {

	private Registry $registry;
	private UserScreen $users;
	private MediaScreen $media;
	private CommentScreen $comments;
	private TermScreen $categories;
	private TermScreen $tags;
	private int $admin;
	private int $editor;
	private int $author;
	private int $contributor;

	public function set_up(): void {
		parent::set_up();
		$this->admin       = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_email' => 'sc-admin@example.org',
			)
		);
		$this->editor      = self::factory()->user->create(
			array(
				'role'       => 'editor',
				'user_email' => 'sc-editor@example.org',
			)
		);
		$this->author      = self::factory()->user->create(
			array(
				'role'       => 'author',
				'user_email' => 'sc-author@example.org',
			)
		);
		$this->contributor = self::factory()->user->create(
			array(
				'role'       => 'contributor',
				'user_email' => 'sc-contrib@example.org',
			)
		);
		wp_set_current_user( $this->admin );

		$this->registry = new Registry();
		TypeLoader::register( $this->registry );
		ScreenTypeLoader::register( $this->registry );
		$sets             = new SetRepository();
		$this->users      = new UserScreen( $this->registry, $sets );
		$this->media      = new MediaScreen( $this->registry, $sets, get_post_type_object( 'attachment' ) );
		$this->comments   = new CommentScreen( $this->registry, $sets );
		$this->categories = new TermScreen( $this->registry, $sets, get_taxonomy( 'category' ) );
		$this->tags       = new TermScreen( $this->registry, $sets, get_taxonomy( 'post_tag' ) );
	}

	private function type( string $key ): Editable {
		$type = $this->registry->get( $key );
		$this->assertInstanceOf( Editable::class, $type, $key );
		return $type;
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private function save( Screen $screen, string $key, int $id, mixed $value, string $mode = 'set', array $settings = array() ): bool|WP_Error {
		return $this->type( $key )->save( $id, new Config( 'c', $key, 'Column', null, 'px', $settings ), $screen, $value, $mode );
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private function read( Screen $screen, string $key, int $id, array $settings = array() ): mixed {
		return $this->type( $key )->edit_value( $id, new Config( 'c', $key, 'Column', null, 'px', $settings ), $screen );
	}

	/**
	 * @param array<string,mixed> $settings
	 * @return array<string,mixed>
	 */
	private function field( Screen $screen, string $key, array $settings = array() ): array {
		return $this->type( $key )->edit_field( new Config( 'c', $key, 'Column', null, 'px', $settings ), $screen );
	}

	private function assertRejected( bool|WP_Error $result ): void {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( '', $result->get_error_message() );
	}

	private function image( int $author = 0 ): int {
		return self::factory()->attachment->create(
			array(
				'post_mime_type' => 'image/jpeg',
				'file'           => 'photo.jpg',
				'post_title'     => 'Photo',
				'post_author'    => $author ?: $this->admin,
			)
		);
	}

	public function test_editor_shapes_and_bulk_modes(): void {
		$expected = array(
			array( $this->users, 'user.display_name', 'text', array( 'set' ) ),
			array( $this->users, 'user.first_name', 'text', array( 'set', 'clear' ) ),
			array( $this->users, 'user.last_name', 'text', array( 'set', 'clear' ) ),
			array( $this->users, 'user.nickname', 'text', array( 'set' ) ),
			array( $this->users, 'user.website', 'text', array( 'set', 'clear' ) ),
			array( $this->users, 'user.description', 'textarea', array( 'set', 'clear' ) ),
			array( $this->users, 'user.email', 'text', null ),
			array( $this->users, 'user.roles', 'select', array( 'set' ) ),
			array( $this->users, 'user.locale', 'select', array( 'set', 'clear' ) ),
			array( $this->users, 'user.rich_editing', 'toggle', array( 'set' ) ),
			array( $this->users, 'user.admin_color', 'select', array( 'set' ) ),
			array( $this->users, 'user.meta', 'text', array( 'set', 'clear' ) ),
			array( $this->media, 'media.alt', 'text', array( 'set', 'clear' ) ),
			array( $this->media, 'media.caption', 'textarea', array( 'set', 'clear' ) ),
			array( $this->media, 'media.description', 'textarea', array( 'set', 'clear' ) ),
			array( $this->media, 'media.attached_to', 'number', array( 'set', 'clear' ) ),
			array( $this->comments, 'comment.author', 'text', array( 'set' ) ),
			array( $this->comments, 'comment.author_email', 'text', null ),
			array( $this->comments, 'comment.author_url', 'text', array( 'set', 'clear' ) ),
			array( $this->comments, 'comment.excerpt', 'textarea', array( 'set' ) ),
			array( $this->comments, 'comment.status', 'select', array( 'set' ) ),
			array( $this->comments, 'comment.meta', 'text', array( 'set', 'clear' ) ),
			array( $this->categories, 'term.name', 'text', array( 'set' ) ),
			array( $this->categories, 'term.slug', 'text', null ),
			array( $this->categories, 'term.description', 'textarea', array( 'set', 'clear' ) ),
			array( $this->categories, 'term.parent', 'select', array( 'set', 'clear' ) ),
			array( $this->categories, 'term.meta', 'text', array( 'set', 'clear' ) ),
		);
		foreach ( $expected as [ $screen, $key, $input, $bulk ] ) {
			$field = $this->field( $screen, $key );
			$this->assertSame( $input, $field['input'], $key );
			if ( null === $bulk ) {
				$this->assertArrayNotHasKey( 'bulk', $field, $key );
			} else {
				$this->assertSame( $bulk, $field['bulk'], $key );
			}
		}
		foreach ( array( 'user.id', 'user.username', 'media.file_name', 'media.mime_type', 'media.uploaded_by', 'media.featured_count', 'comment.id', 'comment.author_ip', 'comment.type', 'comment.user_agent', 'comment.parent', 'term.id', 'term.post_count', 'term.link' ) as $key ) {
			$this->assertNotInstanceOf( Editable::class, $this->registry->get( $key ), $key );
		}
		$this->assertArrayHasKey( 'editor', $this->field( $this->users, 'user.roles' )['options'] );
		$this->assertArrayHasKey( 'hold', $this->field( $this->comments, 'comment.status' )['options'] );
	}

	// Users.

	public function test_user_text_fields_round_trip_without_wiping_other_profile_data(): void {
		$this->assertTrue( $this->save( $this->users, 'user.description', $this->author, "Writes about O'Brien" ) );
		foreach ( array(
			'user.display_name' => "Ann O'Neil",
			'user.first_name'   => 'Ann',
			'user.last_name'    => 'Neil',
			'user.nickname'     => 'annie',
		) as $key => $value ) {
			$this->assertTrue( $this->save( $this->users, $key, $this->author, $value ), $key );
			$this->assertSame( $value, $this->read( $this->users, $key, $this->author ), $key );
		}
		$this->assertSame( "Writes about O'Brien", $this->read( $this->users, 'user.description', $this->author ) );
		$this->assertSame( "Ann O'Neil", get_userdata( $this->author )->display_name );

		$this->assertTrue( $this->save( $this->users, 'user.first_name', $this->author, null, 'clear' ) );
		$this->assertSame( '', $this->read( $this->users, 'user.first_name', $this->author ) );
		$this->assertSame( 'Neil', $this->read( $this->users, 'user.last_name', $this->author ) );
	}

	public function test_user_text_fields_reject_bad_values(): void {
		$this->assertRejected( $this->save( $this->users, 'user.display_name', $this->author, '   ' ) );
		$this->assertRejected( $this->save( $this->users, 'user.display_name', $this->author, array( 'x' ) ) );
		$this->assertRejected( $this->save( $this->users, 'user.display_name', $this->author, 'x', 'clear' ) );
		$this->assertRejected( $this->save( $this->users, 'user.nickname', $this->author, str_repeat( 'a', 300 ) ) );
	}

	public function test_user_website_is_validated(): void {
		$this->assertTrue( $this->save( $this->users, 'user.website', $this->author, 'https://example.com/me' ) );
		$this->assertSame( 'https://example.com/me', $this->read( $this->users, 'user.website', $this->author ) );
		$this->assertRejected( $this->save( $this->users, 'user.website', $this->author, 'javascript:alert(1)' ) );
		$this->assertRejected( $this->save( $this->users, 'user.website', $this->author, 'not a url' ) );
		$this->assertSame( 'https://example.com/me', $this->read( $this->users, 'user.website', $this->author ) );
		$this->assertTrue( $this->save( $this->users, 'user.website', $this->author, null, 'clear' ) );
		$this->assertSame( '', $this->read( $this->users, 'user.website', $this->author ) );
	}

	public function test_user_email_round_trips_stays_unique_and_is_capability_gated(): void {
		$this->assertTrue( $this->save( $this->users, 'user.email', $this->author, 'new-author@example.org' ) );
		$this->assertSame( 'new-author@example.org', $this->read( $this->users, 'user.email', $this->author ) );
		$this->assertTrue( $this->save( $this->users, 'user.email', $this->author, 'new-author@example.org' ) );

		$this->assertRejected( $this->save( $this->users, 'user.email', $this->author, 'not-an-email' ) );
		$this->assertRejected( $this->save( $this->users, 'user.email', $this->author, 'sc-editor@example.org' ) );
		$this->assertRejected( $this->save( $this->users, 'user.email', $this->author, 'x@example.org', 'clear' ) );
		$this->assertSame( 'new-author@example.org', get_userdata( $this->author )->user_email );

		wp_set_current_user( $this->editor );
		$this->assertRejected( $this->save( $this->users, 'user.email', $this->author, 'hijack@example.org' ) );
		$this->assertSame( 'new-author@example.org', get_userdata( $this->author )->user_email );
		$this->assertTrue( $this->save( $this->users, 'user.email', $this->editor, 'ed2@example.org' ) );
		$this->assertSame( 'ed2@example.org', get_userdata( $this->editor )->user_email );
	}

	public function test_user_role_saves_and_obeys_the_role_rules(): void {
		$this->assertSame( 'author', $this->read( $this->users, 'user.roles', $this->author ) );
		$this->assertTrue( $this->save( $this->users, 'user.roles', $this->author, 'editor' ) );
		$this->assertSame( array( 'editor' ), get_userdata( $this->author )->roles );

		$this->assertRejected( $this->save( $this->users, 'user.roles', $this->author, 'no_such_role' ) );
		$this->assertRejected( $this->save( $this->users, 'user.roles', $this->author, 'editor', 'clear' ) );
		$this->assertRejected( $this->save( $this->users, 'user.roles', $this->admin, 'editor' ) );
		$this->assertSame( array( 'administrator' ), get_userdata( $this->admin )->roles );

		wp_set_current_user( $this->editor );
		$this->assertRejected( $this->save( $this->users, 'user.roles', $this->contributor, 'author' ) );
		$this->assertSame( array( 'contributor' ), get_userdata( $this->contributor )->roles );
	}

	public function test_the_last_administrator_cannot_be_demoted_but_one_of_two_can(): void {
		$manager = get_userdata( $this->editor );
		$manager->add_cap( 'edit_users' );
		$manager->add_cap( 'promote_users' );
		foreach ( get_users(
			array(
				'role'    => 'administrator',
				'exclude' => array( $this->admin ),
			)
		) as $other ) {
			$other->set_role( 'subscriber' );
		}
		wp_set_current_user( $this->editor );

		$this->assertCount(
			1,
			get_users(
				array(
					'role'   => 'administrator',
					'fields' => 'ID',
				)
			)
		);
		$result = $this->save( $this->users, 'user.roles', $this->admin, 'author' );
		$this->assertRejected( $result );
		$this->assertSame( 'corinthian_last_admin', $result->get_error_code() );
		$this->assertSame( array( 'administrator' ), get_userdata( $this->admin )->roles );

		$second = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		$this->assertTrue( $this->save( $this->users, 'user.roles', $second, 'author' ) );
		$this->assertSame( array( 'author' ), get_userdata( $second )->roles );
	}

	public function test_user_preferences_round_trip_and_reject_unknown_values(): void {
		$this->assertTrue( $this->save( $this->users, 'user.rich_editing', $this->author, false ) );
		$this->assertFalse( $this->read( $this->users, 'user.rich_editing', $this->author ) );
		$this->assertTrue( $this->save( $this->users, 'user.rich_editing', $this->author, 'true' ) );
		$this->assertTrue( $this->read( $this->users, 'user.rich_editing', $this->author ) );
		$this->assertRejected( $this->save( $this->users, 'user.rich_editing', $this->author, 'maybe' ) );

		$this->assertTrue( $this->save( $this->users, 'user.admin_color', $this->author, 'midnight' ) );
		$this->assertSame( 'midnight', $this->read( $this->users, 'user.admin_color', $this->author ) );
		$this->assertRejected( $this->save( $this->users, 'user.admin_color', $this->author, 'neon' ) );

		$this->assertTrue( $this->save( $this->users, 'user.locale', $this->author, 'en_US' ) );
		$this->assertSame( 'en_US', $this->read( $this->users, 'user.locale', $this->author ) );
		$this->assertTrue( $this->save( $this->users, 'user.locale', $this->author, null, 'clear' ) );
		$this->assertSame( '', $this->read( $this->users, 'user.locale', $this->author ) );
		$this->assertRejected( $this->save( $this->users, 'user.locale', $this->author, 'xx_NOPE' ) );
	}

	public function test_user_edits_are_refused_without_the_capability(): void {
		wp_set_current_user( $this->editor );
		foreach ( array( 'user.display_name', 'user.nickname', 'user.description', 'user.rich_editing' ) as $key ) {
			$this->assertRejected( $this->save( $this->users, $key, $this->author, 'true' ), $key );
		}
		wp_set_current_user( $this->contributor );
		$this->assertRejected( $this->save( $this->users, 'user.first_name', $this->author, 'Nope' ) );
		$this->assertTrue( $this->save( $this->users, 'user.first_name', $this->contributor, 'Me' ) );
	}

	public function test_user_meta_follows_the_meta_capabilities(): void {
		global $wpdb;
		$settings = array( 'meta_key' => 'favourite_colour' );
		$this->assertTrue( $this->save( $this->users, 'user.meta', $this->author, 'teal', 'set', $settings ) );
		$this->assertSame( 'teal', $this->read( $this->users, 'user.meta', $this->author, $settings ) );
		$this->assertTrue( $this->save( $this->users, 'user.meta', $this->author, 'plum', 'set', $settings ) );
		$this->assertSame( 'plum', get_user_meta( $this->author, 'favourite_colour', true ) );
		$this->assertTrue( $this->save( $this->users, 'user.meta', $this->author, null, 'clear', $settings ) );
		$this->assertSame( array(), get_user_meta( $this->author, 'favourite_colour', false ) );

		$number = array(
			'meta_key' => 'score',
			'display'  => 'number',
		);
		$this->assertTrue( $this->save( $this->users, 'user.meta', $this->author, '12.5', 'set', $number ) );
		$this->assertRejected( $this->save( $this->users, 'user.meta', $this->author, 'abc', 'set', $number ) );
		$this->assertSame( '12.5', get_user_meta( $this->author, 'score', true ) );

		$this->assertRejected( $this->save( $this->users, 'user.meta', $this->author, 'x', 'set', array() ) );
		$this->assertRejected( $this->save( $this->users, 'user.meta', $this->author, 'x', 'set', array( 'meta_key' => '_private' ) ) );
		$this->assertRejected( $this->save( $this->users, 'user.meta', $this->author, array( 'x' ), 'set', $settings ) );
		foreach ( array( $wpdb->prefix . 'capabilities', $wpdb->prefix . 'user_level', $wpdb->base_prefix . 'capabilities' ) as $key ) {
			$before = get_user_meta( $this->author, $key, true );
			$this->assertRejected( $this->save( $this->users, 'user.meta', $this->author, 'a:1:{s:13:"administrator";b:1;}', 'set', array( 'meta_key' => $key ) ), $key );
			$this->assertRejected( $this->save( $this->users, 'user.meta', $this->author, null, 'clear', array( 'meta_key' => $key ) ), $key );
			$this->assertSame( $before, get_user_meta( $this->author, $key, true ), $key );
		}
		$this->assertSame( array( 'author' ), get_userdata( $this->author )->roles );

		update_user_meta( $this->author, 'multi', 'a' );
		add_user_meta( $this->author, 'multi', 'b' );
		$this->assertRejected( $this->save( $this->users, 'user.meta', $this->author, 'c', 'set', array( 'meta_key' => 'multi' ) ) );

		add_filter(
			'auth_user_meta_locked_key',
			static fn() => false
		);
		$this->assertRejected( $this->save( $this->users, 'user.meta', $this->author, 'x', 'set', array( 'meta_key' => 'locked_key' ) ) );

		wp_set_current_user( $this->editor );
		$this->assertRejected( $this->save( $this->users, 'user.meta', $this->author, 'nope', 'set', $settings ) );
		$this->assertSame( array(), get_user_meta( $this->author, 'favourite_colour', false ) );
	}

	// Media.

	public function test_media_alt_caption_and_description_round_trip(): void {
		$id = $this->image();
		$this->assertTrue( $this->save( $this->media, 'media.alt', $id, "A cat's <b>nap</b>" ) );
		$this->assertSame( "A cat's nap", $this->read( $this->media, 'media.alt', $id ) );
		$this->assertTrue( $this->save( $this->media, 'media.alt', $id, null, 'clear' ) );
		$this->assertSame( '', get_post_meta( $id, '_wp_attachment_image_alt', true ) );
		$this->assertRejected( $this->save( $this->media, 'media.alt', $id, array( 'x' ) ) );
		$this->assertRejected( $this->save( $this->media, 'media.alt', $id, 'x', 'append' ) );

		$this->assertTrue( $this->save( $this->media, 'media.caption', $id, "Sunset at O'Hare" ) );
		$this->assertSame( "Sunset at O'Hare", $this->read( $this->media, 'media.caption', $id ) );
		$this->assertTrue( $this->save( $this->media, 'media.description', $id, '<p>Long text</p>' ) );
		$this->assertSame( '<p>Long text</p>', $this->read( $this->media, 'media.description', $id ) );
		$this->assertTrue( $this->save( $this->media, 'media.caption', $id, null, 'clear' ) );
		$this->assertSame( '', get_post( $id )->post_excerpt );
		$this->assertSame( 'Photo', get_post( $id )->post_title );
	}

	public function test_media_alt_is_for_images_only_and_other_posts_are_refused(): void {
		$pdf = self::factory()->attachment->create( array( 'post_mime_type' => 'application/pdf' ) );
		$this->assertRejected( $this->save( $this->media, 'media.alt', $pdf, 'Nope' ) );
		$this->assertTrue( $this->save( $this->media, 'media.caption', $pdf, 'Fine for a PDF' ) );

		$post = self::factory()->post->create();
		$this->assertRejected( $this->save( $this->media, 'media.caption', $post, 'Not media' ) );
		$this->assertRejected( $this->save( $this->media, 'media.caption', 999999, 'Missing' ) );
	}

	public function test_media_attached_to_validates_the_parent(): void {
		$id   = $this->image();
		$post = self::factory()->post->create( array( 'post_author' => $this->admin ) );
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->assertTrue( $this->save( $this->media, 'media.attached_to', $id, $post ) );
		$this->assertSame( $post, $this->read( $this->media, 'media.attached_to', $id ) );
		$this->assertTrue( $this->save( $this->media, 'media.attached_to', $id, (string) $page ) );
		$this->assertSame( $page, (int) get_post( $id )->post_parent );

		$this->assertRejected( $this->save( $this->media, 'media.attached_to', $id, 999999 ) );
		$this->assertRejected( $this->save( $this->media, 'media.attached_to', $id, $id ) );
		$this->assertRejected( $this->save( $this->media, 'media.attached_to', $id, '-3' ) );
		$this->assertRejected( $this->save( $this->media, 'media.attached_to', $id, 'abc' ) );
		$this->assertRejected( $this->save( $this->media, 'media.attached_to', $id, $this->image() ) );
		$this->assertSame( $page, (int) get_post( $id )->post_parent );

		$this->assertTrue( $this->save( $this->media, 'media.attached_to', $id, 0 ) );
		$this->assertSame( 0, (int) get_post( $id )->post_parent );
		$this->assertTrue( $this->save( $this->media, 'media.attached_to', $id, $post ) );
		$this->assertTrue( $this->save( $this->media, 'media.attached_to', $id, null, 'clear' ) );
		$this->assertSame( 0, (int) get_post( $id )->post_parent );
	}

	public function test_media_edits_respect_post_capabilities(): void {
		$mine    = $this->image( $this->author );
		$others  = $this->image( $this->admin );
		$private = self::factory()->post->create( array( 'post_author' => $this->admin ) );

		wp_set_current_user( $this->author );
		$this->assertTrue( $this->save( $this->media, 'media.caption', $mine, 'My own' ) );
		$this->assertRejected( $this->save( $this->media, 'media.caption', $others, 'Not mine' ) );
		$this->assertRejected( $this->save( $this->media, 'media.attached_to', $mine, $private ) );
		$this->assertSame( 0, (int) get_post( $mine )->post_parent );
		$this->assertTrue( $this->save( $this->media, 'media.description', $mine, 'Hi <script>alert(1)</script>there' ) );
		$this->assertStringNotContainsString( '<script', get_post( $mine )->post_content );

		wp_set_current_user( $this->contributor );
		$this->assertRejected( $this->save( $this->media, 'media.caption', $mine, 'Nope' ) );
		$this->assertSame( 'My own', get_post( $mine )->post_excerpt );
	}

	// Comments.

	private function comment( string $status = '1', int $post_author = 0 ): int {
		$post = self::factory()->post->create(
			array(
				'post_author' => $post_author ?: $this->admin,
				'post_status' => 'publish',
			)
		);
		return self::factory()->comment->create(
			array(
				'comment_post_ID'      => $post,
				'comment_approved'     => $status,
				'comment_author'       => 'Visitor',
				'comment_author_email' => 'visitor@example.org',
				'comment_content'      => 'Original text',
			)
		);
	}

	public function test_comment_fields_round_trip(): void {
		$id = $this->comment();
		$this->assertTrue( $this->save( $this->comments, 'comment.author', $id, "Pat O'Day" ) );
		$this->assertSame( "Pat O'Day", $this->read( $this->comments, 'comment.author', $id ) );
		$this->assertTrue( $this->save( $this->comments, 'comment.author_email', $id, 'pat@example.org' ) );
		$this->assertSame( 'pat@example.org', get_comment( $id )->comment_author_email );
		$this->assertTrue( $this->save( $this->comments, 'comment.author_url', $id, 'https://example.com/pat' ) );
		$this->assertSame( 'https://example.com/pat', $this->read( $this->comments, 'comment.author_url', $id ) );
		$this->assertTrue( $this->save( $this->comments, 'comment.author_url', $id, null, 'clear' ) );
		$this->assertSame( '', get_comment( $id )->comment_author_url );
		$this->assertTrue( $this->save( $this->comments, 'comment.excerpt', $id, "Line one\nIt's line two" ) );
		$this->assertSame( "Line one\nIt's line two", $this->read( $this->comments, 'comment.excerpt', $id ) );
		$this->assertSame( "Pat O'Day", get_comment( $id )->comment_author );
		$this->assertSame( '1', get_comment( $id )->comment_approved );
	}

	public function test_comment_fields_reject_bad_values(): void {
		$id = $this->comment();
		$this->assertRejected( $this->save( $this->comments, 'comment.author', $id, '' ) );
		$this->assertRejected( $this->save( $this->comments, 'comment.author_email', $id, 'bogus' ) );
		$this->assertRejected( $this->save( $this->comments, 'comment.author_email', $id, '', 'clear' ) );
		$this->assertRejected( $this->save( $this->comments, 'comment.author_url', $id, 'javascript:alert(1)' ) );
		$this->assertRejected( $this->save( $this->comments, 'comment.excerpt', $id, '   ' ) );
		$this->assertRejected( $this->save( $this->comments, 'comment.excerpt', $id, 'x', 'clear' ) );
		$this->assertRejected( $this->save( $this->comments, 'comment.excerpt', $id, array( 'x' ) ) );
		$this->assertRejected( $this->save( $this->comments, 'comment.status', $id, 'deleted' ) );
		$this->assertSame( 'Original text', get_comment( $id )->comment_content );
		$this->assertSame( 'visitor@example.org', get_comment( $id )->comment_author_email );
		$this->assertSame( '1', get_comment( $id )->comment_approved );
	}

	public function test_comment_status_moves_between_states(): void {
		$id = $this->comment();
		$this->assertSame( 'approve', $this->read( $this->comments, 'comment.status', $id ) );
		foreach ( array(
			'hold'    => '0',
			'spam'    => 'spam',
			'approve' => '1',
			'trash'   => 'trash',
		) as $status => $stored ) {
			$this->assertTrue( $this->save( $this->comments, 'comment.status', $id, $status ), $status );
			$this->assertSame( $stored, get_comment( $id )->comment_approved, $status );
		}
		$this->assertSame( 'trash', $this->read( $this->comments, 'comment.status', $id ) );
	}

	public function test_comment_edits_respect_capabilities(): void {
		$on_admin_post = $this->comment( '0', $this->admin );
		$on_own_draft  = self::factory()->comment->create(
			array(
				'comment_post_ID'  => self::factory()->post->create(
					array(
						'post_author' => $this->contributor,
						'post_status' => 'draft',
					)
				),
				'comment_approved' => '0',
			)
		);

		wp_set_current_user( $this->contributor );
		$this->assertRejected( $this->save( $this->comments, 'comment.author', $on_admin_post, 'Nope' ) );
		$this->assertRejected( $this->save( $this->comments, 'comment.status', $on_admin_post, 'approve' ) );
		$this->assertRejected( $this->save( $this->comments, 'comment.status', $on_own_draft, 'approve' ) );
		$this->assertRejected( $this->save( $this->comments, 'comment.status', $on_own_draft, 'trash' ) );
		$this->assertSame( '0', get_comment( $on_admin_post )->comment_approved );
		$this->assertSame( '0', get_comment( $on_own_draft )->comment_approved );

		wp_set_current_user( $this->author );
		$this->assertRejected( $this->save( $this->comments, 'comment.status', $on_admin_post, 'spam' ) );

		wp_set_current_user( $this->editor );
		$this->assertTrue( $this->save( $this->comments, 'comment.status', $on_admin_post, 'approve' ) );
		$this->assertSame( '1', get_comment( $on_admin_post )->comment_approved );
	}

	public function test_comment_meta_follows_the_meta_capabilities(): void {
		$id       = $this->comment();
		$settings = array(
			'meta_key' => 'rating',
			'display'  => 'number',
		);
		$this->assertTrue( $this->save( $this->comments, 'comment.meta', $id, '4', 'set', $settings ) );
		$this->assertSame( '4', $this->read( $this->comments, 'comment.meta', $id, $settings ) );
		$this->assertRejected( $this->save( $this->comments, 'comment.meta', $id, 'five', 'set', $settings ) );
		$this->assertRejected( $this->save( $this->comments, 'comment.meta', $id, '5', 'set', array( 'meta_key' => '_hidden' ) ) );
		$this->assertTrue( $this->save( $this->comments, 'comment.meta', $id, null, 'clear', $settings ) );
		$this->assertSame( array(), get_comment_meta( $id, 'rating', false ) );

		$this->assertTrue( $this->save( $this->comments, 'comment.meta', $id, '3', 'set', $settings ) );
		wp_set_current_user( $this->contributor );
		$this->assertRejected( $this->save( $this->comments, 'comment.meta', $id, '1', 'set', $settings ) );
		$this->assertRejected( $this->save( $this->comments, 'comment.meta', $id, null, 'clear', $settings ) );
		$this->assertSame( '3', get_comment_meta( $id, 'rating', true ) );
	}

	// Terms.

	public function test_term_name_slug_and_description_round_trip(): void {
		$term = self::factory()->category->create( array( 'name' => 'News' ) );
		$this->assertTrue( $this->save( $this->categories, 'term.name', $term, "Kid's Corner" ) );
		$this->assertSame( "Kid's Corner", $this->read( $this->categories, 'term.name', $term ) );
		$this->assertTrue( $this->save( $this->categories, 'term.slug', $term, 'Kids Corner!' ) );
		$this->assertSame( 'kids-corner', $this->read( $this->categories, 'term.slug', $term ) );
		$this->assertTrue( $this->save( $this->categories, 'term.description', $term, 'All <b>about</b> kids' ) );
		$this->assertSame( 'All <b>about</b> kids', $this->read( $this->categories, 'term.description', $term ) );
		$this->assertTrue( $this->save( $this->categories, 'term.description', $term, null, 'clear' ) );
		$this->assertSame( '', get_term( $term )->description );
		$this->assertSame( "Kid's Corner", get_term( $term )->name );

		$this->assertRejected( $this->save( $this->categories, 'term.name', $term, '  ' ) );
		$this->assertRejected( $this->save( $this->categories, 'term.name', $term, array( 'x' ) ) );
		$this->assertRejected( $this->save( $this->categories, 'term.name', $term, 'x', 'clear' ) );
		$this->assertRejected( $this->save( $this->categories, 'term.slug', $term, '!!!' ) );
		$this->assertRejected( $this->save( $this->categories, 'term.slug', $term, 'x', 'clear' ) );
		$this->assertRejected( $this->save( $this->categories, 'term.name', 999999, 'Ghost' ) );
		$this->assertSame( 'kids-corner', get_term( $term )->slug );
	}

	public function test_term_slug_is_unique_within_the_taxonomy_only(): void {
		$one = self::factory()->category->create(
			array(
				'name' => 'One',
				'slug' => 'one',
			)
		);
		$two = self::factory()->category->create(
			array(
				'name' => 'Two',
				'slug' => 'two',
			)
		);
		$tag = self::factory()->tag->create(
			array(
				'name' => 'Shared',
				'slug' => 'shared',
			)
		);

		$result = $this->save( $this->categories, 'term.slug', $two, 'one' );
		$this->assertRejected( $result );
		$this->assertSame( 'corinthian_duplicate', $result->get_error_code() );
		$this->assertSame( 'two', get_term( $two )->slug );
		$this->assertTrue( $this->save( $this->categories, 'term.slug', $one, 'one' ) );
		$this->assertTrue( $this->save( $this->categories, 'term.slug', $one, 'shared' ), 'Another taxonomy may reuse a slug.' );
		$this->assertTrue( $this->save( $this->tags, 'term.slug', $tag, 'shared-x' ) );
	}

	public function test_term_parent_validates_taxonomy_and_cycles(): void {
		$top    = self::factory()->category->create( array( 'name' => 'Top' ) );
		$middle = self::factory()->category->create(
			array(
				'name'   => 'Middle',
				'parent' => $top,
			)
		);
		$leaf   = self::factory()->category->create(
			array(
				'name'   => 'Leaf',
				'parent' => $middle,
			)
		);
		$tag    = self::factory()->tag->create( array( 'name' => 'Tagged' ) );
		$other  = self::factory()->tag->create( array( 'name' => 'Other' ) );

		$this->assertTrue( $this->save( $this->categories, 'term.parent', $leaf, $top ) );
		$this->assertSame( $top, $this->read( $this->categories, 'term.parent', $leaf ) );
		$this->assertTrue( $this->save( $this->categories, 'term.parent', $leaf, (string) $middle ) );

		foreach ( array(
			$top    => $leaf,
			$middle => $leaf,
			$leaf   => $leaf,
		) as $term => $parent ) {
			$result = $this->save( $this->categories, 'term.parent', $term, $parent );
			$this->assertRejected( $result );
			$this->assertSame( 'corinthian_cycle', $result->get_error_code() );
		}
		$this->assertSame( 0, (int) get_term( $top )->parent );
		$this->assertSame( $top, (int) get_term( $middle )->parent );

		$this->assertRejected( $this->save( $this->categories, 'term.parent', $leaf, $tag ) );
		$this->assertRejected( $this->save( $this->categories, 'term.parent', $leaf, 999999 ) );
		$this->assertRejected( $this->save( $this->categories, 'term.parent', $leaf, 'abc' ) );
		$this->assertSame( $middle, (int) get_term( $leaf )->parent );

		$this->assertRejected( $this->save( $this->tags, 'term.parent', $tag, $other ) );
		$this->assertSame( 0, (int) get_term( $tag )->parent );

		$this->assertTrue( $this->save( $this->categories, 'term.parent', $leaf, 0 ) );
		$this->assertSame( 0, (int) get_term( $leaf )->parent );
		$this->assertTrue( $this->save( $this->categories, 'term.parent', $middle, null, 'clear' ) );
		$this->assertSame( 0, (int) get_term( $middle )->parent );
	}

	public function test_term_edits_are_refused_without_the_capability_and_in_the_wrong_taxonomy(): void {
		$term = self::factory()->category->create( array( 'name' => 'Guarded' ) );
		$tag  = self::factory()->tag->create( array( 'name' => 'Tag' ) );

		wp_set_current_user( $this->author );
		foreach ( array(
			'term.name'        => 'Hacked',
			'term.slug'        => 'hacked',
			'term.description' => 'Hacked',
			'term.parent'      => 0,
		) as $key => $value ) {
			$this->assertRejected( $this->save( $this->categories, $key, $term, $value ), $key );
		}
		$this->assertSame( 'Guarded', get_term( $term )->name );
		$this->assertSame( 'guarded', get_term( $term )->slug );

		wp_set_current_user( $this->editor );
		$this->assertTrue( $this->save( $this->categories, 'term.name', $term, 'Allowed' ) );
		$this->assertRejected( $this->save( $this->categories, 'term.name', $tag, 'Wrong screen' ) );
		$this->assertSame( 'Tag', get_term( $tag )->name );
	}

	public function test_term_meta_follows_the_meta_capabilities(): void {
		$term     = self::factory()->category->create( array( 'name' => 'Meta' ) );
		$settings = array( 'meta_key' => 'colour' );
		$this->assertTrue( $this->save( $this->categories, 'term.meta', $term, '#ff0000', 'set', $settings ) );
		$this->assertSame( '#ff0000', $this->read( $this->categories, 'term.meta', $term, $settings ) );
		$this->assertRejected( $this->save( $this->categories, 'term.meta', $term, 'x', 'set', array( 'meta_key' => '_secret' ) ) );
		$this->assertRejected( $this->save( $this->categories, 'term.meta', $term, 'x', 'set', array() ) );

		wp_set_current_user( $this->author );
		$this->assertRejected( $this->save( $this->categories, 'term.meta', $term, '#000', 'set', $settings ) );
		$this->assertRejected( $this->save( $this->categories, 'term.meta', $term, null, 'clear', $settings ) );
		$this->assertSame( '#ff0000', get_term_meta( $term, 'colour', true ) );

		wp_set_current_user( $this->admin );
		$this->assertTrue( $this->save( $this->categories, 'term.meta', $term, null, 'clear', $settings ) );
		$this->assertSame( array(), get_term_meta( $term, 'colour', false ) );
	}
}
