<?php

namespace CorinthianPro\Integrations\WooCommerce;

use Corinthian\Screens\Screen;
use WP_Error;

final class ProductColumns {

	/**
	 * @return list<array{0:string,1:Spec}>
	 */
	public static function specs(): array {
		$stock_status = static fn(): array => Woo::map( 'wc_get_product_stock_status_options' );
		$visibility   = static fn(): array => Woo::map( 'wc_get_product_visibility_options' );
		$classes      = static function (): array {
			$out = array( '0' => __( 'No shipping class', 'corinthian-pro' ) );
			foreach ( self::shipping_classes() as $term ) {
				$out[ $term->slug ] = $term->name;
			}
			return $out;
		};
		$class_ids    = static function (): array {
			$out = array( '0' => __( 'No shipping class', 'corinthian-pro' ) );
			foreach ( self::shipping_classes() as $term ) {
				$out[ (string) $term->term_id ] = $term->name;
			}
			return $out;
		};
		$tax_status   = array(
			'taxable'  => __( 'Taxable', 'corinthian-pro' ),
			'shipping' => __( 'Shipping only', 'corinthian-pro' ),
			'none'     => __( 'None', 'corinthian-pro' ),
		);
		$price        = static fn( mixed $value ): string => Woo::price( $value );

		return array(
			array(
				'SFE',
				new Spec(
					key: 'woo.sku',
					label: __( 'SKU', 'corinthian-pro' ),
					scope: 'product',
					value: static fn( mixed $p ) => $p->get_sku( 'edit' ),
					column: Catalog::lookup( 'sku' ),
					edit: new Edit(
						input: 'text',
						value: static fn( mixed $p ) => $p->get_sku( 'edit' ),
						apply: static function ( mixed $p, mixed $value ): ?WP_Error {
							$sku = trim( sanitize_text_field( (string) $value ) );
							if ( strlen( $sku ) > 100 ) {
								return Catalog::invalid( __( 'The SKU is too long.', 'corinthian-pro' ) );
							}
							$p->set_sku( $sku );
							return null;
						}
					)
				),
			),
			array(
				'SFE',
				new Spec(
					key: 'woo.regular_price',
					label: __( 'Regular price', 'corinthian-pro' ),
					scope: 'product',
					value: static fn( mixed $p ) => self::list_price( $p, 'regular' ),
					html: $price,
					column: Catalog::meta( '_regular_price', 'number' ),
					kind: 'number',
					edit: new Edit(
						input: 'number',
						value: static fn( mixed $p ) => $p->get_regular_price( 'edit' ),
						apply: self::price_apply( 'regular' ),
						bulk: array( 'clear', 'adjust_percent' )
					)
				),
			),
			array(
				'SFE',
				new Spec(
					key: 'woo.sale_price',
					label: __( 'Sale price', 'corinthian-pro' ),
					scope: 'product',
					value: static fn( mixed $p ) => self::list_price( $p, 'sale' ),
					html: $price,
					column: Catalog::meta( '_sale_price', 'number' ),
					kind: 'number',
					edit: new Edit(
						input: 'number',
						value: static fn( mixed $p ) => $p->get_sale_price( 'edit' ),
						apply: self::price_apply( 'sale' ),
						bulk: array( 'clear', 'adjust_percent' )
					)
				),
			),
			array(
				'FE',
				new Spec(
					key: 'woo.stock_status',
					label: __( 'Stock status', 'corinthian-pro' ),
					scope: 'product',
					value: static fn( mixed $p ) => $p->get_stock_status( 'edit' ),
					html: static fn( mixed $v ) => esc_html( Woo::map( 'wc_get_product_stock_status_options' )[ (string) $v ] ?? (string) $v ),
					column: Catalog::lookup( 'stock_status' ),
					kind: 'select',
					options: $stock_status,
					edit: new Edit(
						input: 'select',
						value: static fn( mixed $p ) => $p->get_stock_status( 'edit' ),
						apply: static function ( mixed $p, mixed $value ) use ( $stock_status ): ?WP_Error {
							if ( ! $p->is_type( 'simple' ) ) {
								return Catalog::invalid( __( 'Only simple products take a stock status here.', 'corinthian-pro' ) );
							}
							if ( $p->get_manage_stock( 'edit' ) ) {
								return Catalog::invalid( __( 'Stock status follows the quantity while stock is managed.', 'corinthian-pro' ) );
							}
							if ( ! isset( $stock_status()[ (string) $value ] ) ) {
								return Catalog::invalid( __( 'Choose a valid stock status.', 'corinthian-pro' ) );
							}
							$p->set_stock_status( (string) $value );
							return null;
						},
						bulk: array( 'set' ),
						options: $stock_status
					)
				),
			),
			array(
				'SFE',
				new Spec(
					key: 'woo.stock_quantity',
					label: __( 'Stock quantity', 'corinthian-pro' ),
					scope: 'product',
					value: static fn( mixed $p ) => $p->get_manage_stock( 'edit' ) ? $p->get_stock_quantity( 'edit' ) : null,
					column: Catalog::lookup( 'stock_quantity' ),
					kind: 'number',
					edit: new Edit(
						input: 'number',
						value: static fn( mixed $p ) => $p->get_manage_stock( 'edit' ) ? $p->get_stock_quantity( 'edit' ) : '',
						apply: static function ( mixed $p, mixed $value ): ?WP_Error {
							if ( ! $p->is_type( array( 'simple', 'variable' ) ) ) {
								return Catalog::invalid( __( 'This product type has no stock quantity.', 'corinthian-pro' ) );
							}
							if ( ! $p->get_manage_stock( 'edit' ) ) {
								return Catalog::invalid( __( 'Turn on Manage stock first.', 'corinthian-pro' ) );
							}
							$text = trim( (string) $value );
							if ( ! preg_match( '/^-?\d{1,9}$/', $text ) ) {
								return Catalog::invalid( __( 'Enter a whole number.', 'corinthian-pro' ) );
							}
							$p->set_stock_quantity( (int) $text );
							return null;
						},
						bulk: array( 'set' )
					)
				),
			),
			array(
				'E',
				new Spec(
					key: 'woo.manage_stock',
					label: __( 'Manage stock', 'corinthian-pro' ),
					scope: 'product',
					value: static fn( mixed $p ) => $p->get_manage_stock( 'edit' ) ? '1' : '0',
					html: static fn( mixed $v ) => Woo::yes_no( '1' === $v ),
					edit: new Edit(
						input: 'toggle',
						value: static fn( mixed $p ) => $p->get_manage_stock( 'edit' ) ? '1' : '0',
						apply: static function ( mixed $p, mixed $value ): ?WP_Error {
							if ( ! $p->is_type( array( 'simple', 'variable' ) ) ) {
								return Catalog::invalid( __( 'This product type has no stock to manage.', 'corinthian-pro' ) );
							}
							$on = Catalog::truthy( $value );
							$p->set_manage_stock( $on );
							if ( $on && null === $p->get_stock_quantity( 'edit' ) ) {
								$p->set_stock_quantity( 0 );
							}
							return null;
						}
					)
				),
			),
			array(
				'F',
				new Spec(
					key: 'woo.product_type',
					label: __( 'Product type', 'corinthian-pro' ),
					scope: 'product',
					value: static fn( mixed $p ) => $p->get_type(),
					html: static fn( mixed $v ) => esc_html( Woo::map( 'wc_get_product_types' )[ (string) $v ] ?? (string) $v ),
					kind: 'select',
					options: static fn(): array => Woo::map( 'wc_get_product_types' ),
					condition: static function ( string $op, mixed $value, Screen $screen ): ?array {
						$slugs = self::list( $value );
						if ( ! $slugs || ! in_array( $op, array( 'in', 'not_in' ), true ) ) {
							return null;
						}
						$has = Sql::has_term( Woo::id_col( $screen ), 'product_type', $slugs );
						return array(
							'join'  => '',
							'where' => 'in' === $op ? $has : "NOT {$has}",
						);
					}
				),
			),
			array(
				'FE',
				new Spec(
					key: 'woo.visibility',
					label: __( 'Catalog visibility', 'corinthian-pro' ),
					scope: 'product',
					value: static fn( mixed $p ) => $p->get_catalog_visibility( 'edit' ),
					html: static fn( mixed $v ) => esc_html( Woo::map( 'wc_get_product_visibility_options' )[ (string) $v ] ?? (string) $v ),
					kind: 'select',
					options: $visibility,
					condition: static function ( string $op, mixed $value, Screen $screen ): ?array {
						$wanted = array_values( array_intersect( self::list( $value ), array( 'visible', 'catalog', 'search', 'hidden' ) ) );
						if ( ! $wanted || ! in_array( $op, array( 'in', 'not_in' ), true ) ) {
							return null;
						}
						$id      = Woo::id_col( $screen );
						$no_cat  = Sql::has_term( $id, 'product_visibility', array( 'exclude-from-catalog' ) );
						$no_find = Sql::has_term( $id, 'product_visibility', array( 'exclude-from-search' ) );
						$map     = array(
							'visible' => "(NOT {$no_cat} AND NOT {$no_find})",
							'catalog' => "(NOT {$no_cat} AND {$no_find})",
							'search'  => "({$no_cat} AND NOT {$no_find})",
							'hidden'  => "({$no_cat} AND {$no_find})",
						);
						$any     = '(' . implode( ' OR ', array_map( static fn( $v ) => $map[ $v ], $wanted ) ) . ')';
						return array(
							'join'  => '',
							'where' => 'in' === $op ? $any : "NOT {$any}",
						);
					},
					edit: new Edit(
						input: 'select',
						value: static fn( mixed $p ) => $p->get_catalog_visibility( 'edit' ),
						apply: static function ( mixed $p, mixed $value ) use ( $visibility ): ?WP_Error {
							if ( ! isset( $visibility()[ (string) $value ] ) ) {
								return Catalog::invalid( __( 'Choose a valid visibility.', 'corinthian-pro' ) );
							}
							$p->set_catalog_visibility( (string) $value );
							return null;
						},
						bulk: array( 'set' ),
						options: $visibility
					)
				),
			),
			array(
				'FE',
				new Spec(
					key: 'woo.featured',
					label: __( 'Featured', 'corinthian-pro' ),
					scope: 'product',
					value: static fn( mixed $p ) => $p->get_featured( 'edit' ) ? '1' : '0',
					html: static fn( mixed $v ) => Woo::yes_no( '1' === $v ),
					kind: 'bool',
					condition: static function ( string $op, mixed $value, Screen $screen ): ?array {
						if ( 'eq' !== $op || ! in_array( $value, array( '0', '1' ), true ) ) {
							return null;
						}
						$has = Sql::has_term( Woo::id_col( $screen ), 'product_visibility', array( 'featured' ) );
						return array(
							'join'  => '',
							'where' => '1' === $value ? $has : "NOT {$has}",
						);
					},
					edit: new Edit(
						input: 'toggle',
						value: static fn( mixed $p ) => $p->get_featured( 'edit' ) ? '1' : '0',
						apply: static function ( mixed $p, mixed $value ): ?WP_Error {
							$p->set_featured( Catalog::truthy( $value ) );
							return null;
						},
						bulk: array( 'set' )
					)
				),
			),
			array(
				'SF',
				new Spec(
					key: 'woo.total_sales',
					label: __( 'Total sales', 'corinthian-pro' ),
					scope: 'product',
					value: static fn( mixed $p ) => (int) $p->get_total_sales( 'edit' ),
					column: Catalog::lookup( 'total_sales' ),
					kind: 'number'
				),
			),
			array(
				'S',
				new Spec(
					key: 'woo.weight',
					label: __( 'Weight', 'corinthian-pro' ),
					scope: 'product',
					value: static fn( mixed $p ) => $p->get_weight( 'edit' ),
					html: static fn( mixed $v ) => esc_html( trim( $v . ' ' . (string) get_option( 'woocommerce_weight_unit' ) ) ),
					column: Catalog::meta( '_weight', 'number' ),
					kind: 'number'
				),
			),
			array(
				'',
				new Spec(
					key: 'woo.dimensions',
					label: __( 'Dimensions', 'corinthian-pro' ),
					scope: 'product',
					value: static fn( mixed $p ) => $p->has_dimensions() ? Woo::call( 'wc_format_dimensions', $p->get_dimensions( false ) ) : null
				),
			),
			array(
				'FE',
				new Spec(
					key: 'woo.shipping_class',
					label: __( 'Shipping class', 'corinthian-pro' ),
					scope: 'product',
					value: static fn( mixed $p ) => $p->get_shipping_class() ? $p->get_shipping_class() : null,
					html: static function ( mixed $v ): string {
						$term = get_term_by( 'slug', (string) $v, 'product_shipping_class' );
						return esc_html( $term instanceof \WP_Term ? $term->name : (string) $v );
					},
					kind: 'select',
					options: $classes,
					condition: static function ( string $op, mixed $value, Screen $screen ): ?array {
						$slugs = self::list( $value );
						if ( ! $slugs || ! in_array( $op, array( 'in', 'not_in' ), true ) ) {
							return null;
						}
						$id    = Woo::id_col( $screen );
						$parts = array();
						if ( in_array( '0', $slugs, true ) ) {
							$parts[] = 'NOT ' . Sql::has_term( $id, 'product_shipping_class' );
						}
						$named = array_values( array_diff( $slugs, array( '0' ) ) );
						if ( $named ) {
							$parts[] = Sql::has_term( $id, 'product_shipping_class', $named );
						}
						$any = '(' . implode( ' OR ', $parts ) . ')';
						return array(
							'join'  => '',
							'where' => 'in' === $op ? $any : "NOT {$any}",
						);
					},
					edit: new Edit(
						input: 'select',
						value: static fn( mixed $p ) => (string) $p->get_shipping_class_id( 'edit' ),
						apply: static function ( mixed $p, mixed $value ): ?WP_Error {
							$id = (string) $value;
							if ( ! ctype_digit( $id ) || ( '0' !== $id && ! term_exists( (int) $id, 'product_shipping_class' ) ) ) {
								return Catalog::invalid( __( 'Choose a valid shipping class.', 'corinthian-pro' ) );
							}
							$p->set_shipping_class_id( (int) $id );
							return null;
						},
						options: $class_ids
					)
				),
			),
			array(
				'',
				new Spec(
					key: 'woo.tax_status',
					label: __( 'Tax status', 'corinthian-pro' ),
					scope: 'product',
					value: static fn( mixed $p ) => $p->get_tax_status( 'edit' ),
					html: static fn( mixed $v ) => esc_html( $tax_status[ (string) $v ] ?? (string) $v )
				),
			),
			array(
				'',
				new Spec(
					key: 'woo.tax_class',
					label: __( 'Tax class', 'corinthian-pro' ),
					scope: 'product',
					value: static fn( mixed $p ) => '' === (string) $p->get_tax_class( 'edit' ) ? 'standard' : (string) $p->get_tax_class( 'edit' ),
					html: static function ( mixed $v ): string {
						$all = Woo::map( 'wc_get_product_tax_class_options' );
						$key = 'standard' === $v ? '' : (string) $v;
						return esc_html( $all[ $key ] ?? (string) $v );
					}
				),
			),
			array(
				'S',
				new Spec(
					key: 'woo.average_rating',
					label: __( 'Average rating', 'corinthian-pro' ),
					scope: 'product',
					value: static fn( mixed $p ) => (int) $p->get_rating_count() > 0 ? (float) $p->get_average_rating( 'edit' ) : null,
					html: static fn( mixed $v ) => esc_html( number_format_i18n( (float) $v, 2 ) ),
					column: static function ( Screen $screen, string $alias ): array {
						$sql = Catalog::lookup( 'average_rating' )( $screen, $alias );
						// Unrated products store 0; they sort and filter as having no rating.
						$sql['col'] = "NULLIF({$sql['col']}, 0)";
						return $sql;
					},
					kind: 'number'
				),
			),
			array(
				'S',
				new Spec(
					key: 'woo.review_count',
					label: __( 'Reviews', 'corinthian-pro' ),
					scope: 'product',
					value: static fn( mixed $p ) => (int) $p->get_review_count( 'edit' ),
					column: Catalog::meta( '_wc_review_count', 'number' ),
					kind: 'number'
				),
			),
			array(
				'',
				new Spec(
					key: 'woo.purchase_note',
					label: __( 'Purchase note', 'corinthian-pro' ),
					scope: 'product',
					value: static fn( mixed $p ) => trim( (string) $p->get_purchase_note( 'edit' ) ),
					html: static fn( mixed $v ) => esc_html( wp_trim_words( wp_strip_all_tags( (string) $v ), 20 ) )
				),
			),
			array(
				'',
				new Spec(
					key: 'woo.variations',
					label: __( 'Variations', 'corinthian-pro' ),
					scope: 'product',
					value: static fn( mixed $p ) => $p->is_type( 'variable' ) ? count( $p->get_children() ) : null
				),
			),
		);
	}

	/**
	 * @return list<\WP_Term>
	 */
	private static function shipping_classes(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_shipping_class',
				'hide_empty' => false,
			)
		);
		return is_array( $terms ) ? array_values( array_filter( $terms, static fn( $t ) => $t instanceof \WP_Term ) ) : array();
	}

	/**
	 * @return list<string>
	 */
	private static function list( mixed $value ): array {
		$list = is_array( $value ) ? $value : array( $value );
		return array_values( array_filter( array_map( 'strval', $list ), static fn( $v ) => '' !== $v ) );
	}

	/**
	 * What the list shows: the product's own price, or the lowest variation price for a variable product.
	 */
	private static function list_price( mixed $p, string $which ): mixed {
		if ( $p->is_type( 'variable' ) ) {
			$min = 'regular' === $which ? $p->get_variation_regular_price( 'min' ) : $p->get_variation_sale_price( 'min' );
			return '' === (string) $min ? null : $min;
		}
		$value = 'regular' === $which ? $p->get_regular_price( 'edit' ) : $p->get_sale_price( 'edit' );
		return '' === (string) $value ? null : $value;
	}

	/**
	 * Sets the regular or sale price. "adjust_percent" moves the current value by a percentage.
	 *
	 * @return \Closure(mixed,mixed,string):(WP_Error|null)
	 */
	private static function price_apply( string $which ): \Closure {
		return static function ( mixed $p, mixed $value, string $mode ) use ( $which ): ?WP_Error {
			if ( ! $p->is_type( array( 'simple', 'external' ) ) ) {
				return Catalog::invalid( __( 'Set prices on each variation of this product.', 'corinthian-pro' ) );
			}
			$regular = (string) $p->get_regular_price( 'edit' );
			$sale    = (string) $p->get_sale_price( 'edit' );
			$current = 'regular' === $which ? $regular : $sale;

			if ( 'clear' === $mode ) {
				$new = '';
			} elseif ( 'adjust_percent' === $mode ) {
				if ( ! is_numeric( $value ) ) {
					return Catalog::invalid( __( 'Enter a percentage.', 'corinthian-pro' ) );
				}
				if ( '' === $current ) {
					return Catalog::invalid( __( 'There is no price to adjust.', 'corinthian-pro' ) );
				}
				$float = round( (float) $current * ( 1 + (float) $value / 100 ), Woo::decimals() );
				if ( $float < 0 ) {
					return Catalog::invalid( __( 'The price would go below zero.', 'corinthian-pro' ) );
				}
				$new = (string) $float;
			} else {
				$new = trim( (string) $value );
				if ( '' !== $new && ( ! is_numeric( $new ) || (float) $new < 0 ) ) {
					return Catalog::invalid( __( 'Enter a price of zero or more.', 'corinthian-pro' ) );
				}
			}
			if ( '' !== $new ) {
				$new = (string) Woo::call( 'wc_format_decimal', $new );
			}

			if ( 'regular' === $which ) {
				if ( '' !== $new && '' !== $sale && (float) $new < (float) $sale ) {
					return Catalog::invalid( __( 'The regular price cannot be below the sale price.', 'corinthian-pro' ) );
				}
				$p->set_regular_price( $new );
			} else {
				if ( '' !== $new && ( '' === $regular || (float) $new > (float) $regular ) ) {
					return Catalog::invalid( __( 'The sale price cannot be above the regular price.', 'corinthian-pro' ) );
				}
				$p->set_sale_price( $new );
			}
			return null;
		};
	}
}
