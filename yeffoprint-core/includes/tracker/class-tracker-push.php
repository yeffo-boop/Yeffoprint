<?php
/**
 * Web Push for Dose Tracker reminders, with no Composer dependency —
 * the standard protocol built on PHP's own OpenSSL:
 *
 *   - VAPID (RFC 8292): an ES256 JWT signed with this site's P-256 key,
 *     so push services know the reminders really come from this site.
 *     The key pair is made once and kept in an option, the private half
 *     sealed with the tracker master key (class-tracker-crypto.php).
 *   - Payload encryption (RFC 8291, "aes128gcm"): every message is
 *     encrypted to the customer's own browser key, so Apple/Google's push
 *     servers relay it without being able to read which peptide is due.
 *
 * iPhone supports this once the tracker is added to the Home Screen
 * (iOS 16.4+); Android/desktop Chrome, Edge and Firefox support it in
 * the browser.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Tracker_Push {

	private const VAPID_OPTION  = 'yeffoprint_tracker_vapid';
	private const VAPID_CONTEXT = 'yp-tracker-vapid';

	/** SubjectPublicKeyInfo DER prefix for an uncompressed P-256 point. */
	private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

	/** The public key browsers subscribe with (base64url, 65-byte uncompressed point), or '' if keys can't be made. */
	public static function public_key(): string {
		$keys = self::vapid_keys();
		return $keys ? self::b64url( $keys['public'] ) : '';
	}

	/**
	 * @param array{endpoint:string,keys:array{p256dh:string,auth:string}} $subscription
	 * @return int HTTP status from the push service (201 = delivered; 404/410 = subscription gone), 0 on local failure.
	 */
	public static function send( array $subscription, array $message, int $ttl = 3600 ): int {
		$endpoint = (string) ( $subscription['endpoint'] ?? '' );
		$p256dh   = self::b64url_decode( (string) ( $subscription['keys']['p256dh'] ?? '' ) );
		$auth     = self::b64url_decode( (string) ( $subscription['keys']['auth'] ?? '' ) );
		$keys     = self::vapid_keys();

		if ( ! self::valid_endpoint( $endpoint ) || 65 !== strlen( $p256dh ) || 16 !== strlen( $auth ) || ! $keys ) {
			return 0;
		}

		$body = self::encrypt( wp_json_encode( $message ), $p256dh, $auth );
		$jwt  = self::vapid_jwt( $endpoint, $keys['pem'] );
		if ( '' === $body || '' === $jwt ) {
			return 0;
		}

		$response = wp_remote_post( $endpoint, [
			'timeout'     => 10,
			// The endpoint was checked against real push services above; a
			// redirect would send the request somewhere that wasn't.
			'redirection' => 0,
			'headers'     => [
				'Content-Type'     => 'application/octet-stream',
				'Content-Encoding' => 'aes128gcm',
				'TTL'              => (string) $ttl,
				'Urgency'          => 'high',
				'Authorization'    => 'vapid t=' . $jwt . ', k=' . self::b64url( $keys['public'] ),
			],
			'body'        => $body,
		] );

		return is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
	}

	/** Only real push services over HTTPS — a subscription is customer-supplied, so this is what keeps the sweep from POSTing anywhere else. */
	public static function valid_endpoint( string $endpoint ): bool {
		$parts = wp_parse_url( $endpoint );
		if ( ! $parts || 'https' !== ( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) || strlen( $endpoint ) > 1024 ) {
			return false;
		}
		$host = strtolower( $parts['host'] );
		foreach ( [ 'push.apple.com', 'fcm.googleapis.com', 'push.services.mozilla.com', 'notify.windows.com', 'push.microsoft.com' ] as $allowed ) {
			if ( $host === $allowed || str_ends_with( $host, '.' . $allowed ) ) {
				return true;
			}
		}
		return false;
	}

	/** RFC 8291 aes128gcm: salt(16) | rs(4) | idlen(1) | as_public(65) | ciphertext+tag. Public for the round-trip test in tools/. */
	public static function encrypt( string $plaintext, string $ua_public, string $auth_secret ): string {
		$local = openssl_pkey_new( [ 'curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC ] );
		if ( ! $local ) {
			return '';
		}
		$as_public = self::raw_public( $local );
		$peer      = openssl_pkey_get_public( self::spki_pem( $ua_public ) );
		if ( ! $peer || '' === $as_public ) {
			return '';
		}

		$shared = openssl_pkey_derive( $peer, $local, 32 );
		if ( false === $shared ) {
			return '';
		}

		$salt    = random_bytes( 16 );
		$ikm     = hash_hkdf( 'sha256', $shared, 32, "WebPush: info\0" . $ua_public . $as_public, $auth_secret );
		$cek     = hash_hkdf( 'sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt );
		$nonce   = hash_hkdf( 'sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt );
		$tag     = '';
		$cipher  = openssl_encrypt( $plaintext . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16 );
		if ( false === $cipher ) {
			return '';
		}

		return $salt . pack( 'N', 4096 ) . chr( 65 ) . $as_public . $cipher . $tag;
	}

	private static function vapid_jwt( string $endpoint, string $pem ): string {
		$parts  = wp_parse_url( $endpoint );
		$header = self::b64url( wp_json_encode( [ 'typ' => 'JWT', 'alg' => 'ES256' ] ) );
		$claims = self::b64url( wp_json_encode( [
			'aud' => $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ),
			'exp' => time() + 12 * HOUR_IN_SECONDS,
			'sub' => 'mailto:' . get_option( 'admin_email' ),
		], JSON_UNESCAPED_SLASHES ) );

		$der = '';
		if ( ! openssl_sign( $header . '.' . $claims, $der, $pem, OPENSSL_ALGO_SHA256 ) ) {
			return '';
		}
		$raw = self::der_to_raw_signature( $der );
		return '' === $raw ? '' : $header . '.' . $claims . '.' . self::b64url( $raw );
	}

	/** @return array{pem:string,public:string}|null */
	private static function vapid_keys(): ?array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache ?: null;
		}

		$stored = get_option( self::VAPID_OPTION );
		if ( is_array( $stored ) && ! empty( $stored['private'] ) ) {
			$pem = YeffoPrint_Tracker_Crypto::open( (string) $stored['private'], self::VAPID_CONTEXT );
			$key = '' !== $pem ? openssl_pkey_get_private( $pem ) : false;
			if ( $key ) {
				$cache = [ 'pem' => $pem, 'public' => self::raw_public( $key ) ];
				return $cache;
			}
			// Unreadable (master key changed): fall through and make a
			// new pair — old subscriptions just need re-enabling.
		}

		$key = openssl_pkey_new( [ 'curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC ] );
		$pem = '';
		if ( ! $key || ! openssl_pkey_export( $key, $pem ) ) {
			$cache = false;
			return null;
		}
		$sealed = YeffoPrint_Tracker_Crypto::seal( $pem, self::VAPID_CONTEXT );
		if ( '' === $sealed ) {
			$cache = false;
			return null;
		}
		update_option( self::VAPID_OPTION, [ 'private' => $sealed ], false );

		$cache = [ 'pem' => $pem, 'public' => self::raw_public( $key ) ];
		return $cache;
	}

	/** @param \OpenSSLAsymmetricKey $key */
	private static function raw_public( $key ): string {
		$details = openssl_pkey_get_details( $key );
		if ( empty( $details['ec']['x'] ) || empty( $details['ec']['y'] ) ) {
			return '';
		}
		return "\x04" . str_pad( $details['ec']['x'], 32, "\0", STR_PAD_LEFT ) . str_pad( $details['ec']['y'], 32, "\0", STR_PAD_LEFT );
	}

	private static function spki_pem( string $raw_public ): string {
		$der = hex2bin( self::P256_SPKI_PREFIX ) . $raw_public;
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n"; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/** ECDSA DER (SEQUENCE of two INTEGERs) → the fixed 64-byte r||s JWT expects. */
	private static function der_to_raw_signature( string $der ): string {
		$pos = 0;
		if ( "\x30" !== ( $der[ $pos++ ] ?? '' ) ) {
			return '';
		}
		$len = ord( $der[ $pos++ ] );
		if ( $len & 0x80 ) {
			$pos += $len & 0x7f;
		}
		$out = '';
		for ( $i = 0; $i < 2; $i++ ) {
			if ( "\x02" !== ( $der[ $pos++ ] ?? '' ) ) {
				return '';
			}
			$int_len = ord( $der[ $pos++ ] );
			$int     = ltrim( substr( $der, $pos, $int_len ), "\0" );
			$pos    += $int_len;
			if ( strlen( $int ) > 32 ) {
				return '';
			}
			$out .= str_pad( $int, 32, "\0", STR_PAD_LEFT );
		}
		return $out;
	}

	public static function b64url( string $bin ): string {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public static function b64url_decode( string $s ): string {
		$bin = base64_decode( strtr( $s, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ), true );
		return false === $bin ? '' : $bin;
	}
}
