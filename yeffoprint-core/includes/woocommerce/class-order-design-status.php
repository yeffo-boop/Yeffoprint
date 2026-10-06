<?php
/**
 * Registers an "In Design" WooCommerce order status for Web Design
 * Package orders (direct request: "We need like an 'in design' status
 * for websites that I'm building. Right now they sit in processing and
 * the site thinks I need to 'ship it' or 'label it'"). A website is
 * never printed or shipped, so it gets its own paid status that every
 * print/ship list (production board, Ship today, Pending Orders,
 * Telegram /pending) leaves out by status alone.
 *
 * A web design order moves here on its own the moment it's paid
 * (unpaid → Processing), and leaves when the site is marked live
 * (class-admin-web-design-controller.php's mark_live() completes it).
 * Staff can still pick any status by hand; only a payment moves an
 * order in automatically, so setting one back to Processing sticks.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Order_Design_Status {

	public const STATUS = 'in-design';

	/** Statuses an order is still unpaid in — a move from one of these to Processing is the payment. */
	private const UNPAID_STATUSES = [ 'pending', 'on-hold', 'failed', 'checkout-draft' ];

	/** Set once the web design orders already sitting in Processing have been moved here. */
	private const BACKFILL_OPTION = 'yeffoprint_in_design_backfilled';

	public function __construct() {
		add_action( 'init', [ $this, 'register_status' ] );
		add_filter( 'wc_order_statuses', [ $this, 'add_to_status_list' ] );
		add_filter( 'woocommerce_order_is_paid_statuses', [ $this, 'add_to_paid_statuses' ] );
		add_action( 'woocommerce_order_status_changed', [ $this, 'move_paid_web_design_order' ], 20, 4 );
		add_action( 'admin_init', [ $this, 'backfill' ] );
	}

	public function register_status(): void {
		register_post_status( 'wc-' . self::STATUS, [
			'label'                     => _x( 'In Design', 'Order status', 'yeffoprint-core' ),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: number of orders */
			'label_count'               => _n_noop( 'In Design <span class="count">(%s)</span>', 'In Design <span class="count">(%s)</span>', 'yeffoprint-core' ),
		] );
	}

	/** Slots "In Design" right after "Processing" in every status dropdown/list. */
	public function add_to_status_list( array $order_statuses ): array {
		$new_statuses = [];

		foreach ( $order_statuses as $key => $label ) {
			$new_statuses[ $key ] = $label;
			if ( 'wc-processing' === $key ) {
				$new_statuses[ 'wc-' . self::STATUS ] = _x( 'In Design', 'Order status', 'yeffoprint-core' );
			}
		}

		return $new_statuses;
	}

	public function add_to_paid_statuses( array $statuses ): array {
		$statuses[] = self::STATUS;
		return array_values( array_unique( $statuses ) );
	}

	/**
	 * Runs after WooCommerce's own Processing hooks (the customer's
	 * "order received" email included), so the paid email still goes
	 * out before the order moves on to In Design.
	 */
	public function move_paid_web_design_order( int $order_id, string $from, string $to, $order = null ): void {
		if ( 'processing' !== $to || ! in_array( $from, self::UNPAID_STATUSES, true ) ) {
			return;
		}

		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || ! YeffoPrint_Web_Design_Project_Meta::is_web_design_order( $order ) ) {
			return;
		}

		$order->update_status( self::STATUS, __( 'Website order paid, moved to In Design.', 'yeffoprint-core' ) );
	}

	/** One time: moves web design orders already paid and sitting in Processing to In Design. */
	public function backfill(): void {
		if ( get_option( self::BACKFILL_OPTION ) || ! function_exists( 'wc_get_orders' ) ) {
			return;
		}
		update_option( self::BACKFILL_OPTION, 1, false );

		foreach ( YeffoPrint_Web_Design_Project_Meta::get_all_orders() as $order ) {
			if ( 'processing' === $order->get_status() && YeffoPrint_Web_Design_Project_Meta::is_web_design_order( $order ) ) {
				$order->update_status( self::STATUS, __( 'Website order moved from Processing to In Design.', 'yeffoprint-core' ) );
			}
		}
	}
}
