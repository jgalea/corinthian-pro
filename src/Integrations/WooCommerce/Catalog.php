<?php

namespace CorinthianPro\Integrations\WooCommerce;

use Corinthian\Screens\Screen;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Every WooCommerce column type, and the small helpers the column lists share.
 */
final class Catalog {

	/**
	 * @return list<Column>
	 */
	public static function types(): array {
		$out = array();
		foreach ( array_merge( ProductColumns::specs(), OrderColumns::specs(), CouponColumns::specs() ) as [ $caps, $spec ] ) {
			$out[] = self::make( $caps, $spec );
		}
		return $out;
	}

	/**
	 * $caps lists what the column does: S sorts, F filters, E edits.
	 */
	public static function make( string $caps, Spec $spec ): Column {
		return match ( $caps ) {
			'S'     => new SortColumn( $spec ),
			'F'     => new FilterColumn( $spec ),
			'E'     => new EditColumn( $spec ),
			'SF'    => new SortFilterColumn( $spec ),
			'FE'    => new FilterEditColumn( $spec ),
			'SFE'   => new FullColumn( $spec ),
			default => new ReadColumn( $spec ),
		};
	}

	/**
	 * SQL for a column of wc_product_meta_lookup, joined 1:1 on the product id.
	 *
	 * @return \Closure(Screen,string):array{join:string,col:string}
	 */
	public static function lookup( string $column ): \Closure {
		return static function ( Screen $screen, string $alias ) use ( $column ): array {
			global $wpdb;
			$table = Woo::lookup_table();
			return array(
				'join' => " LEFT JOIN {$table} AS {$alias} ON {$alias}.product_id = {$wpdb->posts}.ID",
				'col'  => "{$alias}.{$column}",
			);
		};
	}

	/**
	 * SQL for one post meta key of the post-table screens.
	 *
	 * @return \Closure(Screen,string):array{join:string,col:string,ts:bool}
	 */
	public static function meta( string $key, string $cast = 'text' ): \Closure {
		return static function ( Screen $screen ) use ( $key, $cast ): array {
			return array(
				'join' => '',
				'col'  => Sql::meta( Woo::id_col( $screen ), $key, $cast ),
				'ts'   => 'ts' === $cast,
			);
		};
	}

	public static function invalid( string $message ): WP_Error {
		return new WP_Error( 'corinthian_invalid', $message );
	}

	public static function truthy( mixed $value ): bool {
		return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
	}
}
