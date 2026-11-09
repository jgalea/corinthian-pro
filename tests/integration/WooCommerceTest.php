<?php

namespace CorinthianPro\Tests;

use Corinthian\Columns\ColumnType;
use Corinthian\Columns\Config;
use Corinthian\Columns\Editable;
use Corinthian\Columns\Filterable;
use Corinthian\Columns\Registry;
use Corinthian\Columns\Sortable;
use CorinthianPro\Integrations\WooCommerce\OrderScreen;
use CorinthianPro\Integrations\WooCommerce\Woo;
use CorinthianPro\Integrations\WooCommerce\WooCommerceIntegration;
use Corinthian\Plugin;
use Corinthian\Screens\PostTypeScreen;
use Corinthian\Screens\Screen;
use Corinthian\Sets\SetRepository;
use WP_Error;
use WP_Query;
use WP_UnitTestCase;

class WooCommerceTest extends WP_UnitTestCase {

	private Registry $registry;
	private SetRepository $sets;
	private PostTypeScreen $products;
	private PostTypeScreen $coupons;
	private PostTypeScreen $legacy_orders;
	private OrderScreen $hpos_orders;

	private int $admin;
	private int $manager;
	private int $customer;

	/** @var array<string,int> */
	private array $p = array();
	/** @var array<string,int> */
	private array $o = array();
	/** @var array<string,int> */
	private array $c = array();

	private static bool $hooks_kept = false;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		if ( ! class_exists( 'WooCommerce' ) ) {
			$file = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
			if ( ! is_file( $file ) ) {
				self::markTestSkipped( 'WooCommerce is not installed. Run: wp plugin install woocommerce' );
			}
			require_once $file;
			// WordPress has already run plugins_loaded and init, so run WooCommerce's side of them now.
			\WC_Install::install();
			WC()->on_plugins_loaded();
			WC()->init();
			\WC_Post_Types::register_post_types();
			\WC_Post_Types::register_taxonomies();
			\WC_Post_Types::register_post_status();
		}
		WooCommerceIntegration::add_types( Plugin::instance()->registry );
	}

	public function set_up(): void {
		parent::set_up();
		if ( ! self::$hooks_kept ) {
			// The suite snapshots hooks once per run; when another class ran first,
			// the hooks WooCommerce added on boot would be wiped after every test.
			$this->_backup_hooks();
			self::$hooks_kept = true;
		}
		update_option( 'timezone_string', 'UTC' );
		if ( ! get_role( 'shop_manager' ) ) {
			// Another run's database reset can wipe the roles WooCommerce created.
			\WC_Install::create_roles();
		}
		$this->admin    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->manager  = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		$this->customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		wp_set_current_user( $this->admin );

		$this->registry = new Registry();
		WooCommerceIntegration::add_types( $this->registry );
		$this->sets          = new SetRepository();
		$this->products      = $this->post_screen( 'product' );
		$this->coupons       = $this->post_screen( 'shop_coupon' );
		$this->legacy_orders = $this->post_screen( 'shop_order' );
		$this->hpos_orders   = new OrderScreen( $this->registry, $this->sets );
	}

	public function tear_down(): void {
		unset( $_GET['corinthian_filter'], $_GET['orderby'], $_GET['order'], $GLOBALS['hook_suffix'] );
		parent::tear_down();
	}

	// ---------------------------------------------------------------- helpers

	private function post_screen( string $post_type ): PostTypeScreen {
		return new PostTypeScreen( $this->registry, $this->sets, get_post_type_object( $post_type ) );
	}

	private function type( string $key ): ColumnType {
		$type = $this->registry->get( $key );
		$this->assertNotNull( $type, "$key is registered" );
		return $type;
	}

	private function config( string $key, string $id = 'c1' ): Config {
		return new Config( $id, $key, 'x' );
	}

	private function html( Screen $screen, string $key, int $id ): string {
		return $this->type( $key )->render( $id, $this->config( $key ), $screen );
	}

	private function dash(): string {
		return '&#8212;';
	}

	private function lookup( int $id ): array {
		global $wpdb;
		$table = Woo::lookup_table();
		return (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE product_id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private function save( Screen $screen, string $key, int $id, mixed $value, string $mode = 'set' ): bool|WP_Error {
		$type = $this->type( $key );
		$this->assertInstanceOf( Editable::class, $type, "$key edits" );
		return $type->save( $id, $this->config( $key ), $screen, $value, $mode );
	}

	private function assertSaved( bool|WP_Error $result, string $message = '' ): void {
		$this->assertTrue( $result, $message . ( is_wp_error( $result ) ? ' ' . $result->get_error_message() : '' ) );
	}

	private function assertRefused( bool|WP_Error $result, string $message = '' ): void {
		$this->assertWPError( $result, $message );
	}

	/**
	 * @param array<string,mixed> $a
	 */
	private function product( string $name, array $a = array() ): int {
		$p = new \WC_Product_Simple();
		$p->set_name( $name );
		$p->set_status( 'publish' );
		if ( isset( $a['sku'] ) ) {
			$p->set_sku( $a['sku'] );
		}
		if ( isset( $a['regular'] ) ) {
			$p->set_regular_price( $a['regular'] );
		}
		if ( isset( $a['sale'] ) ) {
			$p->set_sale_price( $a['sale'] );
		}
		if ( isset( $a['qty'] ) ) {
			$p->set_manage_stock( true );
			$p->set_stock_quantity( $a['qty'] );
		} else {
			$p->set_stock_status( $a['status'] ?? 'instock' );
		}
		if ( isset( $a['sales'] ) ) {
			$p->set_total_sales( $a['sales'] );
		}
		if ( isset( $a['weight'] ) ) {
			$p->set_weight( $a['weight'] );
		}
		if ( isset( $a['featured'] ) ) {
			$p->set_featured( $a['featured'] );
		}
		if ( isset( $a['visibility'] ) ) {
			$p->set_catalog_visibility( $a['visibility'] );
		}
		if ( isset( $a['class'] ) ) {
			$p->set_shipping_class_id( $a['class'] );
		}
		if ( isset( $a['rating'] ) ) {
			$p->set_average_rating( $a['rating'] );
			$p->set_rating_counts( array( 5 => $a['reviews'] ) );
			$p->set_review_count( $a['reviews'] );
		}
		if ( isset( $a['note'] ) ) {
			$p->set_purchase_note( $a['note'] );
		}
		if ( isset( $a['tax'] ) ) {
			$p->set_tax_status( $a['tax'] );
		}
		return $p->save();
	}

	private function variable_product( string $name ): int {
		$parent = new \WC_Product_Variable();
		$parent->set_name( $name );
		$parent->set_status( 'publish' );
		$attr = new \WC_Product_Attribute();
		$attr->set_name( 'Size' );
		$attr->set_options( array( 'S', 'M' ) );
		$attr->set_visible( true );
		$attr->set_variation( true );
		$parent->set_attributes( array( $attr ) );
		$id = $parent->save();
		foreach ( array(
			'S' => '5',
			'M' => '9',
		) as $size => $price ) {
			$v = new \WC_Product_Variation();
			$v->set_parent_id( $id );
			$v->set_attributes( array( 'size' => $size ) );
			$v->set_regular_price( $price );
			$v->save();
		}
		\WC_Product_Variable::sync( $id );
		return $id;
	}

	private int $heavy = 0;
	private int $light = 0;

	private function fixture_products(): void {
		$this->heavy = (int) wp_insert_term( 'Heavy', 'product_shipping_class' )['term_id'];
		$this->light = (int) wp_insert_term( 'Light', 'product_shipping_class' )['term_id'];
		$this->p     = array(
			'alpha'   => $this->product(
				'Alpha',
				array(
					'sku'        => 'SKU-A',
					'regular'    => '10',
					'sale'       => '8',
					'qty'        => 5,
					'sales'      => 30,
					'weight'     => '2',
					'featured'   => true,
					'visibility' => 'hidden',
					'class'      => $this->heavy,
					'rating'     => 4.5,
					'reviews'    => 2,
					'note'       => 'Thanks A',
					'tax'        => 'none',
				)
			),
			'bravo'   => $this->product(
				'Bravo',
				array(
					'sku'        => 'SKU-B',
					'regular'    => '20',
					'status'     => 'onbackorder',
					'sales'      => 10,
					'weight'     => '10',
					'visibility' => 'catalog',
					'class'      => $this->light,
					'rating'     => 3.0,
					'reviews'    => 7,
				)
			),
			'charlie' => $this->product(
				'Charlie',
				array(
					'sku'        => 'ZZ-C',
					'regular'    => '15',
					'sale'       => '12',
					'qty'        => 0,
					'sales'      => 0,
					'weight'     => '5',
					'visibility' => 'search',
				)
			),
			'delta'   => $this->variable_product( 'Delta' ),
			'echo'    => $this->product(
				'Echo',
				array(
					'status' => 'outofstock',
					'sales'  => 5,
				)
			),
			'foxtrot' => $this->product(
				'Foxtrot',
				array(
					'sku'      => 'SKU-F',
					'regular'  => '99',
					'qty'      => 100,
					'featured' => false,
				)
			),
		);
	}

	/**
	 * @param list<int> $ids
	 * @return list<string>
	 */
	private function labels( array $ids ): array {
		$flip = array_flip( $this->p + $this->o + $this->c );
		return array_map( static fn( $id ) => $flip[ $id ] ?? 'id' . $id, $ids );
	}

	/**
	 * A real WP_Query with the column's filter and/or sort applied the way PostTypeScreen applies them.
	 *
	 * @param array{join:string,where:string}|null $filter
	 * @param array{0:Sortable,1:Config,2:string}|null $sort
	 * @param list<int> $only
	 * @return list<int>
	 */
	private function run_posts( Screen $screen, ?array $filter, ?array $sort, array $only ): array {
		global $wpdb;
		$callback = static function ( $clauses ) use ( $filter, $sort, $screen, $wpdb ) {
			if ( $filter ) {
				$clauses['join']  .= ' ' . $filter['join'];
				$clauses['where'] .= ' AND ' . $filter['where'];
			}
			if ( $sort ) {
				$clauses             = $sort[0]->sort_clauses( $clauses, $sort[1], $sort[2], $screen );
				$clauses['orderby'] .= ", {$wpdb->posts}.ID {$sort[2]}";
			}
			return $clauses;
		};
		add_filter( 'posts_clauses', $callback );
		$ids = ( new WP_Query(
			array(
				'post_type'        => $screen->subtype(),
				'post_status'      => 'shop_order' === $screen->subtype() ? array_keys( wc_get_order_statuses() ) : 'any',
				'fields'           => 'ids',
				'posts_per_page'   => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => false,
				'no_found_rows'    => true,
				'post__in'         => $only,
			)
		) )->posts;
		remove_filter( 'posts_clauses', $callback );
		return array_map( 'intval', $ids );
	}

	/**
	 * Labels matched by one filter rule, sorted, or null when the rule is rejected.
	 *
	 * @param list<int> $only
	 * @return list<string>|null
	 */
	private function filtered( Screen $screen, string $key, string $op, mixed $value, array $only ): ?array {
		$type = $this->type( $key );
		$this->assertInstanceOf( Filterable::class, $type, "$key filters" );
		$config = $this->config( $key );
		$this->assertContains( $op, array_column( $type->filter_operators( $config, $screen ), 'op' ), "$key offers $op" );
		$sql = $type->filter_condition( $config, $op, $value, $screen, 'clf0' );
		if ( null === $sql ) {
			return null;
		}
		$ids = $screen instanceof OrderScreen ? $screen->matching_ids( $sql, $only ) : $this->run_posts( $screen, $sql, null, $only );
		$out = $this->labels( $ids );
		sort( $out );
		return $out;
	}

	/**
	 * Labels in sort order.
	 *
	 * @param list<int> $only
	 * @return list<string>
	 */
	private function sorted( Screen $screen, string $key, string $order, array $only ): array {
		$type = $this->type( $key );
		$this->assertInstanceOf( Sortable::class, $type, "$key sorts" );
		$config = $this->config( $key );
		if ( $screen instanceof OrderScreen ) {
			$orders   = Woo::orders_table();
			$callback = static function ( $clauses ) use ( $type, $config, $order, $screen, $orders ) {
				$clauses             = $type->sort_clauses( $clauses, $config, $order, $screen );
				$clauses['orderby'] .= ", {$orders}.id {$order}";
				return $clauses;
			};
			add_filter( 'woocommerce_orders_table_query_clauses', $callback );
			$ids = wc_get_orders(
				array(
					'type'     => 'shop_order',
					'status'   => 'any',
					'limit'    => -1,
					'return'   => 'ids',
					'post__in' => $only,
					'orderby'  => 'ID',
					'order'    => 'ASC',
				)
			);
			remove_filter( 'woocommerce_orders_table_query_clauses', $callback );
			return $this->labels( array_map( 'intval', $ids ) );
		}
		return $this->labels( $this->run_posts( $screen, null, array( $type, $config, $order ), $only ) );
	}

	/**
	 * @param list<string> $expected
	 * @param list<int> $only
	 */
	private function assertFilter( Screen $screen, string $key, string $op, mixed $value, array $expected, array $only ): void {
		sort( $expected );
		$this->assertSame( $expected, $this->filtered( $screen, $key, $op, $value, $only ), "$key $op " . wp_json_encode( $value ) );
	}

	// ---------------------------------------------------------------- registry

	public function test_types_register_only_on_the_matching_screens(): void {
		$woo = static fn( Registry $r, Screen $s ) => array_values( array_filter( array_keys( $r->for_screen( $s ) ), static fn( $k ) => str_starts_with( $k, 'woo.' ) ) );

		$this->assertSame( array(), $woo( $this->registry, $this->post_screen( 'post' ) ) );

		$product_keys = $woo( $this->registry, $this->products );
		$this->assertContains( 'woo.sku', $product_keys );
		$this->assertNotContains( 'woo.order_total', $product_keys );
		$this->assertNotContains( 'woo.coupon_code', $product_keys );
		$this->assertCount( 19, $product_keys );

		$this->assertCount( 7, $woo( $this->registry, $this->coupons ) );

		foreach ( array( $this->legacy_orders, $this->hpos_orders ) as $screen ) {
			$order_keys = $woo( $this->registry, $screen );
			$this->assertCount( 13, $order_keys, $screen->key() );
			$this->assertNotContains( 'woo.sku', $order_keys );
		}
	}

	public function test_integration_registers_hooks_and_the_hpos_screen_only_when_enabled(): void {
		WooCommerceIntegration::register();
		$registry = new Registry();
		do_action( 'corinthian_register_types', $registry );
		$this->assertNotNull( $registry->get( 'woo.sku' ) );

		update_option( 'woocommerce_custom_orders_table_enabled', 'no' );
		$this->assertArrayNotHasKey( 'woo:orders', apply_filters( 'corinthian_screens', array(), $this->registry, $this->sets ) );

		update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
		$screens = apply_filters( 'corinthian_screens', array(), $this->registry, $this->sets );
		$this->assertArrayHasKey( 'woo:orders', $screens );
		$this->assertInstanceOf( OrderScreen::class, $screens['woo:orders'] );

		add_filter( 'corinthian_woocommerce_active', '__return_false' );
		$this->assertArrayNotHasKey( 'woo:orders', apply_filters( 'corinthian_screens', array(), $this->registry, $this->sets ) );
		$none = new Registry();
		WooCommerceIntegration::add_types( $none );
		$this->assertNull( $none->get( 'woo.sku' ) );
	}

	// ---------------------------------------------------------------- products

	public function test_a_saved_set_with_a_woo_product_column_renders_through_pro(): void {
		$this->fixture_products();
		$saved = $this->sets->save(
			array(
				'screen'  => 'product',
				'title'   => 'Saved before the split',
				'columns' => array(
					array(
						'id'    => 'c1',
						'type'  => 'woo.sku',
						'label' => 'SKU',
					),
				),
			)
		);
		$set   = $this->sets->find( $saved->id );
		$this->assertNotNull( $set );
		$this->assertSame( 'woo.sku', $set->columns[0]->type );

		$type = $this->registry->get( $set->columns[0]->type );
		$this->assertNotNull( $type );
		$this->assertSame( 'SKU-A', $type->render( $this->p['alpha'], $set->columns[0], $this->products ) );
	}

	public function test_product_columns_render(): void {
		$this->fixture_products();
		$s    = $this->products;
		$dash = $this->dash();

		$this->assertSame( 'SKU-A', $this->html( $s, 'woo.sku', $this->p['alpha'] ) );
		$this->assertStringContainsString( $dash, $this->html( $s, 'woo.sku', $this->p['echo'] ) );
		$this->assertStringContainsString( '10.00', $this->html( $s, 'woo.regular_price', $this->p['alpha'] ) );
		$this->assertStringContainsString( '8.00', $this->html( $s, 'woo.sale_price', $this->p['alpha'] ) );
		$this->assertStringContainsString( $dash, $this->html( $s, 'woo.sale_price', $this->p['bravo'] ) );
		$this->assertStringContainsString( '5.00', $this->html( $s, 'woo.regular_price', $this->p['delta'] ), 'a variable product shows its lowest variation price' );
		$this->assertSame( 'On backorder', $this->html( $s, 'woo.stock_status', $this->p['bravo'] ) );
		$this->assertSame( 'In stock', $this->html( $s, 'woo.stock_status', $this->p['alpha'] ) );
		$this->assertSame( '5', $this->html( $s, 'woo.stock_quantity', $this->p['alpha'] ) );
		$this->assertStringContainsString( $dash, $this->html( $s, 'woo.stock_quantity', $this->p['bravo'] ), 'unmanaged stock has no quantity' );
		$this->assertStringContainsString( 'dashicons-yes', $this->html( $s, 'woo.manage_stock', $this->p['alpha'] ) );
		$this->assertStringContainsString( 'dashicons-no-alt', $this->html( $s, 'woo.manage_stock', $this->p['bravo'] ) );
		$this->assertSame( 'Simple product', $this->html( $s, 'woo.product_type', $this->p['alpha'] ) );
		$this->assertSame( 'Variable product', $this->html( $s, 'woo.product_type', $this->p['delta'] ) );
		$this->assertSame( 'Hidden', $this->html( $s, 'woo.visibility', $this->p['alpha'] ) );
		$this->assertStringContainsString( 'dashicons-yes', $this->html( $s, 'woo.featured', $this->p['alpha'] ) );
		$this->assertStringContainsString( 'dashicons-no-alt', $this->html( $s, 'woo.featured', $this->p['bravo'] ) );
		$this->assertSame( '30', $this->html( $s, 'woo.total_sales', $this->p['alpha'] ) );
		$this->assertStringContainsString( '2', $this->html( $s, 'woo.weight', $this->p['alpha'] ) );
		$this->assertSame( 'Heavy', $this->html( $s, 'woo.shipping_class', $this->p['alpha'] ) );
		$this->assertStringContainsString( $dash, $this->html( $s, 'woo.shipping_class', $this->p['charlie'] ) );
		$this->assertSame( 'None', $this->html( $s, 'woo.tax_status', $this->p['alpha'] ) );
		$this->assertSame( 'Taxable', $this->html( $s, 'woo.tax_status', $this->p['bravo'] ) );
		$this->assertSame( 'Standard', $this->html( $s, 'woo.tax_class', $this->p['bravo'] ) );
		$this->assertSame( '4.50', $this->html( $s, 'woo.average_rating', $this->p['alpha'] ) );
		$this->assertStringContainsString( $dash, $this->html( $s, 'woo.average_rating', $this->p['echo'] ) );
		$this->assertSame( '2', $this->html( $s, 'woo.review_count', $this->p['alpha'] ) );
		$this->assertSame( 'Thanks A', $this->html( $s, 'woo.purchase_note', $this->p['alpha'] ) );
		$this->assertStringContainsString( $dash, $this->html( $s, 'woo.purchase_note', $this->p['bravo'] ) );
		$this->assertSame( '2', $this->html( $s, 'woo.variations', $this->p['delta'] ) );
		$this->assertStringContainsString( $dash, $this->html( $s, 'woo.variations', $this->p['alpha'] ) );
		$this->assertStringContainsString( $dash, $this->html( $s, 'woo.dimensions', $this->p['alpha'] ) );

		$p = wc_get_product( $this->p['alpha'] );
		$p->set_length( '3' );
		$p->set_width( '4' );
		$p->set_height( '5' );
		$p->save();
		$this->assertStringContainsString( '3 &times; 4 &times; 5', $this->html( $s, 'woo.dimensions', $this->p['alpha'] ) );

		$hostile = $this->product( '<b>x</b>', array( 'sku' => '<i>SKU</i>' ) );
		$this->assertStringNotContainsString( '<i>', $this->html( $s, 'woo.sku', $hostile ) );
		$this->assertStringContainsString( $dash, $this->html( $s, 'woo.sku', 999999 ), 'a missing product renders a dash' );
		$this->assertNull( $this->type( 'woo.sku' )->value( 999999, $this->config( 'woo.sku' ), $s ) );
	}


	public function test_product_sorts(): void {
		$this->fixture_products();
		$all = array_values( $this->p );
		$s   = $this->products;

		$cases = array(
			'woo.sku'            => array(
				array( 'alpha', 'bravo', 'foxtrot', 'charlie', 'delta', 'echo' ),
				array( 'charlie', 'foxtrot', 'bravo', 'alpha', 'echo', 'delta' ),
			),
			'woo.regular_price'  => array(
				array( 'alpha', 'charlie', 'bravo', 'foxtrot', 'delta', 'echo' ),
				array( 'foxtrot', 'bravo', 'charlie', 'alpha', 'echo', 'delta' ),
			),
			'woo.sale_price'     => array(
				array( 'alpha', 'charlie', 'bravo', 'delta', 'echo', 'foxtrot' ),
				array( 'charlie', 'alpha', 'foxtrot', 'echo', 'delta', 'bravo' ),
			),
			'woo.stock_quantity' => array(
				array( 'charlie', 'alpha', 'foxtrot', 'bravo', 'delta', 'echo' ),
				array( 'foxtrot', 'alpha', 'charlie', 'echo', 'delta', 'bravo' ),
			),
			'woo.total_sales'    => array(
				array( 'charlie', 'delta', 'foxtrot', 'echo', 'bravo', 'alpha' ),
				array( 'alpha', 'bravo', 'echo', 'foxtrot', 'delta', 'charlie' ),
			),
			'woo.weight'         => array(
				array( 'alpha', 'charlie', 'bravo', 'delta', 'echo', 'foxtrot' ),
				array( 'bravo', 'charlie', 'alpha', 'foxtrot', 'echo', 'delta' ),
			),
			'woo.average_rating' => array(
				array( 'bravo', 'alpha', 'charlie', 'delta', 'echo', 'foxtrot' ),
				array( 'alpha', 'bravo', 'foxtrot', 'echo', 'delta', 'charlie' ),
			),
			'woo.review_count'   => array(
				array( 'charlie', 'delta', 'echo', 'foxtrot', 'alpha', 'bravo' ),
				array( 'bravo', 'alpha', 'foxtrot', 'echo', 'delta', 'charlie' ),
			),
		);
		foreach ( $cases as $key => [ $asc, $desc ] ) {
			$this->assertSame( $asc, $this->sorted( $s, $key, 'ASC', $all ), "$key ASC" );
			$this->assertSame( $desc, $this->sorted( $s, $key, 'DESC', $all ), "$key DESC" );
		}

		foreach ( array( 'woo.stock_status', 'woo.product_type', 'woo.visibility', 'woo.featured', 'woo.shipping_class', 'woo.manage_stock', 'woo.tax_status', 'woo.tax_class', 'woo.dimensions', 'woo.purchase_note', 'woo.variations' ) as $key ) {
			$this->assertNotInstanceOf( Sortable::class, $this->type( $key ), "$key does not sort" );
		}
	}

	public function test_product_filters(): void {
		$this->fixture_products();
		$all = array_values( $this->p );
		$s   = $this->products;
		$f   = fn( string $key, string $op, mixed $value, array $expected ) => $this->assertFilter( $s, $key, $op, $value, $expected, $all );

		$f( 'woo.sku', 'contains', 'SKU', array( 'alpha', 'bravo', 'foxtrot' ) );
		$f( 'woo.sku', 'contains', 'nomatch', array() );
		$f( 'woo.sku', 'eq', 'SKU-A', array( 'alpha' ) );
		$f( 'woo.sku', 'neq', 'SKU-A', array( 'bravo', 'charlie', 'delta', 'echo', 'foxtrot' ) );
		$f( 'woo.sku', 'starts', 'ZZ', array( 'charlie' ) );
		$f( 'woo.sku', 'not_contains', 'SKU', array( 'charlie', 'delta', 'echo' ) );
		$f( 'woo.sku', 'empty', null, array( 'delta', 'echo' ) );
		$f( 'woo.sku', 'not_empty', null, array( 'alpha', 'bravo', 'charlie', 'foxtrot' ) );
		$f( 'woo.sku', 'contains', "x' OR 1=1 -- ", array() );
		$this->assertNull( $this->filtered( $s, 'woo.sku', 'contains', '', $all ), 'an empty needle is rejected' );

		$f( 'woo.regular_price', 'eq', '10', array( 'alpha' ) );
		$f( 'woo.regular_price', 'neq', '10', array( 'bravo', 'charlie', 'delta', 'echo', 'foxtrot' ) );
		$f( 'woo.regular_price', 'gt', '15', array( 'bravo', 'foxtrot' ) );
		$f( 'woo.regular_price', 'gte', '15', array( 'bravo', 'charlie', 'foxtrot' ) );
		$f( 'woo.regular_price', 'lt', '15', array( 'alpha' ) );
		$f( 'woo.regular_price', 'lte', '15', array( 'alpha', 'charlie' ) );
		$f( 'woo.regular_price', 'between', array( '15', '10' ), array( 'alpha', 'charlie' ) );
		$f( 'woo.regular_price', 'empty', null, array( 'delta', 'echo' ) );
		$f( 'woo.regular_price', 'not_empty', null, array( 'alpha', 'bravo', 'charlie', 'foxtrot' ) );
		$f( 'woo.regular_price', 'eq', '12345', array() );
		$this->assertNull( $this->filtered( $s, 'woo.regular_price', 'gt', 'abc', $all ) );
		$this->assertNull( $this->filtered( $s, 'woo.regular_price', 'between', array( '1' ), $all ) );

		$f( 'woo.sale_price', 'eq', '8', array( 'alpha' ) );
		$f( 'woo.sale_price', 'gt', '0', array( 'alpha', 'charlie' ) );
		$f( 'woo.sale_price', 'empty', null, array( 'bravo', 'delta', 'echo', 'foxtrot' ) );
		$f( 'woo.sale_price', 'gt', '12', array() );

		$f( 'woo.stock_status', 'in', array( 'instock' ), array( 'alpha', 'delta', 'foxtrot' ) );
		$f( 'woo.stock_status', 'in', array( 'outofstock' ), array( 'charlie', 'echo' ) );
		$f( 'woo.stock_status', 'in', array( 'onbackorder' ), array( 'bravo' ) );
		$f( 'woo.stock_status', 'in', array( 'instock', 'onbackorder' ), array( 'alpha', 'bravo', 'delta', 'foxtrot' ) );
		$f( 'woo.stock_status', 'not_in', array( 'instock' ), array( 'bravo', 'charlie', 'echo' ) );
		$f( 'woo.stock_status', 'in', array( 'nonsense' ), array() );
		$this->assertNull( $this->filtered( $s, 'woo.stock_status', 'in', array(), $all ), 'an empty list is rejected' );

		$f( 'woo.stock_quantity', 'eq', '5', array( 'alpha' ) );
		$f( 'woo.stock_quantity', 'eq', '0', array( 'charlie' ) );
		$f( 'woo.stock_quantity', 'gt', '0', array( 'alpha', 'foxtrot' ) );
		$f( 'woo.stock_quantity', 'between', array( '1', '10' ), array( 'alpha' ) );
		$f( 'woo.stock_quantity', 'empty', null, array( 'bravo', 'delta', 'echo' ) );
		$f( 'woo.stock_quantity', 'not_empty', null, array( 'alpha', 'charlie', 'foxtrot' ) );
		$f( 'woo.stock_quantity', 'lt', '0', array() );

		$f( 'woo.product_type', 'in', array( 'variable' ), array( 'delta' ) );
		$f( 'woo.product_type', 'in', array( 'simple' ), array( 'alpha', 'bravo', 'charlie', 'echo', 'foxtrot' ) );
		$f( 'woo.product_type', 'not_in', array( 'simple' ), array( 'delta' ) );
		$f( 'woo.product_type', 'in', array( 'grouped' ), array() );

		$f( 'woo.visibility', 'in', array( 'hidden' ), array( 'alpha' ) );
		$f( 'woo.visibility', 'in', array( 'catalog' ), array( 'bravo' ) );
		$f( 'woo.visibility', 'in', array( 'search' ), array( 'charlie' ) );
		$f( 'woo.visibility', 'in', array( 'visible' ), array( 'delta', 'echo', 'foxtrot' ) );
		$f( 'woo.visibility', 'in', array( 'hidden', 'catalog' ), array( 'alpha', 'bravo' ) );
		$f( 'woo.visibility', 'not_in', array( 'visible' ), array( 'alpha', 'bravo', 'charlie' ) );
		$this->assertNull( $this->filtered( $s, 'woo.visibility', 'in', array( 'bogus' ), $all ) );

		$f( 'woo.featured', 'eq', '1', array( 'alpha' ) );
		$f( 'woo.featured', 'eq', '0', array( 'bravo', 'charlie', 'delta', 'echo', 'foxtrot' ) );
		$this->assertNull( $this->filtered( $s, 'woo.featured', 'eq', '2', $all ) );

		$f( 'woo.total_sales', 'eq', '0', array( 'charlie', 'delta', 'foxtrot' ) );
		$f( 'woo.total_sales', 'gt', '5', array( 'alpha', 'bravo' ) );
		$f( 'woo.total_sales', 'between', array( '5', '10' ), array( 'bravo', 'echo' ) );
		$f( 'woo.total_sales', 'gte', '100', array() );

		$f( 'woo.shipping_class', 'in', array( 'heavy' ), array( 'alpha' ) );
		$f( 'woo.shipping_class', 'in', array( 'light' ), array( 'bravo' ) );
		$f( 'woo.shipping_class', 'in', array( 'heavy', 'light' ), array( 'alpha', 'bravo' ) );
		$f( 'woo.shipping_class', 'in', array( '0' ), array( 'charlie', 'delta', 'echo', 'foxtrot' ) );
		$f( 'woo.shipping_class', 'not_in', array( 'heavy' ), array( 'bravo', 'charlie', 'delta', 'echo', 'foxtrot' ) );
		$f( 'woo.shipping_class', 'in', array( 'nonexistent' ), array() );

		$config = $this->config( 'woo.sku' );
		$this->assertNull( $this->type( 'woo.sku' )->filter_condition( $config, 'gt', '1', $s, 'clf0' ), 'an operator the column does not offer is rejected' );

		foreach ( array( 'woo.weight', 'woo.average_rating', 'woo.review_count', 'woo.manage_stock', 'woo.tax_status', 'woo.tax_class', 'woo.dimensions', 'woo.purchase_note', 'woo.variations' ) as $key ) {
			$this->assertNotInstanceOf( Filterable::class, $this->type( $key ), "$key does not filter" );
		}
	}

	public function test_two_rules_get_their_own_alias_and_combine(): void {
		$this->fixture_products();
		$type = $this->type( 'woo.stock_quantity' );
		$one  = $type->filter_condition( $this->config( 'woo.stock_quantity', 'a' ), 'gt', '0', $this->products, 'clf0' );
		$two  = $type->filter_condition( $this->config( 'woo.stock_quantity', 'b' ), 'lt', '50', $this->products, 'clf1' );
		$this->assertStringContainsString( 'clf0', $one['join'] );
		$this->assertStringContainsString( 'clf1', $two['join'] );
		$ids = $this->run_posts(
			$this->products,
			array(
				'join'  => $one['join'] . ' ' . $two['join'],
				'where' => "({$one['where']}) AND ({$two['where']})",
			),
			null,
			array_values( $this->p )
		);
		$this->assertSame( array( 'alpha' ), $this->labels( $ids ) );
	}

	public function test_product_edit_round_trips_through_crud_and_the_lookup_table(): void {
		$this->fixture_products();
		$s = $this->products;
		$p = $this->p;

		// SKU.
		$this->assertSaved( $this->save( $s, 'woo.sku', $p['echo'], 'NEW-1' ) );
		$this->assertSame( 'NEW-1', wc_get_product( $p['echo'] )->get_sku() );
		$this->assertSame( 'NEW-1', $this->lookup( $p['echo'] )['sku'] );
		$this->assertSame( 'NEW-1', $this->type( 'woo.sku' )->edit_value( $p['echo'], $this->config( 'woo.sku' ), $s ) );
		$this->assertRefused( $this->save( $s, 'woo.sku', $p['echo'], 'SKU-A' ), 'a duplicate SKU' );
		$this->assertSame( 'NEW-1', wc_get_product( $p['echo'] )->get_sku(), 'a refused SKU leaves the old one' );
		$this->assertRefused( $this->save( $s, 'woo.sku', $p['echo'], str_repeat( 'x', 101 ) ), 'a SKU over 100 characters' );
		$this->assertSaved( $this->save( $s, 'woo.sku', $p['echo'], '' ) );
		$this->assertSame( '', $this->lookup( $p['echo'] )['sku'] );
		$this->assertRefused( $this->save( $s, 'woo.sku', $p['echo'], 'X', 'clear' ), 'SKUs are unique, so there is no bulk mode' );
		$this->assertArrayNotHasKey( 'bulk', $this->type( 'woo.sku' )->edit_field( $this->config( 'woo.sku' ), $s ) );

		// Regular price.
		$this->assertSaved( $this->save( $s, 'woo.regular_price', $p['bravo'], '12.5' ) );
		$this->assertSame( '12.5', wc_get_product( $p['bravo'] )->get_regular_price() );
		$this->assertEquals( 12.5, $this->lookup( $p['bravo'] )['min_price'] );
		$this->assertEquals( 12.5, $this->lookup( $p['bravo'] )['max_price'] );
		$this->assertSame( '12.5', $this->type( 'woo.regular_price' )->edit_value( $p['bravo'], $this->config( 'woo.regular_price' ), $s ) );
		$this->assertRefused( $this->save( $s, 'woo.regular_price', $p['bravo'], 'abc' ) );
		$this->assertRefused( $this->save( $s, 'woo.regular_price', $p['bravo'], '-3' ) );
		$this->assertRefused( $this->save( $s, 'woo.regular_price', $p['alpha'], '5' ), 'regular below the sale price' );
		$this->assertSame( '10', wc_get_product( $p['alpha'] )->get_regular_price() );
		$this->assertRefused( $this->save( $s, 'woo.regular_price', $p['delta'], '5' ), 'variable products price their variations' );
		$this->assertEquals( 12.5, $this->lookup( $p['bravo'] )['min_price'], 'refused edits leave the lookup row alone' );

		// Sale price.
		$this->assertSaved( $this->save( $s, 'woo.sale_price', $p['bravo'], '9' ) );
		$this->assertSame( '9', wc_get_product( $p['bravo'] )->get_sale_price() );
		$this->assertEquals( 9, $this->lookup( $p['bravo'] )['min_price'], 'the lookup price follows the sale' );
		$this->assertRefused( $this->save( $s, 'woo.sale_price', $p['bravo'], '25' ), 'sale above regular' );
		$this->assertRefused( $this->save( $s, 'woo.sale_price', $p['echo'], '1' ), 'a sale needs a regular price' );
		$this->assertSaved( $this->save( $s, 'woo.sale_price', $p['bravo'], '', 'clear' ) );
		$this->assertSame( '', wc_get_product( $p['bravo'] )->get_sale_price() );
		$this->assertEquals( 12.5, $this->lookup( $p['bravo'] )['min_price'], 'clearing the sale restores the regular price' );

		// Bulk modes and adjusting by a percentage.
		$field = $this->type( 'woo.regular_price' )->edit_field( $this->config( 'woo.regular_price' ), $s );
		$this->assertSame( array( 'set', 'clear', 'adjust_percent' ), $field['bulk'] );
		$this->assertSaved( $this->save( $s, 'woo.regular_price', $p['alpha'], '10', 'adjust_percent' ), 'adjusting by 10' );
		$this->assertSame( '11', wc_get_product( $p['alpha'] )->get_regular_price() );
		$this->assertRefused( $this->save( $s, 'woo.regular_price', $p['charlie'], '-30', 'adjust_percent' ), 'would drop below the sale price' );
		$this->assertSame( '15', wc_get_product( $p['charlie'] )->get_regular_price() );
		$this->assertSaved( $this->save( $s, 'woo.regular_price', $p['charlie'], '-20', 'adjust_percent' ) );
		$this->assertSame( '12', wc_get_product( $p['charlie'] )->get_regular_price() );
		$this->assertRefused( $this->save( $s, 'woo.regular_price', $p['charlie'], '-200', 'adjust_percent' ), 'below zero' );
		$this->assertRefused( $this->save( $s, 'woo.regular_price', $p['charlie'], 'abc', 'adjust_percent' ) );
		$this->assertRefused( $this->save( $s, 'woo.regular_price', $p['echo'], '10', 'adjust_percent' ), 'nothing to adjust' );
		$this->assertSaved( $this->save( $s, 'woo.sale_price', $p['alpha'], '-50', 'adjust_percent' ) );
		$this->assertSame( '4', wc_get_product( $p['alpha'] )->get_sale_price() );
		$this->assertEquals( 4, $this->lookup( $p['alpha'] )['min_price'] );
		$this->assertSaved( $this->save( $s, 'woo.regular_price', $p['foxtrot'], '0', 'adjust_percent' ), 'adjusting by zero is a no-op' );
		$this->assertSame( '99', wc_get_product( $p['foxtrot'] )->get_regular_price() );
		$this->assertRefused( $this->save( $s, 'woo.regular_price', $p['foxtrot'], '1', 'append' ), 'an unlisted mode' );

		// Stock status.
		$this->assertSaved( $this->save( $s, 'woo.stock_status', $p['bravo'], 'outofstock' ) );
		$this->assertSame( 'outofstock', wc_get_product( $p['bravo'] )->get_stock_status() );
		$this->assertSame( 'outofstock', $this->lookup( $p['bravo'] )['stock_status'] );
		$this->assertRefused( $this->save( $s, 'woo.stock_status', $p['bravo'], 'weird' ) );
		$this->assertRefused( $this->save( $s, 'woo.stock_status', $p['alpha'], 'outofstock' ), 'status follows quantity while stock is managed' );
		$this->assertSame( 'instock', wc_get_product( $p['alpha'] )->get_stock_status() );
		$this->assertRefused( $this->save( $s, 'woo.stock_status', $p['delta'], 'outofstock' ), 'variable products follow their variations' );
		$this->assertSame( array( 'set' ), $this->type( 'woo.stock_status' )->edit_field( $this->config( 'woo.stock_status' ), $s )['bulk'] );

		// Stock quantity.
		$this->assertSaved( $this->save( $s, 'woo.stock_quantity', $p['alpha'], '12' ) );
		$this->assertSame( 12, wc_get_product( $p['alpha'] )->get_stock_quantity() );
		$this->assertEquals( 12, $this->lookup( $p['alpha'] )['stock_quantity'] );
		$this->assertSaved( $this->save( $s, 'woo.stock_quantity', $p['alpha'], '0' ) );
		$this->assertEquals( 0, $this->lookup( $p['alpha'] )['stock_quantity'] );
		$this->assertSame( 'outofstock', $this->lookup( $p['alpha'] )['stock_status'], 'zero stock flips the status' );
		$this->assertSaved( $this->save( $s, 'woo.stock_quantity', $p['charlie'], '7' ) );
		$this->assertSame( 'instock', $this->lookup( $p['charlie'] )['stock_status'] );
		$this->assertRefused( $this->save( $s, 'woo.stock_quantity', $p['charlie'], '1.5' ) );
		$this->assertRefused( $this->save( $s, 'woo.stock_quantity', $p['charlie'], 'abc' ) );
		$this->assertRefused( $this->save( $s, 'woo.stock_quantity', $p['charlie'], '' ) );
		$this->assertRefused( $this->save( $s, 'woo.stock_quantity', $p['echo'], '3' ), 'stock is not managed' );
		$this->assertEquals( 7, $this->lookup( $p['charlie'] )['stock_quantity'] );

		// Manage stock.
		$this->assertSaved( $this->save( $s, 'woo.manage_stock', $p['echo'], '1' ) );
		$this->assertTrue( wc_get_product( $p['echo'] )->get_manage_stock() );
		$this->assertEquals( 0, $this->lookup( $p['echo'] )['stock_quantity'] );
		$this->assertSaved( $this->save( $s, 'woo.manage_stock', $p['echo'], '0' ) );
		$this->assertFalse( wc_get_product( $p['echo'] )->get_manage_stock() );
		$this->assertNull( $this->lookup( $p['echo'] )['stock_quantity'] );

		// Visibility and featured.
		$this->assertSaved( $this->save( $s, 'woo.visibility', $p['bravo'], 'visible' ) );
		$this->assertSame( 'visible', wc_get_product( $p['bravo'] )->get_catalog_visibility() );
		$this->assertContains( 'bravo', $this->filtered( $s, 'woo.visibility', 'in', array( 'visible' ), array_values( $p ) ) );
		$this->assertRefused( $this->save( $s, 'woo.visibility', $p['bravo'], 'zzz' ) );
		$this->assertSaved( $this->save( $s, 'woo.featured', $p['bravo'], '1' ) );
		$this->assertSame( array( 'alpha', 'bravo' ), $this->filtered( $s, 'woo.featured', 'eq', '1', array_values( $p ) ) );
		$this->assertSaved( $this->save( $s, 'woo.featured', $p['alpha'], '0' ) );
		$this->assertSame( array( 'bravo' ), $this->filtered( $s, 'woo.featured', 'eq', '1', array_values( $p ) ) );
		$this->assertSame( array( 'set' ), $this->type( 'woo.featured' )->edit_field( $this->config( 'woo.featured' ), $s )['bulk'] );
		$this->assertSame( array( 'set' ), $this->type( 'woo.visibility' )->edit_field( $this->config( 'woo.visibility' ), $s )['bulk'] );

		// Shipping class.
		$this->assertSaved( $this->save( $s, 'woo.shipping_class', $p['charlie'], (string) $this->heavy ) );
		$this->assertSame( 'heavy', wc_get_product( $p['charlie'] )->get_shipping_class() );
		$this->assertSame( array( 'alpha', 'charlie' ), $this->filtered( $s, 'woo.shipping_class', 'in', array( 'heavy' ), array_values( $p ) ) );
		$this->assertSaved( $this->save( $s, 'woo.shipping_class', $p['charlie'], '0' ) );
		$this->assertSame( '', wc_get_product( $p['charlie'] )->get_shipping_class() );
		$this->assertRefused( $this->save( $s, 'woo.shipping_class', $p['charlie'], '99999' ) );
		$this->assertRefused( $this->save( $s, 'woo.shipping_class', $p['charlie'], 'heavy' ), 'ids only' );
		$this->assertSame( (string) $this->light, $this->type( 'woo.shipping_class' )->edit_value( $p['bravo'], $this->config( 'woo.shipping_class' ), $s ) );
		$options = $this->type( 'woo.shipping_class' )->edit_field( $this->config( 'woo.shipping_class' ), $s )['options'];
		$this->assertSame( 'Heavy', $options[ (string) $this->heavy ] );
	}

	public function test_product_edits_need_edit_product_and_refuse_a_wrong_object(): void {
		$this->fixture_products();
		$s = $this->products;
		$p = $this->p;

		$edits = array(
			'woo.sku'            => 'CAP-1',
			'woo.regular_price'  => '30',
			'woo.sale_price'     => '1',
			'woo.stock_status'   => 'outofstock',
			'woo.stock_quantity' => '9',
			'woo.manage_stock'   => '1',
			'woo.visibility'     => 'search',
			'woo.featured'       => '1',
			'woo.shipping_class' => (string) $this->heavy,
		);
		$target = array(
			'woo.sku'            => $p['echo'],
			'woo.regular_price'  => $p['bravo'],
			'woo.sale_price'     => $p['bravo'],
			'woo.stock_status'   => $p['bravo'],
			'woo.stock_quantity' => $p['alpha'],
			'woo.manage_stock'   => $p['bravo'],
			'woo.visibility'     => $p['alpha'],
			'woo.featured'       => $p['bravo'],
			'woo.shipping_class' => $p['bravo'],
		);
		foreach ( array( $this->customer, 0 ) as $user ) {
			wp_set_current_user( $user );
			foreach ( $edits as $key => $value ) {
				$before = wp_json_encode( wc_get_product( $target[ $key ] )->get_data() );
				$this->assertRefused( $this->save( $s, $key, $target[ $key ], $value ), "$key as user $user" );
				$this->assertSame( $before, wp_json_encode( wc_get_product( $target[ $key ] )->get_data() ), "$key left the product alone" );
			}
		}

		wp_set_current_user( $this->manager );
		foreach ( $edits as $key => $value ) {
			$this->assertSaved( $this->save( $s, $key, $target[ $key ], $value ), "$key as shop manager" );
		}

		wp_set_current_user( $this->manager );
		$this->assertRefused( $this->save( $s, 'woo.sku', $p['delta'] + 9999, 'NOPE' ), 'an id that is not a product' );
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->assertRefused( $this->save( $s, 'woo.sku', $page, 'NOPE' ), 'a page is not a product' );
		$this->assertRefused( $this->save( $s, 'woo.sku', $p['echo'], array( 'a' ) ), 'an array value' );
	}



	// ---------------------------------------------------------------- orders

	/**
	 * @return array<string,array{0:bool}>
	 */
	public static function storages(): array {
		return array(
			'HPOS tables'  => array( true ),
			'legacy posts' => array( false ),
		);
	}

	private function use_storage( bool $hpos ): Screen {
		update_option( 'woocommerce_custom_orders_table_enabled', $hpos ? 'yes' : 'no' );
		$this->assertSame( $hpos, Woo::hpos(), 'the requested order storage is active' );
		return $hpos ? $this->hpos_orders : $this->legacy_orders;
	}

	private function day( int $days_ago ): string {
		return gmdate( 'Y-m-d H:i:s', time() - $days_ago * DAY_IN_SECONDS );
	}

	/**
	 * @param array<string,mixed> $a
	 */
	private function order( array $a ): int {
		$order = wc_create_order();
		$order->set_status( $a['status'] );
		$order->set_total( $a['total'] );
		$order->set_payment_method( $a['method'] );
		$order->set_payment_method_title( $a['title'] );
		$order->set_billing_email( $a['email'] );
		$order->set_billing_first_name( $a['first'] );
		$order->set_billing_last_name( $a['last'] );
		$order->set_billing_country( $a['country'] );
		$order->set_created_via( $a['via'] );
		$order->set_customer_note( $a['note'] ?? '' );
		if ( isset( $a['paid'] ) ) {
			$order->set_date_paid( $this->day( $a['paid'] ) );
		}
		if ( isset( $a['completed'] ) ) {
			$order->set_date_completed( $this->day( $a['completed'] ) );
		}
		if ( isset( $a['qty'] ) ) {
			$item = new \WC_Order_Item_Product();
			$item->set_name( 'Widget' );
			$item->set_quantity( $a['qty'] );
			$order->add_item( $item );
		}
		if ( isset( $a['shipping'] ) ) {
			$ship = new \WC_Order_Item_Shipping();
			$ship->set_method_title( $a['shipping'] );
			$order->add_item( $ship );
		}
		if ( isset( $a['coupon'] ) ) {
			$coupon = new \WC_Order_Item_Coupon();
			$coupon->set_code( $a['coupon'] );
			$order->add_item( $coupon );
		}
		$order->save();
		return $order->get_id();
	}

	private function fixture_orders( bool $hpos ): void {
		$this->o = array(
			'one'   => $this->order(
				array(
					'status'   => 'processing',
					'total'    => '100',
					'method'   => 'bacs',
					'title'    => 'Direct bank transfer',
					'email'    => 'ann@example.com',
					'first'    => 'Ann',
					'last'     => 'Alpha',
					'country'  => 'US',
					'via'      => 'checkout',
					'note'     => 'Leave at door',
					'paid'     => 30,
					'qty'      => 3,
					'shipping' => 'Flat rate',
					'coupon'   => 'SAVE10',
				)
			),
			'two'   => $this->order(
				array(
					'status'    => 'completed',
					'total'     => '50.5',
					'method'    => 'cod',
					'title'     => 'Cash on delivery',
					'email'     => 'bob@example.org',
					'first'     => 'Bob',
					'last'      => 'Beta',
					'country'   => 'GB',
					'via'       => 'admin',
					'paid'      => 10,
					'completed' => 8,
				)
			),
			'three' => $this->order(
				array(
					'status'  => 'on-hold',
					'total'   => '75',
					'method'  => 'bacs',
					'title'   => 'Direct bank transfer',
					'email'   => 'cy@example.com',
					'first'   => 'Cy',
					'last'    => 'Gamma',
					'country' => 'US',
					'via'     => 'rest-api',
				)
			),
			'four'  => $this->order(
				array(
					'status'  => 'pending',
					'total'   => '10',
					'method'  => 'paypal',
					'title'   => 'PayPal',
					'email'   => 'dee@example.net',
					'first'   => 'Dee',
					'last'    => 'Delta',
					'country' => 'DE',
					'via'     => 'checkout',
				)
			),
		);
		global $wpdb;
		$in_table = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id = %d', Woo::orders_table(), $this->o['one'] ) );
		$this->assertSame( $hpos ? 1 : 0, $in_table, 'orders are stored where the test expects' );
		$this->assertSame( ! $hpos, 'shop_order' === get_post_type( $this->o['one'] ) );
	}

	/**
	 * @dataProvider storages
	 */
	public function test_order_columns_render( bool $hpos ): void {
		$s = $this->use_storage( $hpos );
		$this->fixture_orders( $hpos );
		$one = $this->o['one'];

		$this->assertSame( 'Processing', $this->html( $s, 'woo.order_status', $one ) );
		$this->assertSame( 'On hold', $this->html( $s, 'woo.order_status', $this->o['three'] ) );
		$this->assertStringContainsString( '100.00', $this->html( $s, 'woo.order_total', $one ) );
		$this->assertSame( 'Direct bank transfer', $this->html( $s, 'woo.payment_method', $one ) );
		$this->assertSame( 'ann@example.com', $this->html( $s, 'woo.billing_email', $one ) );
		$this->assertSame( 'Ann Alpha', $this->html( $s, 'woo.billing_name', $one ) );
		$this->assertStringContainsString( 'United States', $this->html( $s, 'woo.billing_country', $one ) );
		$this->assertSame( 'Flat rate', $this->html( $s, 'woo.shipping_method', $one ) );
		$this->assertStringContainsString( $this->dash(), $this->html( $s, 'woo.shipping_method', $this->o['two'] ) );
		$this->assertSame( '3', $this->html( $s, 'woo.items_count', $one ) );
		$this->assertSame( 'save10', $this->html( $s, 'woo.order_coupons', $one ) );
		$this->assertSame( 'Leave at door', $this->html( $s, 'woo.customer_note', $one ) );
		$this->assertStringContainsString( $this->dash(), $this->html( $s, 'woo.customer_note', $this->o['two'] ) );
		$this->assertStringContainsString( gmdate( 'Y' ), $this->html( $s, 'woo.date_paid', $one ) );
		$this->assertStringContainsString( $this->dash(), $this->html( $s, 'woo.date_paid', $this->o['three'] ) );
		$this->assertStringNotContainsString( $this->dash(), $this->html( $s, 'woo.date_completed', $this->o['two'] ) );
		$this->assertSame( 'checkout', $this->html( $s, 'woo.created_via', $one ) );
		$this->assertStringContainsString( $this->dash(), $this->html( $s, 'woo.order_status', 999999 ), 'a missing order renders a dash' );

		$order = wc_get_order( $one );
		$order->set_customer_note( '<script>alert(1)</script>Hello' );
		$order->set_billing_first_name( '<b>Ann</b>' );
		$order->save();
		$this->assertStringNotContainsString( '<script', $this->html( $s, 'woo.customer_note', $one ) );
		$this->assertStringNotContainsString( '<b>', $this->html( $s, 'woo.billing_name', $one ) );

		$not_order = $this->product( 'Not an order' );
		$this->assertNull( $this->type( 'woo.order_total' )->value( $not_order, $this->config( 'woo.order_total' ), $s ), 'a product id is not an order' );
	}

	/**
	 * @dataProvider storages
	 */
	public function test_order_sorts( bool $hpos ): void {
		$s = $this->use_storage( $hpos );
		$this->fixture_orders( $hpos );
		$all = array_values( $this->o );

		$cases = array(
			'woo.order_total'    => array(
				array( 'four', 'two', 'three', 'one' ),
				array( 'one', 'three', 'two', 'four' ),
			),
			'woo.date_paid'      => array(
				array( 'one', 'two', 'three', 'four' ),
				array( 'two', 'one', 'four', 'three' ),
			),
			'woo.date_completed' => array(
				array( 'two', 'one', 'three', 'four' ),
				array( 'two', 'four', 'three', 'one' ),
			),
		);
		foreach ( $cases as $key => [ $asc, $desc ] ) {
			$this->assertSame( $asc, $this->sorted( $s, $key, 'ASC', $all ), "$key ASC" );
			$this->assertSame( $desc, $this->sorted( $s, $key, 'DESC', $all ), "$key DESC" );
		}
		foreach ( array( 'woo.order_status', 'woo.payment_method', 'woo.billing_email', 'woo.billing_name', 'woo.billing_country', 'woo.shipping_method', 'woo.items_count', 'woo.order_coupons', 'woo.customer_note', 'woo.created_via' ) as $key ) {
			$this->assertNotInstanceOf( Sortable::class, $this->type( $key ), "$key does not sort" );
		}
	}

	/**
	 * @dataProvider storages
	 */
	public function test_order_filters( bool $hpos ): void {
		$s = $this->use_storage( $hpos );
		$this->fixture_orders( $hpos );
		$all = array_values( $this->o );
		$f   = fn( string $key, string $op, mixed $value, array $expected ) => $this->assertFilter( $s, $key, $op, $value, $expected, $all );
		$d   = fn( int $days ) => gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS );

		$f( 'woo.order_status', 'in', array( 'processing' ), array( 'one' ) );
		$f( 'woo.order_status', 'in', array( 'completed', 'on-hold' ), array( 'two', 'three' ) );
		$f( 'woo.order_status', 'not_in', array( 'pending' ), array( 'one', 'two', 'three' ) );
		$f( 'woo.order_status', 'in', array( 'cancelled' ), array() );
		$this->assertNull( $this->filtered( $s, 'woo.order_status', 'in', array( 'bogus' ), $all ), 'an unknown status is rejected' );

		$f( 'woo.order_total', 'eq', '100', array( 'one' ) );
		$f( 'woo.order_total', 'neq', '100', array( 'two', 'three', 'four' ) );
		$f( 'woo.order_total', 'gt', '50.5', array( 'one', 'three' ) );
		$f( 'woo.order_total', 'gte', '50.5', array( 'one', 'two', 'three' ) );
		$f( 'woo.order_total', 'lt', '50.5', array( 'four' ) );
		$f( 'woo.order_total', 'lte', '50.5', array( 'two', 'four' ) );
		$f( 'woo.order_total', 'between', array( '75', '10' ), array( 'two', 'three', 'four' ) );
		$f( 'woo.order_total', 'eq', '999', array() );
		$this->assertNull( $this->filtered( $s, 'woo.order_total', 'gt', 'abc', $all ) );

		$f( 'woo.payment_method', 'in', array( 'bacs' ), array( 'one', 'three' ) );
		$f( 'woo.payment_method', 'in', array( 'cod' ), array( 'two' ) );
		$f( 'woo.payment_method', 'not_in', array( 'bacs' ), array( 'two', 'four' ) );
		$f( 'woo.payment_method', 'in', array( 'zzz' ), array() );

		$f( 'woo.billing_email', 'contains', 'example.com', array( 'one', 'three' ) );
		$f( 'woo.billing_email', 'eq', 'bob@example.org', array( 'two' ) );
		$f( 'woo.billing_email', 'neq', 'bob@example.org', array( 'one', 'three', 'four' ) );
		$f( 'woo.billing_email', 'starts', 'dee', array( 'four' ) );
		$f( 'woo.billing_email', 'not_contains', 'example.com', array( 'two', 'four' ) );
		$f( 'woo.billing_email', 'not_empty', null, array( 'one', 'two', 'three', 'four' ) );
		$f( 'woo.billing_email', 'empty', null, array() );
		$f( 'woo.billing_email', 'contains', 'nomatch', array() );
		$f( 'woo.billing_email', 'contains', "x' OR 1=1 -- ", array() );

		$f( 'woo.billing_name', 'contains', 'Ann', array( 'one' ) );
		$f( 'woo.billing_name', 'eq', 'Bob Beta', array( 'two' ) );
		$f( 'woo.billing_name', 'starts', 'Cy', array( 'three' ) );
		$f( 'woo.billing_name', 'contains', 'Delta', array( 'four' ) );
		$f( 'woo.billing_name', 'contains', 'Zed', array() );

		$f( 'woo.billing_country', 'in', array( 'US' ), array( 'one', 'three' ) );
		$f( 'woo.billing_country', 'not_in', array( 'US' ), array( 'two', 'four' ) );
		$f( 'woo.billing_country', 'in', array( 'US', 'GB' ), array( 'one', 'two', 'three' ) );
		$f( 'woo.billing_country', 'in', array( 'FR' ), array() );

		$f( 'woo.date_paid', 'before', $d( 29 ), array( 'one' ) );
		$f( 'woo.date_paid', 'before', $d( 30 ), array() );
		$f( 'woo.date_paid', 'after', $d( 30 ), array( 'two' ) );
		$f( 'woo.date_paid', 'after', $d( 31 ), array( 'one', 'two' ) );
		$f( 'woo.date_paid', 'after', $d( 10 ), array() );
		$f( 'woo.date_paid', 'within_days', '20', array( 'two' ) );
		$f( 'woo.date_paid', 'within_days', '5', array() );
		$f( 'woo.date_paid', 'older_than_days', '20', array( 'one' ) );
		$f( 'woo.date_paid', 'older_than_days', '40', array() );
		$f( 'woo.date_paid', 'empty', null, array( 'three', 'four' ) );
		$f( 'woo.date_paid', 'not_empty', null, array( 'one', 'two' ) );
		$this->assertNull( $this->filtered( $s, 'woo.date_paid', 'before', 'yesterday', $all ), 'dates must be Y-m-d' );
		$this->assertNull( $this->filtered( $s, 'woo.date_paid', 'within_days', '-3', $all ) );

		$f( 'woo.date_completed', 'not_empty', null, array( 'two' ) );
		$f( 'woo.date_completed', 'empty', null, array( 'one', 'three', 'four' ) );
		$f( 'woo.date_completed', 'before', $d( 7 ), array( 'two' ) );
		$f( 'woo.date_completed', 'before', $d( 8 ), array() );
		$f( 'woo.date_completed', 'after', $d( 9 ), array( 'two' ) );
		$f( 'woo.date_completed', 'after', $d( 8 ), array() );

		$f( 'woo.created_via', 'eq', 'checkout', array( 'one', 'four' ) );
		$f( 'woo.created_via', 'contains', 'rest', array( 'three' ) );
		$f( 'woo.created_via', 'neq', 'checkout', array( 'two', 'three' ) );
		$f( 'woo.created_via', 'eq', 'nowhere', array() );

		foreach ( array( 'woo.shipping_method', 'woo.items_count', 'woo.order_coupons', 'woo.customer_note' ) as $key ) {
			$this->assertNotInstanceOf( Filterable::class, $this->type( $key ), "$key does not filter" );
		}

		$one = $this->type( 'woo.billing_country' )->filter_condition( $this->config( 'woo.billing_country', 'a' ), 'in', array( 'US' ), $s, 'clf0' );
		$two = $this->type( 'woo.billing_name' )->filter_condition( $this->config( 'woo.billing_name', 'b' ), 'contains', 'Ann', $s, 'clf1' );
		if ( $hpos ) {
			$ids = $s->matching_ids(
				array(
					'join'  => $one['join'] . ' ' . $two['join'],
					'where' => "({$one['where']}) AND ({$two['where']})",
				),
				$all
			);
			$this->assertSame( array( 'one' ), $this->labels( $ids ), 'two rules over the address table use separate aliases' );
		}
	}

	/**
	 * @dataProvider storages
	 */
	public function test_order_edits_go_through_crud_and_respect_caps( bool $hpos ): void {
		$s = $this->use_storage( $hpos );
		$this->fixture_orders( $hpos );
		$o = $this->o;

		$this->assertSaved( $this->save( $s, 'woo.order_status', $o['one'], 'completed' ) );
		$this->assertSame( 'completed', wc_get_order( $o['one'] )->get_status() );
		$this->assertNotNull( wc_get_order( $o['one'] )->get_date_completed(), 'update_status ran its transition' );
		$notes = wc_get_order_notes( array( 'order_id' => $o['one'] ) );
		$contents = array_map( static fn( $n ) => $n->content, $notes );
		$this->assertNotEmpty( array_filter( $contents, static fn( $c ) => str_contains( $c, 'Order status changed from the Corinthian list.' ) ), 'the status change leaves a note' );
		$this->assertSame( 'completed', $this->type( 'woo.order_status' )->edit_value( $o['one'], $this->config( 'woo.order_status' ), $s ) );
		$this->assertSaved( $this->save( $s, 'woo.order_status', $o['one'], 'completed' ), 'setting the same status is a no-op' );
		$this->assertRefused( $this->save( $s, 'woo.order_status', $o['one'], 'nope' ) );
		$this->assertRefused( $this->save( $s, 'woo.order_status', $o['one'], 'wc-processing' ), 'slugs without the wc- prefix only' );
		$this->assertRefused( $this->save( $s, 'woo.order_status', $o['one'], '' ) );
		$this->assertSame( 'completed', wc_get_order( $o['one'] )->get_status() );
		$this->assertSame( array( 'one' ), $this->filtered( $s, 'woo.order_status', 'in', array( 'completed' ), array( $o['one'] ) ) );
		$field = $this->type( 'woo.order_status' )->edit_field( $this->config( 'woo.order_status' ), $s );
		$this->assertSame( array( 'set' ), $field['bulk'] );
		$this->assertArrayHasKey( 'processing', $field['options'] );

		$this->assertSaved( $this->save( $s, 'woo.customer_note', $o['two'], 'Ring <b>twice</b>' ) );
		clean_post_cache( $o['two'] );
		$this->assertSame( 'Ring twice', wc_get_order( $o['two'] )->get_customer_note() );
		$this->assertSame( 'Ring twice', $this->type( 'woo.customer_note' )->edit_value( $o['two'], $this->config( 'woo.customer_note' ), $s ) );
		$this->assertSaved( $this->save( $s, 'woo.customer_note', $o['two'], '' ) );
		$this->assertSame( '', wc_get_order( $o['two'] )->get_customer_note() );
		$this->assertRefused( $this->save( $s, 'woo.customer_note', $o['two'], str_repeat( 'x', 5001 ) ) );
		$this->assertRefused( $this->save( $s, 'woo.customer_note', $o['two'], array( 'a' ) ) );
		$this->assertRefused( $this->save( $s, 'woo.customer_note', $o['two'], 'x', 'clear' ), 'no bulk mode' );

		foreach ( array( 'woo.order_total', 'woo.payment_method', 'woo.billing_email', 'woo.date_paid', 'woo.created_via' ) as $key ) {
			$this->assertNotInstanceOf( Editable::class, $this->type( $key ), "$key is read-only" );
		}

		foreach ( array( $this->customer, 0 ) as $user ) {
			wp_set_current_user( $user );
			$this->assertRefused( $this->save( $s, 'woo.order_status', $o['three'], 'processing' ), "status as user $user" );
			$this->assertRefused( $this->save( $s, 'woo.customer_note', $o['three'], 'hacked' ), "note as user $user" );
		}
		$this->assertSame( 'on-hold', wc_get_order( $o['three'] )->get_status() );
		$this->assertSame( '', wc_get_order( $o['three'] )->get_customer_note() );

		wp_set_current_user( $this->manager );
		$this->assertSaved( $this->save( $s, 'woo.order_status', $o['three'], 'processing' ), 'shop manager changes status' );
		$this->assertSaved( $this->save( $s, 'woo.customer_note', $o['three'], 'ok' ), 'shop manager edits the note' );
		$this->assertSame( 'processing', wc_get_order( $o['three'] )->get_status() );

		wp_set_current_user( $this->manager );
		$this->assertRefused( $this->save( $s, 'woo.order_status', $this->product( 'P' ), 'processing' ), 'a product id is not an order' );
		$this->assertRefused( $this->save( $s, 'woo.order_status', 999999, 'processing' ), 'a missing order' );
	}



	// ---------------------------------------------------------------- HPOS screen

	public function test_hpos_screen_hooks_match_the_installed_woocommerce_source(): void {
		$s = $this->use_storage( true );
		if ( ! function_exists( 'wc_get_page_screen_id' ) ) {
			require_once WC_ABSPATH . 'includes/admin/wc-admin-functions.php';
		}
		$read = static fn( string $file ) => (string) file_get_contents( WC_ABSPATH . $file );
		$list = $read( 'src/Internal/Admin/Orders/ListTable.php' );
		$qry  = $read( 'src/Internal/DataStores/Orders/OrdersTableQuery.php' );
		$ids  = $read( 'includes/admin/wc-admin-functions.php' );

		$this->assertStringContainsString( 'add_filter( "manage_{$this->screen->id}_columns"', $list, 'columns filter' );
		$this->assertStringContainsString( 'do_action( "manage_{$this->screen->id}_custom_column", $column_name, $order )', $list, 'cell render action, with the order object' );
		$this->assertStringContainsString( "'woocommerce_' . \$this->order_type . '_list_table_sortable_columns'", $list, 'sortable list the list validates orderby against' );
		$this->assertStringContainsString( "'woocommerce_order_list_table_prepare_items_query_args'", $list, 'query args filter' );
		$this->assertStringContainsString( 'in_array( $field, $sortable, true )', $list, 'orderby must be in the list own sortable values' );
		$this->assertStringContainsString( "'woocommerce_orders_table_query_clauses'", $qry, 'query clauses filter' );
		$this->assertStringContainsString( "'woocommerce_page_wc-orders'", $ids, 'HPOS screen id' );

		$this->assertSame( 'woocommerce_page_wc-orders', $s->screen_id() );
		$this->assertSame( 'order', $s->object_type() );
		$this->assertSame( 'woo:orders', $s->key() );
		$this->assertStringEndsWith( 'admin.php?page=wc-orders', $s->url() );

		$s->register();
		$this->assertNotFalse( has_filter( 'manage_woocommerce_page_wc-orders_columns', array( $s, 'filter_columns' ) ) );
		$this->assertNotFalse( has_filter( 'manage_woocommerce_page_wc-orders_sortable_columns', array( $s, 'filter_sortable' ) ) );
		$this->assertNotFalse( has_filter( 'woocommerce_shop_order_list_table_sortable_columns', array( $s, 'allow_sorts' ) ) );
		$this->assertNotFalse( has_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $s, 'render_cell' ) ) );
		$this->assertNotFalse( has_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( $s, 'flag_query' ) ) );
		$this->assertNotFalse( has_filter( 'woocommerce_orders_table_query_clauses', array( $s, 'query_clauses' ) ) );
	}

	private function unhook( OrderScreen $s ): void {
		remove_filter( 'manage_woocommerce_page_wc-orders_columns', array( $s, 'filter_columns' ), PHP_INT_MAX - 10 );
		remove_filter( 'manage_woocommerce_page_wc-orders_sortable_columns', array( $s, 'filter_sortable' ), PHP_INT_MAX - 10 );
		remove_filter( 'woocommerce_shop_order_list_table_sortable_columns', array( $s, 'allow_sorts' ) );
		remove_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $s, 'render_cell' ), 10 );
		remove_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( $s, 'flag_query' ) );
		remove_filter( 'woocommerce_orders_table_query_clauses', array( $s, 'query_clauses' ), 10 );
	}

	/**
	 * Drives WooCommerce's own Orders list table against the OrderScreen hooks.
	 *
	 * @param array<string,string> $get
	 * @return array{0:list<string>,1:\Automattic\WooCommerce\Internal\Admin\Orders\ListTable}
	 */
	private function run_list_table( array $get ): array {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		unset( $_GET['corinthian_filter'], $_GET['orderby'], $_GET['order'] );
		set_current_screen( 'woocommerce_page_wc-orders' );
		$GLOBALS['hook_suffix'] = 'woocommerce_page_wc-orders';
		foreach ( $get as $k => $v ) {
			$_GET[ $k ] = $v;
		}
		$screen = new OrderScreen( $this->registry, $this->sets );
		$screen->register();
		$class = 'Automattic\WooCommerce\Internal\Admin\Orders\ListTable';
		$list  = new $class();
		$list->setup( array( 'order_type' => 'shop_order' ) );
		$list->prepare_items();
		$ids = array_map( static fn( $o ) => (int) $o->get_id(), $list->items );
		$this->unhook( $screen );
		return array( $this->labels( $ids ), $list );
	}

	public function test_hpos_orders_list_table_sorts_filters_and_renders_corinthian_columns(): void {
		$s = $this->use_storage( true );
		$this->fixture_orders( true );
		$this->sets->save(
			array(
				'screen'  => 'woo:orders',
				'title'   => 'Orders',
				'columns' => array(
					array( 'id' => 'tot', 'type' => 'woo.order_total', 'label' => 'Total' ),
					array( 'id' => 'ctry', 'type' => 'woo.billing_country', 'label' => 'Country' ),
					array( 'id' => 'st', 'type' => 'woo.order_status', 'label' => 'Status' ),
					array( 'id' => 'note', 'type' => 'woo.customer_note', 'label' => 'Note' ),
				),
			)
		);

		[ $asc ] = $this->run_list_table( array( 'orderby' => 'corinthian-tot', 'order' => 'asc' ) );
		$this->assertSame( array( 'four', 'two', 'three', 'one' ), $asc, 'sorted by total ascending through the list table' );
		[ $desc ] = $this->run_list_table( array( 'orderby' => 'corinthian-tot', 'order' => 'desc' ) );
		$this->assertSame( array( 'one', 'three', 'two', 'four' ), $desc );

		$filter = wp_json_encode(
			array(
				'match' => 'all',
				'rules' => array(
					array( 'column' => 'ctry', 'op' => 'in', 'value' => array( 'US' ) ),
				),
			)
		);
		[ $us ] = $this->run_list_table(
			array(
				'orderby'          => 'corinthian-tot',
				'order'            => 'desc',
				'corinthian_filter' => $filter,
			)
		);
		$this->assertSame( array( 'one', 'three' ), $us, 'filtered to US orders and sorted by total' );

		$none = wp_json_encode(
			array(
				'match' => 'all',
				'rules' => array(
					array( 'column' => 'ctry', 'op' => 'in', 'value' => array( 'FR' ) ),
				),
			)
		);
		[ $zero ] = $this->run_list_table( array( 'corinthian_filter' => $none ) );
		$this->assertSame( array(), $zero, 'a filter that matches nothing returns nothing' );

		$any = wp_json_encode(
			array(
				'match' => 'any',
				'rules' => array(
					array( 'column' => 'ctry', 'op' => 'in', 'value' => array( 'GB' ) ),
					array( 'column' => 'st', 'op' => 'in', 'value' => array( 'pending' ) ),
				),
			)
		);
		[ $either ] = $this->run_list_table( array( 'corinthian_filter' => $any, 'orderby' => 'corinthian-tot', 'order' => 'asc' ) );
		$this->assertSame( array( 'four', 'two' ), $either, 'OR across two columns' );

		[ $plain ] = $this->run_list_table( array( 'orderby' => 'date' ) );
		$this->assertCount( 4, $plain, 'without a Corinthian sort or filter the list is untouched' );

		// An unflagged order query is left alone even while a filter is in the URL.
		set_current_screen( 'woocommerce_page_wc-orders' );
		$_GET['corinthian_filter'] = $none;
		$screen                   = new OrderScreen( $this->registry, $this->sets );
		$screen->register();
		$other = wc_get_orders( array( 'type' => 'shop_order', 'status' => 'any', 'return' => 'ids', 'limit' => -1 ) );
		$this->unhook( $screen );
		$this->assertCount( 4, $other, 'only the list own query is filtered' );

		// Column headers, sortable keys and a rendered row, from a list table built while the hooks are live.
		set_current_screen( 'woocommerce_page_wc-orders' );
		$GLOBALS['hook_suffix'] = 'woocommerce_page_wc-orders';
		$screen                 = new OrderScreen( $this->registry, $this->sets );
		$screen->register();
		$class = 'Automattic\WooCommerce\Internal\Admin\Orders\ListTable';
		$list  = new $class();
		$list->setup( array( 'order_type' => 'shop_order' ) );
		$columns  = apply_filters( 'manage_woocommerce_page_wc-orders_columns', $list->get_columns() );
		$sortable = apply_filters( 'manage_woocommerce_page_wc-orders_sortable_columns', $list->get_sortable_columns() );
		$this->assertSame( array( 'cb', 'corinthian-tot', 'corinthian-ctry', 'corinthian-st', 'corinthian-note' ), array_keys( $columns ), 'the set replaces the list columns' );
		$this->assertArrayHasKey( 'corinthian-tot', $sortable );
		$this->assertArrayNotHasKey( 'corinthian-ctry', $sortable, 'a column that cannot sort gets no arrow' );
		$this->assertSame( 'corinthian-tot', $list->get_sortable_columns()['corinthian-tot'], 'the list accepts the orderby' );
		ob_start();
		$list->single_row( wc_get_order( $this->o['one'] ) );
		$row = (string) ob_get_clean();
		$this->unhook( $screen );
		$this->assertStringContainsString( 'column-corinthian-tot', $row );
		$this->assertStringContainsString( '100.00', $row, 'the cell comes through the custom column action' );
		$this->assertStringContainsString( 'United States', $row );
		$this->assertStringContainsString( 'Processing', $row );
		$this->assertStringContainsString( 'Leave at door', $row );
	}

	public function test_order_screen_permission_and_id_helpers(): void {
		$s = $this->use_storage( true );
		$this->fixture_orders( true );
		$o = $this->o;

		$this->assertTrue( $s->user_can_list() );
		$this->assertTrue( $s->user_can_read( $o['one'] ) );
		$this->assertTrue( OrderScreen::can_edit( $o['one'] ) );
		$this->assertFalse( OrderScreen::can_edit( 0 ) );
		$this->assertFalse( OrderScreen::can_edit( 999999 ) );
		$this->assertFalse( $s->user_can_read( $this->product( 'P' ) ), 'a product id is not an order' );

		wp_set_current_user( $this->manager );
		$this->assertTrue( $s->user_can_list() );
		$this->assertTrue( OrderScreen::can_edit( $o['two'] ) );

		wp_set_current_user( $this->customer );
		$this->assertFalse( $s->user_can_list() );
		$this->assertFalse( OrderScreen::can_edit( $o['two'] ) );
		$this->assertFalse( $s->user_can_read( $o['two'] ) );

		wp_set_current_user( $this->admin );
		$sql = $this->type( 'woo.billing_country' )->filter_condition( $this->config( 'woo.billing_country' ), 'in', array( 'US' ), $s, 'clf0' );
		$this->assertSame( array( 'one', 'three' ), $this->labels( $s->matching_ids( $sql, null ) ) );
		$this->assertSame( array( 'three' ), $this->labels( $s->matching_ids( $sql, array( $o['three'], $o['two'] ) ) ), 'limited to the given ids' );
		$this->assertSame( array(), $s->matching_ids( $sql, array() ), 'an empty id list matches nothing' );
		$this->assertSame( array(), $s->matching_ids( array( 'join' => '', 'where' => '' ), null ), 'an empty filter matches nothing, like Matcher' );
	}

	// ---------------------------------------------------------------- coupons

	/**
	 * @param array<string,mixed> $a
	 */
	private function coupon( string $code, array $a ): int {
		$c = new \WC_Coupon();
		$c->set_code( $code );
		$c->set_discount_type( $a['type'] );
		$c->set_amount( $a['amount'] );
		$c->set_usage_count( $a['used'] ?? 0 );
		$c->set_usage_limit( $a['limit'] ?? 0 );
		$c->set_individual_use( $a['individual'] ?? false );
		$c->set_free_shipping( $a['free'] ?? false );
		if ( isset( $a['expires'] ) ) {
			$c->set_date_expires( $a['expires'] );
		}
		return $c->save();
	}

	private function fixture_coupons(): void {
		$this->c = array(
			'one'   => $this->coupon( 'save10', array( 'type' => 'percent', 'amount' => '10', 'used' => 3, 'limit' => 10, 'individual' => true, 'expires' => strtotime( '2030-01-15 00:00:00 UTC' ) ) ),
			'two'   => $this->coupon( 'fiveoff', array( 'type' => 'fixed_cart', 'amount' => '5', 'free' => true, 'expires' => strtotime( '2030-06-01 00:00:00 UTC' ) ) ),
			'three' => $this->coupon( 'forever', array( 'type' => 'fixed_product', 'amount' => '2.5' ) ),
			'four'  => $this->coupon( 'old', array( 'type' => 'percent', 'amount' => '50', 'expires' => time() - 5 * DAY_IN_SECONDS ) ),
		);
	}

	public function test_coupon_columns_render_sort_and_filter(): void {
		$this->fixture_coupons();
		$s   = $this->coupons;
		$c   = $this->c;
		$all = array_values( $c );

		$this->assertSame( 'save10', $this->html( $s, 'woo.coupon_code', $c['one'] ) );
		$this->assertSame( 'Percentage discount', $this->html( $s, 'woo.coupon_type', $c['one'] ) );
		$this->assertSame( 'Fixed cart discount', $this->html( $s, 'woo.coupon_type', $c['two'] ) );
		$this->assertSame( '10%', $this->html( $s, 'woo.coupon_amount', $c['one'] ) );
		$this->assertStringContainsString( '5.00', $this->html( $s, 'woo.coupon_amount', $c['two'] ) );
		$this->assertSame( '3 / 10', $this->html( $s, 'woo.coupon_usage', $c['one'] ) );
		$this->assertSame( "0 / \u{221E}", $this->html( $s, 'woo.coupon_usage', $c['two'] ) );
		$this->assertSame( '2030-01-15', $this->html( $s, 'woo.coupon_expiry', $c['one'] ) );
		$this->assertStringContainsString( $this->dash(), $this->html( $s, 'woo.coupon_expiry', $c['three'] ) );
		$this->assertStringContainsString( 'dashicons-yes', $this->html( $s, 'woo.coupon_individual', $c['one'] ) );
		$this->assertStringContainsString( 'dashicons-no-alt', $this->html( $s, 'woo.coupon_individual', $c['two'] ) );
		$this->assertStringContainsString( 'dashicons-yes', $this->html( $s, 'woo.coupon_free_shipping', $c['two'] ) );
		$this->assertStringContainsString( $this->dash(), $this->html( $s, 'woo.coupon_code', 999999 ) );

		$this->assertSame( array( 'four', 'one', 'two', 'three' ), $this->sorted( $s, 'woo.coupon_expiry', 'ASC', $all ) );
		$this->assertSame( array( 'two', 'one', 'four', 'three' ), $this->sorted( $s, 'woo.coupon_expiry', 'DESC', $all ) );

		$f = fn( string $op, mixed $value, array $expected ) => $this->assertFilter( $s, 'woo.coupon_expiry', $op, $value, $expected, $all );
		$f( 'before', '2030-02-01', array( 'four', 'one' ) );
		$f( 'before', '2000-01-01', array() );
		$f( 'after', '2030-01-15', array( 'two' ) );
		$f( 'after', '2030-01-14', array( 'one', 'two' ) );
		$f( 'empty', null, array( 'three' ) );
		$f( 'not_empty', null, array( 'four', 'one', 'two' ) );
		$f( 'within_days', '10', array( 'four' ) );
		$f( 'within_days', '3', array() );
		$f( 'older_than_days', '2', array( 'four' ) );
		$f( 'older_than_days', '10', array() );
		$this->assertNull( $this->filtered( $s, 'woo.coupon_expiry', 'before', '15/01/2030', $all ) );

		foreach ( array( 'woo.coupon_code', 'woo.coupon_type', 'woo.coupon_amount', 'woo.coupon_usage', 'woo.coupon_individual', 'woo.coupon_free_shipping' ) as $key ) {
			$this->assertNotInstanceOf( Sortable::class, $this->type( $key ), "$key does not sort" );
			$this->assertNotInstanceOf( Filterable::class, $this->type( $key ), "$key does not filter" );
		}
	}

	public function test_coupon_edits_go_through_crud_and_respect_caps(): void {
		$this->fixture_coupons();
		$s = $this->coupons;
		$c = $this->c;

		$this->assertSaved( $this->save( $s, 'woo.coupon_amount', $c['one'], '15' ) );
		$this->assertSame( '15', ( new \WC_Coupon( $c['one'] ) )->get_amount() );
		$this->assertSame( '15', $this->type( 'woo.coupon_amount' )->edit_value( $c['one'], $this->config( 'woo.coupon_amount' ), $s ) );
		$this->assertRefused( $this->save( $s, 'woo.coupon_amount', $c['one'], '101' ), 'a percentage over 100' );
		$this->assertRefused( $this->save( $s, 'woo.coupon_amount', $c['one'], '-1' ) );
		$this->assertRefused( $this->save( $s, 'woo.coupon_amount', $c['one'], 'abc' ) );
		$this->assertRefused( $this->save( $s, 'woo.coupon_amount', $c['one'], '' ) );
		$this->assertSame( '15', ( new \WC_Coupon( $c['one'] ) )->get_amount() );
		$this->assertSaved( $this->save( $s, 'woo.coupon_amount', $c['two'], '250' ), 'a fixed amount may exceed 100' );
		$this->assertArrayNotHasKey( 'bulk', $this->type( 'woo.coupon_amount' )->edit_field( $this->config( 'woo.coupon_amount' ), $s ) );

		update_option( 'timezone_string', 'America/New_York' );
		$this->assertSaved( $this->save( $s, 'woo.coupon_expiry', $c['three'], '2031-03-04' ) );
		$coupon = new \WC_Coupon( $c['three'] );
		$this->assertSame( '2031-03-04', $coupon->get_date_expires()->date( 'Y-m-d' ), 'the date round-trips in the site timezone' );
		$this->assertSame( (new \DateTimeImmutable( '2031-03-04 00:00:00', new \DateTimeZone( 'America/New_York' ) ))->getTimestamp(), $coupon->get_date_expires()->getTimestamp() );
		$this->assertSame( '2031-03-04', $this->type( 'woo.coupon_expiry' )->edit_value( $c['three'], $this->config( 'woo.coupon_expiry' ), $s ) );
		$only = array( $c['three'] );
		$this->assertSame( array( 'three' ), $this->filtered( $s, 'woo.coupon_expiry', 'after', '2031-03-03', $only ) );
		$this->assertSame( array(), $this->filtered( $s, 'woo.coupon_expiry', 'after', '2031-03-04', $only ), 'after excludes the day itself' );
		$this->assertSame( array( 'three' ), $this->filtered( $s, 'woo.coupon_expiry', 'before', '2031-03-05', $only ) );
		$this->assertSame( array(), $this->filtered( $s, 'woo.coupon_expiry', 'before', '2031-03-04', $only ), 'before excludes the day itself' );
		$this->assertRefused( $this->save( $s, 'woo.coupon_expiry', $c['three'], '2031-13-45' ) );
		$this->assertRefused( $this->save( $s, 'woo.coupon_expiry', $c['three'], '03/04/2031' ) );
		$this->assertRefused( $this->save( $s, 'woo.coupon_expiry', $c['three'], 'tomorrow' ) );
		$this->assertSame( '2031-03-04', ( new \WC_Coupon( $c['three'] ) )->get_date_expires()->date( 'Y-m-d' ) );
		$this->assertSame( array( 'set', 'clear' ), $this->type( 'woo.coupon_expiry' )->edit_field( $this->config( 'woo.coupon_expiry' ), $s )['bulk'] );
		$this->assertSaved( $this->save( $s, 'woo.coupon_expiry', $c['three'], '', 'clear' ) );
		$this->assertNull( ( new \WC_Coupon( $c['three'] ) )->get_date_expires() );
		$this->assertSame( '', (string) get_post_meta( $c['three'], 'date_expires', true ) );
		$this->assertRefused( $this->save( $s, 'woo.coupon_expiry', $c['three'], '2031-03-04', 'append' ) );

		foreach ( array( $this->customer, 0 ) as $user ) {
			wp_set_current_user( $user );
			$this->assertRefused( $this->save( $s, 'woo.coupon_amount', $c['one'], '1' ), "amount as user $user" );
			$this->assertRefused( $this->save( $s, 'woo.coupon_expiry', $c['one'], '2040-01-01' ), "expiry as user $user" );
		}
		$this->assertSame( '15', ( new \WC_Coupon( $c['one'] ) )->get_amount() );
		wp_set_current_user( $this->manager );
		$this->assertSaved( $this->save( $s, 'woo.coupon_amount', $c['one'], '20' ), 'shop manager' );
		$this->assertRefused( $this->save( $s, 'woo.coupon_amount', $this->product( 'P' ), '20' ), 'a product id is not a coupon' );
	}

	// ---------------------------------------------------------------- REST

	public function test_rest_inline_and_bulk_edits_reach_the_product_columns(): void {
		$this->fixture_products();
		$plugin = Plugin::instance();
		( new \ReflectionProperty( $plugin->screens, 'screens' ) )->setValue( $plugin->screens, null );
		$set = $plugin->sets->save(
			array(
				'screen'  => 'post:product',
				'title'   => 'Woo',
				'columns' => array(
					array( 'id' => 'price', 'type' => 'woo.regular_price', 'label' => 'Price' ),
					array( 'id' => 'sku', 'type' => 'woo.sku', 'label' => 'SKU' ),
				),
			)
		);
		$call = static function ( string $route, array $params ) {
			$request = new \WP_REST_Request( 'POST', '/corinthian/v1/' . $route );
			$request->set_body_params( $params );
			return rest_do_request( $request );
		};
		$base = array(
			'screen' => 'post:product',
			'set'    => $set->id,
		);

		$ok = $call( 'edit', $base + array( 'column' => 'price', 'id' => $this->p['alpha'], 'value' => '20' ) );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertStringContainsString( '20.00', $ok->get_data()['html'] );
		$this->assertSame( '20', wc_get_product( $this->p['alpha'] )->get_regular_price() );

		$bad = $call( 'edit', $base + array( 'column' => 'price', 'id' => $this->p['alpha'], 'value' => 'abc' ) );
		$this->assertSame( 400, $bad->get_status() );

		$bulk = $call( 'bulk-edit', $base + array( 'column' => 'price', 'ids' => array( $this->p['bravo'], $this->p['foxtrot'], $this->p['echo'] ), 'mode' => 'adjust_percent', 'value' => '10' ) );
		$this->assertSame( 200, $bulk->get_status() );
		$data = $bulk->get_data();
		$this->assertSame( 2, $data['done'] );
		$this->assertSame( array( $this->p['echo'] ), array_column( $data['failed'], 'id' ), 'a product with no price is reported, not skipped silently' );
		$this->assertSame( '22', wc_get_product( $this->p['bravo'] )->get_regular_price() );
		$this->assertSame( '108.9', wc_get_product( $this->p['foxtrot'] )->get_regular_price() );

		$mode = $call( 'bulk-edit', $base + array( 'column' => 'sku', 'ids' => array( $this->p['bravo'] ), 'mode' => 'set', 'value' => 'X' ) );
		$this->assertSame( 400, $mode->get_status(), 'SKU has no bulk mode' );
		$this->assertSame( 'SKU-B', wc_get_product( $this->p['bravo'] )->get_sku() );

		wp_set_current_user( $this->customer );
		$this->assertContains( $call( 'edit', $base + array( 'column' => 'price', 'id' => $this->p['alpha'], 'value' => '1' ) )->get_status(), array( 401, 403 ) );
		$this->assertSame( '20', wc_get_product( $this->p['alpha'] )->get_regular_price() );
	}

	public function test_core_edit_and_matcher_work_on_hpos_orders(): void {
		$s = $this->use_storage( true );
		$this->fixture_orders( true );
		$set = \Corinthian\Plugin::instance()->sets->save(
			array(
				'screen'  => $s->key(),
				'title'   => 'Core order checks',
				'columns' => array( array( 'id' => 'st', 'type' => 'woo.order_status', 'label' => 'Status' ) ),
			)
		);
		$filter = static fn( array $statuses ) => \CorinthianPro\Filters\FilterSet::from( array( 'rules' => array( array( 'column' => 'st', 'op' => 'in', 'value' => $statuses ) ) ) );

		wp_set_current_user( $this->manager );
		$this->assertTrue( \CorinthianPro\Rest\EditController::can_edit( $s, $this->o['one'] ), 'a shop manager may edit an HPOS order through the core controller' );
		$this->assertSame( array( $this->o['one'] ), \CorinthianPro\Filters\Matcher::ids( $s, $set, $filter( array( 'processing' ) ) ) );
		$this->assertSame( array(), \CorinthianPro\Filters\Matcher::ids( $s, $set, $filter( array( 'cancelled' ) ) ), 'control: no cancelled orders' );

		wp_set_current_user( $this->customer );
		$this->assertFalse( \CorinthianPro\Rest\EditController::can_edit( $s, $this->o['one'] ), 'a customer may not' );
	}
}
