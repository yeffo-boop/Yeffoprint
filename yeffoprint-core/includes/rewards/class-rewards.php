<?php
/**
 * Points-based rewards program.
 *
 * Listed as a V1 non-goal in PROJECT_SPEC §19 ("rewards dashboard,
 * referral system") — built now per direct request. Design, per that
 * same conversation: points-per-dollar earning (not flat % credit),
 * customer opts in to redeeming rather than it being automatic, and
 * deliberately no minimum balance or expiration.
 *
 * Balance is a running total on the user (never negative), not a full
 * transaction ledger — "keep it simple" per the same conversation.
 * Order-level earned/redeemed amounts are still recorded as order
 * meta so the account page can show history without one.
 *
 * Redemption is a negative WC_Cart fee (a discount), not a coupon or
 * a fake product — unlike the custom design fee (class-custom-design-
 * fee-product.php), this reduces the whole cart rather than pricing
 * one line item, and WooCommerce's native fee mechanism is understood
 * by both classic and Blocks-based cart/checkout (Architecture §9's
 * Blocks caveat) with no extra Store API integration needed.
 *
 * Point award/deduction happens at payment time, hooked to the same
 * three events as class-custom-order-payment.php and for the same
 * documented reason: `woocommerce_payment_complete` isn't reliably
 * fired by every gateway this store uses (its own manual/Venmo/Zelle
 * gateways move an order straight to "processing" via update_status()
 * instead) — `woocommerce_order_status_processing`/`_completed` catch
 * that regardless of gateway. All three call the same idempotent
 * handler, guarded by ORDER_PROCESSED_META, so a re-fire never
 * double-awards or double-deducts.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Rewards {

	/** Running points balance on the user — never negative, no expiration. */
	public const BALANCE_META = '_yp_rewards_points_balance';

	/**
	 * How many points the customer has chosen to apply to their next
	 * order — set from the My Account Rewards tab
	 * (class-account-endpoints.php), consumed and reset to 0 once an
	 * order's payment actually completes. User meta, not WC_Session,
	 * so the choice survives across sessions (set today, checkout
	 * tomorrow) the same way the balance itself does.
	 */
	public const PENDING_REDEEM_META = '_yp_rewards_pending_redeem';

	/** Set once an order has actually awarded/deducted points, so finalize_order() never double-processes a re-fired event. */
	public const ORDER_PROCESSED_META = '_yp_rewards_processed';

	public const ORDER_POINTS_EARNED_META   = '_yp_rewards_points_earned';
	public const ORDER_POINTS_REDEEMED_META = '_yp_rewards_points_redeemed';

	/**
	 * A short, capped log of manual admin adjustments only (class-
	 * rewards-admin.php) — migrating a balance from the old site, or
	 * making a customer-service situation right, neither of which has a
	 * real order behind it the way every other balance change here does.
	 * Deliberately not the "full transaction ledger" this class's own
	 * docblock rules out in general: normal earn/redeem is still just
	 * the running balance plus per-order meta, this only exists because
	 * a manual adjustment has no order to explain itself later, and an
	 * admin giving a customer points genuinely needs a record of who
	 * did that, when, and why.
	 */
	private const ADJUSTMENTS_OPTION = 'yeffoprint_rewards_manual_adjustments';
	private const ADJUSTMENTS_MAX    = 300;

	public function __construct() {
		add_action( 'woocommerce_cart_calculate_fees', [ $this, 'apply_pending_redemption' ] );
		add_action( 'woocommerce_payment_complete', [ $this, 'finalize_order' ] );
		add_action( 'woocommerce_order_status_processing', [ $this, 'finalize_order' ] );
		add_action( 'woocommerce_order_status_completed', [ $this, 'finalize_order' ] );
	}

	public static function get_balance( int $user_id ): int {
		return max( 0, (int) get_user_meta( $user_id, self::BALANCE_META, true ) );
	}

	public static function get_pending_redeem( int $user_id ): int {
		return max( 0, (int) get_user_meta( $user_id, self::PENDING_REDEEM_META, true ) );
	}

	/** Admin-configurable (Dashboard → YeffoPrint → Settings). Default: 1 point per $1 spent. */
	public static function points_per_dollar(): float {
		return (float) get_option(
			YeffoPrint_Admin_Menu::REWARDS_POINTS_PER_DOLLAR_OPTION,
			YeffoPrint_Admin_Menu::REWARDS_POINTS_PER_DOLLAR_DEFAULT
		);
	}

	/** Admin-configurable. Default: $0.01/point, i.e. 100 points = $1 — the most common, easiest-to-explain points convention. */
	public static function dollars_per_point(): float {
		return (float) get_option(
			YeffoPrint_Admin_Menu::REWARDS_DOLLARS_PER_POINT_OPTION,
			YeffoPrint_Admin_Menu::REWARDS_DOLLARS_PER_POINT_DEFAULT
		);
	}

	/** "1" or "1.5", never "1.00" or "1.50" — points_per_dollar() as a plain number for display (both the account tab and the homepage promo pattern use this, instead of each formatting it themselves). */
	public static function points_per_dollar_label(): string {
		return rtrim( rtrim( number_format( self::points_per_dollar(), 2 ), '0' ), '.' );
	}

	public static function points_to_dollars( int $points ): float {
		return round( $points * self::dollars_per_point(), 2 );
	}

	private static function fee_label(): string {
		return __( 'Rewards credit', 'yeffoprint-core' );
	}

	public function apply_pending_redemption( \WC_Cart $cart ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return; // Guest carts have no balance to spend.
		}

		$requested = min( self::get_pending_redeem( $user_id ), self::get_balance( $user_id ) );
		if ( $requested <= 0 ) {
			return;
		}

		// Never past $0: a customer with more points than their cart is
		// worth still only gets the cart itself for free, not a credit
		// balance owed back — the unspent remainder just stays in their
		// points balance for a future order.
		$discount = min( self::points_to_dollars( $requested ), $cart->get_subtotal() );
		if ( $discount <= 0 ) {
			return;
		}

		$cart->add_fee( self::fee_label(), -$discount, false );
	}

	public function finalize_order( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( self::ORDER_PROCESSED_META ) ) {
			return;
		}

		$order->update_meta_data( self::ORDER_PROCESSED_META, 1 );

		$user_id = $order->get_customer_id();
		if ( ! $user_id ) {
			$order->save();
			return; // Guest checkout — no account to credit/debit.
		}

		// Redeemed: whatever the Rewards Credit fee actually landed on
		// this order at, converted back to points — may be less than
		// what was pending if the cart changed after the fee was added.
		$fee_id           = sanitize_title( self::fee_label() );
		$redeemed_dollars = 0.0;
		foreach ( $order->get_fees() as $fee ) {
			if ( sanitize_title( $fee->get_name() ) === $fee_id ) {
				$redeemed_dollars += abs( (float) $fee->get_total() );
			}
		}
		$points_redeemed = ( $redeemed_dollars > 0 && self::dollars_per_point() > 0 )
			? (int) round( $redeemed_dollars / self::dollars_per_point() )
			: 0;

		// Earned: points_per_dollar × the order's merchandise subtotal —
		// line items only (WC_Order has no get_subtotal(), unlike
		// WC_Cart), so this never includes shipping, tax, or the amount
		// just discounted away above.
		$subtotal = 0.0;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$subtotal += (float) $item->get_total();
		}
		$points_earned = (int) floor( $subtotal * self::points_per_dollar() );

		$balance = self::get_balance( $user_id ) - $points_redeemed + $points_earned;
		update_user_meta( $user_id, self::BALANCE_META, max( 0, $balance ) );
		update_user_meta( $user_id, self::PENDING_REDEEM_META, 0 );

		$order->update_meta_data( self::ORDER_POINTS_EARNED_META, $points_earned );
		$order->update_meta_data( self::ORDER_POINTS_REDEEMED_META, $points_redeemed );
		$order->save();
	}

	/**
	 * Manual, admin-driven balance change (class-rewards-admin.php) —
	 * see ADJUSTMENTS_OPTION above for why this is logged when nothing
	 * else here is. Never brings the balance below 0, same floor every
	 * other balance change in this class already enforces.
	 *
	 * @return int The resulting balance.
	 */
	public static function adjust_balance( int $user_id, int $delta, string $reason, int $admin_id ): int {
		$balance = max( 0, self::get_balance( $user_id ) + $delta );
		update_user_meta( $user_id, self::BALANCE_META, $balance );

		$log = (array) get_option( self::ADJUSTMENTS_OPTION, [] );
		array_unshift( $log, [
			'user_id'  => $user_id,
			'delta'    => $delta,
			'reason'   => $reason,
			'admin_id' => $admin_id,
			'date'     => current_time( 'mysql' ),
		] );
		update_option( self::ADJUSTMENTS_OPTION, array_slice( $log, 0, self::ADJUSTMENTS_MAX ), false );

		return $balance;
	}

	/** @return array<int, array{user_id:int, delta:int, reason:string, admin_id:int, date:string}> Newest first. */
	public static function get_recent_adjustments( int $limit = 50 ): array {
		return array_slice( (array) get_option( self::ADJUSTMENTS_OPTION, [] ), 0, max( 1, $limit ) );
	}
}
