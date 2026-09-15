<?php
/**
 * Self-serve shipping-address edits via the bot — direct request:
 * "they should be able to edit their shipping address up until
 * shipping. Once a label is generated no changes to address." Explicitly
 * *not* a self-serve cancel (same request, ruled out) — this only ever
 * touches the four shipping fields below, never billing, never status.
 *
 * The gate is a single check, same "one function decides, everyone else
 * just asks it" shape as class-admin-menu.php's away_mode(): a label
 * counts as generated the moment YeffoPrint_Order_Tracking::get_shipments()
 * returns anything, since that's the exact same "does this order have a
 * real tracking number yet" signal every other consumer of that class
 * already trusts (the tracking button, the auto-advance-to-Shipped
 * sweep). A voided label drops back out of get_shipments() by that
 * class's own design — if staff voided one, a corrected label still
 * needs a human to review before it's re-purchased, so this deliberately
 * doesn't try to re-open the window automatically; the order's own edit
 * screen still lets staff fix the address either way.
 *
 * State (which order a reply is about) lives in a transient keyed by
 * chat_id, same "pending state short-circuits everything else" shape
 * and TTL as class-telegram-escalation.php's own pending-message flow —
 * consumed once, win or lose, same as that class's own pending-reject
 * handling, rather than kept alive for a retry on a parse failure (the
 * customer just runs /address again).
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Telegram_Address_Update {

	private const PENDING_TTL        = 10 * MINUTE_IN_SECONDS;
	private const PENDING_TRANSIENT  = 'yp_telegram_address_pending_';
	private const CANCEL_WORDS       = [ 'cancel', 'stop', 'nevermind', 'never mind' ];

	public static function has_pending( int $chat_id ): bool {
		return (bool) get_transient( self::PENDING_TRANSIENT . $chat_id );
	}

	public static function store_pending( int $chat_id, int $order_id ): void {
		set_transient( self::PENDING_TRANSIENT . $chat_id, $order_id, self::PENDING_TTL );
	}

	/** @return int 0 if nothing was pending. Consumes the pending state either way — see class docblock. */
	public static function consume_pending( int $chat_id ): int {
		$key      = self::PENDING_TRANSIENT . $chat_id;
		$order_id = (int) get_transient( $key );
		delete_transient( $key );

		return $order_id;
	}

	public static function is_cancel_word( string $text ): bool {
		return in_array( strtolower( trim( $text ) ), self::CANCEL_WORDS, true );
	}

	/** Null when the order is still editable; otherwise the reason to tell the customer. */
	public static function blocked_reason( \WC_Order $order ): ?string {
		if ( ! $order->needs_shipping_address() ) {
			return __( "This order doesn't have a shipping address to update.", 'yeffoprint-core' );
		}

		if ( $order->has_status( [ 'cancelled', 'refunded', 'failed', 'trash' ] ) ) {
			return __( "This order is no longer active, so its address can't be updated here — email us if you still need a change.", 'yeffoprint-core' );
		}

		if ( YeffoPrint_Order_Tracking::get_shipments( $order ) ) {
			return __( "A shipping label has already been generated for this order, so the address can't be changed here anymore. Email us right away if it's not too late to fix.", 'yeffoprint-core' );
		}

		return null;
	}

	public static function prompt_text(): string {
		return __(
			"Sure — reply with the updated shipping address, one line each:\nFull name\nStreet address\nCity, State ZIP\n\nExample:\nJane Doe\n123 Main St Apt 4\nAustin, TX 78701\n\n(Reply \"cancel\" to stop.)",
			'yeffoprint-core'
		);
	}

	/** Parses the multi-line reply and, if the order is still editable, applies it. */
	public static function apply( int $order_id, string $text ): string {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return __( "Address updates aren't available right now — please try again shortly.", 'yeffoprint-core' );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return __( "I couldn't find that order anymore — please start over with /address.", 'yeffoprint-core' );
		}

		// Re-check the gate here too — a label can be generated in the
		// minutes between the prompt above and this reply arriving.
		$blocked = self::blocked_reason( $order );
		if ( $blocked ) {
			return $blocked;
		}

		$parsed = self::parse( $text );
		if ( ! $parsed ) {
			return __(
				"I couldn't read that as an address. Please start over with /address and reply in this format:\nFull name\nStreet address\nCity, State ZIP",
				'yeffoprint-core'
			);
		}

		$order->set_shipping_first_name( $parsed['first_name'] );
		$order->set_shipping_last_name( $parsed['last_name'] );
		$order->set_shipping_address_1( $parsed['address_1'] );
		$order->set_shipping_address_2( $parsed['address_2'] );
		$order->set_shipping_city( $parsed['city'] );
		$order->set_shipping_state( $parsed['state'] );
		$order->set_shipping_postcode( $parsed['postcode'] );
		$order->save();

		$order->add_order_note( sprintf(
			/* translators: %s: the new shipping address, as a single formatted line */
			__( 'Shipping address updated by the customer via the bot: %s', 'yeffoprint-core' ),
			self::format_address( $parsed )
		) );

		return sprintf(
			/* translators: %s: the new shipping address, as a single formatted line */
			__( "Got it — your shipping address is now:\n%s\n\nThis only applies if your order hasn't shipped yet.", 'yeffoprint-core' ),
			self::format_address( $parsed )
		);
	}

	/**
	 * @return array{first_name:string,last_name:string,address_1:string,address_2:string,city:string,state:string,postcode:string}|null
	 */
	private static function parse( string $text ): ?array {
		$lines = array_values( array_filter( array_map( 'trim', explode( "\n", $text ) ), static function ( string $line ): bool {
			return '' !== $line;
		} ) );

		if ( count( $lines ) < 3 ) {
			return null;
		}

		$name = array_shift( $lines );
		// A trailing 4th line is the optional apt/suite line — the
		// city/state/zip line is always whichever one is last.
		$city_state_zip = array_pop( $lines );
		$address_1      = array_shift( $lines );
		$address_2      = $lines ? implode( ', ', $lines ) : '';

		if ( ! preg_match( '/^(.+?),\s*([A-Za-z]{2})\s+(\d{5})(?:-\d{4})?$/', trim( $city_state_zip ), $match ) ) {
			return null;
		}

		// $name and $address_1 are guaranteed non-empty here — every line in
		// $lines already passed the non-blank filter above.
		$name_parts = preg_split( '/\s+/', $name, 2 );

		return [
			'first_name' => $name_parts[0],
			'last_name'  => $name_parts[1] ?? '',
			'address_1'  => $address_1,
			'address_2'  => $address_2,
			'city'       => trim( $match[1] ),
			'state'      => strtoupper( $match[2] ),
			'postcode'   => $match[3],
		];
	}

	private static function format_address( array $parsed ): string {
		$lines = [
			trim( $parsed['first_name'] . ' ' . $parsed['last_name'] ),
			$parsed['address_1'],
		];
		if ( '' !== $parsed['address_2'] ) {
			$lines[] = $parsed['address_2'];
		}
		$lines[] = sprintf( '%1$s, %2$s %3$s', $parsed['city'], $parsed['state'], $parsed['postcode'] );

		return implode( "\n", $lines );
	}
}
