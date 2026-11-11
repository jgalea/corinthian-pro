<?php

namespace CorinthianPro\Integrations\WooCommerce;

use Corinthian\Columns\Registry;
use Corinthian\Screens\Screen;
use Corinthian\Sets\SetRepository;

defined( 'ABSPATH' ) || exit;

final class WooCommerceIntegration {

	public static function register(): void {
		add_action( 'corinthian_register_types', array( self::class, 'add_types' ) );
		add_filter( 'corinthian_screens', array( self::class, 'add_screens' ), 10, 3 );
	}

	public static function add_types( Registry $registry ): void {
		if ( ! self::is_active() ) {
			return;
		}
		foreach ( Catalog::types() as $type ) {
			$registry->add( $type );
		}
	}

	/**
	 * The HPOS order list is not a post list, so it needs a screen of its own. With
	 * orders stored as posts, the shop_order post screen already exists.
	 *
	 * @param array<string,Screen> $screens
	 * @return array<string,Screen>
	 */
	public static function add_screens( array $screens, Registry $registry, SetRepository $sets ): array {
		if ( self::is_active() && Woo::hpos() ) {
			$orders                    = new OrderScreen( $registry, $sets );
			$screens[ $orders->key() ] = $orders;
		}
		return $screens;
	}

	public static function is_active(): bool {
		return (bool) apply_filters( 'corinthian_woocommerce_active', Woo::active() );
	}
}
