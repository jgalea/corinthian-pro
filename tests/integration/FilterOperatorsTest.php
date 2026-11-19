<?php

namespace CorinthianPro\Tests;

use Corinthian\Plugin;
use CorinthianPro\Admin\FilterPayload;
use CorinthianPro\Filters\FilterSet;
use WP_UnitTestCase;

final class FilterOperatorsTest extends WP_UnitTestCase {

	private \Corinthian\Sets\ColumnSet $set;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->set = Plugin::instance()->sets->save(
			array(
				'screen'  => 'post:post',
				'title'   => 'Ops',
				'columns' => array(
					array(
						'id'       => 'ttl',
						'type'     => 'native',
						'settings' => array( 'key' => 'title' ),
					),
					array(
						'id'       => 'dat',
						'type'     => 'native',
						'settings' => array( 'key' => 'date' ),
					),
					array(
						'id'   => 'pub',
						'type' => 'post.published_date',
					),
				),
			)
		);
		update_option( 'timezone_string', 'Europe/Lisbon' );
		update_option( 'start_of_week', 1 );
		$ts = ( new \DateTimeImmutable( '2026-11-18 12:00:00', wp_timezone() ) )->getTimestamp();
		add_filter( 'corinthian_filter_now', static fn() => $ts );
	}

	public function tear_down(): void {
		remove_all_filters( 'corinthian_filter_now' );
		parent::tear_down();
	}

	/**
	 * @param list<array<string,mixed>> $rules
	 * @return list<int>
	 */
	private function ids( array $rules ): array {
		global $wpdb;
		$screen = Plugin::instance()->screens->get( 'post:post' );
		$sql    = FilterSet::from( array( 'rules' => $rules ) )->compile( $screen, $this->set );
		$this->assertNotSame( '', $sql['where'], 'the rule compiled instead of being dropped' );
		return array_map( 'intval', $wpdb->get_col( "SELECT {$wpdb->posts}.ID FROM {$wpdb->posts} {$sql['join']} WHERE post_type = 'post' AND post_status = 'publish' AND {$sql['where']} ORDER BY ID" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function test_native_title_filters_and_other_native_columns_are_left_out_of_the_filter_bar(): void {
		$a = self::factory()->post->create(
			array(
				'post_title'  => 'Weekly Report',
				'post_status' => 'publish',
			)
		);
		self::factory()->post->create(
			array(
				'post_title'  => 'Notes',
				'post_status' => 'publish',
			)
		);
		$this->assertSame(
			array( $a ),
			$this->ids(
				array(
					array(
						'column' => 'ttl',
						'op'     => 'ends',
						'value'  => 'Report',
					),
				)
			)
		);

		$payload = new FilterPayload( Plugin::instance() );
		$method  = new \ReflectionMethod( $payload, 'filterable_columns' );
		$method->setAccessible( true );
		$ids = array_column( $method->invoke( $payload, Plugin::instance()->screens->get( 'post:post' ), $this->set ), 'id' );
		$this->assertContains( 'ttl', $ids );
		$this->assertContains( 'pub', $ids );
		$this->assertNotContains( 'dat', $ids );
	}

	public function test_date_rules_round_trip_through_the_url_json_and_old_keys_still_work(): void {
		$in   = self::factory()->post->create(
			array(
				'post_date'   => '2026-11-12 09:00:00',
				'post_status' => 'publish',
			)
		);
		$out  = self::factory()->post->create(
			array(
				'post_date'   => '2026-11-16 09:00:00',
				'post_status' => 'publish',
			)
		);
		$json = wp_json_encode(
			array(
				'match' => 'all',
				'rules' => array(
					array(
						'column' => 'pub',
						'op'     => 'between',
						'value'  => array( '2026-11-10', '2026-11-12' ),
					),
				),
			)
		);
		$this->assertSame( array( $in ), $this->ids( FilterSet::from( $json )->to_array()['rules'] ) );
		$this->assertSame(
			array( $out ),
			$this->ids(
				array(
					array(
						'column' => 'pub',
						'op'     => 'after',
						'value'  => '2026-11-14',
					),
				)
			)
		);
		$this->assertSame(
			array( $in ),
			$this->ids(
				array(
					array(
						'column' => 'pub',
						'op'     => 'on',
						'value'  => '2026-11-12',
					),
				)
			)
		);
	}

	public function test_the_ai_builder_accepts_the_new_date_operators(): void {
		foreach ( array( 'on', 'today', 'future', 'next_days', 'this_week', 'this_month' ) as $op ) {
			$this->assertContains( $op, \CorinthianPro\Ai\AiController::OPS );
		}
	}
}
