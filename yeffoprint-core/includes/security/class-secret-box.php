<?php
/**
 * Symmetric encryption for secrets that must be stored (not just
 * hashed) because staff need to read them back — go-live server
 * credentials and staged-site login passwords (class-web-design-
 * project-meta.php). Nothing else in this plugin previously needed
 * reversible encryption at rest (payment/webhook secrets are compared,
 * never displayed, so those stay plain options; passwords elsewhere are
 * WordPress's own hashed user passwords) — this is the first.
 *
 * AES-256-CBC via OpenSSL (bundled with PHP, no new Composer
 * dependency) with a key derived from wp_salt( 'secure_auth' ) — a
 * random, per-install secret WordPress already guarantees exists and
 * never stores in the database (wp-config.php, or generated at runtime
 * if wp-config.php has no AUTH keys defined), so this never invents a
 * new secret-management story. Not the same key any nonce/cookie logic
 * uses for its own purpose (WordPress salts are already used for
 * several unrelated things by core itself), which is fine — a salt is
 * a source of entropy, not a single-purpose key, and hash()'ing it
 * down to exactly 32 bytes here is standard practice for turning a
 * variable-length secret into a fixed-length cipher key.
 *
 * Encrypted values move between requests as an opaque base64 string
 * (IV prepended to ciphertext, both binary) — never split into two
 * separate stored fields — so a caller can treat encrypt()/decrypt() as
 * a black box the same way it already treats update_post_meta()/
 * get_post_meta().
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Secret_Box {

	private const CIPHER = 'aes-256-cbc';

	/** @return string Empty string in, empty string out — never encrypts a blank value into noise a UI would render as "something is set." */
	public static function encrypt( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}

		$iv_length = openssl_cipher_iv_length( self::CIPHER );
		$iv        = openssl_random_pseudo_bytes( $iv_length );
		$cipher    = openssl_encrypt( $plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv );

		if ( false === $cipher ) {
			return '';
		}

		return base64_encode( $iv . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- opaque storage encoding, not obfuscation.
	}

	/** @return string Empty string for anything that isn't a validly-encrypted value (blank input, corrupt/truncated data, wrong key after a salt rotation) — callers treat that as "no secret stored" rather than crashing. */
	public static function decrypt( string $stored ): string {
		if ( '' === $stored ) {
			return '';
		}

		$raw = base64_decode( $stored, true );
		if ( false === $raw ) {
			return '';
		}

		$iv_length = openssl_cipher_iv_length( self::CIPHER );
		if ( strlen( $raw ) <= $iv_length ) {
			return '';
		}

		$iv     = substr( $raw, 0, $iv_length );
		$cipher = substr( $raw, $iv_length );
		$plain  = openssl_decrypt( $cipher, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv );

		return false === $plain ? '' : $plain;
	}

	private static function key(): string {
		return hash( 'sha256', wp_salt( 'secure_auth' ), true );
	}
}
