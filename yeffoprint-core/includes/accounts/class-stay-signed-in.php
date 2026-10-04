<?php
/**
 * Keeps people signed in to the Home Screen apps (direct requests: "I'm
 * logged out every time I open it" for the admin app, then "make sure
 * the same stay login fix applies to the tracker web app").
 *
 * wp-login.php's "Remember Me" box is unticked by default, which makes
 * WordPress's login cookie a session cookie (no expiry date). Safari
 * keeps those while it runs in the background, but an app added to the
 * iPhone Home Screen throws them away whenever it's closed, so every
 * launch landed back on the login screen. Even a "Remember Me" login
 * only lasted 14 days.
 *
 * Whenever someone signed in opens the admin app or the Dose Tracker
 * (/tracker/ and its /tracker/session check, class-tracker-app.php),
 * extend_current_login() re-issues the login cookie for the same session
 * (same token, so nonces and other devices are untouched) as a dated
 * cookie good for LIFETIME from now, at most once a day. Opening the app
 * at least once every LIFETIME keeps it signed in indefinitely; signing
 * out still ends the session at once (and still clears the tracker's
 * device copy).
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Stay_Signed_In {

	private const LIFETIME = 90 * DAY_IN_SECONDS;

	public function __construct() {
		add_action( 'admin_init', [ $this, 'on_admin_app' ] );
	}

	public function on_admin_app(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check.
		if ( ! in_array( $page, [ 'yeffoprint', YeffoPrint_Admin_Push::APP_SLUG ], true ) ) {
			return;
		}
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::extend_current_login();
	}

	/** Must run before any output (it sets cookies). */
	public static function extend_current_login(): void {
		if ( headers_sent() || ! is_user_logged_in() ) {
			return;
		}

		$token = wp_get_session_token();
		if ( '' === $token ) {
			return;
		}

		$user_id = get_current_user_id();
		$manager = WP_Session_Tokens::get_instance( $user_id );
		$session = $manager->get( $token );
		if ( ! is_array( $session ) ) {
			return;
		}

		$now = time();
		if ( (int) ( $session['expiration'] ?? 0 ) - $now > self::LIFETIME - DAY_IN_SECONDS ) {
			return; // Already extended within the last day.
		}

		$session['expiration'] = $now + self::LIFETIME;
		$manager->update( $token, $session );

		$lifetime = static function () {
			return self::LIFETIME;
		};
		add_filter( 'auth_cookie_expiration', $lifetime, PHP_INT_MAX );
		wp_set_auth_cookie( $user_id, true, '', $token );
		remove_filter( 'auth_cookie_expiration', $lifetime, PHP_INT_MAX );
	}
}
