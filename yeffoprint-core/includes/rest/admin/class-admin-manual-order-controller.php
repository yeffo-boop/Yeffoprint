<?php
/**
 * Admin REST endpoints for manually creating a WooCommerce order from
 * the admin app (docs/ARCHITECTURE.md — "Manual order creation") —
 * thin wrapper, same shape as every other admin/* controller: the real
 * work is YeffoPrint_Manual_Order_Creator::create(), this just parses
 * the request and formats the response.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_Manual_Order_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/admin/manual-orders/customer-search', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'search_customers' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/admin/manual-orders', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'create_order' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		// Custom Stickers has no public pricing-preview endpoint the way
		// Custom Design does (class-custom-order-controller.php's
		// /custom-orders/pricing-preview) — the storefront prices a
		// sticker live via the cart itself instead. This admin screen has
		// no cart to price against, so it gets its own small preview
		// wrapper around the same authoritative YeffoPrint_Sticker_Pricing::calculate().
		register_rest_route( self::NAMESPACE, '/admin/manual-orders/sticker-pricing-preview', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'sticker_pricing_preview' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		// Same reasoning as sticker-pricing-preview above: the public
		// /custom-orders/pricing-preview endpoint reads the live session
		// cart's own combined_label_quantity() for its bulk-discount tier
		// (class-custom-order-controller.php::pricing_preview()), which
		// would silently pull in whatever's sitting in the *staff member's
		// own* cart here — this order's own quantity is the whole tier
		// pool instead, same as YeffoPrint_Manual_Order_Creator::add_template_row()
		// itself prices it.
		register_rest_route( self::NAMESPACE, '/admin/manual-orders/template-pricing-preview', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'template_pricing_preview' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		// Direct request: "I need the ability to verify the shipping/billing
		// address... before finalizing." No order exists yet at this point
		// in the flow (unlike class-admin-shippo-controller.php's own
		// /admin/order/{id}/shippo/rates route, which reads an existing
		// order's saved address) — this takes the address straight from the
		// request instead. Shipping-method selection itself doesn't need a
		// route of its own — direct follow-up: "I don't need to rate shop
		// to add shipping, just use my default shipping options" — it
		// reads yeffoprintAdminApp.shippo.manualOrderShippingOptions
		// (Settings → Shipping), no API call.
		register_rest_route( self::NAMESPACE, '/admin/manual-orders/verify-address', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'verify_address' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		// Direct request: "can it pull their existing address from their
		// profile if it has it filled out?" — fetched once, right when
		// staff pick an existing customer (not folded into search_customers()
		// above, which runs on every keystroke against up to 20 results at
		// once — this is one lookup for the one customer actually chosen).
		register_rest_route( self::NAMESPACE, '/admin/manual-orders/customer/(?P<id>\d+)/address', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'customer_address' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );
	}

	/** @return \WP_REST_Response */
	public function search_customers( \WP_REST_Request $request ) {
		$term = trim( (string) $request->get_param( 'q' ) );
		if ( '' === $term ) {
			return rest_ensure_response( [] );
		}

		$users = get_users( [
			'search'         => '*' . esc_attr( $term ) . '*',
			'search_columns' => [ 'user_login', 'user_email', 'display_name' ],
			'number'         => 20,
			'fields'         => [ 'ID', 'display_name', 'user_email' ],
		] );

		return rest_ensure_response( array_map( static function ( $user ) {
			return [
				'id'           => (int) $user->ID,
				'display_name' => $user->display_name,
				'email'        => $user->user_email,
			];
		}, $users ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function create_order( \WP_REST_Request $request ) {
		$params = $request->get_json_params() ?: [];

		$result = YeffoPrint_Manual_Order_Creator::create( $params );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$order            = $result['order'];
		$custom_order_id  = $result['custom_order_id'];

		return rest_ensure_response( [
			'success'                   => true,
			'order_id'                  => $order->get_id(),
			// $order->get_edit_order_url() rather than a hand-built
			// post.php?post=…&action=edit link — resolves correctly
			// whether this store is on classic post-based orders or HPOS.
			'order_edit_url'            => $order->get_edit_order_url(),
			'custom_order_id'           => $custom_order_id,
			'custom_order_approval_url' => $custom_order_id ? yeffoprint_core_proof_approval_url( $custom_order_id ) : '',
		] );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function sticker_pricing_preview( \WP_REST_Request $request ) {
		$params = $request->get_json_params() ?: [];

		$pricing = YeffoPrint_Sticker_Pricing::calculate(
			absint( $params['size_id'] ?? 0 ),
			(float) ( $params['custom_width_in'] ?? 0 ),
			(float) ( $params['custom_height_in'] ?? 0 ),
			absint( $params['material_id'] ?? 0 ),
			sanitize_key( (string) ( $params['sticker_type'] ?? '' ) ),
			sanitize_key( (string) ( $params['shape'] ?? '' ) ),
			max( 1, absint( $params['quantity'] ?? 1 ) )
		);

		if ( is_wp_error( $pricing ) ) {
			return $pricing;
		}

		return rest_ensure_response( $pricing );
	}

	/** @return \WP_REST_Response */
	public function template_pricing_preview( \WP_REST_Request $request ) {
		$params      = $request->get_json_params() ?: [];
		$size_id     = absint( $params['size_id'] ?? 0 );
		$material_id = absint( $params['material_id'] ?? 0 );
		$quantity    = max( 1, absint( $params['quantity'] ?? 1 ) );

		$pricing = YeffoPrint_Pricing_Rule::calculate(
			$this->record_adjustment( 'yp_material', $material_id ),
			$this->record_adjustment( 'yp_size', $size_id ),
			$quantity,
			$quantity
		);

		return rest_ensure_response( $pricing );
	}

	/** Same lookup class-cart-pricing.php's own adjustment() and class-custom-order-controller.php's own record_adjustment() already use — a size/material's PRICE_ADJUSTMENT meta, verified against the given post type first. */
	private function record_adjustment( string $post_type, int $post_id ): float {
		if ( ! $post_id ) {
			return 0.0;
		}

		$post = get_post( $post_id );
		if ( ! $post || $post_type !== $post->post_type ) {
			return 0.0;
		}

		return (float) get_post_meta( $post_id, YeffoPrint_Commerce_Record_Meta::PRICE_ADJUSTMENT, true );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function verify_address( \WP_REST_Request $request ) {
		$client = $this->shippo_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$params  = $request->get_json_params() ?: [];
		$address = $this->address_to_shippo( is_array( $params['address'] ?? null ) ? $params['address'] : [] );

		if ( '' === trim( $address['street1'] ) || '' === trim( $address['zip'] ) ) {
			return new \WP_Error( 'yeffoprint_shippo_incomplete_address', __( 'Enter a complete street address and ZIP/postal code before verifying.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$result = $client->validate_address( $address );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function customer_address( \WP_REST_Request $request ) {
		$user_id = absint( $request['id'] );
		$user    = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new \WP_Error( 'yeffoprint_invalid_customer', __( 'That customer could not be found.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}

		$customer = new \WC_Customer( $user_id );

		return rest_ensure_response( [
			'shipping' => $this->customer_half_address( $customer, 'shipping' ),
			'billing'  => $this->customer_half_address( $customer, 'billing' ),
		] );
	}

	/** One half (shipping or billing) of a WC_Customer's saved address, in this screen's own form shape — null when that half has no street address on file at all, so the caller can tell "nothing saved" from "saved, just blank fields." */
	private function customer_half_address( \WC_Customer $customer, string $type ): ?array {
		$getter = static fn( string $field ) => $customer->{"get_{$type}_{$field}"}();

		if ( '' === trim( (string) $getter( 'address_1' ) ) ) {
			return null;
		}

		return [
			'first_name' => (string) $getter( 'first_name' ),
			'last_name'  => (string) $getter( 'last_name' ),
			'address_1'  => (string) $getter( 'address_1' ),
			'address_2'  => (string) $getter( 'address_2' ),
			'city'       => (string) $getter( 'city' ),
			'state'      => (string) $getter( 'state' ),
			'postcode'   => (string) $getter( 'postcode' ),
			'country'    => (string) $getter( 'country' ),
			'phone'      => (string) $getter( 'phone' ),
		];
	}

	/** Same shape as class-admin-shippo-controller.php's own client() — a token check first, so a missing Shippo token reads as one clear message instead of an API-call failure. @return YeffoPrint_Shippo_Client|\WP_Error */
	private function shippo_client() {
		if ( ! YeffoPrint_Shippo_Settings::is_configured() ) {
			return new \WP_Error( 'yeffoprint_shippo_not_configured', __( 'Add a Shippo API token in Settings → Shipping first.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		return new YeffoPrint_Shippo_Client( YeffoPrint_Shippo_Settings::get_api_key() );
	}

	/** Maps this screen's address form shape (first_name/last_name/address_1/address_2/postcode/…, same field names WC_Order's own setters use) to Shippo's address_to shape (name/street1/street2/zip/…) — same mapping class-admin-shippo-controller.php's own address_to() does starting from a WC_Order instead of a raw form. */
	private function address_to_shippo( array $address ): array {
		$name = trim( sanitize_text_field( (string) ( $address['first_name'] ?? '' ) ) . ' ' . sanitize_text_field( (string) ( $address['last_name'] ?? '' ) ) );

		return [
			'name'    => $name,
			'street1' => sanitize_text_field( (string) ( $address['address_1'] ?? '' ) ),
			'street2' => sanitize_text_field( (string) ( $address['address_2'] ?? '' ) ),
			'city'    => sanitize_text_field( (string) ( $address['city'] ?? '' ) ),
			'state'   => sanitize_text_field( (string) ( $address['state'] ?? '' ) ),
			'zip'     => sanitize_text_field( (string) ( $address['postcode'] ?? '' ) ),
			'country' => strtoupper( sanitize_text_field( (string) ( $address['country'] ?? '' ) ) ),
			'phone'   => sanitize_text_field( (string) ( $address['phone'] ?? '' ) ),
		];
	}
}
