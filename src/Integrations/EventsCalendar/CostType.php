<?php

namespace CorinthianPro\Integrations\EventsCalendar;

use Corinthian\Columns\Config;
use Corinthian\Columns\Filterable;
use Corinthian\Columns\Sortable;
use Corinthian\Columns\Types\PostFilter;
use Corinthian\Screens\Screen;

defined( 'ABSPATH' ) || exit;

/**
 * _EventCost, compared the way TEC's own cost filters do: as DECIMAL(10,5).
 * An event with several prices counts by its cheapest one.
 */
final class CostType extends TecType implements Sortable, Filterable {

	private const KEY = '_EventCost';

	public function key(): string {
		return 'tec.cost';
	}

	public function label(): string {
		return __( 'Event cost', 'corinthian-pro' );
	}

	public function value( int $object_id, Config $config, Screen $screen ): mixed {
		$cost = TecApi::cost( $object_id );
		return '' === $cost ? null : $cost;
	}

	public function sort_clauses( array $clauses, Config $config, string $order, Screen $screen ): array {
		return $this->sort_meta( $clauses, $config, self::KEY, 'MIN(CAST({a}.meta_value AS DECIMAL(10,5)))', $order, " AND {$this->alias( $config )}.meta_value <> ''" );
	}

	public function filter_operators( Config $config, Screen $screen ): array {
		return PostFilter::number_ops( true );
	}

	public function filter_condition( Config $config, string $op, mixed $value, Screen $screen, string $alias ): ?array {
		global $wpdb;
		$cheapest = $wpdb->prepare( "(SELECT MIN(CAST({$alias}.meta_value AS DECIMAL(10,5))) FROM {$wpdb->postmeta} AS {$alias} WHERE {$alias}.post_id = {$wpdb->posts}.ID AND {$alias}.meta_key = %s AND {$alias}.meta_value <> '')", self::KEY ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return PostFilter::wrap( PostFilter::number_where( $cheapest, $op, $value ) );
	}
}
