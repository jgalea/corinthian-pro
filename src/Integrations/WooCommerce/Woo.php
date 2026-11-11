<?php

namespace CorinthianPro\Integrations\WooCommerce;

use Corinthian\Screens\Screen;

defined( 'ABSPATH' ) || exit;

/**
 * The only place that calls WooCommerce. Calls go through call_user_func so the
 * plugin analyses cleanly without WooCommerce's stubs; objects come back as mixed.
 */
final class Woo {

	public static function call( string $name, mixed ...$args ): mixed {
		return \call_user_func_array( $name, $args );
	}

	public static function active(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}

	public static function hpos(): bool {
		$util = 'Automattic\WooCommerce\Utilities\OrderUtil';
		return class_exists( $util ) && (bool) \call_user_func( array( $util, 'custom_orders_table_usage_is_enabled' ) );
	}

	/**
	 * Which kind of WooCommerce list a screen is: product, order, coupon, or '' for any other.
	 */
	public static function scope_of( Screen $screen ): string {
		if ( $screen instanceof OrderScreen ) {
			return 'order';
		}
		if ( 'post' !== $screen->object_type() ) {
			return '';
		}
		return match ( $screen->subtype() ) {
			'product'     => 'product',
			'shop_order'  => 'order',
			'shop_coupon' => 'coupon',
			default       => '',
		};
	}

	/**
	 * Column holding the row id in the screen's main table.
	 */
	public static function id_col( Screen $screen ): string {
		global $wpdb;
		return $screen instanceof OrderScreen ? self::orders_table() . '.id' : "{$wpdb->posts}.ID";
	}

	/**
	 * Per-object capability, the same rule WooCommerce applies in its own editors and REST API.
	 */
	public static function can_edit( string $scope, int $id ): bool {
		// phpcs:disable WordPress.WP.Capabilities.Unknown -- WooCommerce registers these capabilities.
		return match ( $scope ) {
			'order'  => current_user_can( 'edit_shop_orders' ) && current_user_can( 'edit_shop_order', $id ),
			'coupon' => current_user_can( 'edit_shop_coupon', $id ),
			default  => current_user_can( 'edit_product', $id ),
		};
		// phpcs:enable WordPress.WP.Capabilities.Unknown
	}

	public static function product( int $id ): mixed {
		if ( $id <= 0 || 'product' !== get_post_type( $id ) ) {
			return null;
		}
		$product = self::call( 'wc_get_product', $id );
		return is_object( $product ) ? $product : null;
	}

	public static function order( int $id ): mixed {
		if ( $id <= 0 ) {
			return null;
		}
		$order = self::call( 'wc_get_order', $id );
		return is_object( $order ) && 'shop_order' === $order->get_type() ? $order : null;
	}

	public static function coupon( int $id ): mixed {
		if ( $id <= 0 || 'shop_coupon' !== get_post_type( $id ) ) {
			return null;
		}
		$class = 'WC_Coupon';
		return class_exists( $class ) ? new $class( $id ) : null;
	}

	public static function orders_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wc_orders';
	}

	public static function operational_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wc_order_operational_data';
	}

	public static function addresses_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wc_order_addresses';
	}

	public static function lookup_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wc_product_meta_lookup';
	}

	/**
	 * Order statuses without the "wc-" prefix: slug => label.
	 *
	 * @return array<string,string>
	 */
	public static function order_statuses(): array {
		$out = array();
		foreach ( (array) self::call( 'wc_get_order_statuses' ) as $key => $label ) {
			$out[ preg_replace( '/^wc-/', '', (string) $key ) ] = (string) $label;
		}
		return $out;
	}

	/**
	 * @return array<string,string>
	 */
	public static function map( string $callback ): array {
		return function_exists( $callback ) ? array_map( 'strval', (array) self::call( $callback ) ) : array();
	}

	public static function price( mixed $amount ): string {
		return wp_kses_post( (string) self::call( 'wc_price', (float) $amount ) );
	}

	public static function decimals(): int {
		return (int) self::call( 'wc_get_price_decimals' );
	}

	public static function dash(): string {
		return '<span aria-hidden="true">&#8212;</span>';
	}

	public static function yes_no( bool $on ): string {
		return $on
			? '<span class="dashicons dashicons-yes" aria-label="' . esc_attr__( 'Yes', 'corinthian-pro' ) . '"></span>'
			: '<span class="dashicons dashicons-no-alt" aria-label="' . esc_attr__( 'No', 'corinthian-pro' ) . '"></span>';
	}
}
