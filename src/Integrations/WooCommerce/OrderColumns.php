<?php

namespace CorinthianPro\Integrations\WooCommerce;

use Corinthian\Screens\Screen;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Order columns work on both order storages: the HPOS tables on the Orders screen
 * and posts plus meta on the legacy shop_order screen.
 */
final class OrderColumns {

	/**
	 * @return list<array{0:string,1:Spec}>
	 */
	public static function specs(): array {
		$statuses = static fn(): array => Woo::order_statuses();
		$date     = static fn( mixed $v, mixed $o ): string => esc_html( (string) Woo::call( 'wc_format_datetime', $o->get_date_paid() ) );

		return array(
			array(
				'FE',
				new Spec(
					key: 'woo.order_status',
					label: __( 'Order status', 'corinthian-pro' ),
					scope: 'order',
					value: static fn( mixed $o ) => $o->get_status( 'edit' ),
					html: static fn( mixed $v ) => esc_html( (string) Woo::call( 'wc_get_order_status_name', (string) $v ) ),
					kind: 'select',
					options: $statuses,
					condition: static function ( string $op, mixed $value, Screen $screen ) use ( $statuses ): ?array {
						$list = array_values( array_intersect( array_map( 'strval', is_array( $value ) ? $value : array( $value ) ), array_keys( $statuses() ) ) );
						if ( ! $list ) {
							return null;
						}
						$col   = self::sql( 'orders.status', 'post.post_status' )( $screen, 'unused' )['col'];
						$where = Sql::in( $col, $op, array_map( static fn( $s ) => 'wc-' . $s, $list ) );
						return null === $where ? null : array(
							'join'  => '',
							'where' => $where,
						);
					},
					edit: new Edit(
						input: 'select',
						value: static fn( mixed $o ) => $o->get_status( 'edit' ),
						apply: static function ( mixed $o, mixed $value ) use ( $statuses ): ?WP_Error {
							$status = (string) $value;
							if ( ! isset( $statuses()[ $status ] ) ) {
								return Catalog::invalid( __( 'Choose a valid order status.', 'corinthian-pro' ) );
							}
							if ( $o->get_status( 'edit' ) !== $status ) {
								$o->update_status( $status, __( 'Order status changed from the Corinthian list.', 'corinthian-pro' ), true );
							}
							return null;
						},
						bulk: array( 'set' ),
						options: $statuses,
						saves: true
					)
				),
			),
			array(
				'SF',
				new Spec(
					key: 'woo.order_total',
					label: __( 'Order total', 'corinthian-pro' ),
					scope: 'order',
					value: static fn( mixed $o ) => (float) $o->get_total( 'edit' ),
					html: static fn( mixed $v, mixed $o ) => wp_kses_post( (string) $o->get_formatted_order_total() ),
					column: self::sql( 'orders.total_amount', '_order_total', 'number' ),
					kind: 'number'
				),
			),
			array(
				'F',
				new Spec(
					key: 'woo.payment_method',
					label: __( 'Payment method', 'corinthian-pro' ),
					scope: 'order',
					value: static fn( mixed $o ) => $o->get_payment_method( 'edit' ),
					html: static fn( mixed $v, mixed $o ) => esc_html( $o->get_payment_method_title( 'edit' ) ? $o->get_payment_method_title( 'edit' ) : (string) $v ),
					column: self::sql( 'orders.payment_method', '_payment_method' ),
					kind: 'select',
					options: static function (): array {
						$out = array();
						foreach ( (array) Woo::call( 'WC' )->payment_gateways()->payment_gateways() as $id => $gateway ) {
							$out[ (string) $id ] = wp_strip_all_tags( (string) $gateway->get_method_title() );
						}
						return $out;
					}
				),
			),
			array(
				'F',
				new Spec(
					key: 'woo.billing_email',
					label: __( 'Billing email', 'corinthian-pro' ),
					scope: 'order',
					value: static fn( mixed $o ) => $o->get_billing_email( 'edit' ),
					column: self::sql( 'orders.billing_email', '_billing_email' )
				),
			),
			array(
				'F',
				new Spec(
					key: 'woo.billing_name',
					label: __( 'Billing name', 'corinthian-pro' ),
					scope: 'order',
					value: static fn( mixed $o ) => trim( $o->get_billing_first_name( 'edit' ) . ' ' . $o->get_billing_last_name( 'edit' ) ),
					column: static function ( Screen $screen, string $alias ): array {
						if ( $screen instanceof OrderScreen ) {
							$sql = self::sql( 'billing.first_name', '' )( $screen, $alias );
							return array(
								'join' => $sql['join'],
								'col'  => "TRIM(CONCAT_WS(' ', {$alias}.first_name, {$alias}.last_name))",
							);
						}
						$first = self::sql( '', '_billing_first_name' )( $screen, $alias )['col'];
						$last  = self::sql( '', '_billing_last_name' )( $screen, $alias )['col'];
						return array(
							'join' => '',
							'col'  => "TRIM(CONCAT_WS(' ', {$first}, {$last}))",
						);
					}
				),
			),
			array(
				'F',
				new Spec(
					key: 'woo.billing_country',
					label: __( 'Billing country', 'corinthian-pro' ),
					scope: 'order',
					value: static fn( mixed $o ) => $o->get_billing_country( 'edit' ),
					html: static fn( mixed $v ) => esc_html( (string) ( Woo::call( 'WC' )->countries->get_countries()[ (string) $v ] ?? $v ) ),
					column: self::sql( 'billing.country', '_billing_country' ),
					kind: 'select',
					options: static fn(): array => array_map( 'strval', (array) Woo::call( 'WC' )->countries->get_countries() )
				),
			),
			array(
				'',
				new Spec(
					key: 'woo.shipping_method',
					label: __( 'Shipping method', 'corinthian-pro' ),
					scope: 'order',
					value: static fn( mixed $o ) => $o->get_shipping_method()
				),
			),
			array(
				'',
				new Spec(
					key: 'woo.items_count',
					label: __( 'Items', 'corinthian-pro' ),
					scope: 'order',
					value: static fn( mixed $o ) => (int) $o->get_item_count()
				),
			),
			array(
				'',
				new Spec(
					key: 'woo.order_coupons',
					label: __( 'Coupons used', 'corinthian-pro' ),
					scope: 'order',
					value: static fn( mixed $o ) => implode( ', ', (array) $o->get_coupon_codes() )
				),
			),
			array(
				'E',
				new Spec(
					key: 'woo.customer_note',
					label: __( 'Customer note', 'corinthian-pro' ),
					scope: 'order',
					value: static fn( mixed $o ) => trim( (string) $o->get_customer_note( 'edit' ) ),
					html: static fn( mixed $v ) => esc_html( wp_trim_words( (string) $v, 30 ) ),
					edit: new Edit(
						input: 'textarea',
						value: static fn( mixed $o ) => (string) $o->get_customer_note( 'edit' ),
						apply: static function ( mixed $o, mixed $value ): ?WP_Error {
							$note = sanitize_textarea_field( (string) $value );
							if ( strlen( $note ) > 5000 ) {
								return Catalog::invalid( __( 'The note is too long.', 'corinthian-pro' ) );
							}
							$o->set_customer_note( $note );
							return null;
						}
					)
				),
			),
			array(
				'SF',
				new Spec(
					key: 'woo.date_paid',
					label: __( 'Date paid', 'corinthian-pro' ),
					scope: 'order',
					value: static fn( mixed $o ) => self::day( $o->get_date_paid( 'edit' ) ),
					html: $date,
					column: self::sql( 'op.date_paid_gmt', '_date_paid', 'ts' ),
					kind: 'date'
				),
			),
			array(
				'SF',
				new Spec(
					key: 'woo.date_completed',
					label: __( 'Date completed', 'corinthian-pro' ),
					scope: 'order',
					value: static fn( mixed $o ) => self::day( $o->get_date_completed( 'edit' ) ),
					html: static fn( mixed $v, mixed $o ): string => esc_html( (string) Woo::call( 'wc_format_datetime', $o->get_date_completed() ) ),
					column: self::sql( 'op.date_completed_gmt', '_date_completed', 'ts' ),
					kind: 'date'
				),
			),
			array(
				'F',
				new Spec(
					key: 'woo.created_via',
					label: __( 'Created via', 'corinthian-pro' ),
					scope: 'order',
					value: static fn( mixed $o ) => $o->get_created_via( 'edit' ),
					column: self::sql( 'op.created_via', '_created_via' )
				),
			),
		);
	}

	private static function day( mixed $date ): ?string {
		return is_object( $date ) ? $date->date( 'Y-m-d' ) : null;
	}

	/**
	 * SQL for one order field. $hpos is "source.column" where source is orders, op (operational data)
	 * or billing (billing address); $legacy is a post meta key, or "post.column" for a posts column.
	 * $cast: text, number, or ts (the legacy meta holds a unix timestamp).
	 *
	 * @return \Closure(Screen,string):array{join:string,col:string,ts?:bool}
	 */
	private static function sql( string $hpos, string $legacy, string $cast = 'text' ): \Closure {
		return static function ( Screen $screen, string $alias ) use ( $hpos, $legacy, $cast ): array {
			global $wpdb;
			if ( $screen instanceof OrderScreen ) {
				[ $source, $column ] = array_pad( explode( '.', $hpos, 2 ), 2, '' );
				$orders              = Woo::orders_table();
				switch ( $source ) {
					case 'op':
						return array(
							'join' => ' LEFT JOIN ' . Woo::operational_table() . " AS {$alias} ON {$alias}.order_id = {$orders}.id",
							'col'  => "{$alias}.{$column}",
						);
					case 'billing':
						return array(
							'join' => ' LEFT JOIN ' . Woo::addresses_table() . " AS {$alias} ON ({$alias}.order_id = {$orders}.id AND {$alias}.address_type = 'billing')",
							'col'  => "{$alias}.{$column}",
						);
				}
				return array(
					'join' => '',
					'col'  => "{$orders}.{$column}",
				);
			}
			if ( str_starts_with( $legacy, 'post.' ) ) {
				return array(
					'join' => '',
					'col'  => $wpdb->posts . '.' . substr( $legacy, 5 ),
				);
			}
			return array(
				'join' => '',
				'col'  => Sql::meta( "{$wpdb->posts}.ID", $legacy, $cast ),
				'ts'   => 'ts' === $cast,
			);
		};
	}
}
