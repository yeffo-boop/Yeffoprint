<?php
/**
 * Displays every WooCommerce order number as "YP-{id}" instead of a
 * bare number — direct request, so an order number reads unambiguously
 * as belonging to this store wherever a customer or staff member sees
 * it (email subject lines, invoices, My Account, admin order list).
 *
 * Deliberately just a display filter on the id WooCommerce already
 * assigns (`woocommerce_order_number` — the one place get_order_number()
 * itself reads from, so every consumer of that method picks this up
 * automatically) rather than a new independent counter. The underlying
 * order id is completely unchanged: what's stored in the database, the
 * order-edit URL, REST API ids, and every internal lookup this plugin
 * already does by numeric order id all keep working exactly as before
 * — only what's *displayed* as the order number changes.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Order_Number_Format {

	/** Shared by the display filter and the admin-search prefix-strip below, so the two can never drift apart. */
	private const PREFIX = 'YP-';

	public function __construct() {
		add_filter( 'woocommerce_order_number', [ $this, 'format_order_number' ], 10, 2 );
		add_action( 'admin_init', [ $this, 'strip_prefix_from_admin_search' ] );
	}

	public function format_order_number( $order_id, \WC_Order $order ): string {
		return self::PREFIX . $order->get_id();
	}

	/**
	 * WooCommerce's own order search matches a purely numeric search
	 * term straight to an order id (both the classic post-type and HPOS
	 * data stores) — it has no idea about this class's own "YP-"
	 * display prefix, so pasting an order number straight from an
	 * email/support message ("YP-1042") into the admin order search box
	 * would otherwise find nothing. Stripped here, once, before that
	 * search ever runs, rather than duplicating this in a
	 * search-results filter for both data stores separately.
	 */
	public function strip_prefix_from_admin_search(): void {
		if ( empty( $_GET['s'] ) ) {
			return;
		}

		$is_classic_orders_screen = isset( $_GET['post_type'] ) && 'shop_order' === $_GET['post_type'];
		$is_hpos_orders_screen    = isset( $_GET['page'] ) && 'wc-orders' === $_GET['page'];
		if ( ! $is_classic_orders_screen && ! $is_hpos_orders_screen ) {
			return;
		}

		// Read-only search box, not a state change — same reasoning
		// class-rewards-admin.php's own GET-based customer lookup
		// already documents for skipping a nonce here.
		$search = wp_unslash( $_GET['s'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 0 === stripos( $search, self::PREFIX ) ) {
			$stripped      = substr( $search, strlen( self::PREFIX ) );
			$_GET['s']     = $stripped;
			$_REQUEST['s'] = $stripped;
		}
	}
}
