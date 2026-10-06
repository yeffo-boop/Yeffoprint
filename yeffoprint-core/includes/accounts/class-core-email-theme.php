<?php
/**
 * Gives WordPress's own account emails the same look as every WooCommerce
 * email (direct request: "The login details emails is not [themed] and
 * needs to be").
 *
 * WordPress core sends a few account emails itself, as plain text from
 * wp_mail(): "Login Details" (a new account's set-password link), the
 * wp-login.php "Password Reset" link, and "Password Changed" / "Email
 * Changed" notices, plus the matching notes to the admin. Each one is
 * turned into HTML here and wrapped in the theme's email header, footer
 * and styles (woocommerce/emails/email-*.php) through WooCommerce's
 * mailer, the same frame the Confirm your email email uses. Wording stays
 * core's; a line that is only a link becomes a button.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Core_Email_Theme {

	public function __construct() {
		add_filter( 'wp_new_user_notification_email', [ $this, 'login_details' ], 20 );
		add_filter( 'wp_new_user_notification_email_admin', [ $this, 'new_user_admin' ], 20 );
		add_filter( 'retrieve_password_notification_email', [ $this, 'password_reset' ], 20 );
		add_filter( 'password_change_email', [ $this, 'password_changed' ], 20 );
		add_filter( 'email_change_email', [ $this, 'email_changed' ], 20 );
		add_filter( 'wp_password_change_notification_email', [ $this, 'password_changed_admin' ], 20 );
	}

	public function login_details( $email ) {
		return self::theme( $email, __( 'Your login details', 'yeffoprint-core' ), __( 'Set your password →', 'yeffoprint-core' ) );
	}

	public function new_user_admin( $email ) {
		return self::theme( $email, __( 'New account', 'yeffoprint-core' ) );
	}

	public function password_reset( $email ) {
		return self::theme( $email, __( 'Reset your password', 'yeffoprint-core' ), __( 'Choose a new password →', 'yeffoprint-core' ) );
	}

	public function password_changed( $email ) {
		return self::theme( $email, __( 'Your password was changed', 'yeffoprint-core' ) );
	}

	public function email_changed( $email ) {
		return self::theme( $email, __( 'Your email was changed', 'yeffoprint-core' ) );
	}

	public function password_changed_admin( $email ) {
		return self::theme( $email, __( 'Password changed', 'yeffoprint-core' ) );
	}

	/**
	 * @param array|mixed $email  wp_mail() args: to, subject, message, headers.
	 * @param string      $button Label for a line that is only a link.
	 */
	private static function theme( $email, string $heading, string $button = '' ) {
		if ( ! is_array( $email ) || empty( $email['message'] ) || ! function_exists( 'WC' ) || ! class_exists( 'WC_Email' ) ) {
			return $email;
		}

		$headers = $email['headers'] ?? '';
		if ( false !== stripos( is_array( $headers ) ? implode( "\n", $headers ) : (string) $headers, 'text/html' ) ) {
			return $email; // Already HTML; someone else formatted it.
		}

		$body = self::to_html( (string) $email['message'], $button );

		$mailer = WC()->mailer(); // Loads WC_Email and the theme's email templates.
		$html   = ( new WC_Email() )->style_inline( $mailer->wrap_message( $heading, $body ) );

		$email['message'] = $html;
		$email['headers'] = self::html_headers( $headers );
		return $email;
	}

	private static function to_html( string $text, string $button ): string {
		$text   = str_replace( "\r\n", "\n", $text );
		$blocks = preg_split( "/\n{2,}/", trim( $text ) );
		$html   = '';

		foreach ( $blocks as $block ) {
			$block = trim( $block );
			if ( '' === $block ) {
				continue;
			}
			// Older core wrapped links as <https://...>.
			$plain_url = trim( $block, "<> \t" );
			if ( $button && preg_match( '#^https?://\S+$#', $plain_url ) ) {
				$html .= '<p style="margin:22px 0;"><a class="yp-payment-cta-button" href="' . esc_url( $plain_url ) . '">' . esc_html( $button ) . '</a></p>';
				continue;
			}
			$block = preg_replace( '#<(https?://[^>\s]+)>#', '$1', $block );
			$html .= '<p>' . nl2br( make_clickable( esc_html( $block ) ) ) . '</p>';
		}

		return $html;
	}

	/** @param string|array $headers */
	private static function html_headers( $headers ) {
		$type = 'Content-Type: text/html; charset=UTF-8';
		if ( is_array( $headers ) ) {
			$headers   = array_values( array_filter( $headers, fn( $h ) => 0 !== stripos( (string) $h, 'Content-Type:' ) ) );
			$headers[] = $type;
			return $headers;
		}
		$lines   = array_filter( preg_split( "/\r?\n/", (string) $headers ), fn( $h ) => '' !== trim( $h ) && 0 !== stripos( $h, 'Content-Type:' ) );
		$lines[] = $type;
		return implode( "\r\n", $lines );
	}
}
