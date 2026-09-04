<?php
/**
 * Registers a "Shipped" WooCommerce order status between Processing
 * and Completed, and automatically moves an order into it the moment
 * a shipping label with a tracking number is attached (docs/
 * ARCHITECTURE.md — package tracking; direct request: "I'd like order
 * status to change from processing to shipped when I create a
 * shipping label using woocommerce shipping").
 *
 * Detection deliberately doesn't hook any one specific WooCommerce
 * Shipping action — same reasoning as class-order-tracking.php's own
 * docblock ("reads the existing plugin's own label data... rather
 * than adding a second, competing place"): it watches the resulting
 * data (YeffoPrint_Order_Tracking::get_shipments(), the exact same
 * normalized shipment list the customer-facing tracking page already
 * reads) on every order save, which works regardless of which
 * WooCommerce Shipping version — or which of its internal actions —
 * actually wrote the label, and keeps working if that plugin ever
 * renames its own hooks.
 *
 * "Delivered" is a separate, later piece — now built: class-order-
 * delivery-status.php's hourly sweep watches every order in this
 * "shipped" status for live delivery confirmation and moves it on to
 * "completed" once every one of its shipments has arrived.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Order_Shipment_Status {

	public const STATUS = 'shipped';

	/**
	 * A hash of every shipment's tracking number currently reflected in
	 * this order's status — lets maybe_advance_to_shipped() tell "a
	 * label just got attached" apart from "this order was saved again
	 * for an unrelated reason" without caring what changed, only
	 * whether the shipment list itself is new since the last time this
	 * ran.
	 */
	private const SHIPMENT_FINGERPRINT_META = '_yp_shipment_fingerprint';

	public function __construct() {
		add_action( 'init', [ $this, 'register_status' ] );
		add_filter( 'wc_order_statuses', [ $this, 'add_to_status_list' ] );
		add_action( 'woocommerce_after_order_object_save', [ $this, 'maybe_advance_to_shipped' ] );
	}

	public function register_status(): void {
		register_post_status( 'wc-' . self::STATUS, [
			'label'                     => _x( 'Shipped', 'Order status', 'yeffoprint-core' ),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: number of orders */
			'label_count'               => _n_noop( 'Shipped <span class="count">(%s)</span>', 'Shipped <span class="count">(%s)</span>', 'yeffoprint-core' ),
		] );
	}

	/**
	 * Slots "Shipped" right after "In Production" (falling back to right
	 * after "Processing" if that status is somehow missing) — the real
	 * pipeline is Processing → In Production → Shipped → Completed.
	 */
	public function add_to_status_list( array $order_statuses ): array {
		$anchor_key = isset( $order_statuses['wc-in-production'] ) ? 'wc-in-production' : 'wc-processing';

		$new_statuses = [];
		foreach ( $order_statuses as $key => $label ) {
			$new_statuses[ $key ] = $label;
			if ( $anchor_key === $key ) {
				$new_statuses[ 'wc-' . self::STATUS ] = _x( 'Shipped', 'Order status', 'yeffoprint-core' );
			}
		}

		return $new_statuses;
	}

	/**
	 * Fires on every order save — cheap for the overwhelming majority of
	 * them, since the very first check (status must be one this applies
	 * to) throws out every order this doesn't apply to before touching
	 * anything else. Everything it reads (order meta already loaded on
	 * $order) is local — no API calls here.
	 *
	 * Direct bug report: "when I print a shipping label, it should
	 * change the status to shipped, but instead it marked it completed."
	 * Two separate causes, both fixed here:
	 *
	 * 1. This originally only checked for "processing" — but
	 *    class-order-production-status.php's own "Send to Printer"
	 *    button moves an order to "in-production" *before* staff ever
	 *    gets to print a label, so by the time a label actually exists,
	 *    the order was never "processing" anymore and this silently
	 *    never fired. Now accepts "in-production" too.
	 *
	 * 2. WooCommerce Shipping's own label-purchase form has its own
	 *    "After purchasing a label, mark this order as complete and
	 *    notify the customer" option — when checked (its own UI, not
	 *    anything this plugin renders), it calls WooCommerce's native
	 *    complete-order action directly, landing the order on
	 *    "completed" without ever passing through this store's own
	 *    "shipped" status in between. Since this hook fires on every
	 *    save (including that one), also accepting "completed" as a
	 *    starting point here redirects that specific jump back to
	 *    "shipped" — the moment a *new* shipment fingerprint shows up on
	 *    an order that's suddenly "completed", it's this exact case, not
	 *    a deliberate later "mark complete" (which the fingerprint-match
	 *    guard below already lets stand undisturbed, since by then the
	 *    fingerprint is unchanged).
	 */
	public function maybe_advance_to_shipped( \WC_Order $order ): void {
		if ( ! in_array( $order->get_status(), [ 'processing', YeffoPrint_Order_Production_Status::STATUS, 'completed' ], true ) ) {
			return;
		}

		$shipments = YeffoPrint_Order_Tracking::get_shipments( $order );
		if ( ! $shipments ) {
			return;
		}

		$fingerprint = self::fingerprint( $shipments );
		if ( $fingerprint === $order->get_meta( self::SHIPMENT_FINGERPRINT_META, true ) ) {
			return; // Already reflected — this save is for something else entirely.
		}

		$order->update_meta_data( self::SHIPMENT_FINGERPRINT_META, $fingerprint );

		// Same moment new shipment data exists is the cheapest moment to
		// index it — see YeffoPrint_Order_Tracking::index_shipments()'s own
		// docblock (Shippo webhook support: a track_updated payload names a
		// tracking number, never an order, so this is what routes it back).
		YeffoPrint_Order_Tracking::index_shipments( $order );

		$order->set_status( self::STATUS, __( 'A shipping label was purchased — tracking number attached.', 'yeffoprint-core' ) );
		// set_status() alone doesn't persist or fire the status-transition
		// hooks — save() does both. This re-enters this exact method via
		// the same woocommerce_after_order_object_save hook it's already
		// running inside of, but the guard above exits immediately the
		// second time through: $order (the same in-memory object) already
		// reflects "shipped" by then.
		$order->save();
	}

	private static function fingerprint( array $shipments ): string {
		return md5( (string) wp_json_encode( array_column( $shipments, 'tracking_number' ) ) );
	}

	/**
	 * Direct bug report: "the site isn't marking orders completed once
	 * tracking shows it's been delivered." class-order-delivery-status.php's
	 * own maybe_complete() calls $order->update_status( 'completed', ... )
	 * once every shipment shows delivered — but that method's own save()
	 * re-fires woocommerce_after_order_object_save, landing right back in
	 * maybe_advance_to_shipped() above with the order now sitting on
	 * 'completed'. That method's fingerprint guard exists specifically to
	 * catch WooCommerce Shipping's own *premature* auto-complete (see its
	 * own docblock) — it can't tell that jump apart from this store's own
	 * legitimate, delivery-confirmed one unless the fingerprint it compares
	 * against is already current. Whenever it wasn't (an order marked
	 * Shipped by some path other than maybe_advance_to_shipped() itself —
	 * e.g. a manual status change — never recorded one in the first place;
	 * a later refunded/voided label changes the shipment list and so the
	 * fingerprint, after the order was already Shipped), the guard failed
	 * open and force-reverted the brand-new 'completed' status straight
	 * back to 'shipped', silently undoing the auto-complete every time.
	 *
	 * class-order-delivery-status.php now calls this right before
	 * update_status( 'completed' ) so the fingerprint it's about to
	 * re-check is guaranteed current — staged onto the same in-memory
	 * $order object, persisted by that same update_status() call's own
	 * save(), regardless of whatever was (or wasn't) stored before.
	 */
	public static function record_current_fingerprint( \WC_Order $order ): void {
		$shipments = YeffoPrint_Order_Tracking::get_shipments( $order );
		if ( ! $shipments ) {
			return;
		}

		$fingerprint = self::fingerprint( $shipments );
		$order->update_meta_data( self::SHIPMENT_FINGERPRINT_META, $fingerprint );
	}
}
