<?php
/**
 * "Add-on" orders — direct request: let a customer add a second order
 * onto one that's still Processing/In Production without a second
 * shipping charge, and flag the pair on the dashboard so staff pack
 * them into one box. Mocked up first (an Artifact with all four
 * pieces: the emailed nudge, the add-on landing page, checkout, and
 * the dashboard grouping) and approved as drawn, plus three follow-ups:
 * "No time window, as long as the order hasn't shipped. Allow up to 2
 * additional add-ons, let's add the my account button as well."
 *
 * A single order-meta pointer (SHIP_WITH_ORDER_ID_META) on an add-on
 * order names its root — no chaining, every add-on always points
 * straight at the one order the whole group actually ships under, so
 * grouping is a flat lookup rather than a walk up a chain.
 *
 * eligibility() is the single gate every consumer of this feature
 * reads from — the checkout fee (class-order-addon-checkout.php), the
 * order-processed linking (same class), the My Account button
 * (class-order-addon-account.php), and the customer-facing
 * /add-to-order/ page (blocks/order-addon-gate/render.php, theme side)
 * all call this one method rather than re-deriving "can this order
 * still take an add-on" themselves. "Not yet shipped" reuses the exact
 * same signal class-telegram-address-update.php's own blocked_reason()
 * already trusts: YeffoPrint_Order_Tracking::get_shipments() empty.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Order_Addon {

	public const SHIP_WITH_ORDER_ID_META = '_yp_ship_with_order_id';

	/** Direct request: "allow up to 2 additional add-ons." */
	public const MAX_ADDONS = 2;

	/** WC()->session key holding the root order id a customer is currently shopping an add-on for. */
	public const SESSION_KEY = 'yp_addon_to_order_id';

	private static function active_statuses(): array {
		return [ 'processing', YeffoPrint_Order_Production_Status::STATUS ];
	}

	/** The order a group actually ships under — itself, unless it's already tagged as someone else's add-on. */
	public static function root_id_for( \WC_Order $order ): int {
		$root_id = (int) $order->get_meta( self::SHIP_WITH_ORDER_ID_META, true );
		return $root_id ?: $order->get_id();
	}

	/** @return int[] */
	public static function addon_ids_for_root( int $root_id ): array {
		if ( ! $root_id || ! function_exists( 'wc_get_orders' ) ) {
			return [];
		}

		return wc_get_orders( [
			'limit'      => self::MAX_ADDONS + 1, // Defensive only — this count itself is what keeps it from ever really exceeding MAX_ADDONS.
			'return'     => 'ids',
			'meta_key'   => self::SHIP_WITH_ORDER_ID_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value' => $root_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		] );
	}

	/**
	 * @return array{eligible:bool, reason:string, root:\WC_Order}
	 */
	public static function eligibility( \WC_Order $order ): array {
		$root_id = self::root_id_for( $order );
		$root    = $root_id === $order->get_id() ? $order : wc_get_order( $root_id );

		if ( ! $root instanceof \WC_Order ) {
			return [ 'eligible' => false, 'reason' => __( "This order isn't available for add-ons.", 'yeffoprint-core' ), 'root' => $order ];
		}

		if ( ! in_array( $root->get_status(), self::active_statuses(), true ) ) {
			return [
				'eligible' => false,
				'reason'   => __( "This order isn't in production yet, or has already moved past it — add-ons only work while it's Processing or In Production.", 'yeffoprint-core' ),
				'root'     => $root,
			];
		}

		if ( ! empty( YeffoPrint_Order_Tracking::get_shipments( $root ) ) ) {
			return [
				'eligible' => false,
				'reason'   => __( 'A shipping label has already been generated for this order, so it can no longer take an add-on.', 'yeffoprint-core' ),
				'root'     => $root,
			];
		}

		if ( count( self::addon_ids_for_root( $root->get_id() ) ) >= self::MAX_ADDONS ) {
			return [
				'eligible' => false,
				'reason'   => __( 'This order already has the maximum number of add-ons.', 'yeffoprint-core' ),
				'root'     => $root,
			];
		}

		return [ 'eligible' => true, 'reason' => '', 'root' => $root ];
	}

	/** Order-key match (guest-safe) or the logged-in customer's own order — same shape as class-order-tracking-controller.php::check_access(). */
	public static function owns_order( \WC_Order $order, string $key ): bool {
		if ( '' !== $key && hash_equals( $order->get_order_key(), $key ) ) {
			return true;
		}

		if ( is_user_logged_in() ) {
			return current_user_can( 'manage_woocommerce' )
				|| ( $order->get_customer_id() && (int) $order->get_customer_id() === get_current_user_id() );
		}

		return false;
	}

	public static function start_session( int $root_id ): void {
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::SESSION_KEY, $root_id );
		}
	}

	public static function clear_session(): void {
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::SESSION_KEY, null );
		}
	}

	public static function pending_root_id(): int {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return 0;
		}
		return (int) WC()->session->get( self::SESSION_KEY );
	}
}
