<?php
/**
 * Reads shipment/tracking data off a WC_Order and turns it into a
 * public tracking page link — direct request: "when customers hit the
 * order number in their email, they're taken to a page on yeffoprint to
 * track their order."
 *
 * Deliberately reads the *existing* "WooCommerce Shipping" plugin's own
 * label data — the plugin the store already uses to buy USPS/UPS labels
 * and that already attaches the tracking number to the order — rather
 * than adding a second, competing place to enter a tracking number.
 * Staff keep doing exactly what they do today; this just reads what's
 * already there.
 *
 * The tracking page itself needs no new guest-access token, either —
 * WooCommerce already generates a per-order `order_key` for guest
 * checkout (the same one its own "View order"/"Pay for order" links use)
 * and stores it on every order regardless of gateway, so reusing it here
 * is one fewer secret to generate/store/rotate.
 *
 * Bug found live: the site's installed WooCommerce Shipping version has
 * migrated its label storage to a new meta key, `wcshipping_labels`
 * (see its own class-wc-connect-service-settings-store.php ::
 * get_label_order_meta_data()'s `$use_legacy_key = false` default) —
 * `wc_connect_labels` is now legacy-only, read only when that param is
 * explicitly true (old orders WC Shipping's own LegacyLabelMigrator
 * hasn't converted yet). This class originally only read the legacy key,
 * so it silently found nothing on every current-generation label — no
 * auto-advance to "Shipped", no "Track your order" button on any
 * customer email, and no shipping-details box on the new shipped-order
 * email, despite real labels existing. Both keys share the same entry
 * shape (`tracking`/`carrier_id`/`refund`/etc. — confirmed against that
 * same settings-store class's own read/write code), so this now checks
 * the current key first and falls back to the legacy one.
 *
 * A third source, `SHIPPO_LABELS_META`, holds labels purchased through
 * the newer, independent Shippo integration (class-shippo-client.php,
 * class-admin-shippo-controller.php) — direct request: "can we build
 * something with the shippo API to replace it? ... I'd like to run
 * alongside it a bit." Deliberately written in the exact same per-entry
 * shape (`tracking`/`carrier_id`/`refund`) as WC Shipping's own labels,
 * so it merges into the same loop below with no special-casing — every
 * downstream reader of get_shipments() (this class's own tracking
 * button, class-order-shipment-status.php's auto-advance, the
 * shipped-order email) picks up a Shippo-purchased label exactly like a
 * WC Shipping one, with zero changes to any of them.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Order_Tracking {

	private const LABELS_META        = 'wcshipping_labels';
	private const LEGACY_LABELS_META = 'wc_connect_labels';
	private const SHIPPO_LABELS_META = 'yeffoprint_shippo_labels';

	/**
	 * Shared by every live-tracking consumer (the /track-order/ page,
	 * the Telegram bot's order-status reply — direct request: "reply
	 * with the current status from the carrier") so a lookup one of them
	 * already made is reused by the other within this window instead of
	 * hitting the carrier's API twice for the same shipment.
	 */
	private const LIVE_EVENTS_CACHE_TTL = 30 * MINUTE_IN_SECONDS;

	/**
	 * Reverse index: tracking_number -> order id, one meta row per
	 * shipment (add_meta_data(..., unique=false) below), needed because
	 * a Shippo `track_updated` webhook only ever names a carrier +
	 * tracking number, never an order — see find_order_by_tracking_
	 * number(). Kept as its own small index rather than a full-table
	 * scan on every webhook call.
	 */
	private const TRACKING_INDEX_META = '_yp_tracking_number_index';

	/**
	 * Known "WooCommerce Shipping" carrier ids, lowercased. Only USPS and
	 * UPS matter here (the two carriers this store actually ships with —
	 * direct request), but FedEx/DHL are included too since WC Shipping
	 * can return them and an unrecognized id should still degrade
	 * gracefully (see carrier_label()) rather than mis-labeling a
	 * shipment that *did* go out.
	 */
	private const CARRIER_LABELS = [
		'usps'        => 'USPS',
		'ups'         => 'UPS',
		'fedex'       => 'FedEx',
		'dhl_express' => 'DHL Express',
	];

	public function __construct() {
		add_action( 'woocommerce_email_after_order_table', [ $this, 'render_tracking_button' ], 10, 4 );
	}

	/**
	 * A "Track your order" button under the order table — every
	 * customer-facing order email gets this for free (Order Completed,
	 * Processing, On-Hold, Invoice, …) the moment a label exists, with no
	 * per-email-template edit needed. Skipped for the admin's own copy
	 * (nothing to track from their side) and for the plain-text part
	 * (a bare URL below reads better than a styled "button" there).
	 */
	public function render_tracking_button( \WC_Order $order, bool $sent_to_admin, bool $plain_text, \WC_Email $email ): void {
		if ( $sent_to_admin || ! self::get_shipments( $order ) ) {
			return;
		}

		$url = self::tracking_url( $order );
		if ( ! $url ) {
			return;
		}

		if ( $plain_text ) {
			echo esc_html__( 'Track your order:', 'yeffoprint-core' ) . ' ' . esc_url( $url ) . "\n\n";
			return;
		}

		printf(
			'<p style="text-align:center;margin:26px 0 6px;"><a href="%s" class="yp-email-button">%s</a></p>',
			esc_url( $url ),
			esc_html__( 'Track your order', 'yeffoprint-core' )
		);
	}

	/**
	 * @return array{carrier_id:string,carrier_label:string,tracking_number:string,carrier_url:string}[]
	 *   Every label on the order that actually has a tracking number yet
	 *   — a label can be purchased before the carrier scans the package,
	 *   so an entry with no tracking number isn't something to show a
	 *   customer as "trackable" at all.
	 */
	public static function get_shipments( \WC_Order $order ): array {
		$labels = $order->get_meta( self::LABELS_META, true );
		if ( ! is_array( $labels ) || ! $labels ) {
			$labels = $order->get_meta( self::LEGACY_LABELS_META, true );
		}
		if ( ! is_array( $labels ) ) {
			$labels = [];
		}

		$shippo_labels = $order->get_meta( self::SHIPPO_LABELS_META, true );
		if ( is_array( $shippo_labels ) ) {
			$labels = array_merge( $labels, $shippo_labels );
		}

		$shipments = [];

		foreach ( $labels as $label ) {
			$tracking_number = trim( (string) ( $label['tracking'] ?? '' ) );
			if ( '' === $tracking_number ) {
				continue;
			}

			// A refunded/voided label is no longer a real shipment — WC
			// Shipping marks these `refund['status']` on the label entry
			// once a void request is confirmed by the carrier.
			if ( ! empty( $label['refund']['status'] ) && 'refunded' === $label['refund']['status'] ) {
				continue;
			}

			$carrier_id = sanitize_key( (string) ( $label['carrier_id'] ?? '' ) );

			$shipments[] = [
				'carrier_id'      => $carrier_id,
				'carrier_label'   => self::carrier_label( $carrier_id ),
				'tracking_number' => $tracking_number,
				'carrier_url'     => self::carrier_direct_url( $carrier_id, $tracking_number ),
			];
		}

		return $shipments;
	}

	public static function carrier_label( string $carrier_id ): string {
		return self::CARRIER_LABELS[ $carrier_id ] ?? strtoupper( $carrier_id );
	}

	/**
	 * Writes this order's current shipments into TRACKING_INDEX_META,
	 * skipping any tracking number already indexed (checked against the
	 * existing rows, not overwritten) so calling this repeatedly — every
	 * hourly sweep, plus the moment a shipment fingerprint changes
	 * (class-order-shipment-status.php) — never piles up duplicate rows.
	 * Does not save() the order; callers already have their own save()
	 * for the same request (or, like the sweep, only save when something
	 * actually changed) — the returned bool tells them whether this
	 * added anything, so it can join that same decision.
	 *
	 * Never removes an old row — if a label is later voided/refunded
	 * (get_shipments() already excludes those going forward), its
	 * tracking number stays indexed but harmlessly so: a stray webhook
	 * for it still resolves to the right order, and
	 * YeffoPrint_Order_Delivery_Status::record_live_status() ignores any
	 * tracking number that's no longer in get_shipments()'s current list.
	 *
	 * @return bool Whether any new tracking number was indexed.
	 */
	public static function index_shipments( \WC_Order $order ): bool {
		$existing = array_map(
			static fn( $meta ) => $meta->value,
			$order->get_meta( self::TRACKING_INDEX_META, false )
		);

		$added = false;
		foreach ( self::get_shipments( $order ) as $shipment ) {
			if ( ! in_array( $shipment['tracking_number'], $existing, true ) ) {
				$order->add_meta_data( self::TRACKING_INDEX_META, $shipment['tracking_number'], false );
				$added = true;
			}
		}

		return $added;
	}

	/**
	 * The other side of index_shipments() — direct request behind Shippo
	 * webhook support: a `track_updated` payload only ever names a
	 * carrier + tracking number, so this is how class-shippo-webhook-
	 * controller.php resolves it back to a real order. Returns null for
	 * a tracking number this store never indexed (not yet swept/saved
	 * since this feature shipped, or genuinely not one of ours) — the
	 * caller treats that as "nothing to update," never an error.
	 */
	public static function find_order_by_tracking_number( string $tracking_number ): ?\WC_Order {
		if ( ! function_exists( 'wc_get_orders' ) || '' === $tracking_number ) {
			return null;
		}

		$order_ids = wc_get_orders( [
			'meta_key'   => self::TRACKING_INDEX_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a small, purpose-built index, not an ad hoc query.
			'meta_value' => $tracking_number, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'limit'      => 1,
			'return'     => 'ids',
		] );

		$order = $order_ids ? wc_get_order( $order_ids[0] ) : false;

		return $order instanceof \WC_Order ? $order : null;
	}

	/**
	 * Appends a Shippo-purchased label to the order — the write side of
	 * SHIPPO_LABELS_META above. Does not save() the order; callers (e.g.
	 * class-admin-shippo-controller.php) do that themselves once, so a
	 * single save() also carries the order-status auto-advance and any
	 * other meta changes made in the same request.
	 */
	public static function record_shippo_label( \WC_Order $order, string $tracking_number, string $carrier_id, string $label_url ): void {
		$labels   = $order->get_meta( self::SHIPPO_LABELS_META, true );
		$labels   = is_array( $labels ) ? $labels : [];
		$labels[] = [
			'tracking'   => $tracking_number,
			'carrier_id' => $carrier_id,
			'label_url'  => $label_url,
			'refund'     => [],
		];

		$order->update_meta_data( self::SHIPPO_LABELS_META, $labels );
	}

	/**
	 * A direct link to the carrier's own tracking page — needs no API
	 * credentials at all, so this works today, before UPS/USPS API keys
	 * exist, and stays as the fallback afterward if a live lookup ever
	 * fails.
	 */
	public static function carrier_direct_url( string $carrier_id, string $tracking_number ): string {
		switch ( $carrier_id ) {
			case 'usps':
				return add_query_arg( 'tLabels', rawurlencode( $tracking_number ), 'https://tools.usps.com/go/TrackConfirmAction' );
			case 'ups':
				return add_query_arg( 'tracknum', rawurlencode( $tracking_number ), 'https://www.ups.com/track' );
			case 'fedex':
				return add_query_arg( 'trknbr', rawurlencode( $tracking_number ), 'https://www.fedex.com/fedextrack/' );
			case 'dhl_express':
				return add_query_arg( 'tracking-id', rawurlencode( $tracking_number ), 'https://www.dhl.com/us-en/home/tracking.html' );
			default:
				return '';
		}
	}

	/**
	 * Live carrier events for one shipment (class-tracking-provider-
	 * registry.php — Shippo-backed when configured, falling back to a
	 * carrier-native USPS/UPS provider otherwise), cached for
	 * LIVE_EVENTS_CACHE_TTL. Shared by every consumer that wants "what's
	 * actually happening with this package right now" rather than just
	 * a bare tracking number: the /track-order/ page and, direct
	 * request, the Telegram bot's order-status reply — a lookup either
	 * one already made for a given shipment is reused by the other
	 * within that window instead of hitting the carrier twice.
	 *
	 * @return array{status:string,description:string,location:string,timestamp:string}[]
	 *   Newest first, empty when no provider is configured for this
	 *   carrier or the lookup failed — never an error, since every
	 *   caller already has carrier_direct_url() as a complete fallback.
	 */
	public static function live_events( array $shipment ): array {
		$registry = new YeffoPrint_Tracking_Provider_Registry();
		$provider = $registry->get( $shipment['carrier_id'] );

		if ( ! $provider || ! $provider->is_configured() ) {
			return [];
		}

		$cache_key = 'yeffoprint_tracking_' . md5( $shipment['carrier_id'] . '_' . $shipment['tracking_number'] );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		try {
			$events = $provider->get_events( $shipment['tracking_number'] );
		} catch ( YeffoPrint_Tracking_Exception $e ) {
			$events = [];
		}

		set_transient( $cache_key, $events, self::LIVE_EVENTS_CACHE_TTL );

		return $events;
	}

	/** The exact, ready-to-click link an order's own emails/pages use — empty if there's nothing to track yet. */
	public static function tracking_url( \WC_Order $order ): string {
		if ( ! self::get_shipments( $order ) ) {
			return '';
		}

		return add_query_arg(
			[
				'order' => $order->get_id(),
				'key'   => $order->get_order_key(),
			],
			home_url( '/track-order/' )
		);
	}
}
