<?php

namespace CorinthianPro\Integrations\WooCommerce;

use Corinthian\Screens\Screen;

/**
 * The WooCommerce Orders list when orders live in the HPOS tables. Order ids are
 * not post ids there, so this screen reports its own object type, "order".
 */
final class OrderScreen extends Screen {

	/** Query arg that marks the list's own order query, so other order queries are left alone. */
	public const QUERY_FLAG = 'corinthian';

	public function key(): string {
		return 'woo:orders';
	}

	public function label(): string {
		return __( 'Orders', 'corinthian-pro' );
	}

	public function screen_id(): string {
		return function_exists( 'wc_get_page_screen_id' ) ? (string) Woo::call( 'wc_get_page_screen_id', 'shop-order' ) : 'woocommerce_page_wc-orders';
	}

	public function object_type(): string {
		return 'order';
	}

	public function subtype(): string {
		return 'shop_order';
	}

	public function url(): string {
		return admin_url( 'admin.php?page=wc-orders' );
	}

	public function register(): void {
		$id = $this->screen_id();
		add_filter( "manage_{$id}_columns", array( $this, 'filter_columns' ), PHP_INT_MAX - 10 );
		add_filter( "manage_{$id}_sortable_columns", array( $this, 'filter_sortable' ), PHP_INT_MAX - 10 );
		// The list only honours an orderby that is in its own sortable list, and drops it otherwise.
		add_filter( 'woocommerce_shop_order_list_table_sortable_columns', array( $this, 'allow_sorts' ) );
		add_action( "manage_{$id}_custom_column", array( $this, 'render_cell' ), 10, 2 );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( $this, 'flag_query' ) );
		add_filter( 'woocommerce_orders_table_query_clauses', array( $this, 'query_clauses' ), 10, 2 );
	}

	public function user_can_list(): bool {
		return current_user_can( 'edit_shop_orders' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce registers it.
	}

	public function user_can_read( int $id ): bool {
		return self::can_edit( $id );
	}

	public static function can_edit( int $id ): bool {
		return null !== Woo::order( $id ) && Woo::can_edit( 'order', $id );
	}

	/**
	 * @param array<string,string> $sortable
	 * @return array<string,string>
	 */
	public function allow_sorts( $sortable ): array {
		$sortable = (array) $sortable;
		foreach ( array_keys( $this->filter_sortable( array() ) ) as $key ) {
			$sortable[ $key ] = $key;
		}
		return $sortable;
	}

	public function render_cell( mixed $column, mixed $order ): void {
		if ( is_string( $column ) && str_starts_with( $column, 'corinthian-' ) && is_object( $order ) ) {
			echo $this->cell( $column, (int) $order->get_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Column types return escaped HTML.
		}
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function flag_query( $args ): array {
		$args = (array) $args;
		if ( ! $this->is_current() ) {
			return $args;
		}
		$sql     = $this->filter_sql();
		$orderby = $args['orderby'] ?? '';
		$sorting = null !== $this->sort_target( $orderby );
		if ( '' === $sql['where'] && ! $sorting ) {
			return $args;
		}
		// Part of the args, so anything keyed on them sees a different query for a different filter.
		$args[ self::QUERY_FLAG ] = md5( $sql['join'] . $sql['where'] . ( $sorting ? (string) $orderby : '' ) );
		return $args;
	}

	/**
	 * @param array<string,string> $clauses
	 * @return array<string,string>
	 */
	public function query_clauses( $clauses, mixed $query = null ): array {
		$clauses = (array) $clauses;
		if ( ! is_object( $query ) || ! $query->get( self::QUERY_FLAG ) ) {
			return $clauses;
		}
		$sql = $this->filter_sql();
		if ( '' !== $sql['where'] ) {
			$clauses['join']  = trim( ( $clauses['join'] ?? '' ) . ' ' . $sql['join'] );
			$clauses['where'] = ( $clauses['where'] ?? '1=1' ) . ' AND ' . $sql['where'];
		}
		$target = $this->sort_target( $query->get( 'orderby' ) );
		if ( $target ) {
			$order   = self::normalize_order( $query->get( 'order' ) );
			$clauses = $target[1]->sort_clauses( $clauses, $target[0], $order, $this );
			// Stable tie-break so paging never repeats or skips rows.
			$clauses['orderby'] = trim( ( $clauses['orderby'] ?? '' ) . ', ' . Woo::orders_table() . ".id {$order}", ', ' );
		}
		return $clauses;
	}

	/**
	 * Order ids matching a compiled filter, optionally limited to $within.
	 *
	 * @param array{join:string,where:string} $sql
	 * @param list<int>|null                 $within
	 * @return list<int>
	 */
	public function matching_ids( array $sql, ?array $within = null, int $limit = 100000 ): array {
		if ( '' === $sql['where'] || ( null !== $within && array() === $within ) ) {
			return array();
		}
		$append = static function ( $clauses ) use ( $sql ) {
			$clauses['join']  = trim( ( $clauses['join'] ?? '' ) . ' ' . $sql['join'] );
			$clauses['where'] = ( $clauses['where'] ?? '1=1' ) . ' AND ' . $sql['where'];
			return $clauses;
		};
		$args   = array(
			'type'     => 'shop_order',
			'status'   => 'any',
			'limit'    => $limit,
			'return'   => 'ids',
			'orderby'  => 'ID',
			'order'    => 'ASC',
			'paginate' => false,
		);
		if ( null !== $within ) {
			$args['post__in'] = $within;
		}
		add_filter( 'woocommerce_orders_table_query_clauses', $append );
		$ids = Woo::call( 'wc_get_orders', $args );
		remove_filter( 'woocommerce_orders_table_query_clauses', $append );
		return array_values( array_map( 'intval', is_array( $ids ) ? $ids : array() ) );
	}

	public function can_edit_object( int $id ): bool {
		return self::can_edit( $id );
	}

	public function query_ids( array $sql, ?array $within, int $limit ): ?array {
		return $this->matching_ids( $sql, $within, $limit );
	}
}
