<?php

namespace CorinthianPro\Integrations\WooCommerce;

use WP_Error;

final class CouponColumns {

	/**
	 * @return list<array{0:string,1:Spec}>
	 */
	public static function specs(): array {
		return array(
			array(
				'',
				new Spec(
					key: 'woo.coupon_code',
					label: __( 'Coupon code', 'corinthian-pro' ),
					scope: 'coupon',
					value: static fn( mixed $c ) => $c->get_code()
				),
			),
			array(
				'',
				new Spec(
					key: 'woo.coupon_type',
					label: __( 'Discount type', 'corinthian-pro' ),
					scope: 'coupon',
					value: static fn( mixed $c ) => $c->get_discount_type( 'edit' ),
					html: static fn( mixed $v ) => esc_html( Woo::map( 'wc_get_coupon_types' )[ (string) $v ] ?? (string) $v )
				),
			),
			array(
				'E',
				new Spec(
					key: 'woo.coupon_amount',
					label: __( 'Coupon amount', 'corinthian-pro' ),
					scope: 'coupon',
					value: static fn( mixed $c ) => $c->get_amount( 'edit' ),
					html: static fn( mixed $v, mixed $c ) => 'percent' === $c->get_discount_type( 'edit' ) ? esc_html( $v . '%' ) : Woo::price( $v ),
					edit: new Edit(
						input: 'number',
						value: static fn( mixed $c ) => $c->get_amount( 'edit' ),
						apply: static function ( mixed $c, mixed $value ): ?WP_Error {
							$amount = trim( (string) $value );
							if ( '' === $amount || ! is_numeric( $amount ) || (float) $amount < 0 ) {
								return Catalog::invalid( __( 'Enter an amount of zero or more.', 'corinthian-pro' ) );
							}
							if ( str_starts_with( (string) $c->get_discount_type( 'edit' ), 'percent' ) && (float) $amount > 100 ) {
								return Catalog::invalid( __( 'A percentage discount cannot go above 100.', 'corinthian-pro' ) );
							}
							$c->set_amount( Woo::call( 'wc_format_decimal', $amount ) );
							return null;
						}
					)
				),
			),
			array(
				'',
				new Spec(
					key: 'woo.coupon_usage',
					label: __( 'Usage / limit', 'corinthian-pro' ),
					scope: 'coupon',
					value: static fn( mixed $c ) => (int) $c->get_usage_count( 'edit' ) . ' / ' . ( (int) $c->get_usage_limit( 'edit' ) > 0 ? (int) $c->get_usage_limit( 'edit' ) : "\u{221E}" )
				),
			),
			array(
				'SFE',
				new Spec(
					key: 'woo.coupon_expiry',
					label: __( 'Expiry date', 'corinthian-pro' ),
					scope: 'coupon',
					value: static fn( mixed $c ) => self::expiry( $c ),
					column: Catalog::meta( 'date_expires', 'ts' ),
					kind: 'date',
					edit: new Edit(
						input: 'date',
						value: static fn( mixed $c ) => self::expiry( $c ) ?? '',
						apply: static function ( mixed $c, mixed $value, string $mode ): ?WP_Error {
							$day = trim( (string) $value );
							if ( 'clear' === $mode || '' === $day ) {
								$c->set_date_expires( null );
								return null;
							}
							$parts = array_map( 'intval', explode( '-', $day ) );
							if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) || ! checkdate( $parts[1], $parts[2], $parts[0] ) ) {
								return Catalog::invalid( __( 'Enter a date as YYYY-MM-DD.', 'corinthian-pro' ) );
							}
							$c->set_date_expires( ( new \DateTimeImmutable( $day . ' 00:00:00', wp_timezone() ) )->getTimestamp() );
							return null;
						},
						bulk: array( 'clear' )
					)
				),
			),
			array(
				'',
				new Spec(
					key: 'woo.coupon_individual',
					label: __( 'Individual use', 'corinthian-pro' ),
					scope: 'coupon',
					value: static fn( mixed $c ) => $c->get_individual_use( 'edit' ) ? '1' : '0',
					html: static fn( mixed $v ) => Woo::yes_no( '1' === $v )
				),
			),
			array(
				'',
				new Spec(
					key: 'woo.coupon_free_shipping',
					label: __( 'Free shipping', 'corinthian-pro' ),
					scope: 'coupon',
					value: static fn( mixed $c ) => $c->get_free_shipping( 'edit' ) ? '1' : '0',
					html: static fn( mixed $v ) => Woo::yes_no( '1' === $v )
				),
			),
		);
	}

	private static function expiry( mixed $coupon ): ?string {
		$date = $coupon->get_date_expires( 'edit' );
		return is_object( $date ) ? $date->date( 'Y-m-d' ) : null;
	}
}
