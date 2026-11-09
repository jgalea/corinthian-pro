<?php

namespace CorinthianPro\Integrations\Yoast;

use Corinthian\Columns\Config;
use Corinthian\Columns\Filterable;
use Corinthian\Columns\Sortable;
use Corinthian\Columns\Types\PostFilter;
use Corinthian\Screens\Screen;

/**
 * Internal link counts Yoast's link builder keeps on the post's indexable row.
 * Derived data: read-only, and blank for posts Yoast has not indexed.
 */
abstract class LinkCountType extends YoastType implements Sortable, Filterable {

	/**
	 * link_count or incoming_link_count.
	 */
	abstract protected function column(): string;

	protected function meta_name( Config $config ): string {
		return '';
	}

	public function supports( Screen $screen ): bool {
		return parent::supports( $screen ) && Indexables::table_exists();
	}

	/**
	 * Yoast leaves the count empty on an indexed post nothing links to, and counts it as orphaned.
	 */
	protected function empty_means_zero(): bool {
		return false;
	}

	public function value( int $object_id, Config $config, Screen $screen ): mixed {
		$counts = Indexables::link_counts( $object_id );
		$count  = $counts[ $this->column() ];
		return null === $count && $counts['indexed'] && $this->empty_means_zero() ? 0 : $count;
	}

	private function expression( string $alias ): string {
		$column = "{$alias}.{$this->column()}";
		return $this->empty_means_zero() ? "COALESCE({$column}, 0)" : $column;
	}

	public function render( int $object_id, Config $config, Screen $screen ): string {
		$count = $this->value( $object_id, $config, $screen );
		return null === $count ? '<span aria-hidden="true">&#8212;</span>' : esc_html( (string) $count );
	}

	public function sort_clauses( array $clauses, Config $config, string $order, Screen $screen ): array {
		global $wpdb;
		$alias              = $this->alias( $config );
		$table              = YoastIntegration::indexable_table();
		$column             = $this->column();
		$clauses['join']   .= " LEFT JOIN {$table} AS {$alias} ON ({$alias}.object_id = {$wpdb->posts}.ID AND {$alias}.object_type = 'post')";
		$clauses['groupby'] = "{$wpdb->posts}.ID";
		$value              = $this->empty_means_zero() ? "CASE WHEN {$alias}.object_id IS NULL THEN NULL ELSE COALESCE({$alias}.{$column}, 0) END" : "{$alias}.{$column}";
		$clauses['orderby'] = "(MAX({$value}) IS NULL) ASC, MAX({$value}) {$order}";
		return $clauses;
	}

	public function filter_operators( Config $config, Screen $screen ): array {
		return PostFilter::number_ops( true );
	}

	public function filter_condition( Config $config, string $op, mixed $value, Screen $screen, string $alias ): ?array {
		global $wpdb;
		$table  = YoastIntegration::indexable_table();
		$column  = $this->expression( $alias );
		$exists  = "EXISTS (SELECT 1 FROM {$table} AS {$alias} WHERE {$alias}.object_id = {$wpdb->posts}.ID AND {$alias}.object_type = 'post'";
		$counted = $this->empty_means_zero() ? '' : " AND {$column} IS NOT NULL";
		if ( 'empty' === $op ) {
			return PostFilter::wrap( 'NOT ' . $exists . $counted . ')' );
		}
		if ( ! in_array( $op, array( 'eq', 'neq', 'gt', 'lt', 'between' ), true ) ) {
			return null;
		}
		$where = PostFilter::number_where( $column, $op, $value );
		return null === $where ? null : PostFilter::wrap( $exists . $counted . " AND {$where})" );
	}
}
