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
	 * @return array{rates:array}|\WP_Error
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

		if ( 'SUCCESS' !== ( $response['status'] ?? '' ) ) {
			return new \WP_Error( 'yeffoprint_shippo_no_rates', __( 'Shippo returned no rates for this address/package.', 'yeffoprint-core' ) );
		}

		$raw_rates   = $response['rates'] ?? [];
		$shipment_id = (string) ( $response['object_id'] ?? '' );

		// `async: false` above asks Shippo to answer synchronously, but
		// Shippo's own docs describe that synchronous response as a
		// snapshot: a carrier that's slow to respond can still be missing
		// from it even though the shipment itself was created successfully.
		// A follow-up call to the shipment's own rates endpoint picks up
		// whatever's landed since, unioned with the original snapshot by
		// rate id so a rate present in one response but not the other
		// (either order) is never dropped — a slow/failed follow-up call
		// degrades back to the original snapshot instead of losing rates
		// that were already there.
		if ( '' !== $shipment_id ) {
			$follow_up = $this->call( 'GET', '/shipments/' . rawurlencode( $shipment_id ) . '/rates' );
			if ( ! is_wp_error( $follow_up ) && ! empty( $follow_up['results'] ) ) {
				$raw_rates = $this->merge_rates( $raw_rates, $follow_up['results'] );
			}
		}

		if ( empty( $raw_rates ) ) {
			return new \WP_Error( 'yeffoprint_shippo_no_rates', __( 'Shippo returned no rates for this address/package.', 'yeffoprint-core' ) );
		}

		$rates = array_map( [ $this, 'normalize_rate' ], $raw_rates );

		usort( $rates, static fn( $a, $b ) => $a['amount'] <=> $b['amount'] );

		return [ 'rates' => $rates ];
	}

	/**
	 * Live tracking via Shippo's own /tracks/ endpoint — direct request:
	 * "I want live tracking to show for any orders that haven't been
	 * delivered." This works for a shipment regardless of whether its
	 * label was purchased through Shippo or WooCommerce Shipping (Shippo
	 * can look up any tracking number for a carrier it knows), and unlike
	 * the carrier-native providers in includes/woocommerce/tracking-
	 * providers/, it needs no separate developer.usps.com/developer.ups.com
	 * credentials — just the Shippo API key this store already has
	 * configured. See class-shippo-tracking-provider.php, the adapter that
	 * plugs this into that same tracking-provider interface.
	 *
	 * @return array{status:string,events:array{status:string,description:string,location:string,timestamp:string}[]}|\WP_Error
	 */
	public function track( string $carrier_id, string $tracking_number ) {
		$response = $this->call( 'GET', '/tracks/' . rawurlencode( $carrier_id ) . '/' . rawurlencode( $tracking_number ) . '/' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return self::parse_tracking_payload( $response );
	}

	/**
	 * The parsing half of track() above, split out so it can also read a
	 * `track_updated` webhook's `data` field directly (class-shippo-
	 * webhook-controller.php) — Shippo's docs describe that payload as
	 * the identical `tracking_status`/`tracking_history` shape a live GET
	 * to /tracks/ already returns, just pushed instead of polled. One
	 * parser, two ways of getting the raw data to it.
	 *
	 * @return array{status:string,events:array{status:string,description:string,location:string,timestamp:string}[]}
	 */
	public static function parse_tracking_payload( array $payload ): array {
		// `tracking_status` is the current/latest state, `tracking_history`
		// the full timeline — `tracking_status` is normally already
		// `tracking_history`'s own newest entry, but it's folded in here too
		// as a safety net (deduped below on status+timestamp) in case some
		// carrier/account combination ever omits it from the history array,
		// so a shipment with a real current status never comes back
		// reporting zero events.
		$history = $payload['tracking_history'] ?? [];
		if ( ! is_array( $history ) ) {
			$history = [];
		}
		if ( is_array( $payload['tracking_status'] ?? null ) ) {
			$history[] = $payload['tracking_status'];
		}

		if ( ! $history ) {
			return [ 'status' => 'UNKNOWN', 'events' => [] ];
		}

		usort( $history, static function ( $a, $b ) {
			return strtotime( (string) ( $b['status_date'] ?? '' ) ) <=> strtotime( (string) ( $a['status_date'] ?? '' ) );
		} );

		$events = [];
		$seen   = [];
		foreach ( $history as $raw ) {
			$status    = strtoupper( trim( (string) ( $raw['status'] ?? '' ) ) );
			$timestamp = (string) ( $raw['status_date'] ?? '' );
			$dedupe_key = $status . '|' . $timestamp;

			if ( '' === $status || isset( $seen[ $dedupe_key ] ) ) {
				continue;
			}
			$seen[ $dedupe_key ] = true;

			$events[] = [
				'status'      => $status,
				'description' => (string) ( $raw['status_details'] ?? '' ),
				'location'    => self::format_tracking_location( is_array( $raw['location'] ?? null ) ? $raw['location'] : [] ),
				'timestamp'   => $timestamp,
			];
		}

		return [
			'status' => $events[0]['status'] ?? 'UNKNOWN',
			'events' => $events,
		];
	}

	private static function format_tracking_location( array $location ): string {
		$city  = trim( (string) ( $location['city'] ?? '' ) );
		$state = trim( (string) ( $location['state'] ?? '' ) );

		return $city . ( '' !== $city && '' !== $state ? ', ' : '' ) . $state;
	}

	/**
	 * Registers this store's webhook URL with Shippo for the
	 * `track_updated` event — direct question: "Shippo support webhooks
	 * for tracking updates whenever a package status changes. Would that
	 * be better?" Yes for freshness and API-call volume versus the
	 * existing hourly poll (class-order-delivery-status.php), which
	 * stays in place regardless as a reconciliation net for a webhook
	 * call that never arrives.
	 *
	 * Best-effort: unlike /shipments/, /tracks/, and /transactions/ (all
	 * exercised against a real account this session), this endpoint's
	 * exact request/response shape is a documented-but-unverified read of
	 * Shippo's webhook-management API. class-shippo-webhook-sync.php
	 * treats a WP_Error here as informational, not fatal — the webhook
	 * URL is always shown in Settings too, so a wrong guess here just
	 * means the admin adds it by hand in the Shippo dashboard instead of
	 * it happening automatically.
	 *
	 * @return array|\WP_Error
	 */
	public function register_webhook( string $url ) {
		return $this->call( 'POST', '/webhooks/', [
			'event'   => 'track_updated',
			'url'     => $url,
			'is_test' => false,
		] );
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

	/** Union of two raw-rate-object lists by `object_id`, later list's entry winning on a collision — see get_rates()'s own docblock note above. */
	private function merge_rates( array $initial, array $supplemental ): array {
		$by_id = [];

		foreach ( array_merge( $initial, $supplemental ) as $rate ) {
			$id = (string) ( $rate['object_id'] ?? '' );
			if ( '' !== $id ) {
				$by_id[ $id ] = $rate;
			}
		}

		return array_values( $by_id );
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
	private function call( string $method, string $path, array $body = [] ) {
		if ( '' === $this->token ) {
			return new \WP_Error( 'yeffoprint_shippo_no_token', __( 'No Shippo API token is configured.', 'yeffoprint-core' ) );
		}

		$args = [
			'method'  => $method,
			'timeout' => 20,
			'headers' => [
				'Authorization' => 'ShippoToken ' . $this->token,
				'Content-Type'  => 'application/json',
			],
		];

		// Only the two POST calls (create shipment, purchase transaction)
		// carry a body — the GET rates follow-up in get_rates() has nothing
		// to send, and an empty JSON body on a GET is needless noise.
		if ( ! empty( $body ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::API_BASE . $path, $args );

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
