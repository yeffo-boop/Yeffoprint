<?php
/**
 * Admin REST endpoint backing the app's real Dashboard home view
 * (docs/ARCHITECTURE.md, Phase 6) — the four sections
 * `YeffoPrint_Dashboard_Widgets` already renders server-side for the
 * classic reskin's Dashboard page (`includes/admin/class-dashboard-widgets.php`),
 * reached here over REST instead so the new app's client-side router
 * can render them without a page reload, plus one section that class
 * never had: Shipped Packages (package tracking, direct request).
 * Deliberately the same queries as that class, not a rewrite:
 * `wc_get_orders( status: 'processing' )` for Pending Orders, a
 * `_yp_status` meta query for the two CustomOrder pipeline sections,
 * `YeffoPrint_Maintenance_Sub_Meta::get_active()` for subscribers —
 * and `YeffoPrint_Dashboard_Widgets::due_date_days()` itself, reused
 * as-is, so the "N days overdue" threshold can never drift between the
 * old dashboard and this one.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_Dashboard_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	/** Same cap as YeffoPrint_Dashboard_Widgets::ROW_LIMIT — this is a glanceable summary, not a full list (each section's "View all" link goes to the full screen). */
	private const ROW_LIMIT = 25;

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/admin/dashboard-summary', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_summary' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		// Direct request, testing the just-shipped live-tracking feature:
		// staff don't want to wait for the next hourly
		// YeffoPrint_Order_Delivery_Status sweep — this runs the exact same
		// sweep synchronously and hands back the refreshed summary, so the
		// Shipped Packages panel's "Check tracking now" button can show
		// results immediately.
		register_rest_route( self::NAMESPACE, '/admin/dashboard/refresh-tracking', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'refresh_tracking' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );
	}

	public function refresh_tracking(): \WP_REST_Response {
		( new YeffoPrint_Order_Delivery_Status() )->sweep();

		return $this->get_summary();
	}

	public function get_summary(): \WP_REST_Response {
		return rest_ensure_response( [
			'due_date_days'             => YeffoPrint_Dashboard_Widgets::due_date_days(),
			'pending_orders'            => $this->pending_wc_orders(),
			'pending_orders_url'        => $this->orders_list_url(),
			'shipped_packages'          => $this->shipped_packages(),
			'pending_proofs'            => $this->custom_orders_by_status( 'design_in_progress' ),
			'awaiting_approval'         => $this->custom_orders_by_status( 'awaiting_approval' ),
			'maintenance_subscribers'   => $this->maintenance_subscribers(),
			// Site-wide (not per-row) — direct request: an In Production row's action
			// column offers "Print Shipping Label" (opening straight into the same
			// embedded WooCommerce Shipping form the drawer's own Shipping Label panel
			// uses, class-admin-order-controller.php's detail_payload()) instead of a
			// plain status pill, but only when that plugin is actually active.
			'shipping_label_available' => $this->is_shipping_plugin_active(),
		] );
	}

	/** Same is_plugin_active() check as class-admin-order-controller.php's own is_shipping_plugin_active() — duplicated rather than shared, matching orders_list_url() above already duplicating YeffoPrint_Dashboard_Widgets' own HPOS check; this is a site-wide flag here (one value for the whole summary), not per-order like that controller's own use of it. */
	private function is_shipping_plugin_active(): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( 'woocommerce-shipping/woocommerce-shipping.php' );
	}

	/** Same HPOS-aware link YeffoPrint_Dashboard_Widgets::orders_list_url() builds — WooCommerce orders aren't part of this app's own rewrite, so this always points back out to classic wp-admin. */
	private function orders_list_url(): string {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
		) {
			return admin_url( 'admin.php?page=wc-orders' );
		}

		return admin_url( 'edit.php?post_type=shop_order' );
	}

	/**
	 * Direct report: the "Send to Printer" button used to make a row
	 * vanish from this panel entirely, since it only ever queried
	 * "processing" — once an order moved to "In Production"
	 * (class-order-production-status.php), staff lost track of it here.
	 * Now queries both pipeline stages before Shipped, and returns each
	 * row's own status so the frontend can show which is which and only
	 * offer "Send to Printer" on the ones still actually in Processing.
	 */
	private function pending_wc_orders(): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return [];
		}

		$orders = wc_get_orders( [
			'status'  => [ 'processing', YeffoPrint_Order_Production_Status::STATUS ],
			'limit'   => self::ROW_LIMIT,
			'orderby' => 'date',
			'order'   => 'ASC',
		] );

		return array_map( function ( \WC_Order $order ) {
			$date = $order->get_date_created();
			return [
				'id'           => $order->get_id(),
				/* translators: %s: order number */
				'label'        => sprintf( __( 'Order %s', 'yeffoprint-core' ), $order->get_order_number() ),
				'customer'     => $order->get_formatted_billing_full_name() ?: $order->get_billing_email(),
				'edit_url'     => $order->get_edit_order_url(),
				'date'         => $date ? $date->date( 'c' ) : null,
				'status'       => $order->get_status(),
				'status_label' => wc_get_order_status_name( $order->get_status() ),
			];
		}, $orders );
	}

	/**
	 * One row per shipment, not per order — a single order can carry more
	 * than one label (YeffoPrint_Order_Tracking::get_shipments() already
	 * returns every trackable one), and "all of my packages that have
	 * not been delivered yet" (direct request) means every physical
	 * package, not every order. No overdue-days flagging here unlike the
	 * other sections — that needs a real "label purchased" timestamp
	 * this store doesn't reliably have yet (WooCommerce Shipping's own
	 * label data only guarantees a tracking number, not a verified date
	 * field — see class-order-tracking.php's own docblock on staying
	 * conservative about that plugin's exact data shape), so the order
	 * date would just be a misleading proxy for "how long has this been
	 * in transit."
	 *
	 * `tracking_status`/`tracking_status_description`/`tracking_checked_at`
	 * — direct request: "I want live tracking to show for any orders that
	 * haven't been delivered." Reads YeffoPrint_Order_Delivery_Status's own
	 * stored last-known-good status rather than calling a carrier/Shippo
	 * live on every dashboard load — that class's hourly sweep (or the
	 * panel's own "Check tracking now" button, refresh_tracking() above)
	 * is what actually keeps this current.
	 */
	private function shipped_packages(): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return [];
		}

		$orders = wc_get_orders( [
			'status'  => YeffoPrint_Order_Shipment_Status::STATUS,
			'limit'   => self::ROW_LIMIT,
			'orderby' => 'date',
			'order'   => 'ASC',
		] );

		$rows = [];
		foreach ( $orders as $order ) {
			foreach ( YeffoPrint_Order_Tracking::get_shipments( $order ) as $shipment ) {
				$status = YeffoPrint_Order_Delivery_Status::get_status( $order, $shipment['tracking_number'] );

				$rows[] = [
					// Direct report: clicking an order here landed on the classic WooCommerce
					// edit screen instead of this dashboard's own order-detail drawer — 'id'
					// (same field pending_wc_orders() rows already carry) is what the frontend's
					// existing [data-yp-wc-order] click handler needs to open that drawer instead.
					'id'                          => $order->get_id(),
					/* translators: %s: order number */
					'order_label'                 => sprintf( __( 'Order %s', 'yeffoprint-core' ), $order->get_order_number() ),
					'customer'                    => $order->get_formatted_billing_full_name() ?: $order->get_billing_email(),
					'edit_url'                    => $order->get_edit_order_url(),
					'carrier_label'               => $shipment['carrier_label'],
					'tracking_number'             => $shipment['tracking_number'],
					'tracking_url'                => $shipment['carrier_url'],
					'tracking_status'             => $status['status'] ?? null,
					'tracking_status_description' => $status['description'] ?? '',
					'tracking_checked_at'         => $status['checked_at'] ?? null,
				];
			}
		}

		return $rows;
	}

	private function custom_orders_by_status( string $status ): array {
		$query = new \WP_Query( [
			'post_type'      => 'yp_custom_order',
			'post_status'    => 'publish',
			'posts_per_page' => self::ROW_LIMIT,
			'orderby'        => 'date',
			'order'          => 'ASC',
			'meta_query'     => [
				[
					'key'   => YeffoPrint_Custom_Order_Meta::STATUS,
					'value' => $status,
				],
			],
		] );

		return array_map( function ( \WP_Post $post ) {
			$customer = get_post_meta( $post->ID, YeffoPrint_Custom_Order_Meta::CUSTOMER_NAME, true )
				?: get_post_meta( $post->ID, YeffoPrint_Custom_Order_Meta::CUSTOMER_EMAIL, true );
			$date = get_post_datetime( $post );

			return [
				'id'       => $post->ID,
				'label'    => get_the_title( $post ),
				'customer' => (string) $customer,
				'date'     => $date ? $date->format( 'c' ) : null,
			];
		}, $query->posts );
	}

	private function maintenance_subscribers(): array {
		if ( ! class_exists( 'YeffoPrint_Maintenance_Sub_Meta' ) ) {
			return [];
		}

		return array_map( function ( \WP_Post $post ) {
			return [
				'id'     => $post->ID,
				'name'   => get_the_title( $post ),
				'plan'   => (string) get_post_meta( $post->ID, YeffoPrint_Maintenance_Sub_Meta::PLAN_LABEL, true ),
				'renews' => (int) get_post_meta( $post->ID, YeffoPrint_Maintenance_Sub_Meta::CURRENT_PERIOD_END, true ) ?: null,
			];
		}, YeffoPrint_Maintenance_Sub_Meta::get_active() );
	}
}
