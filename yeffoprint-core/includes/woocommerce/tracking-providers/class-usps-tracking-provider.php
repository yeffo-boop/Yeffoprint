<?php
/**
 * USPS Tracking API client (developer.usps.com — the current OAuth2-
 * based "USPS APIs" platform, not the legacy XML Web Tools). Waiting on
 * real credentials (direct request: "I need to get these first") — the
 * OAuth token exchange/caching below is the generic, verifiable-without-
 * credentials part; the event field names in get_events() are this
 * plugin's best-effort read of USPS's current published schema and are
 * flagged for a real-request check once credentials exist, since a
 * wrong field name only means an empty timeline (caught, logged, falls
 * back to the direct usps.com tracking link — see interface-tracking-
 * provider.php), never a broken page.
 *
 * To activate: sign up at https://developer.usps.com/, create an app to
 * get a Consumer Key/Secret, and enter them on the YeffoPrint Settings
 * screen (Dashboard → YeffoPrint → Settings → Shipment Tracking).
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Usps_Tracking_Provider implements YeffoPrint_Tracking_Provider {

	private const TOKEN_URL    = 'https://apis.usps.com/oauth2/v3/token';
	private const TRACKING_URL = 'https://apis.usps.com/tracking/v3/tracking/';
	private const TOKEN_TRANSIENT = 'yeffoprint_usps_access_token';

	public function is_configured(): bool {
		return '' !== $this->consumer_key() && '' !== $this->consumer_secret();
	}

	private function consumer_key(): string {
		return (string) get_option( YeffoPrint_Admin_Menu::USPS_CONSUMER_KEY_OPTION );
	}

	private function consumer_secret(): string {
		return (string) get_option( YeffoPrint_Admin_Menu::USPS_CONSUMER_SECRET_OPTION );
	}

	public function get_events( string $tracking_number ): array {
		if ( ! $this->is_configured() ) {
			throw new YeffoPrint_Tracking_Exception( 'USPS tracking is not configured.' );
		}

		$token = $this->get_access_token();

		$response = wp_remote_get( self::TRACKING_URL . rawurlencode( $tracking_number ), [
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			],
			'timeout' => 10,
		] );

		if ( is_wp_error( $response ) ) {
			throw new YeffoPrint_Tracking_Exception( 'USPS request failed: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			throw new YeffoPrint_Tracking_Exception( 'USPS returned HTTP ' . $code . '.' );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		// Documented shape as of USPS's current Tracking API: a
		// `trackingEvents` array, newest first, each with `eventType`
		// (e.g. "Delivered", "Out for Delivery", "Arrived at Post
		// Office"), `eventCity`/`eventState`, and `eventTimestamp` (ISO
		// 8601). Verify field names against a real response once
		// credentials exist — see this file's own docblock.
		$raw_events = $body['trackingEvents'] ?? [];
		if ( ! is_array( $raw_events ) ) {
			throw new YeffoPrint_Tracking_Exception( 'Unexpected USPS response shape.' );
		}

		$events = [];
		foreach ( $raw_events as $raw ) {
			$events[] = [
				'status'      => (string) ( $raw['eventType'] ?? '' ),
				'description' => (string) ( $raw['eventType'] ?? '' ),
				'location'    => trim( ( $raw['eventCity'] ?? '' ) . ( ! empty( $raw['eventState'] ) ? ', ' . $raw['eventState'] : '' ) ),
				'timestamp'   => (string) ( $raw['eventTimestamp'] ?? '' ),
			];
		}

		return $events;
	}

	/** Client-credentials OAuth2 token, cached until just before it expires — this part needs no schema guessing, it's a standard OAuth2 flow. */
	private function get_access_token(): string {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( $cached ) {
			return (string) $cached;
		}

		$response = wp_remote_post( self::TOKEN_URL, [
			'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
			'body'    => [
				'grant_type'    => 'client_credentials',
				'client_id'     => $this->consumer_key(),
				'client_secret' => $this->consumer_secret(),
				'scope'         => 'tracking',
			],
			'timeout' => 10,
		] );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			throw new YeffoPrint_Tracking_Exception( 'Could not authenticate with USPS.' );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$token = (string) ( $body['access_token'] ?? '' );
		$ttl   = (int) ( $body['expires_in'] ?? 0 );

		if ( '' === $token ) {
			throw new YeffoPrint_Tracking_Exception( 'USPS did not return an access token.' );
		}

		// A minute of slack before the real expiry avoids a token that
		// expires mid-request.
		set_transient( self::TOKEN_TRANSIENT, $token, max( 60, $ttl - 60 ) );

		return $token;
	}
}
