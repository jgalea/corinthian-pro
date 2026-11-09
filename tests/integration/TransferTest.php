<?php

namespace CorinthianPro\Tests;

use Corinthian\Plugin;
use Corinthian\Sets\CodeSets;
use CorinthianPro\Rest\TransferController;
use CorinthianPro\Sets\PhpExport;
use WP_REST_Request;
use WP_UnitTestCase;

final class TransferTest extends WP_UnitTestCase {

	private function set_data( string $title ): array {
		return array(
			'screen'   => 'post:post',
			'title'    => $title,
			'columns'  => array(
				array( 'id' => 'title', 'type' => 'native', 'label' => '', 'settings' => array( 'key' => 'title' ) ),
				array( 'id' => 'prio', 'type' => 'post.meta', 'label' => 'Priority', 'width' => 90, 'settings' => array( 'meta_key' => 'priority', 'display' => 'number' ) ),
			),
			'settings' => array( 'roles' => array( 'editor' ), 'users' => array( 7 ), 'sticky_header' => true ),
		);
	}

	public function test_json_round_trip_restores_columns_and_drops_user_ids(): void {
		$plugin     = Plugin::instance();
		$controller = new TransferController( $plugin );
		$original   = $plugin->sets->save( $this->set_data( 'Round trip' ) );

		$request = new WP_REST_Request( 'POST', '/corinthian/v1/transfer/export' );
		$request->set_param( 'ids', array( $original->id ) );
		$doc = $controller->export( $request )->get_data();
		$this->assertSame( TransferController::FORMAT, $doc['format'] );
		$this->assertCount( 1, $doc['sets'] );

		$plugin->sets->delete( $original->id );
		$this->assertNull( $plugin->sets->find( $original->id ) );

		$import = new WP_REST_Request( 'POST', '/corinthian/v1/transfer/import' );
		$import->set_header( 'content-type', 'application/json' );
		$import->set_body( (string) wp_json_encode( $doc ) );
		$result = $controller->import( $import )->get_data();
		$this->assertCount( 1, $result['created'] );

		$restored = $plugin->sets->find( $result['created'][0] );
		$this->assertNotNull( $restored );
		$this->assertSame( 'Round trip', $restored->title );
		$this->assertSame(
			array_map( static fn( $c ) => $c->to_array(), $original->columns ),
			array_map( static fn( $c ) => $c->to_array(), $restored->columns )
		);
		$this->assertSame( array( 'editor' ), $restored->roles() );
		$this->assertSame( array(), $restored->users(), 'User ids must not travel between sites.' );
		$this->assertTrue( $restored->settings['sticky_header'] );
	}

	public function test_user_only_set_fails_closed_to_administrators(): void {
		$plugin     = Plugin::instance();
		$controller = new TransferController( $plugin );
		$data       = $this->set_data( 'Only user 7' );
		$data['settings']['roles'] = array();
		$import = new WP_REST_Request( 'POST', '/corinthian/v1/transfer/import' );
		$import->set_header( 'content-type', 'application/json' );
		$import->set_body( (string) wp_json_encode( array( 'format' => TransferController::FORMAT, 'version' => 1, 'sets' => array( $data ) ) ) );
		$result = $controller->import( $import )->get_data();
		$set    = $plugin->sets->find( $result['created'][0] );

		$this->assertSame( array( $set->id ), $result['restricted'] );
		$this->assertSame( array( 'administrator' ), $set->roles() );
		$this->assertFalse( $set->visible_to( self::factory()->user->create_and_get( array( 'role' => 'editor' ) ) ), 'An editor must not see a set that was private to one user.' );
		$this->assertTrue( $set->visible_to( self::factory()->user->create_and_get( array( 'role' => 'administrator' ) ) ) );
	}

	public function test_import_rejects_foreign_documents(): void {
		$controller = new TransferController( Plugin::instance() );
		$import     = new WP_REST_Request( 'POST', '/corinthian/v1/transfer/import' );
		$import->set_header( 'content-type', 'application/json' );
		$import->set_body( '{"format":"something-else","sets":[]}' );
		$this->assertWPError( $controller->import( $import ) );
	}

	public function test_import_skips_unknown_screens(): void {
		$controller = new TransferController( Plugin::instance() );
		$import     = new WP_REST_Request( 'POST', '/corinthian/v1/transfer/import' );
		$import->set_header( 'content-type', 'application/json' );
		$import->set_body( (string) wp_json_encode( array( 'format' => TransferController::FORMAT, 'version' => 1, 'sets' => array( array( 'screen' => 'post:no_such_type', 'title' => 'x', 'columns' => array() ) ) ) ) );
		$result = $controller->import( $import )->get_data();
		$this->assertSame( array(), $result['created'] );
		$this->assertSame( array( 'post:no_such_type' ), $result['skipped'] );
	}

	public function test_generated_php_registers_an_equivalent_set(): void {
		$plugin = Plugin::instance();
		$set    = $plugin->sets->save( $this->set_data( 'Exported PHP' ) );
		$php    = PhpExport::to_php( $set );

		$file = wp_tempnam( 'corinthian-php' );
		file_put_contents( $file, "<?php\n" . $php );
		require $file;
		unlink( $file );
		do_action( 'corinthian_register_types', $plugin->registry );

		$code = $plugin->sets->find( CodeSets::id_for( 'exported_php' ) );
		$this->assertNotNull( $code, 'Generated PHP should register the set under its title key.' );
		$this->assertSame(
			array_map( static fn( $c ) => $c->to_array(), $set->columns ),
			array_map( static fn( $c ) => $c->to_array(), $code->columns )
		);
	}
}
