<?php
/**
 * One-time catch-up for YeffoPrint_Web_Design_Project_Meta::ORDER_FLAG —
 * any Web Design order placed before that flag existed (or by a path
 * that predates it) would otherwise never appear in the new "Web Design
 * Orders" list screen or the dashboard's Web Design Milestones panel,
 * since both are bulk meta-query lookups rather than a scan of every
 * order's line items. Same dev-triggered-only, idempotent, never-
 * automatic pattern as class-pages-setup-command.php.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Web_Design_Order_Backfill_Command {

	public function register(): void {
		\WP_CLI::add_command( 'yeffoprint backfill-web-design-orders', [ $this, 'run' ] );
	}

	/**
	 * ## EXAMPLES
	 *
	 *     wp yeffoprint backfill-web-design-orders
	 */
	public function run(): void {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			\WP_CLI::error( 'WooCommerce is not active.' );
			return;
		}

		$order_ids = wc_get_orders( [ 'limit' => -1, 'return' => 'ids' ] );
		$flagged   = 0;

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}

			if ( $order->get_meta( YeffoPrint_Web_Design_Project_Meta::ORDER_FLAG ) ) {
				continue; // Already flagged — nothing to do.
			}

			if ( ! YeffoPrint_Web_Design_Project_Meta::is_web_design_order( $order ) ) {
				continue;
			}

			YeffoPrint_Web_Design_Project_Meta::mark_order( $order );
			$order->save();
			++$flagged;
		}

		\WP_CLI::success( sprintf( 'Flagged %d order(s) as Web Design orders.', $flagged ) );
	}
}
