<?php
/**
 * Shipping prices for 3D prints. Direct request: "I need to set
 * shipping prices for the 3d prints I have. It costs a little more to
 * ship those. I need to take that into account when a customer adds to
 * an order also, they would need to pay the difference on the shipping.
 * Orders should only charge the higher shipping option, never combine."
 *
 * Each 3D print (or one of its sizes) can carry a shipping price
 * (YeffoPrint_Print_Meta::shipping_for_size()). At checkout every paid
 * rate is raised to the highest of those in the cart, and never added
 * on top: USPS Ground at $6 with a $10 print becomes $10, UPS 2nd Day
 * at $15 stays $15, and two $10 prints still ship for $10. Free
 * shipping and local pickup are left alone.
 *
 * An add-on order (class-order-addon-checkout.php) pays only the
 * difference between what the whole group now needs and what the group
 * has already been charged, never less than zero.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Print_Shipping {

	/** Package key carrying the highest print shipping price, so WooCommerce's per-package rate cache tells carts apart. */
	public const PACKAGE_KEY = 'yp_print_shipping';

	/** Rates that stay as they are whatever's in the cart. */
	private const UNTOUCHED_METHODS = [ 'free_shipping', 'local_pickup', 'pickup_location' ];

	/** Add-on orders that never ship, so their shipping doesn't count as paid. */
	private const DEAD_STATUSES = [ 'cancelled', 'failed', 'refunded', 'trash', 'checkout-draft' ];

	public function __construct() {
		add_filter( 'woocommerce_cart_shipping_packages', [ $this, 'tag_packages' ] );

		// After the zone methods, before class-local-pickup.php (100) adds
		// its own free rate and class-order-addon-checkout.php (110)
		// replaces everything for an add-on cart.
		add_filter( 'woocommerce_package_rates', [ $this, 'raise_rates' ], 90, 2 );
	}

	/** One cart item's (or order snapshot's) print shipping price, 0 for anything that isn't a 3D print. */
	public static function cart_item_shipping( array $cart_item ): float {
		$print_id = (int) ( $cart_item[ YeffoPrint_Cart_Item_Keys::PRINT_ID ] ?? 0 );
		if ( ! $print_id ) {
			return 0.0;
		}

		return YeffoPrint_Print_Meta::shipping_for_size( $print_id, (string) ( $cart_item[ YeffoPrint_Cart_Item_Keys::PRINT_SIZE ] ?? '' ) );
	}

	/** The highest print shipping price among a package's items. */
	public static function package_shipping( array $package ): float {
		$highest = 0.0;
		foreach ( (array) ( $package['contents'] ?? [] ) as $item ) {
			$highest = max( $highest, self::cart_item_shipping( (array) $item ) );
		}
		return $highest;
	}

	/**
	 * The highest print shipping price on a placed order, as frozen into
	 * each line's snapshot at checkout. Orders placed before print
	 * shipping existed count as 0, so an add-on never bills for what an
	 * older order wasn't charged.
	 */
	public static function order_shipping( \WC_Order $order ): float {
		$highest = 0.0;
		foreach ( $order->get_items() as $item ) {
			$snapshot = json_decode( (string) $item->get_meta( '_yp_print_snapshot', true ), true );
			if ( is_array( $snapshot ) && isset( $snapshot['shipping'] ) ) {
				$highest = max( $highest, (float) $snapshot['shipping'] );
			}
		}
		return $highest;
	}

	public function tag_packages( $packages ) {
		if ( ! is_array( $packages ) ) {
			return $packages;
		}

		foreach ( $packages as $index => $package ) {
			$packages[ $index ][ self::PACKAGE_KEY ] = self::package_shipping( (array) $package );
		}

		return $packages;
	}

	public function raise_rates( $rates, $package ) {
		$floor = isset( $package[ self::PACKAGE_KEY ] ) ? (float) $package[ self::PACKAGE_KEY ] : self::package_shipping( (array) $package );
		if ( $floor <= 0 || ! is_array( $rates ) ) {
			return $rates;
		}

		foreach ( $rates as $rate ) {
			if ( ! $rate instanceof \WC_Shipping_Rate || in_array( $rate->get_method_id(), self::UNTOUCHED_METHODS, true ) ) {
				continue;
			}

			if ( (float) $rate->get_cost() < $floor ) {
				$rate->set_cost( (string) $floor );
			}
		}

		return $rates;
	}

	/**
	 * What an add-on to $root still owes for shipping once $new_items_shipping
	 * (the add-on cart's own highest print price) joins the group: the
	 * group's highest needed rate, less everything the group has already
	 * been charged. Nothing when the root ships free or is picked up.
	 */
	public static function addon_shipping_due( \WC_Order $root, float $new_items_shipping ): float {
		foreach ( $root->get_shipping_methods() as $method ) {
			$method_id = $method->get_method_id();
			if ( in_array( $method_id, self::UNTOUCHED_METHODS, true ) || YeffoPrint_Local_Pickup::METHOD_ID === $method_id ) {
				return 0.0;
			}
		}

		$needed = max( (float) $root->get_shipping_total(), self::order_shipping( $root ), $new_items_shipping );
		$paid   = (float) $root->get_shipping_total();

		foreach ( YeffoPrint_Order_Addon::addon_ids_for_root( $root->get_id() ) as $addon_id ) {
			$addon = wc_get_order( $addon_id );
			if ( ! $addon instanceof \WC_Order || in_array( $addon->get_status(), self::DEAD_STATUSES, true ) ) {
				continue;
			}

			$needed = max( $needed, self::order_shipping( $addon ) );
			$paid  += (float) $addon->get_shipping_total();
		}

		return max( 0.0, round( $needed - $paid, 2 ) );
	}
}
