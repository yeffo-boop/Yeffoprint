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
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Order_Tracking {

	private const LABELS_META        = 'wcshipping_labels';
	private const LEGACY_LABELS_META = 'wc_connect_labels';

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
			return [];
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
