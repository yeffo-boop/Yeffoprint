<?php
/**
 * "Curing" order status between In Production and Shipped — direct
 * request: holographic orders rest 24 hours after printing so the vinyl
 * can de-gas before it ships (class-holographic-vinyl.php), and Jeff
 * wanted a status to move them to after printing. Staff move an order
 * here from the admin app's order page; the time it entered is kept so
 * the app can say when it's ready to ship. Nothing moves orders here
 * automatically, and Shipped still follows from buying a label.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Order_Curing_Status {

	public const STATUS = 'curing';

	/** When the order entered Curing (unix time). */
	public const SINCE_META = '_yp_curing_since';

	public const HOURS = 24;

	public function __construct() {
		add_action( 'init', [ $this, 'register_status' ] );
		// Before class-order-shipment-status.php (10) so Shipped lands after it.
		add_filter( 'wc_order_statuses', [ $this, 'add_to_status_list' ], 9 );
		add_action( 'woocommerce_order_status_' . self::STATUS, [ $this, 'mark_since' ], 10, 2 );
	}

	public function register_status(): void {
		register_post_status( 'wc-' . self::STATUS, [
			'label'                     => _x( 'Curing', 'Order status', 'yeffoprint-core' ),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: number of orders */
			'label_count'               => _n_noop( 'Curing <span class="count">(%s)</span>', 'Curing <span class="count">(%s)</span>', 'yeffoprint-core' ),
		] );
	}

	/** Processing → In Production → Curing → Shipped → Completed. */
	public function add_to_status_list( array $order_statuses ): array {
		$anchor_key = isset( $order_statuses['wc-in-production'] ) ? 'wc-in-production' : 'wc-processing';

		$new_statuses = [];
		foreach ( $order_statuses as $key => $label ) {
			$new_statuses[ $key ] = $label;
			if ( $anchor_key === $key ) {
				$new_statuses[ 'wc-' . self::STATUS ] = _x( 'Curing', 'Order status', 'yeffoprint-core' );
			}
		}

		return $new_statuses;
	}

	public function mark_since( int $order_id, $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( $order ) {
			$order->update_meta_data( self::SINCE_META, time() );
			$order->save_meta_data();
		}
	}

	/** ISO time the order is done curing, or null when it isn't curing. */
	public static function ready_at( \WC_Order $order ): ?string {
		if ( self::STATUS !== $order->get_status() ) {
			return null;
		}
		$since = (int) $order->get_meta( self::SINCE_META );
		if ( ! $since ) {
			$modified = $order->get_date_modified();
			$since    = $modified ? $modified->getTimestamp() : time();
		}
		return gmdate( 'c', $since + self::HOURS * HOUR_IN_SECONDS );
	}
}
