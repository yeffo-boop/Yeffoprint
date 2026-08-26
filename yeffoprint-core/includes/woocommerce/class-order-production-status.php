<?php
/**
 * Registers an "In Production" WooCommerce order status between
 * Processing and Shipped. Unlike class-order-shipment-status.php's
 * automatic Processing→Shipped transition, this one is staff-triggered
 * only — the Dashboard's "Send to Printer" button (docs/ARCHITECTURE.md)
 * calls YeffoPrint_Admin_Order_Controller::send_to_printer(), which is
 * the only thing that ever moves an order into this status. It doesn't
 * print anything itself; it's purely a status marker so staff can see at
 * a glance which paid orders have actually been queued at the printer
 * versus still sitting untouched.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Order_Production_Status {

	public const STATUS = 'in-production';

	public function __construct() {
		add_action( 'init', [ $this, 'register_status' ] );
		add_filter( 'wc_order_statuses', [ $this, 'add_to_status_list' ] );
	}

	public function register_status(): void {
		register_post_status( 'wc-' . self::STATUS, [
			'label'                     => _x( 'In Production', 'Order status', 'yeffoprint-core' ),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: number of orders */
			'label_count'               => _n_noop( 'In Production <span class="count">(%s)</span>', 'In Production <span class="count">(%s)</span>', 'yeffoprint-core' ),
		] );
	}

	/** Slots "In Production" right after "Processing" in every status dropdown/list — the real pipeline is Processing → In Production → Shipped → Completed. */
	public function add_to_status_list( array $order_statuses ): array {
		$new_statuses = [];

		foreach ( $order_statuses as $key => $label ) {
			$new_statuses[ $key ] = $label;
			if ( 'wc-processing' === $key ) {
				$new_statuses[ 'wc-' . self::STATUS ] = _x( 'In Production', 'Order status', 'yeffoprint-core' );
			}
		}

		return $new_statuses;
	}
}
