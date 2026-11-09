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

	public function value( int $object_id, Config $config, Screen $screen ): mixed {
		return Indexables::link_counts( $object_id )[ $this->column() ];
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
		$clauses['orderby'] = "(MAX({$alias}.{$column}) IS NULL) ASC, MAX({$alias}.{$column}) {$order}";
		return $clauses;
	}

	public function filter_operators( Config $config, Screen $screen ): array {
		return PostFilter::number_ops( true );
	}

	public function filter_condition( Config $config, string $op, mixed $value, Screen $screen, string $alias ): ?array {
		global $wpdb;
		$table  = YoastIntegration::indexable_table();
		$column = "{$alias}.{$this->column()}";
		$exists = "EXISTS (SELECT 1 FROM {$table} AS {$alias} WHERE {$alias}.object_id = {$wpdb->posts}.ID AND {$alias}.object_type = 'post'";
		if ( 'empty' === $op ) {
			return PostFilter::wrap( 'NOT ' . $exists . " AND {$column} IS NOT NULL)" );
		}
		if ( ! in_array( $op, array( 'eq', 'neq', 'gt', 'lt', 'between' ), true ) ) {
			return null;
		}
		$where = PostFilter::number_where( $column, $op, $value );
		return null === $where ? null : PostFilter::wrap( $exists . " AND {$column} IS NOT NULL AND {$where})" );
	}
}
