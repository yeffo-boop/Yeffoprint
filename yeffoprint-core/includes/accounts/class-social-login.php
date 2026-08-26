<?php
/**
 * "Continue with Google" / "Continue with Discord" on the storefront's
 * login form. Direct request: "allow users to login with Google/Apple/
 * Discord" — built for Google and Discord now (a free, few-minutes
 * developer-app registration each); Apple is a deliberately separate
 * follow-up (paid Apple Developer Program membership, domain
 * verification, and a private key that has to be re-signed into a new
 * client secret every 6 months — a meaningfully bigger lift).
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
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Social_Login {

	private const STATE_TRANSIENT_PREFIX = 'yp_oauth_';
	private const STATE_TTL              = 10 * MINUTE_IN_SECONDS;

	public function __construct() {
		add_action( 'template_redirect', [ $this, 'maybe_handle_request' ] );
		add_action( 'woocommerce_login_form_end', [ $this, 'render_login_buttons' ] );
	}

	/** The fixed URL each provider redirects back to — register this exact value as the app's Redirect URI in its developer console. Static + public: class-admin-menu.php's Settings screen shows it without needing an instance. */
	public static function callback_url( string $provider ): string {
		return add_query_arg( 'yp-oauth-callback', $provider, home_url( '/' ) );
	}

	public function maybe_handle_request(): void {
		if ( isset( $_GET['yp-oauth'] ) ) {
			$this->start( sanitize_key( wp_unslash( $_GET['yp-oauth'] ) ) );
		} elseif ( isset( $_GET['yp-oauth-callback'] ) ) {
			$this->callback( sanitize_key( wp_unslash( $_GET['yp-oauth-callback'] ) ) );
		}
	}

	/** @return array{label:string, enabled_opt:string, client_id_opt:string, client_secret_opt:string, authorize_url:string, token_url:string, userinfo_url:string, scope:string}|null */
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

		$authorize_url = add_query_arg( [
			'client_id'     => $client_id,
			'redirect_uri'  => self::callback_url( $provider ),
			'response_type' => 'code',
			'scope'         => $config['scope'],
			'state'         => $state,
		], $config['authorize_url'] );

		wp_redirect( esc_url_raw( $authorize_url ) ); // phpcs:ignore WordPress.Security.SafeRedirect -- an external provider's own authorize URL, not user input.
		exit;
	}

	private function callback( string $provider ): void {
		$config = $this->provider_config( $provider );
		if ( ! $config || ! get_option( $config['enabled_opt'] ) ) {
			$this->fail( __( 'This login option is not available right now.', 'yeffoprint-core' ) );
		}

		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$stashed = $state ? get_transient( self::STATE_TRANSIENT_PREFIX . $state ) : false;
		if ( false === $stashed ) {
			$this->fail( __( 'Your login attempt expired or was invalid — please try again.', 'yeffoprint-core' ) );
		}
		delete_transient( self::STATE_TRANSIENT_PREFIX . $state ); // One-time use, win or lose.

		if ( isset( $_GET['error'] ) ) {
			// Declined on the provider's own consent screen — not an error worth alarming the customer about.
			wp_safe_redirect( $this->account_url() );
			exit;
		}

		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		if ( '' === $code ) {
			$this->fail( __( 'Something went wrong finishing that login — please try again.', 'yeffoprint-core' ) );
		}

		$client_id     = (string) get_option( $config['client_id_opt'] );
		$client_secret = (string) get_option( $config['client_secret_opt'] );
		if ( '' === $client_id || '' === $client_secret ) {
			$this->fail( __( 'This login option is not fully set up yet.', 'yeffoprint-core' ) );
		}

		$access_token = $this->exchange_code( $provider, $config, $client_id, $client_secret, $code );
		if ( ! $access_token ) {
			$this->fail( __( "We couldn't complete that login — please try again.", 'yeffoprint-core' ) );
		}

		$raw_profile = $this->fetch_userinfo( $config, $access_token );
		$identity    = $raw_profile ? $this->normalize_userinfo( $provider, $raw_profile ) : null;

		if ( ! $identity ) {
			$this->fail(
				sprintf(
					/* translators: %s: provider name, e.g. "Google" */
					__( "We couldn't verify your account — make sure your email is verified with %s and try again.", 'yeffoprint-core' ),
					$config['label']
				)
			);
		}

		$user = $this->find_or_create_user( $provider, $identity );
		if ( is_wp_error( $user ) ) {
			$this->fail( $user->get_error_message() );
		}

		$this->log_in( $user );

		wp_safe_redirect( $stashed['redirect_to'] ?: $this->account_url() );
		exit;
	}

	private function exchange_code( string $provider, array $config, string $client_id, string $client_secret, string $code ): ?string {
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
		return ( is_array( $body ) && ! empty( $body['access_token'] ) ) ? (string) $body['access_token'] : null;
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

	/**
	 * Appended right before the login form's submit button
	 * (`woocommerce_login_form_end` — the shared template both
	 * `/my-account/` and checkout's "returning customer" toggle render,
	 * so this covers both automatically). Only ever shows a provider
	 * whose admin has both turned it on *and* pasted in a real Client
	 * ID — never a dead button pointing at an unconfigured provider.
	 */
	public function render_login_buttons(): void {
		$providers = [];
		foreach ( [ 'google', 'discord' ] as $provider ) {
			$config = $this->provider_config( $provider );
			if ( get_option( $config['enabled_opt'] ) && '' !== (string) get_option( $config['client_id_opt'] ) ) {
				$providers[] = $provider;
			}
		}

		if ( ! $providers ) {
			return;
		}

		// A guest who opened this form from checkout's "returning customer?"
		// toggle should land back on checkout, not My Account, once logged
		// in — otherwise they'd have to re-navigate to finish the order
		// they were already partway through.
		$redirect_to = ( function_exists( 'is_checkout' ) && is_checkout() && function_exists( 'wc_get_checkout_url' ) )
			? wc_get_checkout_url()
			: '';
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
	}

	/** Fixed, hand-authored glyphs — no external icon font/CDN, same reasoning as every other inline SVG in this plugin (e.g. class-account-endpoints.php's proof-card icon). */
	private function render_icon( string $provider ): void {
		if ( 'google' === $provider ) {
			echo '<svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true" focusable="false"><path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.92c1.7-1.57 2.68-3.88 2.68-6.62Z"/><path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.92-2.26c-.81.54-1.84.86-3.04.86-2.34 0-4.32-1.58-5.03-3.7H.96v2.33A9 9 0 0 0 9 18Z"/><path fill="#FBBC05" d="M3.97 10.72A5.4 5.4 0 0 1 3.68 9c0-.6.1-1.18.29-1.72V4.95H.96A9 9 0 0 0 0 9c0 1.45.35 2.83.96 4.05l3.01-2.33Z"/><path fill="#EA4335" d="M9 3.58c1.32 0 2.51.46 3.44 1.35l2.59-2.59C13.46.89 11.43 0 9 0A9 9 0 0 0 .96 4.95l3.01 2.33C4.68 5.16 6.66 3.58 9 3.58Z"/></svg>';
			return;
		}

		if ( 'discord' === $provider ) {
			echo '<svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true" focusable="false"><path fill="#5865F2" d="M15.24 3.6a13.7 13.7 0 0 0-3.38-1.05.05.05 0 0 0-.06.03c-.14.26-.31.6-.42.87a12.65 12.65 0 0 0-3.76 0 8.6 8.6 0 0 0-.43-.87.05.05 0 0 0-.06-.03c-1.17.2-2.3.55-3.38 1.05a.05.05 0 0 0-.02.02C1.6 6.86 1.06 10 1.32 13.1a.06.06 0 0 0 .02.04 13.8 13.8 0 0 0 4.15 2.1.05.05 0 0 0 .06-.02c.32-.44.6-.9.85-1.39a.05.05 0 0 0-.03-.08 9.1 9.1 0 0 1-1.3-.62.05.05 0 0 1 0-.09c.09-.07.17-.14.26-.2a.05.05 0 0 1 .05-.01c2.73 1.25 5.68 1.25 8.38 0a.05.05 0 0 1 .05 0c.09.07.17.14.26.21a.05.05 0 0 1 0 .09c-.42.24-.85.45-1.3.62a.05.05 0 0 0-.03.08c.25.49.54.95.85 1.39a.05.05 0 0 0 .06.02 13.75 13.75 0 0 0 4.16-2.1.05.05 0 0 0 .02-.03c.32-3.59-.53-6.7-2.22-9.47a.04.04 0 0 0-.02-.02ZM6.68 11.2c-.82 0-1.5-.76-1.5-1.68 0-.93.66-1.69 1.5-1.69.85 0 1.52.77 1.5 1.69 0 .92-.66 1.68-1.5 1.68Zm4.65 0c-.82 0-1.5-.76-1.5-1.68 0-.93.66-1.69 1.5-1.69.85 0 1.51.77 1.5 1.69 0 .92-.65 1.68-1.5 1.68Z"/></svg>';
		}
	}
}
