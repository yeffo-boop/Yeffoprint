<?php
/**
 * The store policy pages — direct request: "I need a privacy notice
 * drafted and available on the site" and "whatever other pages payment
 * processors usually look for should get made too."
 *
 * Creates (or, for the Privacy Policy, publishes WordPress's own unused
 * draft at /privacy-policy/) one page per policy on the theme's
 * legal-page template, whose yeffoprint/legal-document block renders the
 * repo-owned text for that slug. Then points WordPress and WooCommerce
 * at them, which is what makes the block Checkout's existing Terms block
 * read "By proceeding with your purchase you agree to our Terms and
 * Conditions and Privacy Policy". A "We never sell your information"
 * line goes under every sign-up form.
 *
 * Runs once per SETUP_VERSION, so a page Jeff later edits, unpublishes or
 * moves is never fought over.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Legal_Pages {

	public const TEMPLATE = 'legal-page';

	private const SETUP_VERSION = 1;
	private const SETUP_OPTION  = 'yeffoprint_legal_pages_version';
	private const LOCK_OPTION   = 'yeffoprint_legal_pages_lock';

	public function __construct() {
		add_action( 'init', [ __CLASS__, 'ensure_pages' ], 30 );
		// The live site's sign-up form (My Account sends new customers
		// to wp-login.php's Register form); WooCommerce's own forms get
		// the same line from the privacy text options set below.
		add_action( 'register_form', [ __CLASS__, 'render_register_line' ], 50 );
	}

	public static function render_register_line(): void {
		$url = get_privacy_policy_url();
		if ( ! $url ) {
			return;
		}
		printf(
			'<p class="yp-register-privacy" style="margin:0 0 16px;font-size:13px;">%s <a href="%s">%s</a>.</p>',
			esc_html__( 'We never sell your information. See our', 'yeffoprint-core' ),
			esc_url( $url ),
			esc_html__( 'Privacy Policy', 'yeffoprint-core' )
		);
	}

	/** @return array<string, string> slug => title */
	public static function pages(): array {
		return [
			'privacy-policy'  => __( 'Privacy Policy', 'yeffoprint-core' ),
			'terms'           => __( 'Terms of Service', 'yeffoprint-core' ),
			'refund-policy'   => __( 'Refund & Returns Policy', 'yeffoprint-core' ),
			'shipping-policy' => __( 'Shipping Policy', 'yeffoprint-core' ),
		];
	}

	public static function ensure_pages(): void {
		if ( (int) get_option( self::SETUP_OPTION, 0 ) >= self::SETUP_VERSION ) {
			return;
		}

		if ( ! add_option( self::LOCK_OPTION, time(), '', false ) ) {
			if ( time() - (int) get_option( self::LOCK_OPTION ) < MINUTE_IN_SECONDS ) {
				return;
			}
			update_option( self::LOCK_OPTION, time(), false );
		}

		$ids = [];
		foreach ( self::pages() as $slug => $title ) {
			$id = self::ensure_page( $slug, $title );
			if ( ! $id ) {
				delete_option( self::LOCK_OPTION );
				return; // Try again on the next request.
			}
			$ids[ $slug ] = $id;
		}

		update_option( 'wp_page_for_privacy_policy', $ids['privacy-policy'] );
		update_option( 'woocommerce_terms_page_id', $ids['terms'] );
		update_option( 'woocommerce_refund_returns_page_id', $ids['refund-policy'] );
		update_option( 'woocommerce_registration_privacy_policy_text', __( 'We never sell your information. See our [privacy_policy].', 'yeffoprint-core' ) );
		update_option( 'woocommerce_checkout_privacy_policy_text', __( 'We never sell your information. See our [privacy_policy].', 'yeffoprint-core' ) );

		update_option( self::SETUP_OPTION, self::SETUP_VERSION, false );
		delete_option( self::LOCK_OPTION );
	}

	private static function ensure_page( string $slug, string $title ): int {
		$existing = get_page_by_path( $slug, OBJECT, 'page' );

		if ( $existing && 'trash' !== $existing->post_status ) {
			$result = wp_update_post( [
				'ID'           => $existing->ID,
				'post_title'   => $title,
				'post_status'  => 'publish',
				// WordPress's sample privacy text; the template renders ours.
				'post_content' => '',
			], true );
			if ( is_wp_error( $result ) ) {
				return 0;
			}
			update_post_meta( $existing->ID, '_wp_page_template', self::TEMPLATE );
			return (int) $existing->ID;
		}

		$page_id = wp_insert_post( [
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_content' => '',
			'meta_input'   => [ '_wp_page_template' => self::TEMPLATE ],
		], true );

		return is_wp_error( $page_id ) ? 0 : (int) $page_id;
	}
}
