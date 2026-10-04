<?php
/**
 * Customers choose their own password when they sign up, and confirm
 * their email with a link (direct request: "allow users that are
 * registering to just set a password so they don't have to reset it
 * through email? They should still verify their email").
 *
 * Before this, WooCommerce's "Send password setup link" setting meant a
 * new customer got no password field at all, only an emailed "Set your
 * new password" link, and that email doubled as the proof they owned the
 * address. Now:
 *
 * - WooCommerce's generate-password option is forced off, so every
 *   self-service sign-up form shows a password field: My Account's
 *   Register form (also where the Dose Tracker's "Create a free account"
 *   goes), "Create an account" at checkout (block or classic), and the
 *   order confirmation page's create-account box.
 * - An account made from one of those forms gets an unverified flag and a
 *   "Confirm your email" email in place of WooCommerce's New Account
 *   email. Clicking the link clears the flag.
 * - My Account sign-ups are not signed in until they confirm. Checkout and
 *   order-confirmation sign-ups stay signed in for that visit (WooCommerce
 *   needs it to show them their order), but like everyone else they can't
 *   sign in again until they confirm.
 * - Signing in with the right password while unverified fails with a
 *   message and a "Send a new link" link. Resetting the password by email,
 *   or signing in with Google on the same address, also counts as
 *   confirming it.
 *
 * Accounts that existed before this, and accounts made by Google/Telegram
 * sign-in or by staff on Create Order, never get the flag.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Email_Verification {

	private const META        = '_yp_email_verify';
	private const LINK_TTL    = 7 * DAY_IN_SECONDS;
	private const RESEND_WAIT = 2 * MINUTE_IN_SECONDS;
	public const ERROR_CODE   = 'yp_email_unverified';

	/** Self-service sign-up sources wc_create_new_customer() is told about. */
	private const SIGNUP_SOURCES = [ 'store-api', 'delayed-account-creation' ];

	/** @var int[] Accounts flagged during this request. */
	private static array $flagged_now = [];

	public function __construct() {
		add_filter( 'pre_option_woocommerce_registration_generate_password', [ $this, 'customer_picks_password' ] );
		add_action( 'woocommerce_created_customer', [ $this, 'on_created_customer' ], 1, 3 );
		add_filter( 'woocommerce_email_enabled_customer_new_account', [ $this, 'skip_new_account_email' ], 10, 2 );
		add_filter( 'woocommerce_registration_auth_new_customer', [ $this, 'hold_my_account_login' ], 10, 2 );
		add_filter( 'authenticate', [ $this, 'block_unverified' ], 30 );
		add_action( 'password_reset', [ $this, 'on_password_reset' ] );
		add_action( 'init', [ $this, 'handle_links' ] );
		add_action( 'wp', [ $this, 'show_result_notice' ] );
		add_filter( 'login_message', [ $this, 'login_message' ] );
		add_filter( 'register_url', [ $this, 'register_url' ] );
	}

	public function customer_picks_password(): string {
		return 'no';
	}

	public static function is_unverified( int $user_id ): bool {
		return '' !== get_user_meta( $user_id, self::META, true );
	}

	public static function mark_verified( int $user_id ): void {
		delete_user_meta( $user_id, self::META );
	}

	/**
	 * @param int   $customer_id
	 * @param array $data       Customer data passed to wp_insert_user().
	 * @param bool  $generated  Whether WooCommerce made up the password.
	 */
	public function on_created_customer( $customer_id, $data, $generated ): void {
		if ( $generated || ! self::is_self_signup( (array) $data ) ) {
			return;
		}

		self::$flagged_now[] = (int) $customer_id;
		self::send_link( (int) $customer_id );
	}

	private static function is_self_signup( array $data ): bool {
		if ( in_array( $data['source'] ?? '', self::SIGNUP_SOURCES, true ) ) {
			return true; // Block checkout, order confirmation create-account box.
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce already verified this form's nonce before creating the account.
		if ( isset( $_POST['register'], $_POST['woocommerce-register-nonce'] ) ) {
			return true; // My Account Register form.
		}
		// phpcs:enable
		return defined( 'WOOCOMMERCE_CHECKOUT' ) && WOOCOMMERCE_CHECKOUT; // Classic checkout.
	}

	/** @param bool $enabled */
	public function skip_new_account_email( $enabled, $user ) {
		if ( $user instanceof \WP_User && self::is_unverified( $user->ID ) ) {
			return false; // The Confirm your email email replaces it.
		}
		return $enabled;
	}

	/** My Account sign-ups wait for the link before they're signed in. */
	public function hold_my_account_login( $auth, $customer_id ) {
		if ( ! in_array( (int) $customer_id, self::$flagged_now, true ) ) {
			return $auth;
		}

		$user = get_user_by( 'id', $customer_id );
		wc_clear_notices();
		wc_add_notice( sprintf(
			/* translators: %s: email address */
			__( 'Almost done! We sent a link to %s. Click it to confirm your email, then sign in with the password you just chose.', 'yeffoprint-core' ),
			'<strong>' . esc_html( $user ? $user->user_email : '' ) . '</strong>'
		) );
		return false;
	}

	/**
	 * Runs after WordPress has checked the password (priority 20), so the
	 * message only ever shows to someone who knows it.
	 *
	 * @param \WP_User|\WP_Error|null $user
	 */
	public function block_unverified( $user ) {
		if ( ! ( $user instanceof \WP_User ) || ! self::is_unverified( $user->ID ) ) {
			return $user;
		}

		return new \WP_Error( self::ERROR_CODE, sprintf(
			/* translators: 1: email address, 2: resend link URL */
			__( 'Please confirm your email first. We sent a link to %1$s when you signed up. <a href="%2$s">Send a new link</a>', 'yeffoprint-core' ),
			esc_html( $user->user_email ),
			esc_url( self::resend_url( $user->ID ) )
		) );
	}

	/** Getting a reset link by email proves the address too. */
	public function on_password_reset( $user ): void {
		if ( $user instanceof \WP_User ) {
			self::mark_verified( $user->ID );
		}
	}

	public function handle_links(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Links from email; the key / signature is the check.
		if ( isset( $_GET['yp_verify_email'], $_GET['key'] ) ) {
			$this->confirm( absint( $_GET['yp_verify_email'] ), sanitize_text_field( wp_unslash( $_GET['key'] ) ) );
		}
		if ( isset( $_GET['yp_resend_verify'], $_GET['sig'] ) ) {
			$this->resend( absint( $_GET['yp_resend_verify'] ), sanitize_text_field( wp_unslash( $_GET['sig'] ) ) );
		}
		// phpcs:enable
	}

	private function confirm( int $user_id, string $key ): void {
		$state = get_user_meta( $user_id, self::META, true );

		if ( '' === $state ) {
			$result = get_userdata( $user_id ) ? 'confirmed' : 'expired'; // Already confirmed (e.g. clicked twice).
		} elseif ( is_array( $state )
			&& (int) ( $state['expires'] ?? 0 ) > time()
			&& hash_equals( (string) ( $state['hash'] ?? '' ), hash( 'sha256', $key ) ) ) {
			self::mark_verified( $user_id );
			$result = 'confirmed';
		} else {
			$result = 'expired';
		}

		if ( 'confirmed' === $result && get_current_user_id() === $user_id ) {
			wp_safe_redirect( add_query_arg( 'yp_email', 'confirmed', wc_get_page_permalink( 'myaccount' ) ) );
			exit;
		}
		wp_safe_redirect( add_query_arg( 'yp_email', $result, self::login_page_url() ) );
		exit;
	}

	private function resend( int $user_id, string $sig ): void {
		if ( ! hash_equals( self::resend_sig( $user_id ), $sig ) || ! self::is_unverified( $user_id ) ) {
			wp_safe_redirect( self::login_page_url() );
			exit;
		}

		$wait_key = 'yp_verify_resend_' . $user_id;
		if ( ! get_transient( $wait_key ) ) {
			set_transient( $wait_key, 1, self::RESEND_WAIT );
			self::send_link( $user_id );
		}
		wp_safe_redirect( add_query_arg( 'yp_email', 'sent', self::login_page_url() ) );
		exit;
	}

	private static function messages(): array {
		return [
			'confirmed' => [ 'success', __( 'Your email is confirmed. Sign in with the password you chose.', 'yeffoprint-core' ) ],
			'expired'   => [ 'error', __( 'That confirmation link has expired or was replaced by a newer one. Sign in and we’ll offer to send a new one.', 'yeffoprint-core' ) ],
			'sent'      => [ 'notice', __( 'We sent a new confirmation link. Check your email (and spam folder).', 'yeffoprint-core' ) ],
		];
	}

	private static function result_key(): string {
		return isset( $_GET['yp_email'] ) ? sanitize_key( wp_unslash( $_GET['yp_email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
	}

	private static function result_message(): ?array {
		return self::messages()[ self::result_key() ] ?? null;
	}

	public function show_result_notice(): void {
		$message = self::result_message();
		if ( ! $message || ! function_exists( 'wc_add_notice' ) || ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}
		if ( 'confirmed' === self::result_key() && is_user_logged_in() ) {
			$message[1] = __( 'Your email is confirmed. Thanks!', 'yeffoprint-core' );
		}
		wc_add_notice( $message[1], $message[0] );
	}

	/** @param string $html */
	public function login_message( $html ) {
		$message = self::result_message();
		if ( ! $message ) {
			return $html;
		}
		$class = 'error' === $message[0] ? 'notice-error' : ( 'success' === $message[0] ? 'notice-success' : 'notice-info' );
		return $html . '<div class="notice ' . $class . ' message"><p>' . esc_html( $message[1] ) . '</p></div>';
	}

	/**
	 * wp-login.php's own Register link (shown when WordPress's "Anyone can
	 * register" is on) uses core's flow, which has no password field and
	 * emails a set-password link. Send it to My Account's form instead.
	 *
	 * @param string $url
	 */
	public function register_url( $url ) {
		if ( function_exists( 'wc_get_page_permalink' ) && 'yes' === get_option( 'woocommerce_enable_myaccount_registration' ) ) {
			return wc_get_page_permalink( 'myaccount' );
		}
		return $url;
	}

	/**
	 * Where a signed-out customer signs in: WooCommerce's own My Account
	 * form while self-service registration is on (the theme leaves that
	 * page alone then), wp-login.php otherwise.
	 */
	private static function login_page_url(): string {
		if ( function_exists( 'wc_get_page_permalink' ) && 'yes' === get_option( 'woocommerce_enable_myaccount_registration' ) ) {
			return wc_get_page_permalink( 'myaccount' );
		}
		return wp_login_url();
	}

	private static function resend_sig( int $user_id ): string {
		return substr( wp_hash( 'yp-verify-resend|' . $user_id ), 0, 20 );
	}

	private static function resend_url( int $user_id ): string {
		return add_query_arg( [ 'yp_resend_verify' => $user_id, 'sig' => self::resend_sig( $user_id ) ], home_url( '/' ) );
	}

	/** Issues a fresh link (older ones stop working) and emails it. */
	private static function send_link( int $user_id ): void {
		$user = get_userdata( $user_id );
		if ( ! $user || ! function_exists( 'WC' ) ) {
			return;
		}

		$key = wp_generate_password( 32, false );
		update_user_meta( $user_id, self::META, [
			'hash'    => hash( 'sha256', $key ),
			'expires' => time() + self::LINK_TTL,
		] );

		$url  = add_query_arg( [ 'yp_verify_email' => $user_id, 'key' => $key ], home_url( '/' ) );
		$name = $user->first_name ?: $user->display_name;

		ob_start();
		?>
		<p><?php echo esc_html( sprintf( /* translators: %s: first name */ __( 'Hi %s,', 'yeffoprint-core' ), $name ) ); ?></p>
		<p><?php esc_html_e( 'Thanks for creating your YeffoDesign account. Please confirm this is your email address so you can sign in with the password you chose.', 'yeffoprint-core' ); ?></p>
		<p style="margin:24px 0;"><a href="<?php echo esc_url( $url ); ?>" style="display:inline-block;padding:12px 22px;border-radius:8px;background:#111;color:#fff;font-weight:600;text-decoration:none;"><?php esc_html_e( 'Confirm my email', 'yeffoprint-core' ); ?></a></p>
		<p><?php esc_html_e( 'This link works for 7 days. If you didn’t create an account, you can ignore this email.', 'yeffoprint-core' ); ?></p>
		<?php
		$body = ob_get_clean();

		$mailer  = WC()->mailer();
		$heading = __( 'Confirm your email', 'yeffoprint-core' );
		$mailer->send(
			$user->user_email,
			__( 'Confirm your email for YeffoDesign', 'yeffoprint-core' ),
			$mailer->wrap_message( $heading, $body )
		);
	}
}
