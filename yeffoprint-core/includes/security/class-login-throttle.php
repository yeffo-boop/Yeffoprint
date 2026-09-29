<?php
/**
 * Slows down password guessing on every WordPress sign-in path
 * (wp-login.php, WooCommerce My Account, application passwords) — from
 * the Dose Tracker security audit: tracker entries are encrypted, but
 * anyone who signs in as the customer sees them decrypted, so the
 * account password is the lock that matters, and nothing on the site
 * limited how many guesses one visitor could make.
 *
 * Two counters, both in transients:
 *   - per IP:       10 failed sign-ins in 15 minutes blocks that IP for 15 minutes.
 *   - per username: 30 failed sign-ins in an hour (from any IPs) blocks
 *                   password sign-in for that account for the rest of
 *                   the hour. High enough that a stranger can't lock a
 *                   customer out with a few tries, low enough to stop a
 *                   distributed guessing run. Telegram sign-in and
 *                   "Lost your password?" still work while it's blocked.
 *
 * A successful sign-in clears that IP's counter.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Login_Throttle {

	private const IP_LIMIT    = 10;
	private const IP_WINDOW   = 15 * MINUTE_IN_SECONDS;
	private const USER_LIMIT  = 30;
	private const USER_WINDOW = HOUR_IN_SECONDS;

	private const ERROR_CODE = 'yeffoprint_login_throttled';

	public function __construct() {
		// After core's own checks (priority 20/30), so a block wins over a correct password.
		add_filter( 'authenticate', [ $this, 'maybe_block' ], 99, 2 );
		add_action( 'wp_login_failed', [ $this, 'record_failure' ], 10, 2 );
		add_action( 'wp_login', [ $this, 'clear_ip' ] );
	}

	/**
	 * @param \WP_User|\WP_Error|null $user
	 * @return \WP_User|\WP_Error|null
	 */
	public function maybe_block( $user, $username ) {
		$username = self::normalize( (string) $username );
		if ( '' === $username && ! ( $user instanceof \WP_User ) ) {
			return $user; // Blank form / cookie check: nothing to throttle.
		}

		if ( self::count( self::ip_key() ) >= self::IP_LIMIT
			|| ( '' !== $username && self::count( self::user_key( $username ) ) >= self::USER_LIMIT ) ) {
			return new \WP_Error(
				self::ERROR_CODE,
				__( 'Too many failed sign-in attempts. Please wait a few minutes and try again, or use "Lost your password?" to reset it.', 'yeffoprint-core' )
			);
		}

		return $user;
	}

	/** @param \WP_Error|null $error */
	public function record_failure( $username, $error = null ) {
		if ( $error instanceof \WP_Error && self::ERROR_CODE === $error->get_error_code() ) {
			return; // Already blocked; don't keep extending the block.
		}

		self::bump( self::ip_key(), self::IP_WINDOW );

		$username = self::normalize( (string) $username );
		if ( '' !== $username ) {
			self::bump( self::user_key( $username ), self::USER_WINDOW );
		}
	}

	public function clear_ip(): void {
		delete_transient( self::ip_key() );
	}

	private static function normalize( string $username ): string {
		$username = strtolower( trim( $username ) );
		// Email and username both sign in to the same account.
		if ( is_email( $username ) ) {
			$user = get_user_by( 'email', $username );
			if ( $user ) {
				$username = strtolower( $user->user_login );
			}
		}
		return $username;
	}

	private static function ip_key(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		return 'yp_login_ip_' . md5( $ip );
	}

	private static function user_key( string $username ): string {
		return 'yp_login_user_' . md5( $username );
	}

	private static function count( string $key ): int {
		return (int) get_transient( $key );
	}

	/** The window runs from the first failure, like the site's other per-IP limits. */
	private static function bump( string $key, int $window ): void {
		$count = self::count( $key );
		if ( 0 === $count ) {
			set_transient( $key . '_t', time() + $window, $window );
			set_transient( $key, 1, $window );
			return;
		}
		$expires = (int) get_transient( $key . '_t' );
		set_transient( $key, $count + 1, max( 1, $expires - time() ) );
	}
}
