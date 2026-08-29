<?php
/**
 * "Delivered" — the piece class-order-shipment-status.php's own docblock
 * flagged as "a separate, later piece: it needs a working carrier
 * tracking API connection... which this store doesn't have configured
 * yet." It does now (class-shippo-tracking-provider.php, backed by the
 * Shippo API key already configured in Settings → Shipping). Direct
 * request: "I want live tracking to show for any orders that haven't
 * been delivered. Once the tracking shows delivered the order status
 * should update to completed... that way I can keep track of any
 * packages that are taking too long to deliver or get lost in transit."
 *
 * Same WP-Cron hourly-sweep shape as class-proof-reminder-scheduler.php
 * (see that file's own docblock for the full reasoning) rather than a
 * per-order scheduled event: simpler, self-healing, and the up-to-~1h
 * fuzziness against "is this package late" is irrelevant in practice.
 * A manual "Check tracking now" button (class-admin-dashboard-
 * controller.php's refresh_tracking()) exists alongside the sweep for
 * whenever staff don't want to wait for the next hourly run.
 *
 * A third path in besides the sweep and the manual button: Shippo's own
 * `track_updated` webhook (class-shippo-webhook-controller.php) calls
 * record_live_status() directly with data Shippo already pushed, no
 * poll needed — direct question: "Shippo support webhooks for tracking
 * updates... would that be better?" The sweep keeps running regardless,
 * as a reconciliation net for whenever a webhook call never arrives.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Order_Delivery_Status {

	private const HOOK = 'yeffoprint_delivery_tracking_sweep';

	/**
	 * Per-order meta: tracking_number => {status, description, checked_at}.
	 * Keyed by tracking number (not carrier) since that's what's unique
	 * per shipment on an order — a status here always reflects the most
	 * recent *successful* check for that shipment; a failed/skipped check
	 * (see check_order()) deliberately leaves whatever was last stored in
	 * place rather than overwriting known-good data with "unknown".
	 */
	private const TRACKING_STATUS_META = '_yp_tracking_status';

	public function __construct() {
		add_action( self::HOOK, [ $this, 'sweep' ] );

		// Same "cheap to check every request, wp_schedule_event() is a
		// no-op once scheduled" reasoning as YeffoPrint_Proof_Reminder_
		// Scheduler::ensure_scheduled() — see that method's own docblock.
		add_action( 'init', [ $this, 'ensure_scheduled' ] );
	}

	public function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::HOOK );
		}
	}

	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	/** @return array{status:string,description:string,checked_at:int}|null Last known-good status for one shipment, or null if it's never been successfully checked. */
	public static function get_status( \WC_Order $order, string $tracking_number ): ?array {
		$statuses = $order->get_meta( self::TRACKING_STATUS_META, true );
		return is_array( $statuses ) ? ( $statuses[ $tracking_number ] ?? null ) : null;
	}

	public function sweep(): void {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}

		$orders = wc_get_orders( [
			'status' => YeffoPrint_Order_Shipment_Status::STATUS,
			'limit'  => -1,
		] );

		foreach ( $orders as $order ) {
			$this->check_order( $order );
		}
	}

	private function check_order( \WC_Order $order ): void {
		$shipments = YeffoPrint_Order_Tracking::get_shipments( $order );
		if ( ! $shipments ) {
			return;
		}

		// Backfills the reverse tracking-number index for every order
		// already Shipped before webhook support existed (or that hasn't
		// re-saved since) — every order that ever reaches this sweep gets
		// indexed within the hour even without a fresh fingerprint change.
		// See YeffoPrint_Order_Tracking::index_shipments()'s own docblock.
		$changed  = YeffoPrint_Order_Tracking::index_shipments( $order );
		$registry = new YeffoPrint_Tracking_Provider_Registry();
		$statuses = $order->get_meta( self::TRACKING_STATUS_META, true );
		$statuses = is_array( $statuses ) ? $statuses : [];

		foreach ( $shipments as $shipment ) {
			$provider = $registry->get( $shipment['carrier_id'] );
			if ( ! $provider || ! $provider->is_configured() ) {
				continue; // Nothing new to report — last known status (if any) stands.
			}

			try {
				$events = $provider->get_events( $shipment['tracking_number'] );
			} catch ( YeffoPrint_Tracking_Exception $e ) {
				continue; // A transient lookup failure shouldn't clobber a known-good status with "unknown".
			}

			$statuses[ $shipment['tracking_number'] ] = self::status_entry( $events );
			$changed = true;
		}

		if ( $changed ) {
			$order->update_meta_data( self::TRACKING_STATUS_META, $statuses );
			$order->save();
		}

		$this->maybe_complete( $order, $shipments, $statuses );
	}

	/**
	 * The webhook path: Shippo already pushed this shipment's current
	 * events (class-shippo-webhook-controller.php parsed them out of the
	 * `track_updated` payload) — no provider lookup, no live API call,
	 * just record what was given and check for delivery-completion the
	 * same way the sweep does. A shipment the order doesn't currently
	 * recognize (a stale index entry from a since-voided/replaced label —
	 * see index_shipments()'s own note on never removing old entries) is
	 * a no-op rather than an error: nothing on this order actually
	 * changed, so there's nothing to save or re-check.
	 */
	public function record_live_status( \WC_Order $order, string $tracking_number, array $events ): void {
		$shipments = YeffoPrint_Order_Tracking::get_shipments( $order );
		$is_current = in_array( $tracking_number, array_column( $shipments, 'tracking_number' ), true );
		if ( ! $is_current ) {
			return;
		}

		$statuses = $order->get_meta( self::TRACKING_STATUS_META, true );
		$statuses = is_array( $statuses ) ? $statuses : [];

		$statuses[ $tracking_number ] = self::status_entry( $events );

		$order->update_meta_data( self::TRACKING_STATUS_META, $statuses );
		$order->save();

		$this->maybe_complete( $order, $shipments, $statuses );
	}

	/** @return array{status:string,description:string,checked_at:int} */
	private static function status_entry( array $events ): array {
		$latest = $events[0] ?? null;

		return [
			'status'      => $latest ? strtoupper( (string) $latest['status'] ) : 'UNKNOWN',
			'description' => $latest ? (string) $latest['description'] : '',
			'checked_at'  => time(),
		];
	}

	/**
	 * Only once *every* shipment on the order has a stored status of
	 * DELIVERED — an order with two packages where only one has arrived
	 * isn't done yet, and a shipment that's never been successfully
	 * checked (no entry in $statuses at all) correctly blocks completion
	 * too rather than being silently ignored.
	 */
	private function maybe_complete( \WC_Order $order, array $shipments, array $statuses ): void {
		foreach ( $shipments as $shipment ) {
			$status = $statuses[ $shipment['tracking_number'] ]['status'] ?? '';
			if ( 'DELIVERED' !== $status ) {
				return;
			}
		}

		$order->update_status(
			'completed',
			__( 'Every package on this order shows as delivered — automatically marked Completed.', 'yeffoprint-core' )
		);
	}
}
