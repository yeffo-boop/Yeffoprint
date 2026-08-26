<?php
/**
 * "Continue with Google" / "Continue with Discord" / "Continue with
 * Apple" on the storefront's login form. Direct request: "allow users
 * to login with Google/Apple/Discord" — Google and Discord shipped
 * first (free, few-minutes developer-app registration each); Apple
 * followed once the owner was ready for its bigger setup lift (paid
 * Apple Developer Program membership, domain verification).
 *
 * Apple's own OAuth/OIDC flow differs from Google's and Discord's in
 * two structural ways this class has to account for everywhere, not
 * just in provider_config():
 *
 *  1. **No static client secret.** Apple wants a JWT, signed with an
 *     ES256 (elliptic-curve) private key downloaded once from Apple
 *     Developer, as the "client_secret" on every token exchange —
 *     conventionally generated once and reused until it expires (Apple
 *     caps it at 6 months, so most implementations end up needing a
 *     rotation reminder/cron). `generate_apple_client_secret()`
 *     sidesteps that entirely by minting a fresh one, valid for just a
 *     few minutes, right before every single token exchange — there is
 *     no long-lived secret sitting anywhere to expire or rotate. PHP's
 *     OpenSSL extension signs it directly (`openssl_sign()` with
 *     `OPENSSL_ALGO_SHA256` over an EC key); the one non-obvious step
 *     is `der_to_raw_signature()` — OpenSSL hands back an ASN.1
 *     DER-encoded signature, but the JWS ES256 spec wants the raw
 *     64-byte r‖s concatenation instead, so it has to be unwrapped by
 *     hand (no JWT library in this project — same "minimal third-party
 *     dependencies" stance as everywhere else in this plugin, and
 *     there's no Composer/vendor setup here to hang one off of anyway).
 *  2. **The callback can arrive as a POST, not just a GET.** Apple
 *     requires `response_mode=form_post` whenever the requested scope
 *     includes `name`/`email` (both of which this flow needs) — Apple's
 *     own server returns an auto-submitting HTML form to the browser
 *     that POSTs `code`/`state`/(on the very first authorization only)
 *     `user` to the redirect URI, rather than a query-string GET
 *     redirect. `request_param()` reads `$_POST` first, `$_GET` second,
 *     so `callback()` doesn't need two separate implementations —
 *     Google/Discord's GET-based callback keeps working unchanged.
 *     Apple also has no separate userinfo endpoint the way Google/
 *     Discord do — identity comes from decoding the `id_token` JWT the
 *     token endpoint returns alongside the access token (trusted the
 *     same way this class already trusts Google/Discord's userinfo
 *     response: it arrived over a direct, server-to-server HTTPS call
 *     this code itself just made to the provider's own token endpoint,
 *     not something that passed through the browser).
 *
 * A hand-built OAuth 2.0 Authorization Code flow, not a third-party
 * social-login plugin — same "minimal third-party dependencies"
 * reasoning (PROJECT_SPEC §1.6) every other external integration in
 * this plugin already follows (Stripe, Telegram, the carrier tracking
 * APIs, the Venmo/Zelle gateways).
 *
 * Browser-navigated, not REST — same reasoning
 * class-account-endpoints.php's own docblock already gives for its
 * plain POST-and-redirect handlers: this is a user clicking a link and
 * being redirected, twice over (out to the provider, then back), not a
 * fetch()/JSON exchange, so wrapping it in the REST envelope would add
 * nothing but complication. `?yp-oauth={provider}` starts the flow;
 * `?yp-oauth-callback={provider}` is the fixed redirect URI registered
 * with each provider (see callback_url(), shown to the admin on the
 * Settings screen next to the Client ID/Secret fields).
 *
 * CSRF + the one bit of state that needs to survive the round trip
 * (`redirect_to`) both ride on a single random one-time token: start()
 * stashes `redirect_to` in a transient keyed by that token and sends
 * the token as the OAuth `state` param; callback() can only proceed if
 * it finds (and immediately deletes) that exact transient, so a
 * forged/replayed callback with a guessed or missing state is rejected
 * before any code exchange happens.
 *
 * Account matching: a returning identity (provider + external id
 * already seen) logs straight in. A brand-new identity whose email
 * matches an existing native account auto-links to it — safe because
 * both providers only ever hand back an email they've themselves
 * verified (checked explicitly in normalize_userinfo() for each), the
 * same trust basis every mainstream social-login implementation relies
 * on. Anything else creates a new customer via WooCommerce's own
 * `wc_create_new_customer()` (not a hand-rolled `wp_insert_user()`) so
 * this goes through the exact same account-creation path, hooks, and
 * role assignment (including class-referrals.php's `user_register`
 * attribution) as a normal signup — a random 32-character password is
 * generated and never surfaced anywhere; the customer simply never
 * needs one.
 *
 * This email-match rule applies to *every* WordPress user, administrators
 * included, not just customers — confirmed live: the owner's own admin
 * account linked itself the first time they signed in with the Google
 * account sharing that same address. Worth being explicit about since
 * it's a real, if expected, change to that account's security surface:
 * from that point on, whoever controls the matching Google, Discord, or
 * Apple account can log into it too, alongside its normal password.
 * `render_profile_section()` below surfaces which provider(s), if any,
 * are linked to a given user right on their native WordPress profile
 * screen, so this is never invisible after the fact.
 *
 * Direct report: on the live site, with a provider correctly turned on
 * and a real Client ID/Secret saved, the button never appeared —
 * ruled out (by the owner directly checking each) page caching, a
 * stale deploy, and the settings not actually persisting. The login
 * form itself rendered completely normally otherwise, which points at
 * a template on that specific install (predating this project's
 * git-based deploy, and therefore invisible to this repo — the deploy
 * script only ever `git reset --hard`s, never `git clean`s, so a
 * pre-existing file the git history never knew about just sits there
 * untouched forever) that overrides WooCommerce's own
 * `myaccount/form-login.php` without calling `woocommerce_login_form_end`.
 * Rather than depend on chasing down and fixing that one server's
 * exact template file, `enqueue_fallback_script()` below makes the
 * button's appearance independent of that hook firing at all: it hands
 * the browser the exact same server-rendered markup `render_login_buttons()`
 * would have echoed, and a tiny script finds the login form by
 * WooCommerce's own standard `.woocommerce-form-login` class (present
 * on every WC login form regardless of which template rendered it) and
 * appends it directly — a duplicate-append guard means this is a no-op
 * wherever the hook *does* fire normally.
 *
 * Follow-up direct report: this store's own login flow actually funnels
 * everyone through WordPress's native `wp-login.php`, not the
 * WooCommerce account page — a different screen entirely, built into
 * WP core itself rather than a theme-overridable template, so
 * `render_wp_login_buttons()` targets it separately via WordPress's own
 * `login_form` action (the hook every login/SSO plugin uses for exactly
 * this, fired inside `wp-login.php`'s own `<form id="loginform">` —
 * not overridable by a theme the way a WooCommerce template is).
 * Styling `.yp-social-login` there is the theme's job, not this class's
 * — same division already used for `/my-account/`'s version (styled in
 * `woocommerce.css`): this class only ever outputs the markup. It turns
 * out `yeffoprint/functions.php` already hooks `login_enqueue_scripts`
 * itself, to brand wp-login.php entirely (`assets/css/login.css`) — an
 * earlier assumption here that "wp-login.php never loads the active
 * theme's assets" was simply wrong for this site; that file now styles
 * `.yp-social-login` directly, using its own real brand tokens, rather
 * than this class enqueueing a second, generic, uncoordinated
 * stylesheet for the same page (which is also what caused a follow-up
 * bug: inserting a new block ahead of the floated `.forgetmenot` row
 * silently broke the margin collapsing `.submit`'s own spacing relied
 * on).
 *
 * Direct follow-up: even with that float bug fixed, the block still
 * reads as cramped against Remember Me/Log In, and the actual ask was
 * to move it below the submit button entirely — but `login_form` fires
 * right after the password field (core's own placement, not something
 * this class controls), well ahead of Remember Me and Log In, and
 * WordPress has no hook that fires later while still inside
 * `#loginform`. `login.css` solves this at the layout level instead of
 * chasing the DOM: `#loginform` is a flex column and `.yp-social-login`
 * gets a high `order`, which both re-sequences it to the visual end
 * regardless of DOM position and — as a permanent side effect — stops
 * every sibling margin on that form from ever collapsing again, the
 * same class of bug that caused the float issue in the first place.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Social_Login {

	private const STATE_TRANSIENT_PREFIX = 'yp_oauth_';
	private const STATE_TTL              = 10 * MINUTE_IN_SECONDS;

	public function __construct() {
		add_action( 'template_redirect', [ $this, 'maybe_handle_request' ] );
		add_action( 'woocommerce_login_form_end', [ $this, 'render_login_buttons' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_fallback_script' ] );
		add_action( 'login_form', [ $this, 'render_wp_login_buttons' ] );
		add_action( 'show_user_profile', [ $this, 'render_profile_section' ] );
		add_action( 'edit_user_profile', [ $this, 'render_profile_section' ] );
	}

	/** The fixed URL each provider redirects back to — register this exact value as the app's Redirect URI in its developer console. Static + public: class-admin-menu.php's Settings screen shows it without needing an instance. */
	public static function callback_url( string $provider ): string {
		return add_query_arg( 'yp-oauth-callback', $provider, home_url( '/' ) );
	}

	public function maybe_handle_request(): void {
		if ( isset( $_GET['yp-oauth'] ) ) {
			$this->start( sanitize_key( wp_unslash( $_GET['yp-oauth'] ) ) );
		} elseif ( isset( $_GET['yp-oauth-callback'] ) ) {
			// Present in $_GET regardless of HTTP method — it's part of
			// the redirect URI's own query string, which PHP always
			// parses into $_GET even on a POST request (Apple's
			// form_post callback). See request_param() for how the
			// callback's actual payload (code/state/error/user) is read.
			$this->callback( sanitize_key( wp_unslash( $_GET['yp-oauth-callback'] ) ) );
		}
	}

	/** Apple's form_post callback delivers code/state/error/user via $_POST; Google/Discord's via $_GET. Checking $_POST first, $_GET second, lets callback() read either without knowing which provider it's handling. */
	private function request_param( string $key ): string {
		if ( isset( $_POST[ $key ] ) ) {
			return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
		}
		if ( isset( $_GET[ $key ] ) ) {
			return sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
		}
		return '';
	}

	/** @return array{label:string, enabled_opt:string, client_id_opt:string, client_secret_opt:?string, authorize_url:string, token_url:string, userinfo_url:?string, scope:string, form_post:bool}|null */
	private function provider_config( string $provider ): ?array {
		if ( 'google' === $provider ) {
			return [
				'label'             => 'Google',
				'enabled_opt'       => YeffoPrint_Admin_Menu::GOOGLE_LOGIN_ENABLED_OPTION,
				'client_id_opt'     => YeffoPrint_Admin_Menu::GOOGLE_CLIENT_ID_OPTION,
				'client_secret_opt' => YeffoPrint_Admin_Menu::GOOGLE_CLIENT_SECRET_OPTION,
				'authorize_url'     => 'https://accounts.google.com/o/oauth2/v2/auth',
				'token_url'         => 'https://oauth2.googleapis.com/token',
				'userinfo_url'      => 'https://www.googleapis.com/oauth2/v3/userinfo',
				'scope'             => 'openid email profile',
				'form_post'         => false,
			];
		}

		if ( 'discord' === $provider ) {
			return [
				'label'             => 'Discord',
				'enabled_opt'       => YeffoPrint_Admin_Menu::DISCORD_LOGIN_ENABLED_OPTION,
				'client_id_opt'     => YeffoPrint_Admin_Menu::DISCORD_CLIENT_ID_OPTION,
				'client_secret_opt' => YeffoPrint_Admin_Menu::DISCORD_CLIENT_SECRET_OPTION,
				'authorize_url'     => 'https://discord.com/api/oauth2/authorize',
				'token_url'         => 'https://discord.com/api/oauth2/token',
				'userinfo_url'      => 'https://discord.com/api/users/@me',
				'scope'             => 'identify email',
				'form_post'         => false,
			];
		}

		if ( 'apple' === $provider ) {
			return [
				'label'             => 'Apple',
				'enabled_opt'       => YeffoPrint_Admin_Menu::APPLE_LOGIN_ENABLED_OPTION,
				'client_id_opt'     => YeffoPrint_Admin_Menu::APPLE_CLIENT_ID_OPTION,
				'client_secret_opt' => null, // No static secret — client_secret_for() signs a fresh short-lived JWT per exchange instead.
				'authorize_url'     => 'https://appleid.apple.com/auth/authorize',
				'token_url'         => 'https://appleid.apple.com/auth/token',
				'userinfo_url'      => null, // No userinfo endpoint — identity comes from the token response's id_token (decode_id_token()).
				'scope'             => 'name email',
				'form_post'         => true,
			];
		}

		return null;
	}

	private function start( string $provider ): void {
		$config = $this->provider_config( $provider );
		$client_id = $config ? (string) get_option( $config['client_id_opt'] ) : '';

		if ( ! $config || ! get_option( $config['enabled_opt'] ) || '' === $client_id ) {
			wp_die( esc_html__( 'This login option is not available right now.', 'yeffoprint-core' ), '', [ 'response' => 404 ] );
		}

		$redirect_to = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';
		$redirect_to = wp_validate_redirect( $redirect_to, '' );

		$state = wp_generate_password( 32, false );
		set_transient( self::STATE_TRANSIENT_PREFIX . $state, [ 'redirect_to' => $redirect_to ], self::STATE_TTL );

		$authorize_url = add_query_arg( array_filter( [
			'client_id'     => $client_id,
			'redirect_uri'  => self::callback_url( $provider ),
			'response_type' => 'code',
			'scope'         => $config['scope'],
			'state'         => $state,
			// Apple requires this whenever the requested scope includes
			// name/email — without it Apple rejects the request outright
			// rather than silently falling back to a query redirect.
			'response_mode' => $config['form_post'] ? 'form_post' : null,
		] ), $config['authorize_url'] );

		wp_redirect( esc_url_raw( $authorize_url ) ); // phpcs:ignore WordPress.Security.SafeRedirect -- an external provider's own authorize URL, not user input.
		exit;
	}

	private function callback( string $provider ): void {
		$config = $this->provider_config( $provider );
		if ( ! $config || ! get_option( $config['enabled_opt'] ) ) {
			$this->fail( __( 'This login option is not available right now.', 'yeffoprint-core' ) );
		}

		$state   = $this->request_param( 'state' );
		$stashed = $state ? get_transient( self::STATE_TRANSIENT_PREFIX . $state ) : false;
		if ( false === $stashed ) {
			$this->fail( __( 'Your login attempt expired or was invalid — please try again.', 'yeffoprint-core' ) );
		}
		delete_transient( self::STATE_TRANSIENT_PREFIX . $state ); // One-time use, win or lose.

		if ( '' !== $this->request_param( 'error' ) ) {
			// Declined on the provider's own consent screen — not an error worth alarming the customer about.
			wp_safe_redirect( $this->account_url() );
			exit;
		}

		$code = $this->request_param( 'code' );
		if ( '' === $code ) {
			$this->fail( __( 'Something went wrong finishing that login — please try again.', 'yeffoprint-core' ) );
		}

		$client_id     = (string) get_option( $config['client_id_opt'] );
		$client_secret = $this->client_secret_for( $provider, $config );
		if ( '' === $client_id || ! $client_secret ) {
			$this->fail( __( 'This login option is not fully set up yet.', 'yeffoprint-core' ) );
		}

		$tokens = $this->exchange_tokens( $provider, $config, $client_id, $client_secret, $code );
		if ( ! $tokens ) {
			$this->fail( __( "We couldn't complete that login — please try again.", 'yeffoprint-core' ) );
		}

		if ( null === $config['userinfo_url'] ) {
			// Apple: no userinfo endpoint — identity comes from the
			// id_token the token endpoint itself just returned, trusted
			// the same way this class already trusts a userinfo
			// response: both arrived over a direct, server-to-server
			// HTTPS call this code just made to the provider, never
			// having passed through the browser.
			$claims = isset( $tokens['id_token'] ) ? $this->decode_id_token( (string) $tokens['id_token'] ) : null;
		} else {
			$claims = $this->fetch_userinfo( $config, (string) $tokens['access_token'] );
		}

		$identity = $claims ? $this->normalize_userinfo( $provider, $claims ) : null;

		if ( ! $identity ) {
			$this->fail(
				sprintf(
					/* translators: %s: provider name, e.g. "Google" */
					__( "We couldn't verify your account — make sure your email is verified with %s and try again.", 'yeffoprint-core' ),
					$config['label']
				)
			);
		}

		// Apple only ever includes a name on the very first authorization
		// (as a one-time `user` field alongside the code/state) — every
		// later login carries nothing but the stable `sub` identifier,
		// so this is the only chance to capture it. Never overwrites a
		// name find_or_create_user() may already have on file.
		if ( 'apple' === $provider ) {
			$apple_user = json_decode( $this->request_param( 'user' ), true );
			if ( is_array( $apple_user ) && isset( $apple_user['name'] ) ) {
				$identity['name'] = trim( ( $apple_user['name']['firstName'] ?? '' ) . ' ' . ( $apple_user['name']['lastName'] ?? '' ) );
			}
		}

		$user = $this->find_or_create_user( $provider, $identity );
		if ( is_wp_error( $user ) ) {
			$this->fail( $user->get_error_message() );
		}

		$this->log_in( $user );

		wp_safe_redirect( $stashed['redirect_to'] ?: $this->account_url() );
		exit;
	}

	/** Google/Discord: the saved static secret. Apple: a freshly signed short-lived JWT (see this class's own docblock) — regenerated on every call rather than cached, since signing one is cheap and it sidesteps ever needing to store or rotate a long-lived secret. */
	private function client_secret_for( string $provider, array $config ): ?string {
		if ( 'apple' === $provider ) {
			return $this->generate_apple_client_secret();
		}

		$secret = (string) get_option( $config['client_secret_opt'] );
		return '' !== $secret ? $secret : null;
	}

	/** @return array<string, mixed>|null The decoded token response (access_token, and for Apple id_token) — null on any failure. */
	private function exchange_tokens( string $provider, array $config, string $client_id, string $client_secret, string $code ): ?array {
		$response = wp_remote_post( $config['token_url'], [
			'timeout' => 15,
			'headers' => [ 'Accept' => 'application/json' ],
			'body'    => [
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'code'          => $code,
				'grant_type'    => 'authorization_code',
				'redirect_uri'  => self::callback_url( $provider ),
			],
		] );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return ( is_array( $body ) && ! empty( $body['access_token'] ) ) ? $body : null;
	}

	private function fetch_userinfo( array $config, string $access_token ): ?array {
		$response = wp_remote_get( $config['userinfo_url'], [
			'timeout' => 15,
			'headers' => [
				'Authorization' => 'Bearer ' . $access_token,
				'Accept'        => 'application/json',
			],
		] );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $body ) ? $body : null;
	}

	/**
	 * Apple has no userinfo endpoint — this decodes the id_token JWT's
	 * payload segment directly rather than verifying its signature
	 * against Apple's published keys, since (as the class docblock
	 * explains) the token itself already arrived over a direct HTTPS
	 * call this code just made to Apple's own token endpoint.
	 */
	private function decode_id_token( string $id_token ): ?array {
		$parts = explode( '.', $id_token );
		if ( 3 !== count( $parts ) ) {
			return null;
		}

		$payload = json_decode( self::base64url_decode( $parts[1] ), true );
		return is_array( $payload ) ? $payload : null;
	}

	/**
	 * Apple's "client_secret" is a JWT it wants signed with the ES256
	 * (P-256 elliptic curve) private key from Apple Developer, carrying
	 * the team/services/key ids as claims — not a value Apple ever
	 * hands out directly the way Google/Discord's static secrets are.
	 * A short `exp` (this is used within seconds of being minted, never
	 * stored) rather than the 6-month maximum Apple allows is what lets
	 * this be generated fresh every time instead of cached and rotated.
	 */
	private function generate_apple_client_secret(): ?string {
		$team_id     = (string) get_option( YeffoPrint_Admin_Menu::APPLE_TEAM_ID_OPTION );
		$key_id      = (string) get_option( YeffoPrint_Admin_Menu::APPLE_KEY_ID_OPTION );
		$private_key = (string) get_option( YeffoPrint_Admin_Menu::APPLE_PRIVATE_KEY_OPTION );
		$client_id   = (string) get_option( YeffoPrint_Admin_Menu::APPLE_CLIENT_ID_OPTION );

		if ( '' === $team_id || '' === $key_id || '' === $private_key || '' === $client_id ) {
			return null;
		}

		$pkey = openssl_pkey_get_private( $private_key );
		if ( ! $pkey ) {
			return null;
		}

		$now = time();
		$segments = [
			self::base64url_encode( (string) wp_json_encode( [ 'alg' => 'ES256', 'kid' => $key_id ] ) ),
			self::base64url_encode( (string) wp_json_encode( [
				'iss' => $team_id,
				'iat' => $now,
				'exp' => $now + 300,
				'aud' => 'https://appleid.apple.com',
				'sub' => $client_id,
			] ) ),
		];

		$der_signature = '';
		$signed = openssl_sign( implode( '.', $segments ), $der_signature, $pkey, OPENSSL_ALGO_SHA256 );
		if ( ! $signed ) {
			return null;
		}

		// P-256's coordinates are 32 bytes each — JWS ES256 wants the raw
		// 64-byte r‖s concatenation, but openssl_sign() only ever hands
		// back an ASN.1 DER-encoded SEQUENCE of the two INTEGERs.
		$raw_signature = self::der_ecdsa_signature_to_raw( $der_signature, 32 );
		if ( null === $raw_signature ) {
			return null;
		}

		$segments[] = self::base64url_encode( $raw_signature );
		return implode( '.', $segments );
	}

	/** Unwraps a DER `SEQUENCE { r INTEGER, s INTEGER }` into the fixed-width big-endian r‖s pair a JWS ES256 signature needs. Assumes short-form DER lengths throughout, always true for a P-256 (32-byte coordinate) signature. */
	private static function der_ecdsa_signature_to_raw( string $der, int $part_length ): ?string {
		if ( "\x30" !== ( $der[0] ?? '' ) || "\x02" !== ( $der[2] ?? '' ) ) {
			return null;
		}

		$r_len = ord( $der[3] );
		$r     = substr( $der, 4, $r_len );

		$s_offset = 4 + $r_len;
		if ( "\x02" !== ( $der[ $s_offset ] ?? '' ) ) {
			return null;
		}
		$s_len = ord( $der[ $s_offset + 1 ] );
		$s     = substr( $der, $s_offset + 2, $s_len );

		// DER prepends a 0x00 to an integer whose natural high bit is set
		// (so it isn't misread as negative); stripping every leading NUL
		// and re-padding to a fixed width recovers the same big-endian
		// unsigned value either way.
		$r = str_pad( ltrim( $r, "\x00" ), $part_length, "\x00", STR_PAD_LEFT );
		$s = str_pad( ltrim( $s, "\x00" ), $part_length, "\x00", STR_PAD_LEFT );

		return ( strlen( $r ) === $part_length && strlen( $s ) === $part_length ) ? ( $r . $s ) : null;
	}

	private static function base64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	private static function base64url_decode( string $data ): string {
		$remainder = strlen( $data ) % 4;
		if ( $remainder ) {
			$data .= str_repeat( '=', 4 - $remainder );
		}
		return (string) base64_decode( strtr( $data, '-_', '+/' ) );
	}

	/**
	 * Provider-specific field names, one normalized shape out — and the
	 * one security-relevant check in this whole flow: an unverified
	 * email is refused outright, since find_or_create_user() below
	 * trusts a matching email enough to auto-link an existing account.
	 *
	 * @return array{external_id:string, email:string, name:string}|null
	 */
	private function normalize_userinfo( string $provider, array $profile ): ?array {
		if ( 'google' === $provider ) {
			if ( empty( $profile['sub'] ) || empty( $profile['email'] ) || empty( $profile['email_verified'] ) ) {
				return null;
			}
			return [
				'external_id' => (string) $profile['sub'],
				'email'       => sanitize_email( (string) $profile['email'] ),
				'name'        => (string) ( $profile['name'] ?? '' ),
			];
		}

		if ( 'discord' === $provider ) {
			if ( empty( $profile['id'] ) || empty( $profile['email'] ) || empty( $profile['verified'] ) ) {
				return null;
			}
			return [
				'external_id' => (string) $profile['id'],
				'email'       => sanitize_email( (string) $profile['email'] ),
				'name'        => (string) ( $profile['global_name'] ?? $profile['username'] ?? '' ),
			];
		}

		if ( 'apple' === $provider ) {
			// email_verified arrives as the literal string "true"/"false"
			// on Apple's id_token, not a real boolean — checked as such
			// rather than via empty()/(bool), which "false" would pass.
			$email_verified = $profile['email_verified'] ?? false;
			if ( empty( $profile['sub'] ) || empty( $profile['email'] ) || ( true !== $email_verified && 'true' !== $email_verified ) ) {
				return null;
			}
			return [
				'external_id' => (string) $profile['sub'],
				'email'       => sanitize_email( (string) $profile['email'] ),
				'name'        => '', // Only ever available via the one-time `user` POST field — filled in by callback() when present.
			];
		}

		return null;
	}

	/** @return \WP_User|\WP_Error */
	private function find_or_create_user( string $provider, array $identity ) {
		$meta_key = $this->identity_meta_key( $provider );

		$linked_ids = get_users( [
			'meta_key'   => $meta_key,
			'meta_value' => $identity['external_id'],
			'number'     => 1,
			'fields'     => 'ID',
		] );

		if ( $linked_ids ) {
			return get_user_by( 'id', $linked_ids[0] );
		}

		$by_email = get_user_by( 'email', $identity['email'] );
		if ( $by_email ) {
			update_user_meta( $by_email->ID, $meta_key, $identity['external_id'] );
			return $by_email;
		}

		[ $first_name, $last_name ] = $this->split_name( $identity['name'] );

		$user_id = wc_create_new_customer(
			$identity['email'],
			'',
			wp_generate_password( 32, true, true ),
			[
				'first_name' => $first_name,
				'last_name'  => $last_name,
			]
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		update_user_meta( $user_id, $meta_key, $identity['external_id'] );

		return get_user_by( 'id', $user_id );
	}

	private function identity_meta_key( string $provider ): string {
		return '_yp_social_' . $provider . '_id';
	}

	/**
	 * A read-only "Connected Accounts" section on the native WordPress
	 * Profile/Edit User screen (direct request, after the owner logged
	 * in via Google and asked how to tell) — reads the same
	 * `_yp_social_{provider}_id` meta `find_or_create_user()` writes,
	 * same idea as every other status indicator already in this
	 * codebase (e.g. the Materials list's In Stock/Out of Stock pill).
	 * Fires on both `show_user_profile` (your own profile) and
	 * `edit_user_profile` (an admin viewing someone else's) — WordPress
	 * only ever fires the one that applies to the screen being viewed.
	 */
	public function render_profile_section( \WP_User $user ): void {
		$connected = [];
		foreach ( [ 'google', 'discord', 'apple' ] as $provider ) {
			if ( get_user_meta( $user->ID, $this->identity_meta_key( $provider ), true ) ) {
				$connected[] = $this->provider_config( $provider )['label'];
			}
		}
		?>
		<h2><?php esc_html_e( 'Connected Accounts', 'yeffoprint-core' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><?php esc_html_e( 'Social Login', 'yeffoprint-core' ); ?></th>
				<td>
					<?php if ( $connected ) : ?>
						<p>
							<?php
							echo esc_html( sprintf(
								/* translators: %s: comma-separated provider names, e.g. "Google, Discord" */
								__( 'Signed in with: %s', 'yeffoprint-core' ),
								implode( ', ', $connected )
							) );
							?>
						</p>
						<p class="description"><?php esc_html_e( 'This account can be logged into with this password or any connected provider above.', 'yeffoprint-core' ); ?></p>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'No social account connected — this user logs in with a password only.', 'yeffoprint-core' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	private function split_name( string $name ): array {
		$name = trim( $name );
		if ( '' === $name ) {
			return [ '', '' ];
		}
		$parts = explode( ' ', $name, 2 );
		return [ $parts[0], $parts[1] ?? '' ];
	}

	private function log_in( \WP_User $user ): void {
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true );
		do_action( 'wp_login', $user->user_login, $user );
	}

	private function account_url(): string {
		return function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/my-account/' );
	}

	private function fail( string $message ): void {
		wc_add_notice( $message, 'error' );
		wp_safe_redirect( $this->account_url() );
		exit;
	}

	/** @return string[] Provider slugs both turned on and carrying a real Client ID — never a dead button pointing at an unconfigured provider. */
	private function configured_providers(): array {
		$providers = [];
		foreach ( [ 'google', 'discord', 'apple' ] as $provider ) {
			$config = $this->provider_config( $provider );
			if ( get_option( $config['enabled_opt'] ) && '' !== (string) get_option( $config['client_id_opt'] ) ) {
				$providers[] = $provider;
			}
		}
		return $providers;
	}

	/**
	 * Appended right before the login form's submit button
	 * (`woocommerce_login_form_end` — the shared template both
	 * `/my-account/` and checkout's "returning customer" toggle render,
	 * so this covers both automatically on a stock WooCommerce login
	 * template).
	 */
	public function render_login_buttons(): void {
		echo $this->buttons_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- buttons_html() already escapes every value it interpolates.
	}

	/**
	 * Returns '' when no provider is configured — every caller treats
	 * that as "nothing to show." `$redirect_to`, when passed explicitly
	 * (render_wp_login_buttons() passes wp-login.php's own `redirect_to`
	 * query arg), always wins; left null, it falls back to the
	 * WooCommerce-context guess below.
	 */
	private function buttons_html( ?string $redirect_to = null ): string {
		$providers = $this->configured_providers();
		if ( ! $providers ) {
			return '';
		}

		if ( null === $redirect_to ) {
			// A guest who opened this form from checkout's "returning
			// customer?" toggle should land back on checkout, not My
			// Account, once logged in — otherwise they'd have to
			// re-navigate to finish the order they were already partway
			// through.
			$redirect_to = ( function_exists( 'is_checkout' ) && is_checkout() && function_exists( 'wc_get_checkout_url' ) )
				? wc_get_checkout_url()
				: '';
		}

		ob_start();
		?>
		<div class="yp-social-login">
			<p class="yp-social-login__divider"><?php esc_html_e( 'or continue with', 'yeffoprint-core' ); ?></p>
			<div class="yp-social-login__buttons">
				<?php foreach ( $providers as $provider ) :
					$url = add_query_arg( array_filter( [
						'yp-oauth'    => $provider,
						'redirect_to' => $redirect_to,
					] ), home_url( '/' ) );
					?>
					<a class="yp-social-login__button yp-social-login__button--<?php echo esc_attr( $provider ); ?>" href="<?php echo esc_url( $url ); ?>">
						<?php $this->render_icon( $provider ); ?>
						<?php echo esc_html( sprintf(
							/* translators: %s: provider name, e.g. "Google" */
							__( 'Continue with %s', 'yeffoprint-core' ),
							$this->provider_config( $provider )['label']
						) ); ?>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The resilience fallback (see this class's own docblock): hands the
	 * browser the exact markup `render_login_buttons()` would have
	 * echoed, and a tiny script appends it to whatever login form it
	 * finds by WooCommerce's own standard `.woocommerce-form-login`
	 * class — independent of `woocommerce_login_form_end` actually
	 * firing on this particular install's template. Only enqueued on
	 * pages that could plausibly show a login form, and only once
	 * there's actually something configured to show.
	 */
	public function enqueue_fallback_script(): void {
		if ( ! function_exists( 'is_account_page' ) || ! ( is_account_page() || is_checkout() ) ) {
			return;
		}

		$html = $this->buttons_html();
		if ( '' === $html ) {
			return;
		}

		$path = 'assets/frontend/social-login-inject.js';
		wp_enqueue_script(
			'yeffoprint-social-login-inject',
			YEFFOPRINT_CORE_URL . $path,
			[],
			yeffoprint_core_asset_version( $path ),
			true
		);

		wp_localize_script( 'yeffoprint-social-login-inject', 'yeffoprintSocialLogin', [
			'html' => $html,
		] );
	}

	/**
	 * WordPress core's own `wp-login.php` — this store's actual login
	 * flow (direct report), and a different screen entirely from
	 * WooCommerce's account page: built into WP core rather than a
	 * theme-overridable template, so `login_form` fires reliably
	 * regardless of any WooCommerce template quirks on this install.
	 * Reuses wp-login.php's own `redirect_to` query arg (the same one
	 * every normal username/password login on this page already
	 * respects) rather than guessing at a WooCommerce context that
	 * doesn't apply here.
	 */
	public function render_wp_login_buttons(): void {
		$redirect_to = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';
		$redirect_to = wp_validate_redirect( $redirect_to, '' );

		echo $this->buttons_html( $redirect_to ); // phpcs:ignore WordPress.Security.EscapeOutput -- buttons_html() already escapes every value it interpolates.
	}

	/** Fixed, hand-authored glyphs — no external icon font/CDN, same reasoning as every other inline SVG in this plugin (e.g. class-account-endpoints.php's proof-card icon). */
	private function render_icon( string $provider ): void {
		if ( 'google' === $provider ) {
			echo '<svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true" focusable="false"><path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.92c1.7-1.57 2.68-3.88 2.68-6.62Z"/><path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.92-2.26c-.81.54-1.84.86-3.04.86-2.34 0-4.32-1.58-5.03-3.7H.96v2.33A9 9 0 0 0 9 18Z"/><path fill="#FBBC05" d="M3.97 10.72A5.4 5.4 0 0 1 3.68 9c0-.6.1-1.18.29-1.72V4.95H.96A9 9 0 0 0 0 9c0 1.45.35 2.83.96 4.05l3.01-2.33Z"/><path fill="#EA4335" d="M9 3.58c1.32 0 2.51.46 3.44 1.35l2.59-2.59C13.46.89 11.43 0 9 0A9 9 0 0 0 .96 4.95l3.01 2.33C4.68 5.16 6.66 3.58 9 3.58Z"/></svg>';
			return;
		}

		if ( 'discord' === $provider ) {
			echo '<svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true" focusable="false"><path fill="#5865F2" d="M15.24 3.6a13.7 13.7 0 0 0-3.38-1.05.05.05 0 0 0-.06.03c-.14.26-.31.6-.42.87a12.65 12.65 0 0 0-3.76 0 8.6 8.6 0 0 0-.43-.87.05.05 0 0 0-.06-.03c-1.17.2-2.3.55-3.38 1.05a.05.05 0 0 0-.02.02C1.6 6.86 1.06 10 1.32 13.1a.06.06 0 0 0 .02.04 13.8 13.8 0 0 0 4.15 2.1.05.05 0 0 0 .06-.02c.32-.44.6-.9.85-1.39a.05.05 0 0 0-.03-.08 9.1 9.1 0 0 1-1.3-.62.05.05 0 0 1 0-.09c.09-.07.17-.14.26-.2a.05.05 0 0 1 .05-.01c2.73 1.25 5.68 1.25 8.38 0a.05.05 0 0 1 .05 0c.09.07.17.14.26.21a.05.05 0 0 1 0 .09c-.42.24-.85.45-1.3.62a.05.05 0 0 0-.03.08c.25.49.54.95.85 1.39a.05.05 0 0 0 .06.02 13.75 13.75 0 0 0 4.16-2.1.05.05 0 0 0 .02-.03c.32-3.59-.53-6.7-2.22-9.47a.04.04 0 0 0-.02-.02ZM6.68 11.2c-.82 0-1.5-.76-1.5-1.68 0-.93.66-1.69 1.5-1.69.85 0 1.52.77 1.5 1.69 0 .92-.66 1.68-1.5 1.68Zm4.65 0c-.82 0-1.5-.76-1.5-1.68 0-.93.66-1.69 1.5-1.69.85 0 1.51.77 1.5 1.69 0 .92-.65 1.68-1.5 1.68Z"/></svg>';
			return;
		}

		if ( 'apple' === $provider ) {
			echo '<svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true" focusable="false"><path fill="#000" d="M12.2 9.4c0-2.06 1.68-3.05 1.76-3.1-.96-1.4-2.45-1.6-2.98-1.62-1.27-.1-2.48.75-3.13.75-.65 0-1.65-.73-2.72-.71-1.4.02-2.7.82-3.42 2.08-1.46 2.53-.37 6.27 1.05 8.32.7 1 1.52 2.13 2.61 2.09 1.05-.04 1.44-.68 2.71-.68 1.26 0 1.61.68 2.72.65 1.13-.02 1.84-1.02 2.53-2.03.8-1.15 1.13-2.27 1.14-2.33-.02-.01-2.2-.84-2.22-3.35Zm-2.08-6.15c.58-.7.97-1.67.86-2.65-.83.04-1.83.56-2.43 1.25-.54.62-1 1.61-.88 2.56.92.07 1.87-.47 2.45-1.16Z"/></svg>';
		}
	}
}
