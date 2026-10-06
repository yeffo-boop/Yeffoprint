<?php
/**
 * The Dose Tracker web app at /tracker/ — direct request: "A lot of
 * customers want a way to track what peptides they are taking daily
 * (dosage, units, frequency, completion, etc). Can we build something
 * mobile friendly..."
 *
 * Served straight from the plugin as its own full-screen app shell
 * (not a theme page), because it's meant to be added to a phone's Home
 * Screen and opened like an app: no site header/footer, its own web app
 * manifest, and a service worker scoped to /tracker/ for offline use
 * and push reminders. Same rewrite-rule approach as
 * class-admin-app-shortcut.php's /design/.
 *
 *   /tracker/                        the app (sign-in screen when logged out)
 *   /tracker/sw.js                   service worker (must be served from inside /tracker/ to control it)
 *   /tracker/manifest.webmanifest    Home Screen install metadata
 *   /tracker/session                 a fresh REST nonce for the signed-in customer
 *   /tracker/p/{code}                a shared protocol (class-tracker-shares.php): the app, opened on "Add to my tracker"
 *
 * Nothing about a customer's doses is printed into the page — the app
 * fetches them from the nonce-checked REST API
 * (rest/class-tracker-controller.php) — but the page still carries a
 * per-user nonce, so it's sent no-store and never page-cached.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Tracker_App {

	public const SLUG = 'tracker';

	private const QUERY_VAR = 'yeffoprint_tracker';

	private const SHARE_VAR = 'yeffoprint_tracker_share';

	public function __construct() {
		add_action( 'init', [ $this, 'register_rewrite' ] );
		add_filter( 'query_vars', [ $this, 'register_query_var' ] );
		add_action( 'template_redirect', [ $this, 'maybe_serve' ], 0 );
		add_action( 'wp_footer', [ $this, 'forget_device_copy' ] );
		add_action( 'login_footer', [ $this, 'forget_device_copy' ] );
		add_action( 'wp_footer', [ $this, 'app_sign_in_options' ] );
		add_action( 'login_footer', [ $this, 'app_sign_in_options' ] );
	}

	/**
	 * Inside the Android app (tracker-android/) Google refuses to sign
	 * anyone in from an app's built-in browser, and Telegram's sign-in
	 * needs a pop-up window the app doesn't open, so those two buttons
	 * are hidden there. Email and password, Discord and Apple all work.
	 */
	public function app_sign_in_options(): void {
		if ( is_user_logged_in() ) {
			return;
		}
		?>
<script>(function(){try{var C=window.Capacitor;if(!(C&&C.isNativePlatform&&C.isNativePlatform())){return;}var s=document.createElement('style');s.textContent='.yp-social-login__button--google,.yp-telegram-login{display:none!important}';document.head.appendChild(s);}catch(e){}})();</script>
		<?php
	}

	/**
	 * The app keeps a copy of the customer's entries in the browser so it
	 * opens offline. Its own Sign out button clears that copy, but signing
	 * out anywhere else on the site (the header, My Account, wp-login.php)
	 * didn't, which left the entries readable on a shared computer. Every
	 * signed-out page now clears it: the saved copy and unsynced changes
	 * (localStorage ypt:* / ypt-q:*) and the offline copy of the app page.
	 * In the Android app (tracker-android/) it also cancels the reminders
	 * scheduled on the phone, which carry medication names.
	 */
	public function forget_device_copy(): void {
		if ( is_user_logged_in() ) {
			return;
		}
		?>
<script>(function(){try{var s=window.localStorage,k=[];for(var i=0;i<s.length;i++){var n=s.key(i);if(n&&(n.indexOf('ypt:')===0||n.indexOf('ypt-q:')===0||n.indexOf('ypt-native-reminders:')===0)){k.push(n);}}k.forEach(function(n){s.removeItem(n);});}catch(e){}try{if(window.caches){caches.keys().then(function(ks){ks.forEach(function(c){if(c.indexOf('yp-tracker-')===0){caches.delete(c);}});});}}catch(e){}try{var C=window.Capacitor;if(C&&C.isNativePlatform&&C.isNativePlatform()&&C.nativePromise){C.nativePromise('LocalNotifications','cancelAll',{}).catch(function(){});}}catch(e){}})();</script>
		<?php
	}

	public static function url(): string {
		return home_url( '/' . self::SLUG . '/' );
	}

	/** "/tracker/" (or "/sub/tracker/" when WordPress lives in a subfolder). */
	private static function path(): string {
		return (string) wp_parse_url( self::url(), PHP_URL_PATH );
	}

	public function register_rewrite(): void {
		add_rewrite_rule( '^' . self::SLUG . '/?$', 'index.php?' . self::QUERY_VAR . '=app', 'top' );
		add_rewrite_rule( '^' . self::SLUG . '/sw\.js$', 'index.php?' . self::QUERY_VAR . '=sw', 'top' );
		add_rewrite_rule( '^' . self::SLUG . '/manifest\.webmanifest$', 'index.php?' . self::QUERY_VAR . '=manifest', 'top' );
		add_rewrite_rule( '^' . self::SLUG . '/session$', 'index.php?' . self::QUERY_VAR . '=session', 'top' );
		add_rewrite_rule( '^' . self::SLUG . '/p/([A-Za-z0-9]{10})/?$', 'index.php?' . self::QUERY_VAR . '=app&' . self::SHARE_VAR . '=$matches[1]', 'top' );
	}

	public function register_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		$vars[] = self::SHARE_VAR;
		return $vars;
	}

	public function maybe_serve(): void {
		$what = get_query_var( self::QUERY_VAR );
		if ( ! $what ) {
			return;
		}

		switch ( $what ) {
			case 'sw':
				$this->serve_service_worker();
				break;
			case 'manifest':
				$this->serve_manifest();
				break;
			case 'session':
				$this->serve_session();
				break;
			default:
				$this->serve_app();
		}
		exit;
	}

	private function serve_service_worker(): void {
		status_header( 200 );
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Cache-Control: no-cache' );
		header( 'Service-Worker-Allowed: ' . self::path() );

		// The shell assets' content-hashed URLs are baked in, so a deploy
		// that changes them produces a new service worker (and a fresh cache).
		$config = [
			'version' => substr( md5( implode( '|', self::asset_urls() ) ), 0, 12 ),
			'shell'   => array_values( self::asset_urls() ),
			'appUrl'  => self::url(),
		];
		echo 'self.YP_TRACKER_SW = ' . wp_json_encode( $config ) . ";\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON.
		readfile( YEFFOPRINT_CORE_PATH . 'assets/tracker/sw.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
	}

	/**
	 * A fresh wp_rest nonce once the page's own has expired (the app was
	 * left open, or reopened from the Home Screen's cached copy). This
	 * can't be the REST /session/nonce route: WordPress treats a REST
	 * request without a valid nonce as signed out, so that route only
	 * ever hands back a guest nonce. Same-origin JSON with no CORS
	 * headers, so no other site can read it.
	 */
	private function serve_session(): void {
		YeffoPrint_Stay_Signed_In::extend_current_login();
		nocache_headers();
		header( 'Cache-Control: no-store, private' );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		status_header( 200 );
		echo wp_json_encode( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON.
			'signedIn' => is_user_logged_in(),
			'nonce'    => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
		] );
	}

	private function serve_manifest(): void {
		status_header( 200 );
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );

		$icons = YEFFOPRINT_CORE_URL . 'assets/tracker/icons/';
		echo wp_json_encode( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON.
			'id'               => self::path(),
			'name'             => 'YeffoHealth Dose Tracker',
			'short_name'       => 'YeffoHealth',
			'description'      => 'Track your peptides, medications and health: doses, vials, schedule and progress.',
			'start_url'        => self::path(),
			'scope'            => self::path(),
			'display'          => 'standalone',
			'orientation'      => 'portrait',
			'background_color' => '#FAF9F6',
			'theme_color'      => '#FAF9F6',
			'icons'            => [
				[ 'src' => $icons . 'icon-192.png?v=2', 'sizes' => '192x192', 'type' => 'image/png' ],
				[ 'src' => $icons . 'icon-512.png?v=2', 'sizes' => '512x512', 'type' => 'image/png' ],
				[ 'src' => $icons . 'icon-maskable-512.png?v=2', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable' ],
			],
		], JSON_UNESCAPED_SLASHES );
	}

	private function serve_app(): void {
		YeffoPrint_Stay_Signed_In::extend_current_login();
		nocache_headers();
		header( 'Cache-Control: no-store, private' );
		header( 'X-Robots-Tag: noindex' );
		header( 'Referrer-Policy: same-origin' );
		$script_nonce = self::send_security_headers();
		status_header( 200 );

		$user   = wp_get_current_user();
		$assets = self::asset_urls();

		// A shared protocol link: the app opens on it. Nothing in it identifies whoever shared it.
		$share_code = (string) get_query_var( self::SHARE_VAR );
		$share      = '' !== $share_code ? YeffoPrint_Tracker_Shares::get( $share_code ) : null;
		$here       = '' !== $share_code ? YeffoPrint_Tracker_Shares::url( $share_code ) : self::url();

		$config = [
			'signedIn'      => is_user_logged_in(),
			'ready'         => YeffoPrint_Tracker_Crypto::is_ready(),
			'firstName'     => $user->ID ? ( $user->first_name ?: $user->display_name ) : '',
			'email'         => $user->ID ? $user->user_email : '',
			'version'       => YEFFOPRINT_CORE_VERSION,
			'userKey'       => $user->ID ? substr( hash_hmac( 'sha256', (string) $user->ID, wp_salt( 'auth' ) ), 0, 16 ) : '',
			'restUrl'       => esc_url_raw( rest_url( 'yeffoprint-core/v1/' ) ),
			'nonce'         => $user->ID ? wp_create_nonce( 'wp_rest' ) : '',
			'appUrl'        => self::url(),
			'swUrl'         => home_url( '/' . self::SLUG . '/sw.js' ),
			'loginUrl'      => wp_login_url( $here ),
			'registerUrl'   => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_registration_url(),
			'logoutUrl'     => $user->ID ? wp_logout_url( self::url() ) : '',
			'homeUrl'       => home_url( '/' ),
			'labelsUrl'     => home_url( '/shop-labels/' ),
			'cartUrl'       => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart/' ),
			'qtyPresets'    => function_exists( 'yeffoprint_core_quantity_presets' ) ? array_values( array_map( 'intval', yeffoprint_core_quantity_presets() ) ) : [ 10, 20, 30, 50, 100 ],
			'calculatorUrl' => home_url( '/peptide-calculator/' ),
			'compounds'     => self::compound_names(),
			'medications'   => YeffoPrint_Tracker_Medications::all(),
			'whatsNew'      => include YEFFOPRINT_CORE_PATH . 'includes/tracker/whats-new.php',
			'share'         => '' !== $share_code ? [ 'code' => $share_code, 'protocol' => $share ] : null,
		];

		include YEFFOPRINT_CORE_PATH . 'includes/tracker/views/app.php';
	}

	/**
	 * The app page is its own document (no wp_head), so it can lock down
	 * what a browser will run in it: only this site's own tracker.js and
	 * the one inline config script (by nonce), data sent only back to this
	 * site, and no framing by other sites. If anything ever slipped a
	 * script into customer text, the browser would refuse to run it.
	 *
	 * @return string The inline-script nonce.
	 */
	private static function send_security_headers(): string {
		$nonce = base64_encode( random_bytes( 16 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$csp   = [
			"default-src 'self'",
			"script-src 'self' 'nonce-{$nonce}'",
			"style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
			"font-src 'self' https://fonts.gstatic.com data:",
			"img-src 'self' data: blob: https:",
			"connect-src 'self'",
			"worker-src 'self'",
			"manifest-src 'self'",
			"object-src 'none'",
			"base-uri 'none'",
			"form-action 'self'",
			"frame-ancestors 'none'",
		];
		header( 'Content-Security-Policy: ' . implode( '; ', $csp ) );
		header( 'X-Frame-Options: DENY' );
		header( 'X-Content-Type-Options: nosniff' );
		return $nonce;
	}

	/** @return array{css:string,js:string} Content-hashed, so a deploy busts every cache (browser and service worker). */
	public static function asset_urls(): array {
		return [
			'css' => YEFFOPRINT_CORE_URL . 'assets/tracker/tracker.css?ver=' . yeffoprint_core_asset_version( 'assets/tracker/tracker.css' ),
			'js'  => YEFFOPRINT_CORE_URL . 'assets/tracker/tracker.js?ver=' . yeffoprint_core_asset_version( 'assets/tracker/tracker.js' ),
		];
	}

	/** Autocomplete for "Add a medication" (peptides and hormones; everyday medications come from YeffoPrint_Tracker_Medications) — the same Catalog > Compound List the label spell-check uses, whether or not spell-check is switched on. @return string[] */
	private static function compound_names(): array {
		if ( ! class_exists( 'YeffoPrint_Compound_List' ) ) {
			return [];
		}
		// Mixing waters are in the list for label spell-check, but aren't something anyone doses.
		$names = array_filter( array_map( static function ( $row ) {
			return is_array( $row ) ? (string) ( $row['name'] ?? '' ) : '';
		}, YeffoPrint_Compound_List::get_compounds() ), static function ( $name ) {
			return '' !== $name && false === stripos( $name, 'water' );
		} );
		natcasesort( $names );
		return array_values( array_unique( $names ) );
	}
}
