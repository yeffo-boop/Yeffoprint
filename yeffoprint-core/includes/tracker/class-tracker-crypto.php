<?php
/**
 * Encryption for the Dose Tracker (class-tracker-app.php) — direct
 * request: "Would need to make sure user data is encrypted because it
 * is technically health data."
 *
 * Two layers, both XChaCha20-Poly1305 (libsodium, bundled with PHP 7.2+
 * and polyfilled by WordPress's own sodium_compat where it isn't):
 *
 *   1. A master key that never touches the database. Read from the
 *      YEFFOPRINT_TRACKER_KEY constant (wp-config.php) if defined,
 *      otherwise from a key file this class creates once, on first use,
 *      one folder ABOVE the WordPress install (outside the web root and
 *      outside the git clone, so neither a `git reset --hard` deploy
 *      nor a database/wp-content backup ever carries it). Only if that
 *      folder isn't writable does it fall back to a PHP file under
 *      wp-content/ whose first line exits, so a web request for it
 *      prints nothing.
 *   2. A random per-customer key, stored in user meta only in wrapped
 *      (master-key-encrypted) form. Every record is encrypted with its
 *      owner's key, with the owner/kind/record id bound in as associated
 *      data so a row copied onto another account or record simply fails
 *      to decrypt.
 *
 * Deleting a customer's wrapped key (forget_user()) makes anything of
 * theirs that survived in an old backup permanently unreadable.
 *
 * YeffoPrint_Secret_Box (security/class-secret-box.php) isn't reused on
 * purpose: its key is derived from wp_salt(), which lives in the same
 * wp-config.php most backups include and which a salt rotation would
 * silently change, wiping every customer's history.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Tracker_Crypto {

	private const USER_KEY_META = '_yeffoprint_tracker_key';
	private const PREFIX        = 'v1:';
	private const KEY_FILE_NAME = 'yeffoprint-tracker.key';

	private static ?string $master = null;

	/** True once a master key is available (or could be created) — the app shows a "not set up yet" screen instead of storing anything otherwise. */
	public static function is_ready(): bool {
		return '' !== self::master_key();
	}

	/** Where the master key lives, for the admin screen and docs — never the key itself. */
	public static function key_location(): string {
		if ( defined( 'YEFFOPRINT_TRACKER_KEY' ) ) {
			return 'wp-config.php (YEFFOPRINT_TRACKER_KEY)';
		}
		foreach ( self::key_file_candidates() as $path ) {
			if ( is_readable( $path ) ) {
				return $path;
			}
		}
		return '';
	}

	public static function encrypt_record( int $user_id, string $kind, string $record_id, array $data ): string {
		$key = self::user_key( $user_id, true );
		if ( '' === $key ) {
			return '';
		}

		$nonce  = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
		$cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
			wp_json_encode( $data ),
			self::record_ad( $user_id, $kind, $record_id ),
			$nonce,
			$key
		);

		return self::PREFIX . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- opaque storage encoding.
	}

	/** @return array|null Null for anything that won't decrypt (tampered, moved, or the key was forgotten). */
	public static function decrypt_record( int $user_id, string $kind, string $record_id, string $stored ): ?array {
		$key = self::user_key( $user_id, false );
		$raw = self::unpack( $stored );
		if ( '' === $key || null === $raw ) {
			return null;
		}

		$plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( $raw[1], self::record_ad( $user_id, $kind, $record_id ), $raw[0], $key );
		if ( false === $plain ) {
			return null;
		}

		$data = json_decode( $plain, true );
		return is_array( $data ) ? $data : null;
	}

	/** Encrypt a small server-side secret (the push signing key) with the master key directly. */
	public static function seal( string $plain, string $context ): string {
		$master = self::master_key();
		if ( '' === $master ) {
			return '';
		}
		$nonce = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
		return self::PREFIX . base64_encode( $nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $plain, $context, $nonce, $master ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public static function open( string $stored, string $context ): string {
		$master = self::master_key();
		$raw    = self::unpack( $stored );
		if ( '' === $master || null === $raw ) {
			return '';
		}
		$plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( $raw[1], $context, $raw[0], $master );
		return false === $plain ? '' : $plain;
	}

	/** Crypto-shred: without the wrapped key nothing of this customer's can be decrypted again, including copies in old backups. */
	public static function forget_user( int $user_id ): void {
		delete_user_meta( $user_id, self::USER_KEY_META );
	}

	private static function user_key( int $user_id, bool $create ): string {
		if ( $user_id <= 0 ) {
			return '';
		}

		$ad      = 'yp-tracker-user-key|' . $user_id;
		$wrapped = (string) get_user_meta( $user_id, self::USER_KEY_META, true );
		if ( '' !== $wrapped ) {
			return self::open( $wrapped, $ad );
		}
		if ( ! $create ) {
			return '';
		}

		$key     = sodium_crypto_aead_xchacha20poly1305_ietf_keygen();
		$wrapped = self::seal( $key, $ad );
		if ( '' === $wrapped ) {
			return '';
		}
		update_user_meta( $user_id, self::USER_KEY_META, $wrapped );

		return $key;
	}

	private static function record_ad( int $user_id, string $kind, string $record_id ): string {
		return 'yp-tracker|' . $user_id . '|' . $kind . '|' . $record_id;
	}

	/** @return array{0:string,1:string}|null [nonce, ciphertext] */
	private static function unpack( string $stored ): ?array {
		if ( 0 !== strpos( $stored, self::PREFIX ) ) {
			return null;
		}
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		$n   = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
		if ( false === $raw || strlen( $raw ) <= $n ) {
			return null;
		}
		return [ substr( $raw, 0, $n ), substr( $raw, $n ) ];
	}

	private static function master_key(): string {
		if ( null !== self::$master ) {
			return self::$master;
		}

		self::$master = '';

		if ( defined( 'YEFFOPRINT_TRACKER_KEY' ) ) {
			$key = base64_decode( (string) YEFFOPRINT_TRACKER_KEY, true );
			if ( false !== $key && SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES === strlen( $key ) ) {
				self::$master = $key;
			}
			// A defined-but-malformed constant is a setup mistake to fix,
			// not a reason to quietly generate a different key.
			return self::$master;
		}

		foreach ( self::key_file_candidates() as $path ) {
			if ( is_readable( $path ) ) {
				self::$master = self::read_key_file( $path );
				return self::$master;
			}
		}

		foreach ( self::key_file_candidates() as $path ) {
			$key = self::create_key_file( $path );
			if ( '' !== $key ) {
				self::$master = $key;
				break;
			}
		}

		return self::$master;
	}

	/** @return string[] Preferred first: outside the web root, then a no-output PHP file under wp-content. */
	private static function key_file_candidates(): array {
		return [
			dirname( untrailingslashit( ABSPATH ) ) . '/' . self::KEY_FILE_NAME,
			WP_CONTENT_DIR . '/yeffoprint-private/tracker-key.php',
		];
	}

	private static function read_key_file( string $path ): string {
		$contents = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! preg_match( '/([A-Za-z0-9+\/]{43}=)/', $contents, $m ) ) {
			return '';
		}
		$key = base64_decode( $m[1], true );
		return ( false !== $key && 32 === strlen( $key ) ) ? $key : '';
	}

	private static function create_key_file( string $path ): string {
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		if ( ! is_writable( $dir ) ) {
			return '';
		}

		$key  = sodium_crypto_aead_xchacha20poly1305_ietf_keygen();
		$b64  = base64_encode( $key ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$body = str_ends_with( $path, '.php' )
			? "<?php exit; ?>\n" . $b64 . "\n"
			: $b64 . "\n";

		// 'x' mode: never overwrite a key another request just created.
		$handle = @fopen( $path, 'x' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return is_readable( $path ) ? self::read_key_file( $path ) : '';
		}
		fwrite( $handle, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		@chmod( $path, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod

		if ( str_ends_with( $path, '.php' ) && ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		return self::read_key_file( $path );
	}
}
