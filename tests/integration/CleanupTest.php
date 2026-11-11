<?php

namespace CorinthianPro\Tests;

use Corinthian\Columns\Types\WordCountMeta;
use Corinthian\Plugin;
use CorinthianPro\Rest\SegmentsController;
use WP_UnitTestCase;

final class CleanupTest extends WP_UnitTestCase {

	public function test_uninstall_removes_segments_transients_and_the_release_cache(): void {
		$user = self::factory()->user->create();
		update_user_meta( $user, SegmentsController::USER_META_PREFIX . '7', array( array( 'id' => 'a' ) ) );
		update_user_meta( $user, 'unrelated_user_meta', 'keep' );
		set_transient( 'corinthian_ai_calls_' . $user, array( 'n' => 1, 't' => time() ), HOUR_IN_SECONDS );
		set_transient( 'unrelated_transient', 'keep', HOUR_IN_SECONDS );
		set_site_transient( 'corinthian_pro_release', array( 'version' => '9.9.9' ), HOUR_IN_SECONDS );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'corinthian-pro/corinthian-pro.php' );
		}
		include dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertSame( '', get_user_meta( $user, SegmentsController::USER_META_PREFIX . '7', true ) );
		$this->assertSame( 'keep', get_user_meta( $user, 'unrelated_user_meta', true ) );
		$this->assertFalse( get_transient( 'corinthian_ai_calls_' . $user ) );
		$this->assertSame( 'keep', get_transient( 'unrelated_transient' ) );
		$this->assertFalse( get_site_transient( 'corinthian_pro_release' ) );
	}

	public function test_deleting_a_set_removes_every_users_personal_segments_for_it(): void {
		$set   = Plugin::instance()->sets->save(
			array(
				'screen'  => 'post:post',
				'title'   => 'Temp',
				'columns' => array(),
			)
		);
		$alice = self::factory()->user->create();
		$bob   = self::factory()->user->create();
		foreach ( array( $alice, $bob ) as $user ) {
			update_user_meta( $user, SegmentsController::USER_META_PREFIX . $set->id, array( array( 'id' => 'x' ) ) );
			update_user_meta( $user, SegmentsController::USER_META_PREFIX . '99999', array( array( 'id' => 'y' ) ) );
		}

		Plugin::instance()->sets->delete( $set->id );

		$this->assertSame( '', get_user_meta( $alice, SegmentsController::USER_META_PREFIX . $set->id, true ) );
		$this->assertSame( '', get_user_meta( $bob, SegmentsController::USER_META_PREFIX . $set->id, true ) );
		$this->assertNotSame( '', get_user_meta( $alice, SegmentsController::USER_META_PREFIX . '99999', true ), 'other sets are untouched' );
	}

	public function test_a_set_using_content_health_keeps_word_counts_maintained(): void {
		$this->assertContains( 'post.content_health', WordCountMeta::types() );
	}

	public function test_update_checks_reject_a_release_with_a_bad_version_or_package_host(): void {
		$serve = static function ( array $release ) {
			return static function () use ( $release ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => (string) wp_json_encode( $release ),
				);
			};
		};
		$good = array(
			'tag_name' => 'v1.2.3',
			'html_url' => 'https://github.com/jgalea/corinthian-pro/releases/tag/v1.2.3',
			'assets'   => array(
				array(
					'name'                 => 'corinthian-pro.zip',
					'browser_download_url' => 'https://github.com/jgalea/corinthian-pro/releases/download/v1.2.3/corinthian-pro.zip',
				),
			),
		);

		$cases = array(
			'good'            => array( $good, true ),
			'evil host'       => array( array_replace_recursive( $good, array( 'assets' => array( array( 'browser_download_url' => 'https://evil.example/corinthian-pro.zip' ) ) ) ), false ),
			'plain http'      => array( array_replace_recursive( $good, array( 'assets' => array( array( 'browser_download_url' => 'http://github.com/x/corinthian-pro.zip' ) ) ) ), false ),
			'junk version'    => array( array_merge( $good, array( 'tag_name' => '<script>alert(1)</script>' ) ), false ),
		);
		foreach ( $cases as $label => [ $release, $accepted ] ) {
			delete_site_transient( 'corinthian_pro_release' );
			$filter = $serve( $release );
			add_filter( 'pre_http_request', $filter );
			$found = corinthian_pro_latest_release();
			remove_filter( 'pre_http_request', $filter );
			$this->assertSame( $accepted, null !== $found, $label );
		}
		delete_site_transient( 'corinthian_pro_release' );
	}
}
