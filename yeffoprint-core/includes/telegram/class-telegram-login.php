<?php
/**
 * "Log in with Telegram" on the storefront's login form — Telegram's
 * own official Login Widget (direct request), a sibling to
 * class-social-login.php's Google/Discord/Apple buttons but not built
 * the same way, because Telegram's mechanism is structurally
 * different from OAuth 2.0's Authorization Code flow that class is
 * built entirely around:
 *
 *  - There's no "start" redirect this code has to issue — Telegram's
 *    own widget script (embedded directly on the page) *is* the
 *    button; tapping it opens Telegram's own popup/app, and on
 *    success Telegram itself redirects the browser straight to
 *    `data-auth-url` with the identity data as query params. No
 *    client_id/authorize_url/token exchange at all.
 *  - Identity arrives signed, not fetched: `id, first_name, last_name,
 *    username, photo_url, auth_date, hash` come back directly in that
 *    redirect's query string, authenticated by an HMAC-SHA256 the bot
 *    token itself is the key for (verify_and_get_data() below) —
 *    Telegram's documented algorithm, not something this class
 *    invented. There's no separate userinfo endpoint to call.
 *  - No client_id/client_secret to register anywhere — it reuses the
 *    exact bot token already configured for the bot itself
 *    (class-telegram-settings.php). The one external setup step is on
 *    Telegram's side: the bot's domain must be authorized for the
 *    widget via @BotFather's `/setdomain`, or Telegram refuses to
 *    render/authenticate it at all — same category of one-time
 *    external registration as "add this redirect URI in Google's
 *    developer console" for the OAuth providers, just done through a
 *    chat with a bot instead of a web console.
 *
 * Given how different the actual mechanics are, this intentionally
 * doesn't try to wedge Telegram into class-social-login.php's
 * OAuth-shaped abstraction (provider_config()/exchange_tokens()/etc.)
 * — same "give it its own class rather than force-fit the shape"
 * reasoning already used throughout this plugin's Telegram bot
 * integration (one class per concern). It still mirrors that class's
 * externally-visible behavior as closely as it can: the same
 * `woocommerce_login_form_end`/`login_form` hook points, the same
 * `log_in()` shape (wp_set_current_user + wp_set_auth_cookie + the
 * wp_login action), and the same "returning identity logs straight
 * in, unrecognized one creates a real customer via wc_create_new_
 * customer()" account-matching philosophy.
 *
 * The one thing that genuinely has no OAuth-provider equivalent:
 * Telegram never hands back an email address at all. A returning
 * customer is unaffected (find_or_create_user() below resolves them
 * via class-telegram-account-link.php's existing chat_id↔user_id
 * mapping — the same numeric id a private Telegram chat's chat_id
 * already is, so no new identity concept was even needed for that
 * case). A brand-new sign-in gets a syntactically-valid, clearly-
 * fake `.invalid`-domain placeholder address (YeffoPrint_Telegram_
 * Account_Link::placeholder_email()) rather than either refusing to
 * create the account or silently leaving a real feature (order
 * confirmations, proof-approval emails, rewards) pointed at nothing —
 * class-account-endpoints.php then nags and gates checkout on that
 * account until a real one is saved. See that class's own docblock
 * for the full flow.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Telegram_Login {

	/** How stale an auth payload can be before it's refused outright — Telegram's own recommended replay mitigation for the redirect-based widget mode. */
	private const MAX_AUTH_AGE = DAY_IN_SECONDS;

	/** A used hash is never accepted twice — same window as MAX_AUTH_AGE, since that's the longest a hash could still otherwise pass the freshness check above. Closes the practical replay risk of this callback URL sitting in browser history/server logs (Telegram's own documented caveat about the redirect-mode widget, versus its JS-callback mode which never puts the data in a URL at all). */
	private const USED_HASH_TRANSIENT_PREFIX = 'yp_telegram_login_used_';

	public function __construct() {
		add_action( 'template_redirect', [ $this, 'maybe_handle_callback' ] );
		add_action( 'woocommerce_login_form_end', [ $this, 'render_login_widget' ], 11 ); // After class-social-login.php's own buttons (priority 10, default).
		add_action( 'login_form', [ $this, 'render_wp_login_widget' ], 11 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_fallback_script' ] );
	}

	public static function callback_url(): string {
		return add_query_arg( 'yp-telegram-login-callback', '1', home_url( '/' ) );
	}

	public static function is_available(): bool {
		return (bool) get_option( YeffoPrint_Admin_Menu::TELEGRAM_LOGIN_ENABLED_OPTION, false )
			&& '' !== YeffoPrint_Telegram_Settings::get_bot_token()
			&& '' !== YeffoPrint_Telegram_Account_Link::bot_username();
	}

	public function maybe_handle_callback(): void {
		if ( isset( $_GET['yp-telegram-login-callback'] ) ) {
			$this->handle_callback();
		}
	}

	private function handle_callback(): void {
		if ( ! self::is_available() ) {
			$this->fail( __( 'This login option is not available right now.', 'yeffoprint-core' ) );
		}

		$data = $this->verify_and_get_data();
		if ( ! $data ) {
			$this->fail( __( "We couldn't verify that Telegram login — please try again.", 'yeffoprint-core' ) );
		}

		$user = $this->find_or_create_user( $data );
		if ( is_wp_error( $user ) ) {
			$this->fail( $user->get_error_message() );
		}

		$this->log_in( $user );

		$redirect_to = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';
		$redirect_to = wp_validate_redirect( $redirect_to, '' );

		wp_safe_redirect( $redirect_to ?: $this->account_url() );
		exit;
	}

	/**
	 * Telegram's own documented verification algorithm: every field it
	 * sent except `hash`, sorted by key, joined as `key=value\n...`,
	 * HMAC-SHA256'd with SHA256(bot_token) as the raw-bytes key — must
	 * match `hash` exactly. Reads $_GET via wp_unslash() only, never
	 * sanitize_text_field() — the hash is computed over exactly what
	 * Telegram sent, and sanitizing first (which does more than
	 * unslash: strips tags, collapses whitespace) would make a
	 * perfectly legitimate request's hash silently never match.
	 * Sanitizing happens afterward, only once verification has already
	 * succeeded, when the values are actually used below.
	 *
	 * @return array{id:int, first_name:string, last_name:string}|null
	 */
	private function verify_and_get_data(): ?array {
		$fields = [ 'id', 'first_name', 'last_name', 'username', 'photo_url', 'auth_date', 'hash' ];
		$data   = [];

		foreach ( $fields as $field ) {
			if ( isset( $_GET[ $field ] ) ) {
				$data[ $field ] = wp_unslash( $_GET[ $field ] );
			}
		}

		if ( empty( $data['id'] ) || empty( $data['auth_date'] ) || empty( $data['hash'] ) ) {
			return null;
		}

		$hash = (string) $data['hash'];
		unset( $data['hash'] );

		ksort( $data );
		$pairs = [];
		foreach ( $data as $key => $value ) {
			$pairs[] = $key . '=' . $value;
		}
		$check_string = implode( "\n", $pairs );

		$token = YeffoPrint_Telegram_Settings::get_bot_token();
		if ( '' === $token ) {
			return null;
		}

		$secret_key     = hash( 'sha256', $token, true );
		$computed_hash  = hash_hmac( 'sha256', $check_string, $secret_key );

		if ( ! hash_equals( $computed_hash, $hash ) ) {
			return null;
		}

		if ( ( time() - (int) $data['auth_date'] ) > self::MAX_AUTH_AGE ) {
			return null;
		}

		$used_key = self::USED_HASH_TRANSIENT_PREFIX . md5( $hash );
		if ( get_transient( $used_key ) ) {
			return null; // Already logged in with this exact callback once — refuse a replay.
		}
		set_transient( $used_key, 1, self::MAX_AUTH_AGE );

		return [
			'id'         => (int) $data['id'],
			'first_name' => sanitize_text_field( (string) ( $data['first_name'] ?? '' ) ),
			'last_name'  => sanitize_text_field( (string) ( $data['last_name'] ?? '' ) ),
		];
	}

	/**
	 * @param array{id:int, first_name:string, last_name:string} $data
	 * @return \WP_User|\WP_Error
	 */
	private function find_or_create_user( array $data ) {
		$existing_user_id = YeffoPrint_Telegram_Account_Link::get_user_id_for_chat( $data['id'] );
		if ( $existing_user_id ) {
			return get_user_by( 'id', $existing_user_id );
		}

		// Brand-new sign-in — no real email to create a normal customer
		// with (see this class's own docblock for why), so this one
		// gets a placeholder that class-account-endpoints.php then
		// requires be replaced with a real address before checkout.
		$user_id = wc_create_new_customer(
			YeffoPrint_Telegram_Account_Link::placeholder_email( $data['id'] ),
			'',
			wp_generate_password( 32, true, true ),
			[
				'first_name' => $data['first_name'],
				'last_name'  => $data['last_name'],
			]
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		YeffoPrint_Telegram_Account_Link::link( $user_id, $data['id'] );
		YeffoPrint_Telegram_Account_Link::mark_placeholder_email( $user_id );

		return get_user_by( 'id', $user_id );
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

	public function render_login_widget(): void {
		$redirect_to = ( function_exists( 'is_checkout' ) && is_checkout() && function_exists( 'wc_get_checkout_url' ) )
			? wc_get_checkout_url()
			: '';

		echo $this->widget_html( $redirect_to ); // phpcs:ignore WordPress.Security.EscapeOutput -- widget_html() already escapes every value it interpolates.
	}

	public function render_wp_login_widget(): void {
		$redirect_to = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';
		$redirect_to = wp_validate_redirect( $redirect_to, '' );

		echo $this->widget_html( $redirect_to ); // phpcs:ignore WordPress.Security.EscapeOutput -- widget_html() already escapes every value it interpolates.
	}

	/**
	 * Telegram's own widget script — an iframe it injects itself, not
	 * markup this theme styles, so it deliberately doesn't share
	 * class-social-login.php's `.yp-social-login__button` treatment; a
	 * plain labeled block of its own is the honest representation of
	 * "this button looks and behaves however Telegram wants it to."
	 */
	private function widget_html( string $redirect_to ): string {
		if ( ! self::is_available() ) {
			return '';
		}

		$auth_url = $redirect_to ? add_query_arg( 'redirect_to', $redirect_to, self::callback_url() ) : self::callback_url();

		ob_start();
		?>
		<div class="yp-telegram-login">
			<p class="yp-telegram-login__divider"><?php esc_html_e( 'or log in with Telegram', 'yeffoprint-core' ); ?></p>
			<script async
				src="https://telegram.org/js/telegram-widget.js?22"
				data-telegram-login="<?php echo esc_attr( YeffoPrint_Telegram_Account_Link::bot_username() ); ?>"
				data-size="large"
				data-radius="8"
				data-auth-url="<?php echo esc_url( $auth_url ); ?>"
				data-request-access="write"
			></script>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Same resilience fallback class-social-login.php's own docblock
	 * documents (a real, previously-hit production bug: a pre-existing
	 * login template on this install that doesn't fire `woocommerce_
	 * login_form_end`) — hands the browser the exact markup
	 * render_login_widget() would have echoed, and telegram-login-inject.js
	 * appends it to whatever login form it finds via WooCommerce's own
	 * `.woocommerce-form-login` class, independent of that hook firing.
	 * A dedicated script rather than reusing social-login-inject.js: the
	 * injected markup here includes Telegram's own `<script src=...>`
	 * widget tag, which (unlike a plain link) needs to be specifically
	 * recreated to actually execute once inserted via innerHTML — see
	 * that script's own docblock.
	 */
	public function enqueue_fallback_script(): void {
		if ( ! function_exists( 'is_account_page' ) || ! ( is_account_page() || is_checkout() ) ) {
			return;
		}

		$html = $this->widget_html( '' );
		if ( '' === $html ) {
			return;
		}

		$path = 'assets/frontend/telegram-login-inject.js';
		wp_enqueue_script(
			'yeffoprint-telegram-login-inject',
			YEFFOPRINT_CORE_URL . $path,
			[],
			yeffoprint_core_asset_version( $path ),
			true
		);

		wp_localize_script( 'yeffoprint-telegram-login-inject', 'yeffoprintTelegramLogin', [
			'html' => $html,
		] );
	}
}
