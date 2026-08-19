<?php
/**
 * Shared E2E seed data utilities.
 *
 * @package Automattic\WCShipping\Testing
 */

namespace Automattic\WCShipping\Testing;

defined( 'ABSPATH' ) || exit;

/**
 * Creates deterministic WooCommerce product and order data for e2e tests.
 */
class E2ESeedData {

	/**
	 * Option key for the latest seeded order id.
	 */
	private const OPTION_ORDER_ID = 'wcshipping_e2e_order_id';

	/**
	 * Option key for the latest seeded product id.
	 */
	private const OPTION_PRODUCT_ID = 'wcshipping_e2e_product_id';

	/**
	 * Seed a fresh product and order for e2e tests.
	 *
	 * @return array<string,int>
	 * @throws \RuntimeException If WooCommerce is unavailable or data cannot be created.
	 */
	public static function seed() {
		self::ensure_woocommerce_ready();
		self::cleanup_previous_seed();

		$product_id = self::create_product();
		$order_id   = self::create_order( $product_id );

		update_option( self::OPTION_PRODUCT_ID, $product_id, false );
		update_option( self::OPTION_ORDER_ID, $order_id, false );

		return array(
			'productId' => $product_id,
			'orderId'   => $order_id,
		);
	}

	/**
	 * Return the latest seeded identifiers stored in options.
	 *
	 * @return array<string,int>
	 */
	public static function get_seed_state() {
		return array(
			'productId' => absint( get_option( self::OPTION_PRODUCT_ID ) ),
			'orderId'   => absint( get_option( self::OPTION_ORDER_ID ) ),
		);
	}

	/**
	 * Ensure WooCommerce APIs are ready before seeding.
	 *
	 * @return void
	 * @throws \RuntimeException If WooCommerce is unavailable.
	 */
	private static function ensure_woocommerce_ready() {
		if ( ! function_exists( 'wc_create_order' ) || ! class_exists( '\WC_Product_Simple' ) ) {
			throw new \RuntimeException( 'WooCommerce is not available for e2e seeding.' );
		}
	}

	/**
	 * Delete previously-seeded entities so each setup starts clean.
	 *
	 * @return void
	 */
	private static function cleanup_previous_seed() {
		$previous_order_id = absint( get_option( self::OPTION_ORDER_ID ) );

		if ( $previous_order_id > 0 ) {
			wp_delete_post( $previous_order_id, true );
		}
	}

	/**
	 * Create a simple physical product if the store does not already have one.
	 *
	 * @return int
	 * @throws \RuntimeException If the product cannot be created.
	 */
	private static function create_product() {
		$existing_product_id = self::find_existing_product_id();

		if ( $existing_product_id > 0 ) {
			return $existing_product_id;
		}

		$product = new \WC_Product_Simple();
		$product->set_props(
			array(
				'name'          => 'Dummy Product',
				'status'        => 'publish',
				'regular_price' => 10,
				'price'         => 10,
				'sku'           => 'WCSHIPPING E2E SKU ' . time(),
				'manage_stock'  => false,
				'tax_status'    => 'taxable',
				'downloadable'  => false,
				'virtual'       => false,
				'stock_status'  => 'instock',
				'weight'        => '1.1',
				'length'        => '1',
				'width'         => '1',
				'height'        => '1',
			)
		);
		$product->save();

		$product_id = $product->get_id();
		if ( $product_id <= 0 ) {
			throw new \RuntimeException( 'Could not create seeded e2e product.' );
		}

		return $product_id;
	}

	/**
	 * Find an existing shippable product to reuse for e2e seeding.
	 *
	 * @return int
	 */
	private static function find_existing_product_id() {
		$product_ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 20,
				'orderby'        => 'ID',
				'order'          => 'DESC',
			)
		);

		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( $product instanceof \WC_Product && $product->needs_shipping() ) {
				return (int) $product_id;
			}
		}

		return 0;
	}

	/**
	 * Create an order with one line item.
	 *
	 * @param int $product_id Product id.
	 * @return int
	 * @throws \RuntimeException If the order cannot be created.
	 */
	private static function create_order( $product_id ) {
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			throw new \RuntimeException( 'Could not load seeded e2e product.' );
		}

		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		$order                  = wc_create_order(
			array(
				'status'        => 'pending',
				'customer_id'   => 1,
				'customer_note' => '',
				'total'         => '',
			)
		);

		$item = new \WC_Order_Item_Product();
		$item->set_props(
			array(
				'product'  => $product,
				'quantity' => 4,
				'subtotal' => wc_get_price_excluding_tax( $product, array( 'qty' => 4 ) ),
				'total'    => wc_get_price_excluding_tax( $product, array( 'qty' => 4 ) ),
			)
		);
		$item->save();
		$order->add_item( $item );

		$order->set_created_via( 'e2e' );
		$order->set_billing_first_name( 'Jeroen' );
		$order->set_billing_last_name( 'Sormani' );
		$order->set_billing_company( 'WooCompany' );
		$order->set_billing_address_1( 'WooAddress' );
		$order->set_billing_address_2( '' );
		$order->set_billing_city( 'WooCity' );
		$order->set_billing_state( 'NY' );
		$order->set_billing_postcode( '12345' );
		$order->set_billing_country( 'US' );
		$order->set_billing_email( 'admin@example.org' );
		$order->set_billing_phone( '555-32123' );
		$order->set_shipping_first_name( 'E2E' );
		$order->set_shipping_last_name( 'Tester' );
		$order->set_shipping_address_1( '123 Main St' );
		$order->set_shipping_address_2( '' );
		$order->set_shipping_city( 'San Francisco' );
		$order->set_shipping_state( 'CA' );
		$order->set_shipping_postcode( '94103' );
		$order->set_shipping_country( 'US' );
		$order->add_item( self::create_shipping_item( $product_id ) );
		$order->set_shipping_total( 10 );
		$order->set_discount_total( 0 );
		$order->set_discount_tax( 0 );
		$order->set_cart_tax( 0 );
		$order->set_shipping_tax( 0 );
		$order->set_total( 50 );
		self::set_bacs_payment_method( $order );
		$order->save();

		$order_id = $order->get_id();
		if ( $order_id <= 0 ) {
			throw new \RuntimeException( 'Could not create seeded e2e order.' );
		}

		return $order_id;
	}

	/**
	 * Create a shipping line that includes packaging metadata expected by the label UI.
	 *
	 * @param int $product_id Product id.
	 * @return \WC_Order_Item_Shipping
	 */
	private static function create_shipping_item( $product_id ) {
		$shipping_taxes = \WC_Tax::calc_shipping_tax( '10', \WC_Tax::get_shipping_tax_rates() );
		$rate           = new \WC_Shipping_Rate(
			'flat_rate_shipping',
			'Flat rate shipping',
			'10',
			$shipping_taxes,
			'flat_rate'
		);
		$shipping_item  = new \WC_Order_Item_Shipping();

		$shipping_item->set_props(
			array(
				'method_title' => $rate->label,
				'method_id'    => $rate->id,
				'total'        => wc_format_decimal( $rate->cost ),
				'taxes'        => $rate->taxes,
			)
		);
		foreach ( $rate->get_meta_data() as $key => $value ) {
			$shipping_item->add_meta_data( $key, $value, true );
		}
		$shipping_item->add_meta_data(
			'wcshipping_packages',
			array(
				'default_box' => array(
					'id'     => 'default_box',
					'box_id' => 'custom_box',
					'height' => 1,
					'length' => 1,
					'weight' => 1,
					'width'  => 1,
					'items'  => array(
						array(
							'product_id' => $product_id,
							'quantity'   => 1,
							'height'     => 1,
							'length'     => 1,
							'weight'     => 1,
							'width'      => 1,
						),
					),
				),
			)
		);

		return $shipping_item;
	}

	/**
	 * Mirror WC_Helper_Order and set BACS as the order payment method.
	 *
	 * @param \WC_Order $order Order instance.
	 * @return void
	 */
	private static function set_bacs_payment_method( $order ) {
		$payment_gateways = WC()->payment_gateways ? WC()->payment_gateways->payment_gateways() : array();

		if ( isset( $payment_gateways['bacs'] ) ) {
			$order->set_payment_method( $payment_gateways['bacs'] );
			return;
		}

		$order->set_payment_method( 'bacs' );
		$order->set_payment_method_title( 'Direct bank transfer' );
	}
}
