<?php namespace TierPricingTable\Integrations\Plugins;

use TierPricingTable\PriceManager;
use WC_Product;

/**
 * Compatibility with WooCommerce Subscriptions: "All Products for Subscriptions" (APFS) plans,
 * synchronised/prorated first payments, sign-up fees, free trials and subscription switching.
 *
 * APFS applies a subscription plan's price/discount to a product purely through view-context
 * `woocommerce_product_get_price` filters (it never writes the price prop). WooCommerce Subscriptions
 * additionally filters non-subscription product prices to 0 during its "recurring totals" pass. Both
 * are `view`-context filters, so reading `get_price('edit')` returns the raw, unmodified price.
 *
 * This integration keeps tiered pricing correct on plan items without changing behaviour for any other
 * store:
 *
 *  - Charging: it recomputes a plan item's tiered price in the "edit" context, so a percentage tier is
 *    based on the raw price rather than the plan-discounted (and, in the recurring pass, zeroed) "view"
 *    price. The plan discount is then applied exactly once — by Subscriptions, when the stored tier
 *    price is read during totals — and the tier no longer collapses to false on renewals.
 *  - Display: it defers a plan item's cart price/subtotal to WooCommerce, which already reflects the
 *    plan discount, instead of the plugin rebuilding it from a fresh (plan-unaware) product instance.
 *
 * Everything is scoped to items actually purchased on a plan and is a no-op unless APFS is active.
 */
class WooCommerceSubscriptions extends PluginIntegrationAbstract {

	public function run() {

		// These hooks are registered unconditionally: run() executes at plugin-include time, before
		// WooCommerce Subscriptions lazily loads its "All Products for Subscriptions" cart API on
		// plugins_loaded. Availability is instead checked inside each callback (isSubscriptionPlanItem),
		// which runs at cart-calculation time, so the callbacks are safe no-ops when APFS is absent.

		// Charging: recompute plan items in the "edit" context (raw price base). Runs even when the
		// incoming price is false — during the recurring pass getTierPrice() returns false because the
		// product's "view" price has been filtered to 0.
		add_filter( 'tiered_pricing_table/cart/product_cart_price',
			function ( $price, $cartItem, $cartItemKey, $totalQuantity ) {

				if ( ! $this->isSubscriptionPlanItem( $cartItem ) ) {
					return $price;
				}

				return PriceManager::getPriceByRules( $totalQuantity, $cartItem['data']->get_id(), 'edit', 'cart',
					false );
			}, 10, 4 );

		// Display: defer the subtotal column to WooCommerce for plan items so it matches the charge.
		add_filter( 'tiered_pricing_table/cart/recalculate_cart_item_subtotal',
			function ( $state, $cartItem ) {
				return $this->isSubscriptionPlanItem( $cartItem ) ? false : $state;
			}, 10, 2 );

		// Display: defer the price column to WooCommerce for plan items.
		add_filter( 'tiered_pricing_table/cart/need_price_recalculation/item',
			function ( $state, $cartItem ) {
				return $this->isSubscriptionPlanItem( $cartItem ) ? false : $state;
			}, 10, 2 );

		/**
		 * Charging: give percentage tiers on ANY subscription product a correct base price.
		 *
		 * While the cart totals are being calculated, Subscriptions filters the product's "view" price
		 * (WC_Subscriptions_Cart::set_subscription_prices_for_calculation) to fold in the sign-up fee,
		 * replace the price with the fee during a free trial, and prorate it for synchronised products.
		 * A percentage tier reads that price as its base, and the discounted result is stored with
		 * set_price() — so Subscriptions applies the very same transformation a second time when the
		 * price is read again, double-prorating the first payment.
		 *
		 * Detach only that one filter while the tiered price is computed. Currency conversion and
		 * role-based pricing keep filtering the price, so the base stays correct in the customer's
		 * currency; Subscriptions then applies its own transformation exactly once, afterwards.
		 *
		 * Runs at the lowest priority so it sees the final decision: if anything earlier disabled the
		 * recalculation, getCartItemPrice() returns before product_cart_price fires and the filter would
		 * never be restored.
		 */
		add_filter( 'tiered_pricing_table/cart/need_price_recalculation',
			function ( $state, $cartItem ) {

				if ( $state && $this->isSubscriptionItem( $cartItem ) ) {
					$this->detachSubscriptionsPriceFilter();
				}

				return $state;
			}, PHP_INT_MAX, 2 );

		// Restore it as soon as the tiered price has been computed, before any other handler runs.
		add_filter( 'tiered_pricing_table/cart/product_cart_price', function ( $price ) {

			$this->reattachSubscriptionsPriceFilter();

			return $price;
		}, 1 );

		/**
		 * Switching: base the prorated switch cost on the tiered price.
		 *
		 * WCS_Switch_Totals_Calculator runs on "woocommerce_before_calculate_totals" at priority 99,
		 * while the tiered price is applied at 99999, so WCS_Switch_Cart_Item::get_new_price_per_day()
		 * reads the untiered price. The switch cost — days x (new per day - old per day) — is then
		 * calculated from the full price, overcharging the customer for the tier discount.
		 */
		add_filter( 'wcs_switch_proration_new_price_per_day',
			array( $this, 'useTieredPriceForSwitchProration' ), 10, 4 );
	}

	/**
	 * Replace the switch calculator's per-day price with one based on the tiered price.
	 *
	 * @param  float  $newPricePerDay
	 * @param  mixed  $subscription
	 * @param  array  $cartItem
	 * @param  int    $daysInNewCycle
	 *
	 * @return float
	 */
	public function useTieredPriceForSwitchProration( $newPricePerDay, $subscription, $cartItem, $daysInNewCycle ) {

		if ( $daysInNewCycle <= 0 || empty( $cartItem['data'] ) || ! ( $cartItem['data'] instanceof WC_Product ) ) {
			return $newPricePerDay;
		}

		$quantity = (int) ( $cartItem['quantity'] ?? 0 );

		if ( $quantity < 1 ) {
			return $newPricePerDay;
		}

		// Subscriptions re-attaches its price filter before firing this filter, so the same base-price
		// correction is needed here.
		$this->detachSubscriptionsPriceFilter();

		$tieredPrice = PriceManager::getPricingRule( $cartItem['data']->get_id() )
		                           ->getTierPrice( $quantity, false, 'cart' );

		$this->reattachSubscriptionsPriceFilter();

		// No tier applies at this quantity — leave the switch calculation untouched.
		if ( false === $tieredPrice ) {
			return $newPricePerDay;
		}

		return ( (float) $tieredPrice * $quantity ) / $daysInNewCycle;
	}

	/**
	 * Hooks Subscriptions uses to transform the product price while totals are calculated.
	 */
	const SUBSCRIPTIONS_PRICE_HOOKS = array(
		'woocommerce_product_get_price',
		'woocommerce_product_variation_get_price',
	);

	const SUBSCRIPTIONS_PRICE_CALLBACK = 'WC_Subscriptions_Cart::set_subscription_prices_for_calculation';

	/**
	 * Hooks this integration actually detached, so only those are restored.
	 *
	 * @var string[]
	 */
	protected $detachedPriceHooks = array();

	protected function detachSubscriptionsPriceFilter() {

		$this->detachedPriceHooks = array();

		foreach ( self::SUBSCRIPTIONS_PRICE_HOOKS as $hook ) {
			// remove_filter() reports whether the callback was actually attached. Outside the totals
			// calculation Subscriptions removes it itself, and re-adding it there would change prices
			// that are only being displayed.
			if ( remove_filter( $hook, self::SUBSCRIPTIONS_PRICE_CALLBACK, 100 ) ) {
				$this->detachedPriceHooks[] = $hook;
			}
		}
	}

	protected function reattachSubscriptionsPriceFilter() {

		foreach ( $this->detachedPriceHooks as $hook ) {
			add_filter( $hook, self::SUBSCRIPTIONS_PRICE_CALLBACK, 100, 2 );
		}

		$this->detachedPriceHooks = array();
	}

	/**
	 * Whether a cart item is any kind of subscription: a native subscription product/variation or a
	 * product purchased on an APFS plan (Subscriptions reports both through the same API).
	 *
	 * @param  array  $cartItem
	 *
	 * @return bool
	 */
	protected function isSubscriptionItem( $cartItem ): bool {

		if ( ! class_exists( '\WC_Subscriptions_Product' ) ) {
			return false;
		}

		if ( empty( $cartItem['data'] ) || ! ( $cartItem['data'] instanceof WC_Product ) ) {
			return false;
		}

		return (bool) \WC_Subscriptions_Product::is_subscription( $cartItem['data'] );
	}

	/**
	 * Whether a cart item is currently purchased on a subscription plan (APFS).
	 *
	 * Reads the scheme stored on the cart item itself, which survives the cloned recurring cart, rather
	 * than the product object's runtime state.
	 *
	 * @param  array  $cartItem
	 *
	 * @return bool
	 */
	protected function isSubscriptionPlanItem( $cartItem ): bool {

		if ( ! class_exists( '\WCS_ATT_Cart' ) ) {
			return false;
		}

		if ( empty( $cartItem['data'] ) || ! ( $cartItem['data'] instanceof WC_Product ) ) {
			return false;
		}

		$scheme = \WCS_ATT_Cart::get_subscription_scheme( $cartItem );

		// A non-empty, non-'0' scheme key means the item is on a plan; '0'/null means a one-time purchase.
		return ! empty( $scheme ) && '0' !== (string) $scheme;
	}

	public function getTitle(): string {
		return 'WooCommerce Subscriptions';
	}

	public function getDescription(): string {
		return __( 'Apply tiered pricing correctly to products purchased on a WooCommerce Subscriptions plan, in both the initial and recurring totals.', 'tier-pricing-table' );
	}

	public function getSlug(): string {
		return 'woocommerce-subscriptions';
	}

	public function getAuthorURL(): string {
		return 'https://woocommerce.com/products/woocommerce-subscriptions/';
	}

	public function getIconURL(): string {
		return $this->getContainer()->getFileManager()->locateAsset( 'admin/integrations/woocommerce-develop.jpeg' );
	}

	public function getIntegrationCategory(): string {
		return 'custom_product_types';
	}
}
