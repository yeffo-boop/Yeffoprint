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
	}

	public function get_summary(): \WP_REST_Response {
		return rest_ensure_response( [
			'due_date_days'           => YeffoPrint_Dashboard_Widgets::due_date_days(),
			'pending_orders'          => $this->pending_wc_orders(),
			'pending_orders_url'      => $this->orders_list_url(),
			'shipped_packages'        => $this->shipped_packages(),
			'pending_proofs'          => $this->custom_orders_by_status( 'design_in_progress' ),
			'awaiting_approval'       => $this->custom_orders_by_status( 'awaiting_approval' ),
			'maintenance_subscribers' => $this->maintenance_subscribers(),
		] );
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

	private function pending_wc_orders(): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return [];
		}

		$orders = wc_get_orders( [
			'status'  => 'processing',
			'limit'   => self::ROW_LIMIT,
			'orderby' => 'date',
			'order'   => 'ASC',
		] );

		return array_map( function ( \WC_Order $order ) {
			$date = $order->get_date_created();
			return [
				'id'       => $order->get_id(),
				/* translators: %s: order number */
				'label'    => sprintf( __( 'Order %s', 'yeffoprint-core' ), $order->get_order_number() ),
				'customer' => $order->get_formatted_billing_full_name() ?: $order->get_billing_email(),
				'edit_url' => $order->get_edit_order_url(),
				'date'     => $date ? $date->date( 'c' ) : null,
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
				$rows[] = [
					/* translators: %s: order number */
					'order_label'     => sprintf( __( 'Order %s', 'yeffoprint-core' ), $order->get_order_number() ),
					'customer'        => $order->get_formatted_billing_full_name() ?: $order->get_billing_email(),
					'edit_url'        => $order->get_edit_order_url(),
					'carrier_label'   => $shipment['carrier_label'],
					'tracking_number' => $shipment['tracking_number'],
					'tracking_url'    => $shipment['carrier_url'],
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
