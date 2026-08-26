<?php
/**
 * Guest-safe order lookup for the Telegram bot — same access rule as
 * WooCommerce's own native order-tracking shortcode: order number +
 * the exact billing email used at checkout. No order_key/nonce option
 * here (unlike class-order-tracking-controller.php's /track-order/
 * page) since a Telegram chat has neither — the email match is the
 * entire access control, so a wrong guess reveals nothing about
 * whether the order even exists.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Telegram_Order_Lookup {

	public static function find( string $order_ref, string $email ): ?\WC_Order {
		$id = self::normalize_order_ref( $order_ref );
		if ( ! $id ) {
			return null;
		}

		$order = wc_get_order( $id );
		if ( ! $order instanceof \WC_Order ) {
			return null;
		}

		$billing_email = strtolower( trim( $order->get_billing_email() ) );
		if ( '' === $billing_email || $billing_email !== strtolower( trim( $email ) ) ) {
			return null;
		}

		return $order;
	}

	/** Strips the "YP-" display prefix (class-order-number-format.php) — the underlying order id is what get/wc_get_order() actually need. */
	private static function normalize_order_ref( string $ref ): int {
		$ref = preg_replace( '/^YP-?/i', '', trim( $ref ) ) ?? '';
		return absint( $ref );
	}

	public static function format_status( \WC_Order $order ): string {
		$lines   = [];
		$lines[] = sprintf(
			/* translators: 1: order number, 2: order status label */
			__( 'Order %1$s — %2$s', 'yeffoprint-core' ),
			$order->get_order_number(),
			wc_get_order_status_name( $order->get_status() )
		);

		$date = $order->get_date_created();
		if ( $date ) {
			$lines[] = sprintf( /* translators: %s: date */ __( 'Placed: %s', 'yeffoprint-core' ), wp_date( get_option( 'date_format' ), $date->getTimestamp() ) );
		}

		$lines[] = '';
		$lines[] = __( 'Items:', 'yeffoprint-core' );
		foreach ( $order->get_items() as $item ) {
			$lines[] = sprintf( '• %1$s × %2$d', $item->get_name(), $item->get_quantity() );
		}

		$lines[] = '';
		$lines[] = sprintf( /* translators: %s: formatted order total */ __( 'Total: %s', 'yeffoprint-core' ), self::plain_total( $order ) );

		$shipments = YeffoPrint_Order_Tracking::get_shipments( $order );
		if ( $shipments ) {
			$lines[] = '';
			$lines[] = __( 'Tracking:', 'yeffoprint-core' );
			foreach ( $shipments as $shipment ) {
				$lines[] = sprintf( '%1$s %2$s', $shipment['carrier_label'], $shipment['tracking_number'] );
				if ( $shipment['carrier_url'] ) {
					$lines[] = $shipment['carrier_url'];
				}
			}
		}

		$custom_status = self::custom_order_status_label( $order->get_id() );
		if ( $custom_status ) {
			$lines[] = '';
			$lines[] = sprintf( /* translators: %s: custom order production status */ __( 'Custom design status: %s', 'yeffoprint-core' ), $custom_status );
		}

		return implode( "\n", $lines );
	}

	/**
	 * get_formatted_order_total() returns HTML meant for a browser to
	 * render — WooCommerce's own currency symbols are literal HTML
	 * entities internally (USD is the string "&#36;", not "$"), so a
	 * browser decodes it but plain text never does. wp_strip_all_tags()
	 * alone only removes the wrapping <span>/<bdi> markup and leaves
	 * the entity itself as literal text (found live: a Telegram reply
	 * showing "Total: &#36;74.60" instead of "Total: $74.60") —
	 * html_entity_decode() afterward is what actually turns it back
	 * into a real "$". Shared here since class-telegram-admin-alerts.php
	 * needs the exact same plain-text total.
	 */
	public static function plain_total( \WC_Order $order ): string {
		return html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ), ENT_QUOTES, 'UTF-8' );
	}

	/** The linked Custom Order (yp_custom_order) record's own production status, if this WC order came from that flow — separate pipeline from WooCommerce's order status, per PROJECT_SPEC §13. */
	private static function custom_order_status_label( int $wc_order_id ): ?string {
		$posts = get_posts( [
			'post_type'   => 'yp_custom_order',
			'post_status' => 'any',
			'numberposts' => 1,
			'meta_key'    => YeffoPrint_Custom_Order_Meta::WC_ORDER_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one order's own lookup, not a listing screen.
			'meta_value'  => $wc_order_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'fields'      => 'ids',
		] );

		if ( empty( $posts ) ) {
			return null;
		}

		$status = (string) get_post_meta( $posts[0], YeffoPrint_Custom_Order_Meta::STATUS, true );
		return '' !== $status ? YeffoPrint_Custom_Order_Meta::get_status_label( $status ) : null;
	}
}
