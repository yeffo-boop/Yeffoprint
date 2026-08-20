<?php
/**
 * UPS Tracking API client (UPS Developer Kit — developer.ups.com).
 * Same "waiting on credentials, generic OAuth part is real, event field
 * names are a best-effort read of UPS's current schema to verify once
 * credentials exist" situation as class-usps-tracking-provider.php —
 * see that file's docblock, same reasoning applies here.
 *
 * To activate: register an app at https://developer.ups.com/ to get a
 * Client ID/Secret, and enter them on the YeffoPrint Settings screen
 * (Dashboard → YeffoPrint → Settings → Shipment Tracking).
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Ups_Tracking_Provider implements YeffoPrint_Tracking_Provider {

	private const TOKEN_URL       = 'https://onlinetools.ups.com/security/v1/oauth/token';
	private const TRACKING_URL    = 'https://onlinetools.ups.com/api/track/v1/details/';
	private const TOKEN_TRANSIENT = 'yeffoprint_ups_access_token';

	public function is_configured(): bool {
		return '' !== $this->client_id() && '' !== $this->client_secret();
	}

	private function client_id(): string {
		return (string) get_option( YeffoPrint_Admin_Menu::UPS_CLIENT_ID_OPTION );
	}

	private function client_secret(): string {
		return (string) get_option( YeffoPrint_Admin_Menu::UPS_CLIENT_SECRET_OPTION );
	}

	public function get_events( string $tracking_number ): array {
		if ( ! $this->is_configured() ) {
			throw new YeffoPrint_Tracking_Exception( 'UPS tracking is not configured.' );
		}

		$token = $this->get_access_token();

		$response = wp_remote_get( self::TRACKING_URL . rawurlencode( $tracking_number ), [
			'headers' => [
				'Authorization'    => 'Bearer ' . $token,
				'Accept'           => 'application/json',
				// UPS requires these on every tracking call — a fixed
				// per-request id and a short label for where the call
				// originates from, per their API reference.
				'transId'          => substr( md5( $tracking_number . microtime() ), 0, 32 ),
				'transactionSrc'   => 'yeffoprint',
			],
			'timeout' => 10,
		] );

		if ( is_wp_error( $response ) ) {
			throw new YeffoPrint_Tracking_Exception( 'UPS request failed: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			throw new YeffoPrint_Tracking_Exception( 'UPS returned HTTP ' . $code . '.' );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		// Documented shape as of UPS's current Tracking API:
		// trackResponse.shipment[0].package[0].activity[], each with
		// status.description, location.address.city/stateProvince, and
		// date ("YYYYMMDD") + time ("HHMMSS") as separate fields. Verify
		// against a real response once credentials exist.
		$activity = $body['trackResponse']['shipment'][0]['package'][0]['activity'] ?? [];
		if ( ! is_array( $activity ) ) {
			throw new YeffoPrint_Tracking_Exception( 'Unexpected UPS response shape.' );
		}

		$events = [];
		foreach ( $activity as $raw ) {
			$address  = $raw['location']['address'] ?? [];
			$city     = (string) ( $address['city'] ?? '' );
			$state    = (string) ( $address['stateProvince'] ?? '' );
			$date     = (string) ( $raw['date'] ?? '' ); // YYYYMMDD
			$time     = (string) ( $raw['time'] ?? '' ); // HHMMSS
			$timestamp = ( 8 === strlen( $date ) && 6 === strlen( $time ) )
				? sprintf( '%s-%s-%sT%s:%s:%s', substr( $date, 0, 4 ), substr( $date, 4, 2 ), substr( $date, 6, 2 ), substr( $time, 0, 2 ), substr( $time, 2, 2 ), substr( $time, 4, 2 ) )
				: '';

			$events[] = [
				'status'      => (string) ( $raw['status']['type'] ?? '' ),
				'description' => (string) ( $raw['status']['description'] ?? '' ),
				'location'    => trim( $city . ( $state ? ', ' . $state : '' ) ),
				'timestamp'   => $timestamp,
			];
		}

		return $events;
	}

	/** Client-credentials OAuth2 token (Basic-auth client id/secret), cached until just before it expires. */
	private function get_access_token(): string {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( $cached ) {
			return (string) $cached;
		}

		$response = wp_remote_post( self::TOKEN_URL, [
			'headers' => [
				'Content-Type'  => 'application/x-www-form-urlencoded',
				'Authorization' => 'Basic ' . base64_encode( $this->client_id() . ':' . $this->client_secret() ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			],
			'body'    => [ 'grant_type' => 'client_credentials' ],
			'timeout' => 10,
		] );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			throw new YeffoPrint_Tracking_Exception( 'Could not authenticate with UPS.' );
		}

		$body  = json_decode( wp_remote_retrieve_body( $response ), true );
		$token = (string) ( $body['access_token'] ?? '' );
		$ttl   = (int) ( $body['expires_in'] ?? 0 );

		if ( '' === $token ) {
			throw new YeffoPrint_Tracking_Exception( 'UPS did not return an access token.' );
		}

		set_transient( self::TOKEN_TRANSIENT, $token, max( 60, $ttl - 60 ) );

		return $token;
	}
}
