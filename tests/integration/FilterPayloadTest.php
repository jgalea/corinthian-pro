<?php

namespace CorinthianPro\Tests;

use Corinthian\Plugin;
use CorinthianPro\Admin\FilterPayload;
use CorinthianPro\Filters\FilterSet;
use WP_UnitTestCase;

final class FilterPayloadTest extends WP_UnitTestCase {

	private FilterPayload $payload;

	private \Corinthian\Sets\ColumnSet $set;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->set     = Plugin::instance()->sets->save(
			array(
				'screen'  => 'post:post',
				'title'   => 'States',
				'columns' => array(
					array(
						'id'       => 'ttl',
						'type'     => 'native',
						'settings' => array( 'key' => 'title' ),
					),
					array(
						'id'   => 'pub',
						'type' => 'post.published_date',
					),
				),
			)
		);
		$this->payload = new FilterPayload( Plugin::instance() );
	}

	public function tear_down(): void {
		unset( $_GET[ FilterSet::QUERY_VAR ] );
		parent::tear_down();
	}

	/**
	 * @return array<string,mixed>
	 */
	private function filter_data(): array {
		$data = $this->payload->extend( array(), Plugin::instance()->screens->get( 'post:post' ), $this->set );
		return $data['filter'];
	}

	public function test_a_clean_link_drops_nothing(): void {
		$_GET[ FilterSet::QUERY_VAR ] = wp_json_encode(
			array(
				'rules' => array(
					array(
						'column' => 'ttl',
						'op'     => 'contains',
						'value'  => 'a',
					),
				),
			)
		);
		$this->assertSame( array(), $this->filter_data()['dropped'] );
		unset( $_GET[ FilterSet::QUERY_VAR ] );
		$this->assertSame( array(), $this->filter_data()['dropped'] );
	}

	public function test_a_stale_link_reports_each_rule_it_loses_and_why(): void {
		$_GET[ FilterSet::QUERY_VAR ] = wp_json_encode(
			array(
				'rules' => array(
					array(
						'column' => 'gone',
						'op'     => 'eq',
						'value'  => 'x',
					),
					array(
						'column' => 'ttl',
						'op'     => 'teleports',
						'value'  => 'x',
					),
					array(
						'column' => 'ttl',
						'op'     => 'contains',
						'value'  => 'ok',
					),
					array( 'op' => 'eq' ),
					'junk',
				),
			)
		);
		$dropped                      = $this->filter_data()['dropped'];
		$this->assertSame( array( 'unknown_column', 'unsupported_op', 'malformed', 'malformed' ), array_column( $dropped, 'reason' ) );
		$this->assertSame( 'gone', $dropped[0]['rule']['column'] );
	}

	public function test_a_link_that_is_not_json_is_reported_as_unreadable(): void {
		$_GET[ FilterSet::QUERY_VAR ] = '{not json';
		$this->assertSame( array( array( 'reason' => 'unreadable' ) ), $this->filter_data()['dropped'] );
	}

	public function test_core_filter_row_steps_aside_only_once_the_bar_is_on_the_screen(): void {
		$this->assertFalse( $this->payload->hide_native( false ) );
		$this->assertSame( 'a', $this->payload->body_class( 'a' ) );

		$this->filter_data();
		$this->assertTrue( $this->payload->hide_native( false ) );
		$this->assertSame( 'a ' . FilterPayload::BODY_CLASS, $this->payload->body_class( 'a' ) );
	}
}
