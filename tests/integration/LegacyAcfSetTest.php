<?php

namespace CorinthianPro\Tests;

use Corinthian\Columns\Registry;
use Corinthian\Screens\PostTypeScreen;
use Corinthian\Sets\SetRepository;
use WP_UnitTestCase;

class LegacyAcfSetTest extends WP_UnitTestCase {

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
			acf()->init();
		}
	}

	public function test_a_saved_set_with_an_acf_column_renders_through_pro(): void {
		acf_add_local_field_group(
			array(
				'key'      => 'group_legacy_acf',
				'title'    => 'Legacy',
				'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ),
				'fields'   => array(
					array(
						'key'   => 'field_legacy_subtitle',
						'label' => 'Subtitle',
						'name'  => 'legacy_subtitle',
						'type'  => 'text',
					),
				),
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$repo = new SetRepository();
		$id   = $repo->save(
			array(
				'screen'  => 'post',
				'title'   => 'Saved before the split',
				'columns' => array(
					array(
						'id'       => 'c1',
						'type'     => 'acf.field',
						'label'    => 'Subtitle',
						'settings' => array( 'field_key' => 'field_legacy_subtitle' ),
					),
				),
			)
		)->id;
		$set  = $repo->find( $id );
		$this->assertNotNull( $set );
		$this->assertSame( 'acf.field', $set->columns[0]->type );

		$registry = new Registry();
		do_action( 'corinthian_register_types', $registry );
		$type = $registry->get( $set->columns[0]->type );
		$this->assertNotNull( $type );

		$post = self::factory()->post->create();
		update_field( 'field_legacy_subtitle', 'Hello', $post );
		$screen = new PostTypeScreen( $registry, $repo, get_post_type_object( 'post' ) );
		$this->assertStringContainsString( 'Hello', $type->render( $post, $set->columns[0], $screen ) );
	}
}
