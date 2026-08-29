<?php
/**
 * Thin wrapper over the Shippo REST API (https://goshippo.com/docs/reference)
 * — outbound calls only, same shape as class-telegram-client.php: one
 * private call() helper, public methods that return an array on success
 * or \WP_Error on failure, no local state beyond the API token.
 *
 * Two calls only, matching the drawer's actual flow (rate-shop, then
 * purchase): get_rates() creates a Shipment object (address_from +
 * address_to + one parcel) with `async: false` so Shippo returns live
 * rates synchronously in the same response — no polling needed.
 * purchase_label() creates a Transaction against a chosen rate's
 * object_id, also synchronous. Rate-shopping itself never charges
 * anything on a Shippo account; only a Transaction (an actual label
 * purchase) does.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Shippo_Client {

	private const API_BASE = 'https://api.goshippo.com';

	private string $token;

	public function __construct( string $token ) {
		$this->token = $token;
	}

	/**
	 * @param array $address_to {street1, street2, city, state, zip, country, name}
	 * @param array $parcel {weight_oz, length_in, width_in, height_in}
	 * @return array{rates:array,notes:string[]}|\WP_Error
	 */
	public function get_rates( array $address_to, array $parcel ) {
		$response = $this->call( 'POST', '/shipments/', [
			'address_from' => $this->format_address( YeffoPrint_Shippo_Settings::get_ship_from_address() ),
			'address_to'   => $this->format_address( $address_to ),
			'parcels'      => [ [
				'weight'        => (string) $parcel['weight_oz'],
				'mass_unit'     => 'oz',
				'length'        => (string) $parcel['length_in'],
				'width'         => (string) $parcel['width_in'],
				'height'        => (string) $parcel['height_in'],
				'distance_unit' => 'in',
			] ],
			'async'        => false,
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$notes = $this->relevant_messages( $response['messages'] ?? [] );

		if ( 'SUCCESS' !== ( $response['status'] ?? '' ) || empty( $response['rates'] ) ) {
			return new \WP_Error(
				'yeffoprint_shippo_no_rates',
				$notes ? implode( ' ', $notes ) : __( 'Shippo returned no rates for this address/package.', 'yeffoprint-core' )
			);
		}

		$rates = array_map( [ $this, 'normalize_rate' ], $response['rates'] );

		usort( $rates, static fn( $a, $b ) => $a['amount'] <=> $b['amount'] );

		// A partial response — some rates came back, but not every
		// enabled carrier account produced one — still carries a
		// `messages` entry per carrier that came up empty. Direct
		// question, after connecting a real UPS account in the Shippo
		// dashboard: "it only has a few USPS rates shown, how can I show
		// UPS rates as well?" Previously this was only ever read on the
		// all-fail path, so the exact reason a specific carrier didn't
		// return a rate (an incomplete carrier-account setup on Shippo's
		// side, an unsupported service for this route, …) was silently
		// dropped whenever at least one other carrier succeeded — surfaced
		// here so that question can answer itself in the panel instead of
		// needing a guess.
		return [ 'rates' => $rates, 'notes' => $notes ];
	}

	/**
	 * Every new Shippo account comes with a set of default sample carrier
	 * accounts (mostly international — Correos, DPD, Chronopost, Hermes
	 * UK, a Canada Post/DHL/UPS master account…) that reject a normal
	 * domestic US shipment with byte-identical wording on *every*
	 * request, one message per sample account — a dozen duplicate lines
	 * that used to bury the one or two genuine, distinct messages (an
	 * incomplete address, a real carrier account connected but not fully
	 * set up, …). Previously this filtered out any message containing
	 * that shared sample-account wording outright — but a real, connected
	 * account can return the exact same generic wording for its own
	 * reason (e.g. a service level like UPS 2nd Day Air that isn't
	 * enabled on that carrier account), and pattern-matching the text
	 * silently dropped that too. Deduping instead of pattern-matching
	 * collapses the dozen identical sample-account lines down to one
	 * without discarding a real carrier's message just because it shares
	 * the same wording.
	 *
	 * @return string[]
	 */
	private function relevant_messages( array $raw_messages ): array {
		$messages = array_map(
			static fn( $m ) => trim( (string) ( $m['text'] ?? '' ) ),
			$raw_messages
		);

		$messages = array_filter( $messages, static function ( string $text ): bool {
			return '' !== $text && false === strpos( $text, 'Too Many Requests' );
		} );

		return array_values( array_unique( $messages ) );
	}

	/** @return array{tracking_number:string,carrier_id:string,carrier_label:string,label_url:string}|\WP_Error */
	public function purchase_label( string $rate_id ) {
		$response = $this->call( 'POST', '/transactions/', [
			'rate'            => $rate_id,
			'label_file_type' => 'PDF',
			'async'           => false,
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 'SUCCESS' !== ( $response['status'] ?? '' ) ) {
			$messages = array_map(
				static fn( $m ) => (string) ( $m['text'] ?? '' ),
				$response['messages'] ?? []
			);
			return new \WP_Error(
				'yeffoprint_shippo_purchase_failed',
				$messages ? implode( ' ', array_filter( $messages ) ) : __( 'Shippo could not complete the label purchase.', 'yeffoprint-core' )
			);
		}

		$carrier_id = sanitize_key( str_replace( ' ', '_', strtolower( (string) ( $response['rate']['provider'] ?? '' ) ) ) );

		return [
			'tracking_number' => (string) ( $response['tracking_number'] ?? '' ),
			'carrier_id'      => $carrier_id,
			'carrier_label'   => (string) ( $response['rate']['provider'] ?? YeffoPrint_Order_Tracking::carrier_label( $carrier_id ) ),
			'label_url'       => (string) ( $response['label_url'] ?? '' ),
		];
	}

	private function normalize_rate( array $rate ): array {
		$carrier_id = sanitize_key( str_replace( ' ', '_', strtolower( (string) ( $rate['provider'] ?? '' ) ) ) );

		return [
			'id'            => (string) ( $rate['object_id'] ?? '' ),
			'carrier_id'    => $carrier_id,
			'carrier_label' => (string) ( $rate['provider'] ?? '' ),
			'service'       => (string) ( $rate['servicelevel']['name'] ?? '' ),
			'amount'        => (float) ( $rate['amount'] ?? 0 ),
			'currency'      => (string) ( $rate['currency'] ?? 'USD' ),
			'days'          => isset( $rate['estimated_days'] ) ? (int) $rate['estimated_days'] : null,
		];
	}

	private function format_address( array $address ): array {
		// email/phone are optional for address_to (the recipient) but
		// required by Shippo/USPS for address_from (the sender) — see
		// YeffoPrint_Shippo_Settings::get_ship_from_address()'s own
		// docblock. Only included when present so an address_to with
		// neither doesn't send empty strings Shippo could just as easily
		// flag as "must not be empty" the way street1/zip already were.
		return array_filter( [
			'name'    => $address['name'] ?? '',
			'street1' => $address['street1'] ?? '',
			'street2' => $address['street2'] ?? '',
			'city'    => $address['city'] ?? '',
			'state'   => $address['state'] ?? '',
			'zip'     => $address['zip'] ?? '',
			'country' => $address['country'] ?? '',
			'email'   => $address['email'] ?? '',
			'phone'   => $address['phone'] ?? '',
		], static fn( $value, $key ) => '' !== $value || ! in_array( $key, [ 'email', 'phone' ], true ), ARRAY_FILTER_USE_BOTH );
	}

	/** @return array|\WP_Error */
	private function call( string $method, string $path, array $body ) {
		if ( '' === $this->token ) {
			return new \WP_Error( 'yeffoprint_shippo_no_token', __( 'No Shippo API token is configured.', 'yeffoprint-core' ) );
		}

		$response = wp_remote_request( self::API_BASE . $path, [
			'method'  => $method,
			'timeout' => 20,
			'headers' => [
				'Authorization' => 'ShippoToken ' . $this->token,
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( $body ),
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'yeffoprint_shippo_bad_response', __( 'Unexpected response from Shippo.', 'yeffoprint-core' ) );
		}

		if ( $code >= 400 ) {
			$detail = (string) ( $data['detail'] ?? wp_json_encode( $data ) );
			return new \WP_Error( 'yeffoprint_shippo_api_error', sprintf(
				/* translators: %s: error detail from Shippo */
				__( 'Shippo API error: %s', 'yeffoprint-core' ),
				$detail
			) );
		}

		return $data;
	}
}
