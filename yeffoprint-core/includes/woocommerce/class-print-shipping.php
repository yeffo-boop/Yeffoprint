<?php
/**
 * Shipping prices for 3D prints. Direct request: "I need to set
 * shipping prices for the 3d prints I have. It costs a little more to
 * ship those. I need to take that into account when a customer adds to
 * an order also, they would need to pay the difference on the shipping.
 * Orders should only charge the higher shipping option, never combine."
 * Then: "$12 for usps ground advantage, $25 for ups 2nd day air, $40
 * for international", so a print carries one price per shipping option
 * (YeffoPrint_Print_Meta::get_shipping(), keyed by the zone method's
 * instance id).
 *
 * At checkout each paid rate is raised to the highest price any print in
 * the cart sets for that option, and never added on top: USPS Ground at
 * $6 with a $12 print becomes $12, and two $12 prints still ship for
 * $12. An option a print sets no price for keeps its normal rate. Free
 * shipping ("truly free shipping over $200") and local pickup are left
 * alone.
 *
 * An add-on order (class-order-addon-checkout.php) pays only the
 * difference between what the whole group now needs on the root order's
 * shipping option and what the group has already been charged, never
 * less than zero.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Print_Shipping {

	/** Package key carrying the cart's highest print price per option, so WooCommerce's per-package rate cache tells carts apart. */
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

	/**
	 * Merges price maps (instance id => price), keeping the highest per option.
	 *
	 * @param array<string, float> $into
	 * @param array<string, float> $prices
	 * @return array<string, float>
	 */
	private static function keep_highest( array $into, array $prices ): array {
		foreach ( $prices as $instance => $price ) {
			$into[ (string) $instance ] = max( (float) ( $into[ (string) $instance ] ?? 0 ), (float) $price );
		}
		return $into;
	}

	/** @return array<string, float> The highest print price per shipping option among a package's items. */
	public static function package_shipping( array $package ): array {
		$highest = [];
		foreach ( (array) ( $package['contents'] ?? [] ) as $item ) {
			$print_id = (int) ( $item[ YeffoPrint_Cart_Item_Keys::PRINT_ID ] ?? 0 );
			if ( $print_id ) {
				$highest = self::keep_highest( $highest, YeffoPrint_Print_Meta::get_shipping( $print_id ) );
			}
		}
		ksort( $highest );
		return $highest;
	}

	/**
	 * The highest print price per shipping option on a placed order, as
	 * frozen into each line's snapshot at checkout. Orders placed before
	 * print shipping existed have none, so an add-on never bills for what
	 * an older order wasn't charged.
	 *
	 * @return array<string, float>
	 */
	public static function order_shipping( \WC_Order $order ): array {
		$highest = [];
		foreach ( $order->get_items() as $item ) {
			$snapshot = json_decode( (string) $item->get_meta( '_yp_print_snapshot', true ), true );
			if ( is_array( $snapshot ) && isset( $snapshot['shipping'] ) && is_array( $snapshot['shipping'] ) ) {
				$highest = self::keep_highest( $highest, $snapshot['shipping'] );
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

	/** @return array<string, float> */
	public static function prices_for_package( array $package ): array {
		return isset( $package[ self::PACKAGE_KEY ] ) && is_array( $package[ self::PACKAGE_KEY ] )
			? $package[ self::PACKAGE_KEY ]
			: self::package_shipping( $package );
	}

	public function raise_rates( $rates, $package ) {
		$prices = self::prices_for_package( (array) $package );
		if ( ! $prices || ! is_array( $rates ) ) {
			return $rates;
		}

		foreach ( $rates as $rate ) {
			if ( ! $rate instanceof \WC_Shipping_Rate || in_array( $rate->get_method_id(), self::UNTOUCHED_METHODS, true ) ) {
				continue;
			}

			$floor = (float) ( $prices[ (string) $rate->get_instance_id() ] ?? 0 );
			if ( (float) $rate->get_cost() < $floor ) {
				$rate->set_cost( (string) $floor );
			}
		}

		return $rates;
	}

	/**
	 * What an add-on to $root still owes for shipping once the add-on
	 * cart's own prints ($new_prices, instance id => price) join the
	 * group: the group's highest price on the root's shipping option,
	 * less everything the group has already been charged. Nothing when
	 * the root ships free or is picked up.
	 *
	 * @param array<string, float> $new_prices
	 */
	public static function addon_shipping_due( \WC_Order $root, array $new_prices ): float {
		$instance = '';
		foreach ( $root->get_shipping_methods() as $method ) {
			$method_id = $method->get_method_id();
			if ( in_array( $method_id, self::UNTOUCHED_METHODS, true ) || YeffoPrint_Local_Pickup::METHOD_ID === $method_id ) {
				return 0.0;
			}
			$instance = (string) $method->get_instance_id();
		}

		$paid   = (float) $root->get_shipping_total();
		$prices = self::keep_highest( self::order_shipping( $root ), $new_prices );

		foreach ( YeffoPrint_Order_Addon::addon_ids_for_root( $root->get_id() ) as $addon_id ) {
			$addon = wc_get_order( $addon_id );
			if ( ! $addon instanceof \WC_Order || in_array( $addon->get_status(), self::DEAD_STATUSES, true ) ) {
				continue;
			}

			$prices = self::keep_highest( $prices, self::order_shipping( $addon ) );
			$paid  += (float) $addon->get_shipping_total();
		}

		$needed = max( (float) $root->get_shipping_total(), (float) ( $prices[ $instance ] ?? 0 ) );

		return max( 0.0, round( $needed - $paid, 2 ) );
	}
}
