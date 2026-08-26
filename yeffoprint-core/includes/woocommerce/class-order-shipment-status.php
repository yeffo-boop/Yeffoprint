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
 * "Delivered" is a separate, later piece: it needs a working carrier
 * tracking API connection to know a shipment has actually arrived,
 * which this store doesn't have configured yet (Dashboard → YeffoPrint
 * → Settings → Shipment Tracking).
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

	/** Slots "Shipped" right after "Processing" in every status dropdown/list — the same place it sits in the real production pipeline. */
	public function add_to_status_list( array $order_statuses ): array {
		$new_statuses = [];

		foreach ( $order_statuses as $key => $label ) {
			$new_statuses[ $key ] = $label;
			if ( 'wc-processing' === $key ) {
				$new_statuses[ 'wc-' . self::STATUS ] = _x( 'Shipped', 'Order status', 'yeffoprint-core' );
			}
		}

		return $new_statuses;
	}

	/**
	 * Fires on every order save — cheap for the overwhelming majority of
	 * them, since the very first check (status must currently be
	 * "processing") throws out every order this doesn't apply to before
	 * touching anything else. Everything it reads (order meta already
	 * loaded on $order) is local — no API calls here.
	 */
	public function maybe_advance_to_shipped( \WC_Order $order ): void {
		if ( 'processing' !== $order->get_status() ) {
			return;
		}

		$shipments = YeffoPrint_Order_Tracking::get_shipments( $order );
		if ( ! $shipments ) {
			return;
		}

		$fingerprint = $this->fingerprint( $shipments );
		if ( $fingerprint === $order->get_meta( self::SHIPMENT_FINGERPRINT_META, true ) ) {
			return; // Already reflected — this save is for something else entirely.
		}

		$order->update_meta_data( self::SHIPMENT_FINGERPRINT_META, $fingerprint );
		$order->set_status( self::STATUS, __( 'A shipping label was purchased — tracking number attached.', 'yeffoprint-core' ) );
		// set_status() alone doesn't persist or fire the status-transition
		// hooks — save() does both. This re-enters this exact method via
		// the same woocommerce_after_order_object_save hook it's already
		// running inside of, but the guard above exits immediately the
		// second time through: $order (the same in-memory object) already
		// reflects "shipped" by then.
		$order->save();
	}

	private function fingerprint( array $shipments ): string {
		return md5( (string) wp_json_encode( array_column( $shipments, 'tracking_number' ) ) );
	}
}
