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

				$status_line = self::live_status_line( $shipment );
				if ( $status_line ) {
					$lines[] = $status_line;
				}

				if ( $shipment['carrier_url'] ) {
					$lines[] = $shipment['carrier_url'];
				}
			}
		}

		$custom_statuses = self::custom_order_status_labels( $order->get_id() );
		if ( $custom_statuses ) {
			$lines[] = '';
			// A manually-created order can now carry more than one linked
			// yp_custom_order — direct request behind that: "customers
			// order custom design items mixed with template items... at
			// the same time," each kind getting its own proof — so this
			// lists every one found rather than assuming exactly one.
			if ( count( $custom_statuses ) > 1 ) {
				$lines[] = __( 'Custom design status:', 'yeffoprint-core' );
				foreach ( $custom_statuses as $status ) {
					$lines[] = '• ' . $status;
				}
			} else {
				$lines[] = sprintf( /* translators: %s: custom order production status */ __( 'Custom design status: %s', 'yeffoprint-core' ), $custom_statuses[0] );
			}
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

	/**
	 * Live "Status: ..." line for one shipment, straight from the
	 * carrier — direct request: "if someone wants to check the status of
	 * an order that's shipped it will reply with the current status from
	 * the carrier." Reuses YeffoPrint_Order_Tracking::live_events(), the
	 * exact same lookup (and its 30-minute cache) the customer-facing
	 * /track-order/ page already uses, so a Telegram reply and a page
	 * load asking about the same shipment within that window share one
	 * carrier API call instead of making two. Returns '' rather than an
	 * error line when no provider is configured or the lookup fails —
	 * the bare tracking number + carrier link already printed above it
	 * is still a complete, useful answer on its own.
	 */
	private static function live_status_line( array $shipment ): string {
		$events = YeffoPrint_Order_Tracking::live_events( $shipment );
		if ( ! $events ) {
			return '';
		}

		$latest = $events[0];
		$text   = '' !== $latest['description']
			? $latest['description']
			: ucwords( strtolower( str_replace( '_', ' ', $latest['status'] ) ) );

		if ( '' !== $latest['location'] ) {
			$text .= ' — ' . $latest['location'];
		}

		return sprintf( /* translators: %s: live status text from the carrier, e.g. "Delivered — Spokane, WA" */ __( 'Status: %s', 'yeffoprint-core' ), $text );
	}

	/**
	 * Every linked Custom Order (yp_custom_order) record's own
	 * production status, if this WC order came from that flow —
	 * separate pipeline from WooCommerce's order status, per
	 * PROJECT_SPEC §13. A manually-created order can now carry more
	 * than one (direct request: "customers order custom design items
	 * mixed with template items... at the same time" — each kind gets
	 * its own proof, class-manual-order-creator.php), so this returns
	 * every one found rather than assuming exactly one the way this
	 * used to (back when an order could only ever have one).
	 *
	 * @return string[]
	 */
	private static function custom_order_status_labels( int $wc_order_id ): array {
		$posts = get_posts( [
			'post_type'   => 'yp_custom_order',
			'post_status' => 'any',
			'numberposts' => -1,
			'meta_key'    => YeffoPrint_Custom_Order_Meta::WC_ORDER_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one order's own lookup, not a listing screen.
			'meta_value'  => $wc_order_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'fields'      => 'ids',
		] );

		$labels = [];
		foreach ( $posts as $post_id ) {
			$status = (string) get_post_meta( $post_id, YeffoPrint_Custom_Order_Meta::STATUS, true );
			if ( '' !== $status ) {
				$labels[] = YeffoPrint_Custom_Order_Meta::get_status_label( $status );
			}
		}

		return $labels;
	}
}
