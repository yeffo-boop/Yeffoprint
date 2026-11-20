<?php
/**
 * Automatic shipping insurance on bigger orders.
 *
 * Direct request: "Any way to have it automatically added to orders over
 * $200? But I don't want the customer to see a separate line item, can
 * you just include it in the rate quote?"
 *
 * At checkout, when the items (after discounts) come to more than the
 * threshold, every paid shipping rate goes up by the Shippo insurance
 * cost for that value (a percentage, 1.25% by default, rounded up to the
 * cent). No new line, no new label: the customer just sees a slightly
 * higher shipping price. It works the same on flat rates and on live
 * Shippo rates. Free rates (free shipping, local pickup, "ships with
 * order") stay free, so the store pays the insurance on those, unless
 * "Also add it to free shipping" is ticked: then the free shipping
 * rate costs just the insurance (local pickup still never does).
 *
 * The amount is kept on the order's shipping line (hidden meta
 * _yp_insurance), and the admin Ship screen pre-ticks "Add Shippo
 * insurance" for any order over the threshold, so the label is actually
 * insured when it's bought.
 *
 * @package YeffoPrint_Core
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Shipping_Insurance {

	public const ENABLED_OPTION   = 'yeffoprint_auto_insure_enabled';
	public const THRESHOLD_OPTION = 'yeffoprint_auto_insure_threshold';
	public const PERCENT_OPTION   = 'yeffoprint_auto_insure_percent';
	public const ON_FREE_OPTION   = 'yeffoprint_auto_insure_on_free';

	/** Hidden (underscore) so it never shows on emails or My Account. */
	public const RATE_META = '_yp_insurance';

	private const DEFAULT_THRESHOLD = 200.0;

	/** Shippo (XCover) price for US shipments. */
	private const DEFAULT_PERCENT = 1.25;

	public function __construct() {
		// After every filter that sets a rate's price (live rates 80, 3D
		// prints 90, local pickup 100, add-on orders 110) and before the
		// delivery time text (120).
		add_filter( 'woocommerce_package_rates', [ $this, 'add_to_rates' ], 115, 2 );

		foreach ( [ self::ENABLED_OPTION, self::THRESHOLD_OPTION, self::PERCENT_OPTION, self::ON_FREE_OPTION ] as $option ) {
			add_action( 'add_option_' . $option, [ YeffoPrint_Local_Pickup::class, 'bust_rate_cache' ] );
			add_action( 'update_option_' . $option, [ YeffoPrint_Local_Pickup::class, 'bust_rate_cache' ] );
		}
	}

	public static function is_enabled(): bool {
		return (bool) get_option( self::ENABLED_OPTION, false );
	}

	public static function threshold(): float {
		return max( 0.0, (float) get_option( self::THRESHOLD_OPTION, self::DEFAULT_THRESHOLD ) );
	}

	public static function percent(): float {
		return max( 0.0, (float) get_option( self::PERCENT_OPTION, self::DEFAULT_PERCENT ) );
	}

	public static function on_free_shipping(): bool {
		return (bool) get_option( self::ON_FREE_OPTION, false );
	}

	/** Settings screen payload. */
	public static function settings(): array {
		return [
			'enabled'   => self::is_enabled(),
			'threshold' => self::threshold(),
			'percent'   => self::percent(),
			'on_free'   => self::on_free_shipping(),
		];
	}

	/** Int, not bool: update_option( key, false ) never creates a missing row. */
	public static function save( array $params ): void {
		update_option( self::ENABLED_OPTION, (int) ! empty( $params['enabled'] ) );
		update_option( self::THRESHOLD_OPTION, round( max( 0.0, (float) ( $params['threshold'] ?? self::DEFAULT_THRESHOLD ) ), 2 ) );
		update_option( self::ON_FREE_OPTION, (int) ! empty( $params['on_free'] ) );
		update_option( self::PERCENT_OPTION, round( min( 10.0, max( 0.0, (float) ( $params['percent'] ?? self::DEFAULT_PERCENT ) ) ), 2 ) );
	}

	/** Whether a package/order worth this much gets insured. */
	public static function applies_to( float $value ): bool {
		return self::is_enabled() && $value > self::threshold() && $value > 0;
	}

	/** Insurance cost for this value, rounded up to the cent. */
	public static function cost_for( float $value ): float {
		return ceil( round( $value * self::percent(), 4 ) ) / 100;
	}

	/**
	 * @param \WC_Shipping_Rate[] $rates
	 * @return \WC_Shipping_Rate[]
	 */
	public function add_to_rates( array $rates, array $package ): array {
		if ( ! self::is_enabled() || empty( $package['contents'] ) ) {
			return $rates;
		}

		$value = 0.0;
		foreach ( $package['contents'] as $item ) {
			$value += (float) ( $item['line_total'] ?? 0 );
		}
		$value = round( $value, 2 );
		if ( ! self::applies_to( $value ) ) {
			return $rates;
		}

		$cost = self::cost_for( $value );
		if ( $cost <= 0 ) {
			return $rates;
		}

		foreach ( $rates as $rate ) {
			if ( ! $rate instanceof \WC_Shipping_Rate ) {
				continue;
			}
			// Free shipping, local pickup and "ships with order" stay free,
			// except free shipping when the setting says to charge for it.
			if ( (float) $rate->get_cost() <= 0 && ! ( 'free_shipping' === $rate->get_method_id() && self::on_free_shipping() ) ) {
				continue;
			}
			if ( 'free_shipping' === $rate->get_method_id() ) {
				// "Free shipping $3.13" would read as a mistake.
				$rate->set_label( __( 'Discounted shipping', 'yeffoprint-core' ) );
			}
			$rate->set_cost( (string) round( (float) $rate->get_cost() + $cost, 2 ) );
			$rate->add_meta_data( self::RATE_META, $value );
		}

		return $rates;
	}

	/** Value the customer paid insurance on at checkout, or 0. */
	public static function paid_for( \WC_Order $order ): float {
		foreach ( $order->get_items( 'shipping' ) as $line ) {
			$value = (float) $line->get_meta( self::RATE_META, true );
			if ( $value > 0 ) {
				return $value;
			}
		}
		return 0.0;
	}

	/** Whether the Ship screen should start with "Add Shippo insurance" ticked. */
	public static function should_insure( \WC_Order $order ): bool {
		return self::paid_for( $order ) > 0 || self::applies_to( YeffoPrint_Order_Value::merchandise_value( $order ) );
	}
}
