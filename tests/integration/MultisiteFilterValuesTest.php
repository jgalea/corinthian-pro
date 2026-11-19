<?php

namespace CorinthianPro\Tests;

use Corinthian\Columns\Config;
use Corinthian\Columns\Registry;
use Corinthian\Columns\Types\ScreenTypeLoader;
use Corinthian\Screens\NetworkUserScreen;
use Corinthian\Screens\UserScreen;
use Corinthian\Sets\SetRepository;
use WP_UnitTestCase;

/**
 * Run with phpunit-multisite.xml.dist.
 *
 * @group MultisiteTest
 * @group ms-required
 */
class MultisiteFilterValuesTest extends WP_UnitTestCase {

	public function test_a_site_only_offers_user_meta_values_from_its_own_members(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs multisite: run phpunit-multisite.xml.dist.' );
		}
		$registry = new Registry();
		ScreenTypeLoader::register( $registry );
		$sets = new SetRepository();

		$super = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $super );
		wp_set_current_user( $super );

		$member = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_user_meta( $member, 'ms_tier', 'gold' );

		$other_site = self::factory()->blog->create();
		$outsider   = self::factory()->user->create();
		remove_user_from_blog( $outsider, get_current_blog_id() );
		add_user_to_blog( $other_site, $outsider, 'subscriber' );
		update_user_meta( $outsider, 'ms_tier', 'platinum' );

		$type   = $registry->get( 'user.meta' );
		$config = new Config( 'tier', 'user.meta', 'Tier', null, 'px', array( 'meta_key' => 'ms_tier' ) );

		$site = $type->filter_options( $config, new UserScreen( $registry, $sets ), '', 1, 20 )['options'];
		$this->assertSame( array( 'gold' ), array_keys( $site ) );

		$network = $type->filter_options( $config, new NetworkUserScreen( $registry, $sets ), '', 1, 20 )['options'];
		$this->assertSame( array( 'gold', 'platinum' ), array_keys( $network ) );
	}
}
