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
 * customer is unaffected (handle_callback() below resolves them via
 * class-telegram-account-link.php's existing chat_id↔user_id mapping —
 * the same numeric id a private Telegram chat's chat_id already is, so
 * no new identity concept was even needed for that case). A brand-new
 * sign-in doesn't get an account yet at all: the verified identity is
 * stashed in a short-lived transient and the browser is sent to a
 * one-field "what's your email" step (handle_email_step() below,
 * `wp-login.php?action=yp_telegram_email`) — the standard WordPress
 * mechanism for a custom wp-login.php sub-page, the same `login_form_
 * {action}` hook core's own lostpassword/register actions use.
 * wc_create_new_customer() only ever runs once a real, available email
 * is actually submitted there, so no account with a fake address is
 * ever created in the first place.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Telegram_Login {

	/** How stale an auth payload can be before it's refused outright — Telegram's own recommended replay mitigation for the redirect-based widget mode. */
	private const MAX_AUTH_AGE = DAY_IN_SECONDS;

	/** A used hash is never accepted twice — same window as MAX_AUTH_AGE, since that's the longest a hash could still otherwise pass the freshness check above. Closes the practical replay risk of this callback URL sitting in browser history/server logs (Telegram's own documented caveat about the redirect-mode widget, versus its JS-callback mode which never puts the data in a URL at all). */
	private const USED_HASH_TRANSIENT_PREFIX = 'yp_telegram_login_used_';

	/**
	 * Holds a brand-new sign-in's verified Telegram identity between
	 * handle_callback() and handle_email_step() below — nothing about
	 * this customer is written to the database (no account exists yet)
	 * until a real email actually clears both checks in the latter.
	 * Same 15-minute window as class-telegram-account-link.php's own
	 * /link code transient — long enough to type an email, short enough
	 * that an abandoned sign-up doesn't linger.
	 */
	private const PENDING_SIGNUP_TRANSIENT_PREFIX = 'yp_telegram_pending_signup_';
	private const PENDING_SIGNUP_TTL              = 15 * MINUTE_IN_SECONDS;

	private const EMAIL_NONCE_ACTION = 'yeffoprint_telegram_login_email';
	private const EMAIL_NONCE_NAME   = 'yp_telegram_email_nonce';

	public function __construct() {
		add_action( 'template_redirect', [ $this, 'maybe_handle_callback' ] );
		add_action( 'woocommerce_login_form_end', [ $this, 'render_login_widget' ], 11 ); // After class-social-login.php's own buttons (priority 10, default).
		add_action( 'login_form', [ $this, 'render_wp_login_widget' ], 11 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_fallback_script' ] );
		add_action( 'login_form_yp_telegram_email', [ $this, 'handle_email_step' ] );
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

		$redirect_to = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';
		$redirect_to = wp_validate_redirect( $redirect_to, '' );

		$existing_user_id = YeffoPrint_Telegram_Account_Link::get_user_id_for_chat( $data['id'] );
		if ( $existing_user_id ) {
			$user = get_user_by( 'id', $existing_user_id );
			if ( ! $user ) {
				$this->fail( __( "We couldn't verify that Telegram login — please try again.", 'yeffoprint-core' ) );
			}

			$this->log_in( $user );
			wp_safe_redirect( $redirect_to ?: $this->account_url() );
			exit;
		}

		// Brand-new sign-in — no account exists for this Telegram id yet,
		// and none is created here either: Telegram never hands back an
		// email, so the verified identity is stashed and the browser is
		// sent on to handle_email_step() below, which is the only place
		// wc_create_new_customer() for a Telegram sign-in actually runs.
		$token = wp_generate_password( 32, false, false );
		set_transient( self::PENDING_SIGNUP_TRANSIENT_PREFIX . $token, [
			'telegram_id' => $data['id'],
			'first_name'  => $data['first_name'],
			'last_name'   => $data['last_name'],
			'redirect_to' => $redirect_to,
		], self::PENDING_SIGNUP_TTL );

		wp_safe_redirect( add_query_arg( [
			'action' => 'yp_telegram_email',
			't'      => $token,
		], wp_login_url() ) );
		exit;
	}

	/**
	 * The one-field "what's your email" step a brand-new Telegram sign-in
	 * lands on — a wp-login.php sub-page (`?action=yp_telegram_email`),
	 * the standard WordPress mechanism for adding a custom step to that
	 * flow (the same `login_form_{action}` hook core's own lostpassword/
	 * register actions use), rather than a bespoke page/template of this
	 * plugin's own — it inherits wp-login.php's existing branding
	 * (functions.php's login.css/login_header enqueue) for free, and
	 * this site's own login consolidation already treats wp-login.php as
	 * the one login surface a visitor ever lands on.
	 *
	 * Only ever reached via the redirect at the end of handle_callback()
	 * above — never linked to directly — so a missing/expired/foreign
	 * token just shows a plain "try again" message rather than doing
	 * anything harmful.
	 */
	public function handle_email_step(): void {
		$token   = isset( $_REQUEST['t'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['t'] ) ) : '';
		$pending = $token ? get_transient( self::PENDING_SIGNUP_TRANSIENT_PREFIX . $token ) : false;

		if ( ! is_array( $pending ) ) {
			login_header(
				__( 'Log in with Telegram', 'yeffoprint-core' ),
				'',
				new \WP_Error( 'yp_telegram_expired', __( 'That sign-up link has expired. Please try "Log in with Telegram" again.', 'yeffoprint-core' ) )
			);
			login_footer();
			exit;
		}

		$error = '';

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['yp_telegram_email_submit'] ) ) {
			if ( ! isset( $_POST[ self::EMAIL_NONCE_NAME ] ) || ! wp_verify_nonce( wp_unslash( $_POST[ self::EMAIL_NONCE_NAME ] ), self::EMAIL_NONCE_ACTION ) ) {
				$error = __( 'Your session expired — please try again.', 'yeffoprint-core' );
			} else {
				$email = sanitize_email( wp_unslash( $_POST['yp_email'] ?? '' ) );

				if ( ! is_email( $email ) ) {
					$error = __( 'Please enter a valid email address.', 'yeffoprint-core' );
				} elseif ( email_exists( $email ) ) {
					$error = __( 'That email is already registered to an account. Log in to that account instead, then connect Telegram from My Account.', 'yeffoprint-core' );
				} else {
					delete_transient( self::PENDING_SIGNUP_TRANSIENT_PREFIX . $token ); // One-shot, win or lose from here on — same reasoning as the /link code transient.

					$user_id = wc_create_new_customer( $email, '', wp_generate_password( 32, true, true ), [
						'first_name' => $pending['first_name'],
						'last_name'  => $pending['last_name'],
					] );

					if ( is_wp_error( $user_id ) ) {
						$this->fail( $user_id->get_error_message() );
					}

					YeffoPrint_Telegram_Account_Link::link( $user_id, $pending['telegram_id'] );

					$user = get_user_by( 'id', $user_id );
					$this->log_in( $user );

					wp_safe_redirect( $pending['redirect_to'] ?: $this->account_url() );
					exit;
				}
			}
		}

		login_header(
			__( 'One more step', 'yeffoprint-core' ),
			'',
			$error ? new \WP_Error( 'yp_telegram_email', $error ) : null
		);
		?>
		<form name="yp-telegram-email-form" id="yp-telegram-email-form" action="<?php echo esc_url( add_query_arg( [ 'action' => 'yp_telegram_email', 't' => $token ], wp_login_url() ) ); ?>" method="post">
			<p><?php esc_html_e( "Telegram doesn't share an email address with us — enter yours to finish creating your account. We'll use it for order confirmations, proof approvals, and reward updates.", 'yeffoprint-core' ); ?></p>
			<p>
				<label for="yp_email"><?php esc_html_e( 'Email address', 'yeffoprint-core' ); ?></label>
				<input type="email" name="yp_email" id="yp_email" class="input" value="<?php echo esc_attr( wp_unslash( $_POST['yp_email'] ?? '' ) ); ?>" size="20" autocapitalize="off" autocomplete="email" required autofocus />
			</p>
			<?php wp_nonce_field( self::EMAIL_NONCE_ACTION, self::EMAIL_NONCE_NAME ); ?>
			<p class="submit">
				<button type="submit" name="yp_telegram_email_submit" value="1" class="button button-primary button-large"><?php esc_html_e( 'Continue', 'yeffoprint-core' ); ?></button>
			</p>
		</form>
		<p id="nav">
			<a href="<?php echo esc_url( wp_login_url() ); ?>">&larr; <?php esc_html_e( 'Back to login', 'yeffoprint-core' ); ?></a>
		</p>
		<?php
		login_footer( 'yp_email' );
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
