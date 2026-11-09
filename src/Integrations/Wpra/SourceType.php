<?php

namespace CorinthianPro\Integrations\Wpra;

use Corinthian\Columns\Config;
use Corinthian\Columns\Filterable;
use Corinthian\Columns\Sortable;
use Corinthian\Columns\Types\PostFilter;
use Corinthian\Screens\Screen;

/**
 * The feed source an item came from. WPRA stores the source id in _wpra_source (one
 * row per source) and the sources themselves in its own table, so names come from
 * there; an item whose source was deleted falls back to the name saved on the item.
 */
final class SourceType extends WpraType implements Sortable, Filterable {

	public function key(): string {
		return 'wpra.source';
	}

	public function label(): string {
		return __( 'Source feed', 'corinthian-pro' );
	}

	public function supports( Screen $screen ): bool {
		return parent::supports( $screen ) && WpraApi::has_sources_table();
	}

	/**
	 * @return array<int,string> source id => name
	 */
	private function sources_of( int $item_id ): array {
		$names = WpraApi::source_names();
		$out   = array();
		foreach ( get_post_meta( $item_id, WpraApi::SOURCE, false ) as $id ) {
			$id = is_scalar( $id ) ? absint( $id ) : 0;
			if ( $id > 0 ) {
				$out[ $id ] = $names[ $id ] ?? $this->meta( $item_id, WpraApi::SOURCE_NAME );
			}
		}
		return $out;
	}

	public function value( int $object_id, Config $config, Screen $screen ): mixed {
		if ( ! $this->readable( $object_id ) ) {
			return null;
		}
		$names = array_values( array_filter( $this->sources_of( $object_id ), static fn( string $name ): bool => '' !== $name ) );
		return $names ? $names : null;
	}

	public function render( int $object_id, Config $config, Screen $screen ): string {
		if ( ! $this->readable( $object_id ) ) {
			return parent::render( $object_id, $config, $screen );
		}
		$links = array();
		foreach ( $this->sources_of( $object_id ) as $id => $name ) {
			$url     = add_query_arg(
				array(
					'post_type'   => $screen->subtype(),
					'wpra_source' => $id,
				),
				admin_url( 'edit.php' )
			);
			$links[] = sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( '' !== $name ? $name : '#' . $id ) );
		}
		return $links ? implode( '<br>', $links ) : parent::render( $object_id, $config, $screen );
	}

	public function sort_clauses( array $clauses, Config $config, string $order, Screen $screen ): array {
		global $wpdb;
		$alias              = $this->alias( $config );
		$clauses['join']   .= $wpdb->prepare( " LEFT JOIN {$wpdb->postmeta} AS {$alias} ON ({$alias}.post_id = {$wpdb->posts}.ID AND {$alias}.meta_key = %s) LEFT JOIN %i AS {$alias}_s ON ({$alias}_s.id = CAST({$alias}.meta_value AS UNSIGNED))", WpraApi::SOURCE, WpraApi::sources_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$clauses['groupby'] = "{$wpdb->posts}.ID";
		$clauses['orderby'] = "(MIN({$alias}_s.name) IS NULL) ASC, MIN({$alias}_s.name) {$order}";
		return $clauses;
	}

	public function filter_operators( Config $config, Screen $screen ): array {
		return PostFilter::in_ops( WpraApi::source_options() );
	}

	public function filter_condition( Config $config, string $op, mixed $value, Screen $screen, string $alias ): ?array {
		return PostFilter::meta_ids( WpraApi::SOURCE, static fn() => array() )->condition( $config, $op, $value, $screen, $alias );
	}
}
