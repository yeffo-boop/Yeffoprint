<?php
/**
 * Waives shipping on an add-on order's cart, and links the two orders
 * together the moment the add-on order is actually placed. See
 * class-order-addon.php's own docblock for the feature this belongs to.
 *
 * Direct report: "when a customer presses the Add to Order button it is
 * trying to charge them shipping a second time." The waiver used to be a
 * negative fee next to the normal shipping line, so checkout still
 * showed (and asked them to pick) a paid shipping option. Now an add-on
 * cart is offered exactly one rate, "Ships with Order N", at $0. The
 * root id is folded into each shipping package so WooCommerce's
 * per-package rate cache can't hand back the normal paid rates.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Order_Addon_Checkout {

	/** Shipping rate/method id an add-on order is placed with. */
	public const METHOD_ID = 'yp_addon_combined';

	public function __construct() {
		add_filter( 'woocommerce_cart_shipping_packages', [ $this, 'tag_packages' ] );

		// After class-local-pickup.php's own rate (100), so pickup is
		// replaced too: the add-on goes wherever its root order goes.
		add_filter( 'woocommerce_package_rates', [ $this, 'combined_rate' ], 110, 2 );

		// Classic checkout and the Checkout block each fire their own hook.
		add_action( 'woocommerce_checkout_order_processed', [ $this, 'attach_to_root' ], 10, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'attach_to_root_store_api' ] );
	}

	/** The root order this cart is an add-on to, while it can still take one. */
	private static function eligible_root(): ?\WC_Order {
		$root_id = YeffoPrint_Order_Addon::pending_root_id();
		if ( ! $root_id || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$root = wc_get_order( $root_id );
		if ( ! $root instanceof \WC_Order || ! YeffoPrint_Order_Addon::eligibility( $root )['eligible'] ) {
			return null;
		}

		return $root;
	}

	public function tag_packages( $packages ) {
		$root = self::eligible_root();
		if ( ! $root || ! is_array( $packages ) ) {
			return $packages;
		}

		foreach ( $packages as $index => $package ) {
			$packages[ $index ]['yp_addon_root'] = $root->get_id();
		}

		return $packages;
	}

	public function combined_rate( $rates, $package ) {
		$root_id = (int) ( $package['yp_addon_root'] ?? 0 );
		if ( ! $root_id ) {
			return $rates;
		}

		$root = wc_get_order( $root_id );
		if ( ! $root instanceof \WC_Order ) {
			return $rates;
		}

		$rate = new \WC_Shipping_Rate(
			self::METHOD_ID,
			sprintf(
				/* translators: %s: the order this add-on ships together with */
				__( 'Ships with Order %s (already paid)', 'yeffoprint-core' ),
				$root->get_order_number()
			),
			0,
			[],
			self::METHOD_ID
		);

		return [ self::METHOD_ID => $rate ];
	}

	public function attach_to_root_store_api( $order ): void {
		if ( $order instanceof \WC_Order ) {
			$this->attach_to_root( $order->get_id(), [], $order );
		}
	}

	/**
	 * @param int       $order_id
	 * @param array     $posted_data
	 * @param \WC_Order $order
	 */
	public function attach_to_root( $order_id, $posted_data, $order ): void {
		$root_id = YeffoPrint_Order_Addon::pending_root_id();

		// One-shot regardless of outcome below — never carries into a
		// later, unrelated order this same browser session happens to
		// place afterward.
		YeffoPrint_Order_Addon::clear_session();

		if ( ! $root_id || $root_id === (int) $order_id || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$root = wc_get_order( $root_id );
		if ( ! $root instanceof \WC_Order || ! YeffoPrint_Order_Addon::eligibility( $root )['eligible'] ) {
			return; // Became ineligible between adding to cart and placing the order — this just completes as a normal, separately-shipped order.
		}

		$order->update_meta_data( YeffoPrint_Order_Addon::SHIP_WITH_ORDER_ID_META, $root_id );
		$order->save();

		$order->add_order_note( sprintf(
			/* translators: %s: the original order this one ships together with */
			__( 'Placed as an add-on to Order %s — ships together, no separate shipping charge.', 'yeffoprint-core' ),
			$root->get_order_number()
		) );

		$root->add_order_note( sprintf(
			/* translators: %s: the new add-on order that ships together with this one */
			__( 'Order %s was added on to this order — pack and ship them together.', 'yeffoprint-core' ),
			$order->get_order_number()
		) );
	}
}
