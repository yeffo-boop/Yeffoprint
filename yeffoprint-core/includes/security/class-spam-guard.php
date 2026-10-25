<?php
/**
 * Keeps bots out of the sign-up forms and the site's public forms
 * without slowing real customers down (direct request: "add some anti
 * spam measures to the user registration form and anywhere else you
 * think we should").
 *
 * Sign-up (wp-login.php's Register form and WooCommerce's My Account
 * Register form, which is also where the YeffoHealth tracker's "Create
 * a free account" goes) gets, all invisible to a person:
 *   - a honeypot field hidden off-screen that only a form-filling bot fills;
 *   - a signed time stamp: a form sent back under 3 seconds after it
 *     loaded, or posted straight at wp-login.php without loading the
 *     form at all, is a bot;
 *   - 5 new accounts per IP an hour;
 *   - throwaway inbox domains (mailinator and friends) and email domains
 *     that don't exist are refused, with a message asking for a real one;
 *   - usernames with a link in them, or keyboard mash like "XkTqRfWzLp".
 * Plus Cloudflare Turnstile once Jeff pastes keys under Settings ›
 * Integrations › Spam protection (off until then). It runs in
 * "interaction-only" mode, so almost everyone never sees it.
 *
 * The public REST forms (Contact, web design quote/order, custom label
 * and sticker requests, emailed saved-design links) call the helpers
 * below from their own controllers.
 *
 * Every block is counted and the last few are kept, so Settings shows
 * what's being stopped (and a real customer caught by mistake would
 * show up there too).
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Spam_Guard {

	public const TURNSTILE_SITE_KEY_OPTION   = 'yeffoprint_turnstile_site_key';
	public const TURNSTILE_SECRET_KEY_OPTION = 'yeffoprint_turnstile_secret_key';

	private const LOG_OPTION = 'yeffoprint_spam_log';
	private const LOG_KEEP   = 40;

	private const HONEYPOT   = 'yp_url';
	private const TIME_FIELD = 'yp_ts';
	private const MIN_SECS   = 3;
	private const MAX_SECS   = 12 * HOUR_IN_SECONDS;

	private const SIGNUP_LIMIT  = 5;
	private const SIGNUP_WINDOW = HOUR_IN_SECONDS;

	private const TURNSTILE_VERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

	/** Throwaway inbox services. Not exhaustive: the common ones bots and signup-farmers use. */
	private const DISPOSABLE_DOMAINS = [
		'10minutemail.com', '10minutemail.net', '20minutemail.com', '33mail.com', 'anonaddy.me',
		'burnermail.io', 'byom.de', 'discard.email', 'disposablemail.com', 'dispostable.com',
		'dropmail.me', 'emailondeck.com', 'emailfake.com', 'fakeinbox.com', 'fakemail.net',
		'getairmail.com', 'getnada.com', 'guerrillamail.biz', 'guerrillamail.com', 'guerrillamail.de',
		'guerrillamail.info', 'guerrillamail.net', 'guerrillamail.org', 'guerrillamailblock.com',
		'harakirimail.com', 'inboxbear.com', 'incognitomail.org', 'jetable.org', 'mail.tm',
		'mailcatch.com', 'maildrop.cc', 'mailinator.com', 'mailinator.net', 'mailinator2.com',
		'mailnesia.com', 'mailpoof.com', 'mailsac.com', 'mintemail.com', 'moakt.com', 'mohmal.com',
		'mytemp.email', 'nada.email', 'sharklasers.com', 'spam4.me', 'spambox.us', 'spamgourmet.com',
		'temp-mail.io', 'temp-mail.org', 'tempail.com', 'tempmail.com', 'tempmail.dev', 'tempmail.net',
		'tempmailo.com', 'tempr.email', 'throwawaymail.com', 'trashmail.com', 'trashmail.de',
		'trashmail.net', 'yopmail.com', 'yopmail.fr', 'yopmail.net', 'grr.la', 'pokemail.net',
		'spamdecoy.net', 'mvrht.net', 'emltmp.com', 'tmpmail.org', 'tmpmail.net', 'tmail.ws',
		'minuteinbox.com', 'luxusmail.org', 'mailto.plus', 'fexpost.com', 'fexbox.org', 'rover.info',
		'cuvox.de', 'armyspy.com', 'dayrep.com', 'einrot.com', 'fleckens.hu',
		'gustr.com', 'jourrapide.com', 'rhyta.com', 'superrito.com', 'teleworm.us',
	];

	public function __construct() {
		add_action( 'register_form', [ __CLASS__, 'render_fields' ], 5 );
		add_filter( 'registration_errors', [ $this, 'check_wp_login_signup' ], 5, 3 );

		add_action( 'woocommerce_register_form', [ __CLASS__, 'render_fields' ], 5 );
		add_filter( 'woocommerce_process_registration_errors', [ $this, 'check_my_account_signup' ], 5, 4 );

		// Only accounts actually made count toward the hourly cap, so a
		// customer retrying a taken username isn't locked out.
		add_action( 'register_new_user', [ $this, 'count_signup' ] );
		add_action( 'woocommerce_created_customer', [ $this, 'count_my_account_signup' ] );
	}

	public function count_signup(): void {
		self::bump( 'signup', self::SIGNUP_WINDOW );
	}

	public function count_my_account_signup(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the form's nonce before creating the account.
		if ( isset( $_POST['register'], $_POST['woocommerce-register-nonce'] ) ) {
			self::bump( 'signup', self::SIGNUP_WINDOW );
		}
	}

	/* ---------- Sign-up forms ---------- */

	/** Honeypot, time stamp and (when keys are set) Turnstile, inside the form. */
	public static function render_fields(): void {
		$now = time();
		?>
		<div class="yp-hp-field" aria-hidden="true" style="position:absolute!important;left:-9999px!important;width:1px;height:1px;overflow:hidden;">
			<label for="<?php echo esc_attr( self::HONEYPOT ); ?>"><?php esc_html_e( 'Website', 'yeffoprint-core' ); ?></label>
			<input type="text" name="<?php echo esc_attr( self::HONEYPOT ); ?>" id="<?php echo esc_attr( self::HONEYPOT ); ?>" value="" tabindex="-1" autocomplete="off" />
		</div>
		<input type="hidden" name="<?php echo esc_attr( self::TIME_FIELD ); ?>" value="<?php echo esc_attr( $now . '.' . self::sign( $now ) ); ?>" />
		<?php
		if ( self::turnstile_enabled() ) {
			// interaction-only: invisible unless Cloudflare wants a click.
			?>
			<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( self::turnstile_site_key() ); ?>" data-appearance="interaction-only" style="margin:0 0 12px;"></div>
			<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
			<?php
		}
	}

	/**
	 * wp-login.php?action=register.
	 *
	 * @param \WP_Error $errors
	 */
	public function check_wp_login_signup( $errors, $login = '', $email = '' ) {
		$problem = self::check_signup( (string) $login, (string) $email, 'signup' );
		if ( $problem ) {
			$errors->add( 'yp_spam', '<strong>' . esc_html__( 'Error:', 'yeffoprint-core' ) . '</strong> ' . esc_html( $problem ) );
		}
		return $errors;
	}

	/**
	 * My Account Register form.
	 *
	 * @param \WP_Error $errors
	 */
	public function check_my_account_signup( $errors, $username = '', $password = '', $email = '' ) {
		$problem = self::check_signup( (string) $username, (string) $email, 'signup' );
		if ( $problem ) {
			$errors->add( 'yp_spam', esc_html( $problem ) );
		}
		return $errors;
	}

	/** @return string Message for the person, or '' when it looks fine. */
	private static function check_signup( string $login, string $email, string $form ): string {
		$generic = __( 'We couldn’t create your account. Please refresh the page and try again, or contact us if it keeps happening.', 'yeffoprint-core' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Core's register form has no nonce; WooCommerce checks its own before this filter runs.
		if ( '' !== trim( (string) wp_unslash( $_POST[ self::HONEYPOT ] ?? '' ) ) ) {
			self::record( $form, 'Hidden field filled in', $email );
			return $generic;
		}

		$stamp = sanitize_text_field( wp_unslash( (string) ( $_POST[ self::TIME_FIELD ] ?? '' ) ) );
		// phpcs:enable
		$age = self::stamp_age( $stamp );
		if ( null === $age ) {
			self::record( $form, 'Sent without loading the form', $email );
			return $generic;
		}
		if ( $age < self::MIN_SECS ) {
			self::record( $form, sprintf( 'Filled in %d seconds after loading', $age ), $email );
			return $generic;
		}
		if ( $age > self::MAX_SECS ) {
			return __( 'This page was open a long time. Please refresh it and try again.', 'yeffoprint-core' );
		}

		if ( self::turnstile_enabled() && ! self::turnstile_passes() ) {
			self::record( $form, 'Failed the Cloudflare check', $email );
			return __( 'Please complete the security check below and try again.', 'yeffoprint-core' );
		}

		$email_problem = self::email_problem( $email );
		if ( $email_problem ) {
			self::record( $form, $email_problem, $email );
			return __( 'Please use a permanent email address you can check. We send your confirmation link and order updates there.', 'yeffoprint-core' );
		}

		if ( '' !== $login && self::looks_like_junk_name( $login ) ) {
			self::record( $form, 'Junk username: ' . $login, $email );
			return __( 'Please choose a different username.', 'yeffoprint-core' );
		}

		if ( self::hits( 'signup' ) >= self::SIGNUP_LIMIT ) {
			self::record( $form, 'Too many sign-ups from one connection', $email );
			return __( 'Too many accounts were created from your connection recently. Please try again later.', 'yeffoprint-core' );
		}

		return '';
	}

	/* ---------- Helpers the public forms share ---------- */

	/**
	 * Counts one try against a per-IP limit and says whether it's over.
	 * No IP means no key to limit by, so it fails open like the site's
	 * other per-IP limits.
	 */
	public static function over_limit( string $bucket, int $max, int $window ): bool {
		if ( '' === self::ip() ) {
			return false;
		}
		if ( self::hits( $bucket ) >= $max ) {
			return true;
		}
		self::bump( $bucket, $window );
		return false;
	}

	private static function hits( string $bucket ): int {
		return '' === self::ip() ? 0 : (int) get_transient( self::limit_key( $bucket ) );
	}

	private static function bump( string $bucket, int $window ): void {
		if ( '' !== self::ip() ) {
			set_transient( self::limit_key( $bucket ), self::hits( $bucket ) + 1, $window );
		}
	}

	private static function limit_key( string $bucket ): string {
		return 'yp_spam_rl_' . $bucket . '_' . md5( self::ip() );
	}

	/**
	 * For the JS forms: `yp_t` is how many milliseconds the form was open
	 * before it was sent. Missing is allowed (older cached copies of the
	 * scripts don't send it); under 2 seconds is a bot.
	 */
	public static function sent_too_fast( \WP_REST_Request $request ): bool {
		$elapsed = $request->get_param( 'yp_t' );
		return null !== $elapsed && '' !== $elapsed && (int) $elapsed < 2000;
	}

	/** @return string Why the address is refused, or ''. */
	public static function email_problem( string $email ): string {
		$email = strtolower( trim( $email ) );
		$at    = strrpos( $email, '@' );
		if ( false === $at ) {
			return '';
		}
		$domain = substr( $email, $at + 1 );

		foreach ( self::DISPOSABLE_DOMAINS as $bad ) {
			if ( $domain === $bad || str_ends_with( $domain, '.' . $bad ) ) {
				return 'Throwaway email domain: ' . $domain;
			}
		}

		if ( ! self::domain_takes_mail( $domain ) ) {
			return 'Email domain doesn’t exist: ' . $domain;
		}
		return '';
	}

	/** A link in a name field, or random keyboard mash, is never a real person's name. */
	public static function looks_like_junk_name( string $name ): bool {
		$name = trim( $name );
		if ( '' === $name ) {
			return false;
		}
		if ( preg_match( '~https?://|www\.|\[url|<a\s~i', $name ) ) {
			return true;
		}

		foreach ( preg_split( '/[\s._\-0-9]+/', $name ) as $word ) {
			if ( strlen( $word ) < 10 || ! ctype_alpha( $word ) || ctype_upper( $word ) ) {
				continue;
			}
			// "XkTqRfWzLp": capitals scattered all through one long word.
			if ( preg_match_all( '/[A-Z]/', substr( $word, 1 ) ) >= 4 ) {
				return true;
			}
			// "xjvkqlwpzr": seven consonants in a row.
			if ( preg_match( '/[bcdfghjklmnpqrstvwxz]{7,}/i', $word ) ) {
				return true;
			}
		}
		return false;
	}

	/** Remembers one blocked try for Settings › Integrations › Spam protection. */
	public static function record( string $form, string $reason, string $email = '' ): void {
		$log = get_option( self::LOG_OPTION, [] );
		if ( ! is_array( $log ) ) {
			$log = [];
		}
		$log['counts']          = (array) ( $log['counts'] ?? [] );
		$log['counts'][ $form ] = (int) ( $log['counts'][ $form ] ?? 0 ) + 1;
		$log['since']           = (int) ( $log['since'] ?? time() );

		$recent = (array) ( $log['recent'] ?? [] );
		array_unshift( $recent, [
			'time'   => time(),
			'form'   => $form,
			'reason' => mb_substr( $reason, 0, 160 ),
			'email'  => mb_substr( sanitize_email( $email ), 0, 120 ),
		] );
		$log['recent'] = array_slice( $recent, 0, self::LOG_KEEP );

		update_option( self::LOG_OPTION, $log, false );
	}

	/** For the admin Settings screen. */
	public static function settings(): array {
		$log = get_option( self::LOG_OPTION, [] );
		return [
			'turnstile_site_key'       => self::turnstile_site_key(),
			'turnstile_secret_key_set' => '' !== self::turnstile_secret_key(),
			'turnstile_on'             => self::turnstile_enabled(),
			'blocked_counts'           => is_array( $log ) ? (array) ( $log['counts'] ?? [] ) : [],
			'blocked_since'            => is_array( $log ) ? (int) ( $log['since'] ?? 0 ) : 0,
			'blocked_recent'           => is_array( $log ) ? array_slice( (array) ( $log['recent'] ?? [] ), 0, 15 ) : [],
		];
	}

	/** Only keys that are sent are saved; a blank secret keeps the saved one. */
	public static function save_settings( array $params ): void {
		if ( array_key_exists( 'turnstile_site_key', $params ) ) {
			update_option( self::TURNSTILE_SITE_KEY_OPTION, sanitize_text_field( (string) $params['turnstile_site_key'] ) );
			if ( '' === trim( (string) $params['turnstile_site_key'] ) ) {
				update_option( self::TURNSTILE_SECRET_KEY_OPTION, '', false ); // Clearing the site key turns it off.
			}
		}
		$secret = trim( (string) ( $params['turnstile_secret_key'] ?? '' ) );
		if ( '' !== $secret ) {
			update_option( self::TURNSTILE_SECRET_KEY_OPTION, sanitize_text_field( $secret ), false );
		}
	}

	/* ---------- Internals ---------- */

	private static function turnstile_site_key(): string {
		return trim( (string) get_option( self::TURNSTILE_SITE_KEY_OPTION, '' ) );
	}

	private static function turnstile_secret_key(): string {
		return trim( (string) get_option( self::TURNSTILE_SECRET_KEY_OPTION, '' ) );
	}

	public static function turnstile_enabled(): bool {
		return '' !== self::turnstile_site_key() && '' !== self::turnstile_secret_key();
	}

	private static function turnstile_passes(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Token check, not a state change on its own.
		$token = sanitize_text_field( wp_unslash( (string) ( $_POST['cf-turnstile-response'] ?? '' ) ) );
		if ( '' === $token ) {
			return false;
		}

		$response = wp_remote_post( self::TURNSTILE_VERIFY, [
			'timeout' => 8,
			'body'    => [
				'secret'   => self::turnstile_secret_key(),
				'response' => $token,
				'remoteip' => self::ip(),
			],
		] );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return true; // Cloudflare unreachable: don't lock real customers out; the other checks still ran.
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return ! empty( $body['success'] );
	}

	private static function domain_takes_mail( string $domain ): bool {
		if ( '' === $domain || ! function_exists( 'checkdnsrr' ) ) {
			return true;
		}
		$key    = 'yp_mx_' . md5( $domain );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return 'y' === $cached;
		}
		$ok = checkdnsrr( $domain, 'MX' ) || checkdnsrr( $domain, 'A' ) || checkdnsrr( $domain, 'AAAA' );
		if ( ! $ok && ! checkdnsrr( 'gmail.com', 'MX' ) ) {
			return true; // The server's own DNS is down; don't blame the customer.
		}
		set_transient( $key, $ok ? 'y' : 'n', DAY_IN_SECONDS );
		return $ok;
	}

	/** @return int|null Seconds since the form loaded, or null for a missing or forged stamp. */
	private static function stamp_age( string $stamp ): ?int {
		if ( ! preg_match( '/^(\d{9,11})\.([a-f0-9]{20})$/', $stamp, $m ) ) {
			return null;
		}
		if ( ! hash_equals( self::sign( (int) $m[1] ), $m[2] ) ) {
			return null;
		}
		return time() - (int) $m[1];
	}

	private static function sign( int $time ): string {
		return substr( hash_hmac( 'sha256', 'yp_form_ts|' . $time, wp_salt( 'nonce' ) ), 0, 20 );
	}

	private static function ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}
}
