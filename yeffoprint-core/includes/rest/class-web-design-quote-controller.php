<?php
/**
 * The Web Design page's quote-request form submission endpoint — direct
 * follow-up: every "Get a Quote" link on that page pointed at the
 * generic Contact form (name/email/message), which doesn't ask any of
 * the things a real web-design lead needs answered (existing site?
 * hosting? domain? which package? timeline?). Same "email is the
 * record, no CPT/admin list" shape as class-contact-controller.php's
 * own docblock explains — this is a richer notification, not a new
 * kind of record to manage.
 *
 * Same guest-or-nonced-write permission, honeypot, and per-IP rate
 * limit as the Contact form's endpoint — a public, unauthenticated POST
 * endpoint is exactly what a spam bot tries first, same reasoning.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Web_Design_Quote_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	private const RATE_LIMIT_WINDOW = 900; // 15 minutes
	private const RATE_LIMIT_MAX    = 5;

	private const YES_NO_UNSURE = [ 'yes', 'no', 'unsure' ];
	private const YES_NO        = [ 'yes', 'no' ];

	private const PRODUCT_COUNTS = [
		'1_10'    => '1–10',
		'11_50'   => '11–50',
		'51_200'  => '51–200',
		'200_up'  => '200+',
	];

	private const TIMELINES = [
		'asap'        => 'ASAP',
		'one_month'   => 'Within a month',
		'one_to_three' => '1–3 months',
		'exploring'   => 'Just exploring',
	];

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/web-design-quote', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'submit' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'guest_or_nonced_write' ],
		] );
	}

	public function submit( \WP_REST_Request $request ) {
		// Honeypot — same convention as class-contact-controller.php's own
		// (a field named to look worth filling in to a bot's form-filler,
		// left empty and CSS-hidden rather than type="hidden" for real
		// visitors). Fake success, no signal for a bot to adapt against.
		if ( '' !== (string) $request->get_param( 'website' ) ) {
			return rest_ensure_response( [ 'success' => true ] );
		}

		$rate_limited = $this->check_rate_limit();
		if ( is_wp_error( $rate_limited ) ) {
			return $rate_limited;
		}

		$business_name = sanitize_text_field( (string) $request->get_param( 'business_name' ) );
		if ( '' === $business_name ) {
			return new \WP_Error( 'yeffoprint_missing_business_name', __( 'Please enter your business or brand name.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$name = sanitize_text_field( (string) $request->get_param( 'name' ) );
		if ( '' === $name ) {
			return new \WP_Error( 'yeffoprint_missing_name', __( 'Please enter your name.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'yeffoprint_invalid_email', __( 'Please enter a valid email address.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$what_you_sell = sanitize_text_field( (string) $request->get_param( 'what_you_sell' ) );
		if ( '' === $what_you_sell ) {
			return new \WP_Error( 'yeffoprint_missing_what_you_sell', __( 'Please tell us briefly what you\'re selling.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$package = sanitize_text_field( (string) $request->get_param( 'package' ) );
		if ( '' === $package ) {
			return new \WP_Error( 'yeffoprint_missing_package', __( 'Please choose a package, or "Not sure yet."', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		// Everything below is optional — the intake shouldn't feel like a
		// wall of required questions. Free-text fields are sanitized;
		// constrained choices fall back to '' (rendered as "Not answered")
		// for anything outside their known value set rather than erroring,
		// since none of these gate whether the lead can be followed up on.
		$phone              = sanitize_text_field( (string) $request->get_param( 'phone' ) );
		$has_website        = $this->one_of( (string) $request->get_param( 'has_website' ), self::YES_NO );
		$website_url        = esc_url_raw( (string) $request->get_param( 'website_url' ) );
		$product_count      = $this->one_of( (string) $request->get_param( 'product_count' ), array_keys( self::PRODUCT_COUNTS ) );
		$timeline           = $this->one_of( (string) $request->get_param( 'timeline' ), array_keys( self::TIMELINES ) );
		$has_hosting        = $this->one_of( (string) $request->get_param( 'has_hosting' ), self::YES_NO_UNSURE );
		$has_domain         = $this->one_of( (string) $request->get_param( 'has_domain' ), self::YES_NO );
		$domain_name        = sanitize_text_field( (string) $request->get_param( 'domain_name' ) );
		$wants_hosting_addon = $this->one_of( (string) $request->get_param( 'wants_hosting_addon' ), [ 'yes', 'no', 'tell_me_more' ] );
		$wants_maintenance  = $this->one_of( (string) $request->get_param( 'wants_maintenance' ), self::YES_NO_UNSURE );
		$details            = sanitize_textarea_field( (string) $request->get_param( 'details' ) );

		$this->send( [
			'business_name'       => $business_name,
			'name'                => $name,
			'email'               => $email,
			'phone'               => $phone,
			'what_you_sell'       => $what_you_sell,
			'has_website'         => $has_website,
			'website_url'         => $website_url,
			'product_count'       => $product_count,
			'package'             => $package,
			'timeline'            => $timeline,
			'has_hosting'         => $has_hosting,
			'has_domain'          => $has_domain,
			'domain_name'         => $domain_name,
			'wants_hosting_addon' => $wants_hosting_addon,
			'wants_maintenance'   => $wants_maintenance,
			'details'             => $details,
		] );

		return rest_ensure_response( [ 'success' => true ] );
	}

	/** @param string[] $allowed */
	private function one_of( string $value, array $allowed ): string {
		$value = sanitize_key( $value );
		return in_array( $value, $allowed, true ) ? $value : '';
	}

	private function send( array $answers ): void {
		$recipient = get_option( YeffoPrint_Admin_Menu::CONTACT_RECIPIENT_EMAIL_OPTION, YeffoPrint_Admin_Menu::CONTACT_RECIPIENT_EMAIL_DEFAULT );
		if ( ! is_email( $recipient ) ) {
			$recipient = YeffoPrint_Admin_Menu::CONTACT_RECIPIENT_EMAIL_DEFAULT;
		}

		$yes_no_unsure_labels = [ 'yes' => __( 'Yes', 'yeffoprint-core' ), 'no' => __( 'No', 'yeffoprint-core' ), 'unsure' => __( 'Not sure', 'yeffoprint-core' ) ];
		$yes_no_labels        = [ 'yes' => __( 'Yes', 'yeffoprint-core' ), 'no' => __( 'No', 'yeffoprint-core' ) ];
		$hosting_addon_labels = [ 'yes' => __( 'Yes', 'yeffoprint-core' ), 'no' => __( 'No', 'yeffoprint-core' ), 'tell_me_more' => __( 'Tell me more', 'yeffoprint-core' ) ];

		$not_answered = __( 'Not answered', 'yeffoprint-core' );

		$lines = [
			sprintf( __( 'Business/brand: %s', 'yeffoprint-core' ), $answers['business_name'] ),
			sprintf( __( 'Name: %s', 'yeffoprint-core' ), $answers['name'] ),
			sprintf( __( 'Email: %s', 'yeffoprint-core' ), $answers['email'] ),
			sprintf( __( 'Phone: %s', 'yeffoprint-core' ), $answers['phone'] ?: $not_answered ),
			'',
			sprintf( __( 'What they sell: %s', 'yeffoprint-core' ), $answers['what_you_sell'] ),
			sprintf( __( 'Existing website: %s', 'yeffoprint-core' ), $yes_no_labels[ $answers['has_website'] ] ?? $not_answered ),
		];

		if ( 'yes' === $answers['has_website'] && $answers['website_url'] ) {
			$lines[] = sprintf( __( 'Website URL: %s', 'yeffoprint-core' ), $answers['website_url'] );
		}

		$lines[] = sprintf( __( 'Roughly how many products: %s', 'yeffoprint-core' ), self::PRODUCT_COUNTS[ $answers['product_count'] ] ?? $not_answered );
		$lines[] = sprintf( __( 'Interested package: %s', 'yeffoprint-core' ), $answers['package'] );
		$lines[] = sprintf( __( 'Target launch: %s', 'yeffoprint-core' ), self::TIMELINES[ $answers['timeline'] ] ?? $not_answered );
		$lines[] = '';
		$lines[] = sprintf( __( 'Already has hosting: %s', 'yeffoprint-core' ), $yes_no_unsure_labels[ $answers['has_hosting'] ] ?? $not_answered );
		$lines[] = sprintf( __( 'Already owns a domain: %s', 'yeffoprint-core' ), $yes_no_labels[ $answers['has_domain'] ] ?? $not_answered );

		if ( 'yes' === $answers['has_domain'] && $answers['domain_name'] ) {
			$lines[] = sprintf( __( 'Domain name: %s', 'yeffoprint-core' ), $answers['domain_name'] );
		}

		$lines[] = sprintf( __( 'Interested in hosting add-on ($35/mo): %s', 'yeffoprint-core' ), $hosting_addon_labels[ $answers['wants_hosting_addon'] ] ?? $not_answered );
		$lines[] = sprintf( __( 'Interested in ongoing maintenance: %s', 'yeffoprint-core' ), $yes_no_unsure_labels[ $answers['wants_maintenance'] ] ?? $not_answered );

		if ( $answers['details'] ) {
			$lines[] = '';
			$lines[] = __( 'Anything else:', 'yeffoprint-core' );
			$lines[] = $answers['details'];
		}

		$subject = sprintf( /* translators: %s: business/brand name */ __( 'Web design quote request — %s', 'yeffoprint-core' ), $answers['business_name'] );

		// Reply-To (not From) — same reasoning as the Contact form's own
		// send(): "just hit reply" should reach the lead, not the site.
		$headers = [ sprintf( 'Reply-To: %1$s <%2$s>', $answers['name'], $answers['email'] ) ];

		wp_mail( $recipient, $subject, implode( "\n", $lines ), $headers );

		/**
		 * Same "let other modules react without knowing this controller
		 * exists" seam as yeffoprint_contact_form_submitted — the Telegram
		 * admin-alerts integration listens for this too (class-telegram-
		 * admin-alerts.php).
		 */
		do_action( 'yeffoprint_web_design_quote_submitted', $answers );
	}

	/**
	 * @return \WP_Error|null Error if this IP has hit the window's cap;
	 *                        null (and the attempt is now counted) otherwise.
	 */
	private function check_rate_limit(): ?\WP_Error {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' === $ip ) {
			return null; // Can't key a limit without an IP — fail open rather than block legitimate requests.
		}

		$key   = 'yp_wdquote_rl_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT_MAX ) {
			return new \WP_Error(
				'yeffoprint_rate_limited',
				__( 'Too many requests sent recently. Please wait a few minutes and try again.', 'yeffoprint-core' ),
				[ 'status' => 429 ]
			);
		}

		set_transient( $key, $count + 1, self::RATE_LIMIT_WINDOW );
		return null;
	}
}
