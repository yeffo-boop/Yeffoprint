<?php
/**
 * Admin REST endpoints for the Shippo rate-shop/label-purchase panel
 * (yeffoprint-core/assets/admin-app/app.js's shippoPanelHtml()) —
 * direct request: "can we build something with the shippo API to
 * replace [WooCommerce Shipping]? ... I'd like to run alongside it a
 * bit." This panel sits beside, not instead of, the existing embedded
 * WooCommerce Shipping form (class-admin-order-controller.php,
 * wcOrderShippingLabelHtml()) — nothing here touches that flow.
 *
 * Two routes matching the drawer's two steps: rate-shop (always free on
 * Shippo's side, no purchase happens) and purchase (a real charge —
 * the frontend makes that unambiguous before this ever fires). A
 * successful purchase records the label via
 * YeffoPrint_Order_Tracking::record_shippo_label() and calls
 * $order->save() — the same save class-order-shipment-status.php's own
 * maybe_advance_to_shipped() is already hooked to, so an order still
 * in Processing/In Production auto-advances to Shipped and the
 * shipped-order email fires, exactly as it would for a WooCommerce
 * Shipping label, with no special-casing needed here.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_Shippo_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/admin/order/(?P<id>\d+)/shippo/rates', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'get_rates' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/admin/order/(?P<id>\d+)/shippo/purchase', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'purchase_label' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function get_rates( \WP_REST_Request $request ) {
		$order = $this->validate_order( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$client = $this->client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$params      = $request->get_json_params() ?: [];
		$parcel      = $this->parcel_from_request( $params );
		$address_to  = $this->address_to( $order );

		// Direct report: an incomplete address (this order had neither a
		// shipping nor billing street/zip on file) reached Shippo and came
		// back as two genuine "must not be empty" messages buried inside a
		// dozen unrelated "Carrier account ... doesn't support" lines from
		// Shippo's own default sample international carrier accounts every
		// new account gets — technically correct, but an unreadable wall
		// of noise for what's really one clear problem. Catching this here
		// means a broken address reads as one sentence instead of that.
		if ( '' === trim( $address_to['street1'] ) || '' === trim( $address_to['zip'] ) ) {
			return new \WP_Error(
				'yeffoprint_shippo_incomplete_address',
				__( 'This order has no complete shipping or billing address on file — Shippo can\'t rate a shipment without a street address and ZIP/postal code.', 'yeffoprint-core' ),
				[ 'status' => 400 ]
			);
		}

		$result = $client->get_rates( $address_to, $parcel );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function purchase_label( \WP_REST_Request $request ) {
		$order = $this->validate_order( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$client = $this->client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$params  = $request->get_json_params() ?: [];
		$rate_id = sanitize_text_field( (string) ( $params['rate_id'] ?? '' ) );
		if ( '' === $rate_id ) {
			return new \WP_Error( 'yeffoprint_shippo_missing_rate', __( 'Choose a rate first.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		// Direct report: "the shipped packages dashboard doesn't show the
		// carrier for the labels we're making with shippo, it just shows
		// a -" — the frontend already displayed this exact rate's carrier
		// (get_rates() below), so it's sent along with the purchase
		// rather than re-derived from Shippo's own transaction response
		// afterward. See YeffoPrint_Shippo_Client::purchase_label()'s own
		// docblock for why that response can't be trusted for this.
		$carrier_id    = sanitize_key( (string) ( $params['carrier_id'] ?? '' ) );
		$carrier_label = sanitize_text_field( (string) ( $params['carrier_label'] ?? '' ) );

		$label = $client->purchase_label( $rate_id, $carrier_id, $carrier_label );
		if ( is_wp_error( $label ) ) {
			return $label;
		}

		YeffoPrint_Order_Tracking::record_shippo_label( $order, $label['tracking_number'], $label['carrier_id'], $label['label_url'] );
		$order->save();

		return rest_ensure_response( [
			'label'  => $label,
			'id'     => $order->get_id(),
			'status' => $order->get_status(),
		] );
	}

	/** @return YeffoPrint_Shippo_Client|\WP_Error */
	private function client() {
		if ( ! YeffoPrint_Shippo_Settings::is_configured() ) {
			return new \WP_Error( 'yeffoprint_shippo_not_configured', __( 'Add a Shippo API token in Settings → Shipping first.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		return new YeffoPrint_Shippo_Client( YeffoPrint_Shippo_Settings::get_api_key() );
	}

	/** @return array{weight_oz:float,length_in:float,width_in:float,height_in:float} */
	private function parcel_from_request( array $params ): array {
		$default = YeffoPrint_Shippo_Settings::get_default_package();

		return [
			'weight_oz' => isset( $params['weight_oz'] ) ? (float) $params['weight_oz'] : $default['weight_oz'],
			'length_in' => isset( $params['length_in'] ) ? (float) $params['length_in'] : $default['length_in'],
			'width_in'  => isset( $params['width_in'] ) ? (float) $params['width_in'] : $default['width_in'],
			'height_in' => isset( $params['height_in'] ) ? (float) $params['height_in'] : $default['height_in'],
		];
	}

	/** Falls back to billing when there's no separate shipping address, same as detail_payload()'s own shipping_address field. */
	private function address_to( \WC_Order $order ): array {
		$has_shipping = '' !== trim( $order->get_shipping_address_1() );

		return [
			'name'    => $has_shipping ? $order->get_formatted_shipping_full_name() : $order->get_formatted_billing_full_name(),
			'street1' => $has_shipping ? $order->get_shipping_address_1() : $order->get_billing_address_1(),
			'street2' => $has_shipping ? $order->get_shipping_address_2() : $order->get_billing_address_2(),
			'city'    => $has_shipping ? $order->get_shipping_city() : $order->get_billing_city(),
			'state'   => $has_shipping ? $order->get_shipping_state() : $order->get_billing_state(),
			'zip'     => $has_shipping ? $order->get_shipping_postcode() : $order->get_billing_postcode(),
			'country' => $has_shipping ? $order->get_shipping_country() : $order->get_billing_country(),
		];
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
}
