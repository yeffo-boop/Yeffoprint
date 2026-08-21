<?php
/**
 * The Contact form's submission endpoint. Direct request, for a
 * brand-new site: a low-friction way for a visitor who hits a bug to
 * reach the store owner, without needing an account or a real support
 * ticket system. No CPT/admin list of past submissions — this is a
 * notification, not a record; email is the record.
 *
 * Same "guest is fine, a logged-in request needs a valid nonce"
 * permission and per-IP rate-limit shape as class-custom-order-
 * controller.php's upload endpoint — a public, unauthenticated POST
 * endpoint is exactly what a spam bot tries first.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Contact_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	private const RATE_LIMIT_WINDOW = 900; // 15 minutes
	private const RATE_LIMIT_MAX    = 5;

	private const METHODS = [ 'email', 'whatsapp', 'telegram' ];

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/contact', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'submit' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'guest_or_nonced_write' ],
		] );
	}

	public function submit( \WP_REST_Request $request ) {
		// Honeypot: a field named to look worth filling in to a bot's
		// form-filler, left empty (and hidden from a real visitor via
		// CSS, not `type="hidden"` — some bots skip genuinely hidden
		// inputs) by everyone else. A non-empty value means a bot filled
		// in every field it could see — fake success instead of an
		// error, so it has no signal to adapt against.
		if ( '' !== (string) $request->get_param( 'website' ) ) {
			return rest_ensure_response( [ 'success' => true ] );
		}

		$rate_limited = $this->check_rate_limit();
		if ( is_wp_error( $rate_limited ) ) {
			return $rate_limited;
		}

		$name = sanitize_text_field( (string) $request->get_param( 'name' ) );
		if ( '' === $name ) {
			return new \WP_Error( 'yeffoprint_missing_name', __( 'Please enter your name.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'yeffoprint_invalid_email', __( 'Please enter a valid email address.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$method = sanitize_key( (string) $request->get_param( 'contact_method' ) );
		if ( ! in_array( $method, self::METHODS, true ) ) {
			return new \WP_Error( 'yeffoprint_invalid_method', __( 'Please choose how you\'d like to be contacted.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		// Only meaningful for whatsapp/telegram — email already has the
		// email field above, so nothing extra to collect for that choice.
		$handle = sanitize_text_field( (string) $request->get_param( 'contact_handle' ) );
		if ( 'email' !== $method && '' === $handle ) {
			return new \WP_Error(
				'yeffoprint_missing_handle',
				/* translators: %s: "WhatsApp" or "Telegram" */
				sprintf( __( 'Please enter your %s username.', 'yeffoprint-core' ), 'whatsapp' === $method ? 'WhatsApp' : 'Telegram' ),
				[ 'status' => 400 ]
			);
		}

		$message = sanitize_textarea_field( (string) $request->get_param( 'message' ) );
		if ( '' === $message ) {
			return new \WP_Error( 'yeffoprint_missing_message', __( 'Please enter a message.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$this->send( $name, $email, $method, $handle, $message );

		return rest_ensure_response( [ 'success' => true ] );
	}

	private function send( string $name, string $email, string $method, string $handle, string $message ): void {
		$recipient = get_option( YeffoPrint_Admin_Menu::CONTACT_RECIPIENT_EMAIL_OPTION, YeffoPrint_Admin_Menu::CONTACT_RECIPIENT_EMAIL_DEFAULT );
		if ( ! is_email( $recipient ) ) {
			$recipient = YeffoPrint_Admin_Menu::CONTACT_RECIPIENT_EMAIL_DEFAULT;
		}

		$method_labels = [
			'email'    => __( 'Email', 'yeffoprint-core' ),
			'whatsapp' => __( 'WhatsApp', 'yeffoprint-core' ),
			'telegram' => __( 'Telegram', 'yeffoprint-core' ),
		];

		$lines = [
			sprintf( __( 'Name: %s', 'yeffoprint-core' ), $name ),
			sprintf( __( 'Email: %s', 'yeffoprint-core' ), $email ),
			sprintf( __( 'Preferred contact method: %s', 'yeffoprint-core' ), $method_labels[ $method ] ?? $method ),
		];

		if ( 'email' !== $method ) {
			$lines[] = sprintf( __( '%1$s username: %2$s', 'yeffoprint-core' ), $method_labels[ $method ], $handle );
		}

		$lines[] = '';
		$lines[] = __( 'Message:', 'yeffoprint-core' );
		$lines[] = $message;

		$subject = sprintf( /* translators: %s: sender's name */ __( 'YeffoPrint contact form — %s', 'yeffoprint-core' ), $name );

		// Reply-To (not From — wp_mail's own default From stays a real,
		// deliverable site address) is what makes "just hit reply"
		// actually go to the customer instead of back to the site.
		$headers = [ sprintf( 'Reply-To: %1$s <%2$s>', $name, $email ) ];

		wp_mail( $recipient, $subject, implode( "\n", $lines ), $headers );
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

		$key   = 'yp_contact_rl_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT_MAX ) {
			return new \WP_Error(
				'yeffoprint_rate_limited',
				__( 'Too many messages sent recently. Please wait a few minutes and try again.', 'yeffoprint-core' ),
				[ 'status' => 429 ]
			);
		}

		set_transient( $key, $count + 1, self::RATE_LIMIT_WINDOW );
		return null;
	}
}
