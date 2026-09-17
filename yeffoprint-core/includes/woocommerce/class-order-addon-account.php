<?php
/**
 * "Add to this order" on the customer's own order-detail view in My
 * Account — the second of the two entry points, alongside the token
 * link on the /add-to-order/ page (usually reached from an order
 * email). Hooks WooCommerce's own extension point rather than
 * overriding myaccount/view-order.php wholesale — this theme doesn't
 * customize that template at all today, and re-implementing
 * WooCommerce's own order-details markup from scratch just to add one
 * button would be a much larger, more fragile change than hooking the
 * point core already exposes for exactly this.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Order_Addon_Account {

	public function __construct() {
		add_action( 'woocommerce_order_details_after_order_table', [ $this, 'render_button' ] );
	}

	public function render_button( \WC_Order $order ): void {
		if ( ! is_wc_endpoint_url( 'view-order' ) ) {
			return;
		}

		// WooCommerce's own account area already only ever lets a
		// customer reach this template for their own order (or an admin
		// viewing it) — no extra ownership check needed here the way the
		// token-based /add-to-order/ page needs one.
		$eligibility = YeffoPrint_Order_Addon::eligibility( $order );
		if ( ! $eligibility['eligible'] ) {
			return;
		}

		$root = $eligibility['root'];
		$url  = add_query_arg(
			[ 'order' => $root->get_id(), 'key' => $root->get_order_key() ],
			home_url( '/add-to-order/' )
		);

		printf(
			'<p class="yp-addon-account-cta"><a class="button" href="%1$s">%2$s</a></p>',
			esc_url( $url ),
			esc_html__( 'Add to this order (no extra shipping)', 'yeffoprint-core' )
		);
	}
}
