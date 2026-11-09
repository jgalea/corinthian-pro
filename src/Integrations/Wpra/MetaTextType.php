<?php

namespace CorinthianPro\Integrations\Wpra;

use Corinthian\Columns\Config;
use Corinthian\Columns\Filterable;
use Corinthian\Columns\Sortable;
use Corinthian\Columns\Types\PostFilter;
use Corinthian\Screens\Screen;

/**
 * A single text value kept in one post meta row of an imported item.
 */
abstract class MetaTextType extends WpraType implements Sortable, Filterable {

	abstract protected function meta_key(): string;

	public function value( int $object_id, Config $config, Screen $screen ): mixed {
		if ( ! $this->readable( $object_id ) ) {
			return null;
		}
		$value = $this->meta( $object_id, $this->meta_key() );
		return '' === $value ? null : $value;
	}

	/**
	 * Expression that orders the stored value, with {a} standing for the join alias.
	 */
	protected function sort_expression(): string {
		return 'MAX({a}.meta_value)';
	}

	public function sort_clauses( array $clauses, Config $config, string $order, Screen $screen ): array {
		global $wpdb;
		$alias              = $this->alias( $config );
		$expr               = str_replace( '{a}', $alias, $this->sort_expression() );
		$clauses['join']   .= $wpdb->prepare( " LEFT JOIN {$wpdb->postmeta} AS {$alias} ON ({$alias}.post_id = {$wpdb->posts}.ID AND {$alias}.meta_key = %s)", $this->meta_key() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$clauses['groupby'] = "{$wpdb->posts}.ID";
		$clauses['orderby'] = "(MAX({$alias}.meta_value) IS NULL OR MAX({$alias}.meta_value) = '') ASC, {$expr} {$order}";
		return $clauses;
	}

	public function filter_operators( Config $config, Screen $screen ): array {
		return PostFilter::text_ops();
	}

	public function filter_condition( Config $config, string $op, mixed $value, Screen $screen, string $alias ): ?array {
		$col  = "{$alias}.meta_value";
		$key  = $this->meta_key();
		$have = static fn( string $extra ): string => PostFilter::meta_exists( $alias, $key, $extra );

		switch ( $op ) {
			case 'empty':
				return PostFilter::wrap( 'NOT ' . $have( "{$col} <> ''" ) );
			case 'not_empty':
				return PostFilter::wrap( $have( "{$col} <> ''" ) );
			case 'contains':
			case 'eq':
			case 'starts':
				$match = PostFilter::text_where( $col, $op, $value );
				return null === $match ? null : PostFilter::wrap( $have( $match ) );
			case 'not_contains':
			case 'neq':
				$match = PostFilter::text_where( $col, 'not_contains' === $op ? 'contains' : 'eq', $value );
				return null === $match ? null : PostFilter::wrap( 'NOT ' . $have( $match ) );
		}
		return null;
	}
}
