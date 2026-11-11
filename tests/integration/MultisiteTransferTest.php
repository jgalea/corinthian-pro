<?php

namespace CorinthianPro\Tests;

use Corinthian\Plugin;
use CorinthianPro\Rest\TransferController;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Column set import and export on a network. Run with phpunit-multisite.xml.dist.
 *
 * @group MultisiteTest
 * @group ms-required
 */
class MultisiteTransferTest extends WP_UnitTestCase {

	private int $super;
	private int $plain_admin;

	public function set_up(): void {
		parent::set_up();
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs multisite: run phpunit-multisite.xml.dist.' );
		}
		$this->super = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $this->super );
		$this->plain_admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	private function import( array $sets ): array {
		$request = new WP_REST_Request( 'POST', '/corinthian/v1/transfer/import' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array( 'format' => TransferController::FORMAT, 'version' => 1, 'sets' => $sets ) ) );
		return ( new TransferController( Plugin::instance() ) )->import( $request )->get_data();
	}

	private function network_set(): array {
		return array( 'screen' => 'network-users', 'title' => 'Network set', 'columns' => array(), 'position' => -999 );
	}

	public function test_a_site_admin_cannot_import_a_network_set(): void {
		wp_set_current_user( $this->plain_admin );
		$result = $this->import( array( $this->network_set() ) );
		$this->assertSame( array(), $result['created'] );
		$this->assertSame( array( 'network-users' ), $result['skipped'] );
		$this->assertSame( array(), Plugin::instance()->sets->for_screen( 'network-users' ) );
	}

	public function test_a_super_admin_can_import_a_network_set(): void {
		wp_set_current_user( $this->super );
		$result = $this->import( array( $this->network_set() ) );
		$this->assertCount( 1, $result['created'] );
	}

	public function test_a_site_admin_export_leaves_out_network_sets(): void {
		Plugin::instance()->sets->save( $this->network_set() );
		$request = new WP_REST_Request( 'POST', '/corinthian/v1/transfer/export' );
		$export  = static fn() => array_column( ( new TransferController( Plugin::instance() ) )->export( $request )->get_data()['sets'], 'screen' );

		wp_set_current_user( $this->plain_admin );
		$this->assertNotContains( 'network-users', $export() );
		wp_set_current_user( $this->super );
		$this->assertContains( 'network-users', $export() );
	}

	public function test_uninstall_visits_every_site_not_just_the_first_hundred(): void {
		$seen = null;
		add_filter(
			'sites_pre_query',
			static function ( $sites, $query ) use ( &$seen ) {
				$seen = $query->query_vars['number'];
				return array();
			},
			10,
			2
		);
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'corinthian-pro/corinthian-pro.php' );
		}
		include dirname( __DIR__, 2 ) . '/uninstall.php';
		$this->assertSame( 0, $seen );
	}
}
