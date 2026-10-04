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
 * exposed here (order notes, editing) is still the classic screen's
 * job, reached via this detail view's own "Open in WooCommerce" link.
 * Refunds (direct request) are the one exception — create_refund()
 * below wraps wc_create_refund() the same way the classic order
 * screen's own refund panel does, so a full/partial refund no longer
 * requires leaving this app. Editing an order that hasn't been paid yet
 * lives in class-admin-manual-order-controller.php (same pricing code as
 * creating one); this controller only reports whether it's allowed.
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

		register_rest_route( self::NAMESPACE, '/admin/order/(?P<id>\d+)/refund', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'create_refund' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/admin/order/(?P<id>\d+)/record-payment', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'record_payment' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/admin/orders', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'list_orders' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );
	}

	/**
	 * Order History (direct request: "a way of pulling up previous
	 * orders... searchable and should also just list out previous
	 * orders that I can go through... paginated as I expect a lot of
	 * orders"). Every WooCommerce order regardless of status — unlike
	 * the Dashboard's Pending Orders panel (class-admin-dashboard-
	 * controller.php), which only ever queries Processing/In Production
	 * — reusing this same controller's own detail_payload() for the
	 * click-through drawer rather than building a second detail
	 * endpoint.
	 *
	 * Search goes through \WC_Data_Store::load('order')->search_orders(),
	 * the exact same method the classic wp-admin Orders screen's own
	 * search box calls — it matches billing/shipping address, name,
	 * email, phone, order item names, and a bare numeric term against
	 * the order ID itself. That call has no pagination or status
	 * filtering of its own, so its id list is handed to wc_get_orders()
	 * as `post__in` (an unmapped WC_Order_Query arg that passes straight
	 * through to the underlying WP_Query, same as any native WP_Query
	 * arg) — letting one real WP_Query apply the status filter and
	 * pagination together, rather than slicing/filtering the id array
	 * by hand in PHP.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function list_orders( \WP_REST_Request $request ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new \WP_Error( 'yeffoprint_woocommerce_inactive', __( 'WooCommerce is not active.', 'yeffoprint-core' ), [ 'status' => 500 ] );
		}

		$search   = trim( (string) $request->get_param( 'search' ) );
		$status   = sanitize_key( (string) $request->get_param( 'status' ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) );

		$args = [
			'paginate' => true,
			'limit'    => $per_page,
			'page'     => $page,
			'orderby'  => 'date',
			'order'    => 'DESC',
		];

		if ( '' !== $status && array_key_exists( $status, $this->status_options() ) ) {
			$args['status'] = $status;
		}

		if ( '' !== $search ) {
			$order_ids = \WC_Data_Store::load( 'order' )->search_orders( wc_clean( $search ) );
			if ( empty( $order_ids ) ) {
				return rest_ensure_response( [ 'orders' => [], 'total' => 0, 'max_num_pages' => 0, 'page' => $page ] );
			}
			$args['post__in'] = $order_ids;
		}

		$result          = wc_get_orders( $args );
		$unpaid_requests = $this->unpaid_custom_requests();

		return rest_ensure_response( [
			'orders'          => array_map( [ $this, 'summary_payload' ], $result->orders ),
			'total'           => $result->total,
			'max_num_pages'   => $result->max_num_pages,
			'page'            => $page,
			// Only sent with the Drafts tab, which lists them above its orders.
			'unpaid_requests' => 'checkout-draft' === $status ? $unpaid_requests : [],
			// Counts for Order History's quick tabs (direct request: "the
			// ability to see draft orders"). Drafts never show under "All
			// statuses" — WooCommerce registers checkout-draft as
			// exclude_from_search, same as its own Orders screen — so the
			// tab count is how they get noticed.
			'counts'        => [
				'pending'        => wc_orders_count( 'pending' ),
				'checkout-draft' => wc_orders_count( 'checkout-draft' ) + count( $unpaid_requests ),
			],
		] );
	}

	/**
	 * Custom design requests a customer submitted but never checked out
	 * (direct request: "a custom proof showing 'awaiting payment', but I
	 * don't see a matching draft order"). Submitting the custom design
	 * form only creates the unpublished yp_custom_order and puts its
	 * items in the customer's cart — no WooCommerce order exists until
	 * they press Place order, so there's nothing for the Drafts tab's
	 * order query to find. Unpaid records already on a WooCommerce order
	 * (a manual order's proof, or a checkout that got as far as Place
	 * order) are left out: that order is what shows up instead.
	 */
	private function unpaid_custom_requests(): array {
		$ids = get_posts( [
			'post_type'      => 'yp_custom_order',
			'post_status'    => 'draft',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
		] );

		if ( ! $ids ) {
			return [];
		}

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above; WooCommerce has no API for "which order items carry this meta value".
		$linked = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_value FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE meta_key = '_yp_custom_order_id' AND meta_value IN ( {$placeholders} )", $ids ) );
		$linked = array_map( 'intval', $linked );

		$rows = [];
		foreach ( $ids as $id ) {
			if ( in_array( (int) $id, $linked, true ) ) {
				continue;
			}

			$batch      = json_decode( (string) get_post_meta( $id, YeffoPrint_Custom_Order_Meta::BATCH, true ), true );
			$label_rows = is_array( $batch ) ? count( $batch ) : 0;
			$quantity   = is_array( $batch ) ? array_sum( array_map( static fn( $row ) => (int) ( $row['quantity'] ?? 0 ), $batch ) ) : (int) get_post_meta( $id, YeffoPrint_Custom_Order_Meta::QUANTITY, true );
			$order_type = YeffoPrint_Custom_Order_Meta::get_order_type( (int) $id );

			$rows[] = [
				'id'               => (int) $id,
				'title'            => get_the_title( $id ),
				'order_type_label' => YeffoPrint_Custom_Order_Meta::ORDER_TYPES[ $order_type ],
				'date'             => get_post_datetime( $id ) ? get_post_datetime( $id )->format( 'c' ) : null,
				'customer_name'    => (string) get_post_meta( $id, YeffoPrint_Custom_Order_Meta::CUSTOMER_NAME, true ),
				'customer_email'   => (string) get_post_meta( $id, YeffoPrint_Custom_Order_Meta::CUSTOMER_EMAIL, true ),
				'label_rows'       => $label_rows,
				'quantity'         => $quantity,
			];
		}

		return $rows;
	}

	/** A lighter row shape for the Order History list — detail_payload() (full items/shipping/Shippo panel data) only loads once a row is actually clicked open. */
	private function summary_payload( \WC_Order $order ): array {
		return [
			'id'             => $order->get_id(),
			'number'         => $order->get_order_number(),
			'status'         => $order->get_status(),
			'status_label'   => wc_get_order_status_name( $order->get_status() ),
			'date'           => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : null,
			'customer_name'  => trim( $order->get_formatted_billing_full_name() ),
			'customer_email' => $order->get_billing_email(),
			'total'          => (float) $order->get_total(),
			'item_count'     => $order->get_item_count(),
			'express'        => YeffoPrint_Express_Order::is_express( $order ),
		];
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

	/**
	 * Wraps wc_create_refund() — the exact same entry point the classic
	 * order screen's own "Refund" panel calls. `refund_via_gateway`
	 * (only meaningful, and only ever offered by the frontend, when
	 * detail_payload()'s own `refund_gateway_supported` came back true
	 * for this order's payment method) is passed straight through as
	 * wc_create_refund()'s `refund_payment` flag — when the gateway
	 * doesn't support automatic refunds (this store's Manual/Venmo/
	 * Zelle/NOWPayments gateways, none of which declare 'refunds' support),
	 * wc_create_refund() already knows to just record the refund without
	 * attempting to contact a processor, so nothing extra is needed here
	 * for that case.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_refund( \WP_REST_Request $request ) {
		$order = $this->validate_order( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$params = $request->get_json_params() ?: [];
		$amount = (float) ( $params['amount'] ?? 0 );
		$reason = sanitize_text_field( (string) ( $params['reason'] ?? '' ) );
		$via_gateway = ! empty( $params['refund_via_gateway'] );

		if ( $amount <= 0 ) {
			return new \WP_Error( 'yeffoprint_refund_invalid_amount', __( 'Enter an amount to refund.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$remaining = (float) $order->get_remaining_refund_amount();
		if ( $amount > $remaining + 0.01 ) {
			return new \WP_Error(
				'yeffoprint_refund_too_much',
				sprintf(
					/* translators: %s: the remaining refundable amount as a plain "$12.34" string — this reads as plain text in the admin app's error UI, so it deliberately skips wc_price()'s own HTML-wrapped output */
					__( 'That’s more than what’s left to refund ($%s).', 'yeffoprint-core' ),
					number_format( $remaining, 2 )
				),
				[ 'status' => 400 ]
			);
		}

		$refund = wc_create_refund( [
			'order_id'       => $order->get_id(),
			'amount'         => $amount,
			'reason'         => $reason,
			'refund_payment' => $via_gateway,
		] );

		if ( is_wp_error( $refund ) ) {
			return $refund;
		}

		// Re-fetched rather than reusing $order — wc_create_refund()
		// writes the new total/refund records straight to the DB via
		// its own fresh order instance, so this one's in-memory props
		// (get_remaining_refund_amount() in particular) would otherwise
		// still reflect the pre-refund state.
		return rest_ensure_response( $this->detail_payload( wc_get_order( $order->get_id() ) ) );
	}

	/**
	 * Direct request: "alert a customer that they accidentally underpaid
	 * if a zelle/venmo comes in that's slightly short." Staff enter what
	 * actually arrived; a full payment marks the order paid, a short one
	 * emails the customer the balance (class-partial-payments.php).
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function record_payment( \WP_REST_Request $request ) {
		$order = $this->validate_order( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		if ( ! YeffoPrint_Partial_Payments::can_record( $order ) ) {
			return new \WP_Error( 'yeffoprint_order_not_unpaid', __( 'This order is already paid or closed.', 'yeffoprint-core' ), [ 'status' => 409 ] );
		}

		$params = $request->get_json_params() ?: [];
		$amount = round( (float) ( $params['amount'] ?? 0 ), 2 );
		$method = sanitize_key( (string) ( $params['method'] ?? '' ) );

		if ( $amount <= 0 ) {
			return new \WP_Error( 'yeffoprint_payment_invalid_amount', __( 'Enter the amount you received.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}
		if ( ! array_key_exists( $method, YeffoPrint_Partial_Payments::METHODS ) ) {
			return new \WP_Error( 'yeffoprint_payment_invalid_method', __( 'Pick how they paid.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		YeffoPrint_Partial_Payments::record( $order, $amount, $method, __( 'recorded by staff', 'yeffoprint-core' ), ! empty( $params['email_customer'] ) );

		return rest_ensure_response( $this->detail_payload( wc_get_order( $order->get_id() ) ) );
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
			// Drives the drawer's Cancel order button (unpaid orders only).
			'date_paid'            => $order->get_date_paid() ? $order->get_date_paid()->date( 'c' ) : null,
			'customer_name'        => trim( $order->get_formatted_billing_full_name() ),
			'customer_email'       => $order->get_billing_email(),
			'customer_phone'       => $order->get_billing_phone(),
			'customer_note'        => $order->get_customer_note(),
			'express'              => YeffoPrint_Express_Order::is_express( $order ),
			// Falls back to billing when there's no separate shipping
			// address — same behavior WooCommerce's own order screen and
			// order emails already use, not a new convention introduced
			// here.
			'shipping_address'     => $order->get_formatted_shipping_address() ?: $order->get_formatted_billing_address(),
			// Direct request: staff can flag a manual order "customer will
			// provide it" instead of blocking on an address they don't have
			// yet (class-order-pay-address.php) — true only while that's
			// still outstanding, so the drawer can call it out.
			'needs_customer_address' => YeffoPrint_Order_Pay_Address::needs_address( $order ),
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
			// Direct request: edit an order "before it's been paid" — lets
			// the drawer offer Edit order, and show the pay link it keeps.
			'editable'             => YeffoPrint_Manual_Order_Creator::is_editable( $order ),
			'payment_url'          => $order->needs_payment() ? $order->get_checkout_payment_url() : null,
			'customer_picks_shipping' => YeffoPrint_Order_Pay_Address::customer_picks_shipping( $order ),
			// Record payment panel (class-partial-payments.php): what has
			// come in so far on an unpaid order, and what's still owed.
			'can_record_payment'   => YeffoPrint_Partial_Payments::can_record( $order ),
			'amount_received'      => YeffoPrint_Partial_Payments::received( $order ),
			'balance_due'          => YeffoPrint_Partial_Payments::balance_due( $order ),
			'payments_received'    => YeffoPrint_Partial_Payments::log( $order ),
			'payment_method'       => $order->get_payment_method(),
			'shipping_lines'       => array_values( array_map( static function ( \WC_Order_Item_Shipping $item ): array {
				return [ 'title' => $item->get_method_title(), 'amount' => (float) $item->get_total() ];
			}, $order->get_items( 'shipping' ) ) ),
			'shipping_country'     => $order->get_shipping_country() ?: $order->get_billing_country(),
			// Direct request: print a real shipping label from this drawer, "without having to go to
			// WooCommerce" — the WooCommerce Shipping plugin only ever renders its label-purchase UI
			// as a meta box (#woocommerce-order-label) on the classic order edit screen; there's no
			// public API to drive rate-shopping/label purchase from outside it. Rather than
			// reimplement that (a large proprietary React app — rates, customs forms, payment,
			// printing), the frontend embeds that exact meta box via a same-origin iframe onto
			// `edit_url` and hides the surrounding chrome with injected CSS, so this flag just tells
			// it whether that plugin is even active.
			//
			// Direct bug report: this originally checked class_exists( '\Automattic\WCShipping\Loader' )
			// — that fully-qualified class name is specific to one version of the plugin's internal
			// code, and came back false on the live site even with the plugin genuinely active (its
			// meta box rendered fine on the classic screen) — the installed version's internal
			// structure just didn't match what this checked for, so every "the panel exists but is
			// hidden" run looked identical to "the plugin genuinely isn't active." is_plugin_active()
			// asks WordPress directly instead — the exact same source of truth the Plugins screen's
			// own "Active" label reads from, version-independent. It isn't autoloaded outside
			// /wp-admin/ (unlike a normal admin page load, which already pulled it in), hence the
			// explicit require below.
			'shipping_label_available' => $this->is_shipping_plugin_active(),
			// The independent Shippo panel (class-admin-shippo-controller.php) — direct request:
			// "can we build something with the shippo API to replace it? ... I'd like to run
			// alongside it a bit." Shown next to, not instead of, the row above.
			'shippo_configured'        => YeffoPrint_Shippo_Settings::is_configured(),
			'shippo_default_package'   => YeffoPrint_Shippo_Settings::get_default_package(),
			'shippo_customs'           => YeffoPrint_Admin_Shippo_Controller::customs_payload( $order ),
			// Direct request: "need the ability to go back and print the label later." Every
			// Shippo label already purchased on this order, printable link included, so the panel
			// can offer a reprint regardless of whether it was purchased in this drawer session or
			// a previous one.
			'shippo_labels'            => $this->shippo_labels_payload( $order ),
			// Direct request: "can we add the rewards info to this screen... how many points this
			// order will receive (or has received)?" Same processed-vs-pending distinction as the
			// classic order screen's own "Rewards Points" meta box (class-rewards-order-box.php) —
			// once YeffoPrint_Rewards::finalize_order() has actually run (order paid), show the
			// real stored amounts; before that, YeffoPrint_Rewards::calculate_points() is a safe,
			// read-only live estimate of what finalize_order() would compute right now.
			'rewards'                  => $this->rewards_payload( $order ),
			// Direct request: refund an order without leaving this app.
			// `refund_gateway_supported` tells the frontend whether to
			// even offer an "also refund via {gateway}" checkbox —
			// this store's Manual/Venmo/Zelle/NOWPayments gateways don't
			// declare `WC_Payment_Gateway::supports('refunds')`, so a
			// refund on one of those orders can only ever be a local
			// record, never an automatic processor refund.
			'total_refunded'            => (float) $order->get_total_refunded(),
			'remaining_refund_amount'   => (float) $order->get_remaining_refund_amount(),
			'refund_gateway_supported'  => $this->refund_gateway_supported( $order ),
			'refunds'                   => array_map( static function ( \WC_Order_Refund $refund ): array {
				return [
					'id'     => $refund->get_id(),
					'amount' => (float) $refund->get_amount(),
					'reason' => $refund->get_reason(),
					'date'   => $refund->get_date_created() ? $refund->get_date_created()->date( 'c' ) : null,
				];
			}, $order->get_refunds() ),
			// Direct request: "add notes to customers so when I print
			// their future orders I can refer to them" — keyed by
			// billing email (YeffoPrint_Customer_Notes), so this works
			// identically for a guest or a registered account, and the
			// drawer can add a note right here without a trip to the
			// separate Customers screen.
			'customer_notes'            => YeffoPrint_Customer_Notes::get_notes( $order->get_billing_email() ),
			// Direct request: an Agreement/Staging Site/Go-Live workflow
			// for Web Design Package orders, right in this same drawer.
			// `null` for every other order — the frontend only renders
			// the Web Design panel when this key is present at all, so
			// no `if (isWebDesign)` guard is duplicated in two places.
			'web_design'                => YeffoPrint_Web_Design_Project_Meta::is_web_design_order( $order )
				? [ 'package_id' => YeffoPrint_Web_Design_Project_Meta::get_package_id( $order ) ]
				: null,
		];
	}

	/**
	 * Every Shippo label on the order. International labels bought before
	 * the carrier's customs messages were saved get them looked up once
	 * first, so "Customs invoice sent electronically" shows on those too.
	 */
	private function shippo_labels_payload( \WC_Order $order ): array {
		if ( YeffoPrint_Shippo_Settings::is_configured() && YeffoPrint_Admin_Shippo_Controller::customs_payload( $order )['international'] ) {
			YeffoPrint_Order_Tracking::backfill_shippo_label_customs( $order, new YeffoPrint_Shippo_Client( YeffoPrint_Shippo_Settings::get_api_key() ) );
		}

		return YeffoPrint_Order_Tracking::get_shippo_labels( $order );
	}

	private function refund_gateway_supported( \WC_Order $order ): bool {
		$gateway = wc_get_payment_gateway_by_order( $order );
		return $gateway instanceof \WC_Payment_Gateway && $gateway->supports( 'refunds' );
	}

	private function rewards_payload( \WC_Order $order ): array {
		if ( ! $order->get_customer_id() ) {
			return [ 'guest' => true, 'processed' => false, 'earned' => 0, 'redeemed' => 0 ];
		}

		$processed = (bool) $order->get_meta( YeffoPrint_Rewards::ORDER_PROCESSED_META );
		$points    = $processed
			? [
				'earned'   => (int) $order->get_meta( YeffoPrint_Rewards::ORDER_POINTS_EARNED_META ),
				'redeemed' => (int) $order->get_meta( YeffoPrint_Rewards::ORDER_POINTS_REDEEMED_META ),
			]
			: YeffoPrint_Rewards::calculate_points( $order );

		return [
			'guest'     => false,
			'processed' => $processed,
			'earned'    => $points['earned'],
			'redeemed'  => $points['redeemed'],
		];
	}

	private function is_shipping_plugin_active(): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( 'woocommerce-shipping/woocommerce-shipping.php' );
	}

	private function item_payload( \WC_Order_Item $item ): ?array {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return null; // Fee/shipping/tax line items — nothing to customize, and the totals above already account for them.
		}

		$product = $item->get_product();

		return [
			'id'        => $item->get_id(),
			'name'      => $item->get_name(),
			'quantity'  => $item->get_quantity(),
			'total'     => (float) $item->get_total(),
			// The linked product's own image — for a Template line item
			// this is always the template's featured image, kept in sync
			// on every Template save (class-linked-product.php), so no
			// separate lookup through the order item's own template
			// snapshot is needed here. Custom Design/Sticker line items
			// use a generic linked product with no image, so this is
			// simply null for those — the frontend already handles a
			// missing image (falls back to a placeholder swatch).
			// 'medium' keeps the image's own shape; 'thumbnail' is a
			// square crop that cut wide label previews off (direct report).
			'image_url' => $product ? ( wp_get_attachment_image_url( $product->get_image_id(), 'medium' ) ?: null ) : null,
			// The custom design request (yp_custom_order) behind this line,
			// if any — the new admin's order page shows its proof there.
			'custom_order_id' => (int) $item->get_meta( '_yp_custom_order_id' ),
			// Shown on the label card itself so an order with several
			// brands can be told apart without opening Details.
			'brands'    => self::item_brands( $item ),
			// display_value is already wp_kses_post()-safe HTML by the
			// time get_formatted_meta_data() returns it (WC_Order_Item's
			// own method) — the same batch tables/variant summaries/QR
			// links the classic order screen renders raw, rendered raw
			// here too rather than re-escaped into visible markup.
			//
			// mark/unmark_admin_app_context() around the call itself —
			// direct report, with a screenshot: this table was showing
			// the raw joined "Corner Finish: Rounded — Compound Name: …"
			// summary string instead of the field-by-field layout the
			// classic order screen already gets, because
			// YeffoPrint_Order_Item_Meta::format_customization_display()
			// only reformats it there or in an HTML email. This flag is
			// this REST request's own "order screen," so the same
			// reformatted markup (and its color swatches) now renders
			// here too.
			'meta'      => array_values( array_map( static function ( $entry ) {
				return [ 'label' => (string) $entry->display_key, 'value' => (string) $entry->display_value ];
			}, self::formatted_meta_data( $item ) ) ),
		];
	}

	/**
	 * The brand name(s) behind a label line. A custom design request
	 * keeps its brand on the yp_custom_order record (never copied into
	 * the line's own meta); a Template batch keeps it per label in
	 * _yp_variants, under whichever field is a "brand" one.
	 */
	private static function item_brands( \WC_Order_Item_Product $item ): array {
		$brands          = [];
		$custom_order_id = (int) $item->get_meta( '_yp_custom_order_id' );
		if ( $custom_order_id ) {
			$brands[] = (string) get_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::BRAND_NAME, true );
		}

		$variants = json_decode( (string) $item->get_meta( '_yp_variants' ), true );
		foreach ( is_array( $variants ) ? $variants : [] as $variant ) {
			foreach ( (array) ( $variant['values'] ?? [] ) as $key => $value ) {
				if ( false !== stripos( (string) $key, 'brand' ) && is_scalar( $value ) ) {
					$brands[] = (string) $value;
				}
			}
		}

		return array_values( array_unique( array_filter( array_map( 'trim', $brands ), 'strlen' ) ) );
	}

	/** @see item_payload()'s own call site above for why this wraps get_formatted_meta_data() instead of calling it directly. */
	private static function formatted_meta_data( \WC_Order_Item $item ): array {
		YeffoPrint_Order_Item_Meta::mark_admin_app_context();
		$formatted_meta = $item->get_formatted_meta_data();
		YeffoPrint_Order_Item_Meta::unmark_admin_app_context();

		return $formatted_meta;
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
