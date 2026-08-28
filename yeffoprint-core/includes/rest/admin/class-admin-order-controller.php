<?php
/**
 * Admin REST endpoints for WooCommerce orders, from the Dashboard's
 * Pending Orders panel — direct request: staff want the same "click a
 * row, see everything in a sidebar" experience the Custom Orders screen
 * already has (class-admin-custom-order-controller.php), for a normal
 * paid order too, so they never have to leave the admin app for the
 * classic WooCommerce order screen. Read-only past status, same shape
 * as that controller's own save_status() — this never lets staff edit
 * line items, addresses, or payment details; anything beyond what's
 * exposed here (refunds, order notes, editing) is still the classic
 * screen's job, reached via this detail view's own "Open in WooCommerce"
 * link.
 *
 * get_formatted_meta_data() (WC_Order_Item's own method) is what
 * actually supplies every customization/quantity/template-selection
 * value in item_payload() below — the exact same call, with the exact
 * same result, that the classic order screen's own item table already
 * renders line items with (wc_display_item_meta()), batch tables/
 * variant summaries/QR download links included, since those all hook
 * into the same woocommerce_order_item_get_formatted_meta_data filter
 * chain (class-order-item-meta.php). Nothing about that display logic
 * needed reimplementing here.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_Order_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/admin/order/(?P<id>\d+)', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_order' ],
				'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
			],
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'save_status' ],
				'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/admin/order/(?P<id>\d+)/send-to-printer', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'send_to_printer' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function get_order( \WP_REST_Request $request ) {
		$order = $this->validate_order( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		return rest_ensure_response( $this->detail_payload( $order ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function save_status( \WP_REST_Request $request ) {
		$order = $this->validate_order( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$params = $request->get_json_params() ?: [];
		$status = sanitize_key( (string) ( $params['status'] ?? '' ) );

		if ( ! array_key_exists( $status, $this->status_options() ) ) {
			return new \WP_Error( 'yeffoprint_invalid_status', __( 'That is not a valid status.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$order->set_status( $status, __( 'Status changed from the dashboard.', 'yeffoprint-core' ) );
		$order->save();

		return rest_ensure_response( $this->detail_payload( $order ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function send_to_printer( \WP_REST_Request $request ) {
		$order = $this->validate_order( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		if ( 'processing' !== $order->get_status() ) {
			return new \WP_Error(
				'yeffoprint_order_not_processing',
				__( 'This order is no longer in the Processing status — someone else may have already sent it to the printer.', 'yeffoprint-core' ),
				[ 'status' => 409 ]
			);
		}

		$order->set_status( YeffoPrint_Order_Production_Status::STATUS, __( 'Sent to printer from the dashboard.', 'yeffoprint-core' ) );
		$order->save();

		return rest_ensure_response( [ 'id' => $order->get_id(), 'status' => $order->get_status() ] );
	}

	/** @return \WC_Order|\WP_Error */
	private function validate_order( int $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return new \WP_Error( 'yeffoprint_woocommerce_inactive', __( 'WooCommerce is not active.', 'yeffoprint-core' ), [ 'status' => 500 ] );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error( 'yeffoprint_order_not_found', __( 'That order could not be found.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}

		return $order;
	}

	private function detail_payload( \WC_Order $order ): array {
		return [
			'id'                   => $order->get_id(),
			'number'               => $order->get_order_number(),
			'status'               => $order->get_status(),
			'status_label'         => wc_get_order_status_name( $order->get_status() ),
			'statuses'             => $this->status_options(),
			'date'                 => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : null,
			'customer_name'        => trim( $order->get_formatted_billing_full_name() ),
			'customer_email'       => $order->get_billing_email(),
			'customer_phone'       => $order->get_billing_phone(),
			'customer_note'        => $order->get_customer_note(),
			// Falls back to billing when there's no separate shipping
			// address — same behavior WooCommerce's own order screen and
			// order emails already use, not a new convention introduced
			// here.
			'shipping_address'     => $order->get_formatted_shipping_address() ?: $order->get_formatted_billing_address(),
			'payment_method_title' => $order->get_payment_method_title(),
			// The shipping method(s) the customer actually selected at checkout (comma-joined titles
			// of every shipping line item — same accessor WooCommerce Shipping's own order presenter
			// reads, class-wc-connect-order-presenter.php). Direct request: since this store's 3
			// shipping options are plain WooCommerce methods rather than WooCommerce Shipping's own
			// live carrier-rate method, there's no order data linking them to a specific carrier
			// service for that plugin to auto-select — this surfaces the choice as a plain string so
			// the frontend can show it right next to the embedded label form instead.
			'shipping_method'      => $order->get_shipping_method(),
			'items'                => array_values( array_filter( array_map( [ $this, 'item_payload' ], $order->get_items() ) ) ),
			'subtotal'             => (float) $order->get_subtotal(),
			'shipping_total'       => (float) $order->get_shipping_total(),
			'total'                => (float) $order->get_total(),
			'edit_url'             => $order->get_edit_order_url(),
			// Direct request: print a real shipping label from this drawer, "without having to go to
			// WooCommerce" — the WooCommerce Shipping plugin (Automattic\WCShipping\Loader, already
			// active on this store) only ever renders its label-purchase UI as a meta box
			// (#woocommerce-order-label) on the classic order edit screen; there's no public API to
			// drive rate-shopping/label purchase from outside it. Rather than reimplement that (a large
			// proprietary React app — rates, customs forms, payment, printing), the frontend embeds that
			// exact meta box via a same-origin iframe onto `edit_url` and hides the surrounding chrome
			// with injected CSS, so this flag just tells it whether that plugin is even active.
			'shipping_label_available' => class_exists( '\Automattic\WCShipping\Loader' ),
		];
	}

	private function item_payload( \WC_Order_Item $item ): ?array {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return null; // Fee/shipping/tax line items — nothing to customize, and the totals above already account for them.
		}

		return [
			'name'     => $item->get_name(),
			'quantity' => $item->get_quantity(),
			'total'    => (float) $item->get_total(),
			// display_value is already wp_kses_post()-safe HTML by the
			// time get_formatted_meta_data() returns it (WC_Order_Item's
			// own method) — the same batch tables/variant summaries/QR
			// links the classic order screen renders raw, rendered raw
			// here too rather than re-escaped into visible markup.
			'meta'     => array_values( array_map( static function ( $entry ) {
				return [ 'label' => (string) $entry->display_key, 'value' => (string) $entry->display_value ];
			}, $item->get_formatted_meta_data() ) ),
		];
	}

	/** wc_get_order_statuses()'s own list (every standard WC status plus this plugin's own "In Production"/"Shipped" ones — both registered through the standard woocommerce_order_statuses filter, class-order-production-status.php/class-order-shipment-status.php), keys unprefixed to match WC_Order::get_status()'s own return value. */
	private function status_options(): array {
		$statuses = [];
		foreach ( wc_get_order_statuses() as $key => $label ) {
			$statuses[ preg_replace( '/^wc-/', '', $key ) ] = $label;
		}
		return $statuses;
	}
}
