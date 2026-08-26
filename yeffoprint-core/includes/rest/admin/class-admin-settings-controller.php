<?php
/**
 * Admin REST endpoint for the Settings screen (docs/ARCHITECTURE.md,
 * Phase 7) — every option `class-admin-menu.php`'s classic Settings
 * API page registers (`register_setting()`/`add_settings_field()`),
 * reached here as one GET/POST pair instead of a `options.php` form
 * POST + full page reload. Same sanitize rules as that page's own
 * field callbacks, just applied here instead of through the Settings
 * API's `sanitize_callback` pipeline — this never touches
 * `register_setting()`'s registry, it reads/writes the exact same
 * `get_option()`/`update_option()` keys directly.
 *
 * Deliberately one comprehensive endpoint rather than one per section
 * (Announcement Bar, Tracking, Promo, …) — every section here is a
 * handful of related fields, not independently useful on its own, and
 * a single Save on this screen (like the classic page's single submit
 * button) should save all of them together.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_Settings_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/admin/settings', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_settings' ],
				'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
			],
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'save_settings' ],
				'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
			],
		] );
	}

	public function get_settings(): \WP_REST_Response {
		return rest_ensure_response( $this->payload() );
	}

	public function save_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_json_params() ?: [];
		$M      = 'YeffoPrint_Admin_Menu';

		update_option( $M::ANNOUNCEMENT_BAR_OPTION, sanitize_text_field( (string) ( $params['announcement_bar_text'] ?? '' ) ) );

		update_option( $M::UPS_CLIENT_ID_OPTION, sanitize_text_field( (string) ( $params['ups_client_id'] ?? '' ) ) );
		update_option( $M::UPS_CLIENT_SECRET_OPTION, sanitize_text_field( (string) ( $params['ups_client_secret'] ?? '' ) ) );
		update_option( $M::USPS_CONSUMER_KEY_OPTION, sanitize_text_field( (string) ( $params['usps_consumer_key'] ?? '' ) ) );
		update_option( $M::USPS_CONSUMER_SECRET_OPTION, sanitize_text_field( (string) ( $params['usps_consumer_secret'] ?? '' ) ) );

		update_option( $M::LIVE_PREVIEW_ENABLED_OPTION, (bool) ( $params['live_preview_enabled'] ?? false ) );

		update_option( $M::PROMO_ENABLED_OPTION, (bool) ( $params['promo_enabled'] ?? false ) );
		$promo_theme = sanitize_key( (string) ( $params['promo_theme'] ?? '' ) );
		update_option( $M::PROMO_THEME_OPTION, null !== YeffoPrint_Promo_Themes::get( $promo_theme ) ? $promo_theme : $M::PROMO_THEME_DEFAULT );
		update_option( $M::PROMO_OFFER_OPTION, sanitize_text_field( (string) ( $params['promo_offer'] ?? '' ) ) );
		update_option( $M::PROMO_CODE_OPTION, sanitize_text_field( (string) ( $params['promo_code'] ?? '' ) ) );

		update_option( $M::CONTACT_RECIPIENT_EMAIL_OPTION, sanitize_email( (string) ( $params['contact_recipient_email'] ?? '' ) ) );

		update_option( $M::SPLASH_ENABLED_OPTION, (bool) ( $params['splash_enabled'] ?? false ) );
		update_option( $M::SPLASH_IMAGE_ID_OPTION, absint( $params['splash_image_id'] ?? 0 ) );

		update_option( $M::DASHBOARD_DUE_DATE_DAYS_OPTION, max( 1, (int) ( $params['dashboard_due_date_days'] ?? $M::DASHBOARD_DUE_DATE_DAYS_DEFAULT ) ) );

		update_option( $M::MAINTENANCE_PAYMENT_LINK_OPTION, esc_url_raw( (string) ( $params['maintenance_payment_link'] ?? '' ) ) );
		update_option( YeffoPrint_Stripe_Webhook_Secret::OPTION_KEY, sanitize_text_field( (string) ( $params['maintenance_webhook_secret'] ?? '' ) ) );

		update_option( $M::TELEGRAM_BOT_TOKEN_OPTION, sanitize_text_field( (string) ( $params['telegram_bot_token'] ?? '' ) ) );
		update_option( $M::TELEGRAM_ENABLED_OPTION, (bool) ( $params['telegram_enabled'] ?? false ) );

		return rest_ensure_response( $this->payload() );
	}

	private function payload(): array {
		$M = 'YeffoPrint_Admin_Menu';

		$promo_themes = [];
		foreach ( YeffoPrint_Promo_Themes::all() as $slug => $theme ) {
			$promo_themes[ $slug ] = $theme['label'];
		}

		$splash_image_id = (int) get_option( $M::SPLASH_IMAGE_ID_OPTION, 0 );

		return [
			'announcement_bar_text'      => (string) get_option( $M::ANNOUNCEMENT_BAR_OPTION, $M::ANNOUNCEMENT_BAR_DEFAULT ),
			'ups_client_id'              => (string) get_option( $M::UPS_CLIENT_ID_OPTION, '' ),
			'ups_client_secret'          => (string) get_option( $M::UPS_CLIENT_SECRET_OPTION, '' ),
			'usps_consumer_key'          => (string) get_option( $M::USPS_CONSUMER_KEY_OPTION, '' ),
			'usps_consumer_secret'       => (string) get_option( $M::USPS_CONSUMER_SECRET_OPTION, '' ),
			'live_preview_enabled'       => (bool) get_option( $M::LIVE_PREVIEW_ENABLED_OPTION, true ),
			'promo_enabled'              => (bool) get_option( $M::PROMO_ENABLED_OPTION, false ),
			'promo_theme'                => (string) get_option( $M::PROMO_THEME_OPTION, $M::PROMO_THEME_DEFAULT ),
			'promo_offer'                => (string) get_option( $M::PROMO_OFFER_OPTION, $M::PROMO_OFFER_DEFAULT ),
			'promo_code'                 => (string) get_option( $M::PROMO_CODE_OPTION, '' ),
			'promo_themes'               => $promo_themes,
			'contact_recipient_email'    => (string) get_option( $M::CONTACT_RECIPIENT_EMAIL_OPTION, $M::CONTACT_RECIPIENT_EMAIL_DEFAULT ),
			'splash_enabled'             => (bool) get_option( $M::SPLASH_ENABLED_OPTION, false ),
			'splash_image_id'            => $splash_image_id,
			'splash_image_url'           => $splash_image_id ? ( wp_get_attachment_image_url( $splash_image_id, 'medium' ) ?: '' ) : '',
			'dashboard_due_date_days'    => (int) get_option( $M::DASHBOARD_DUE_DATE_DAYS_OPTION, $M::DASHBOARD_DUE_DATE_DAYS_DEFAULT ),
			'maintenance_payment_link'   => (string) get_option( $M::MAINTENANCE_PAYMENT_LINK_OPTION, '' ),
			'maintenance_webhook_secret' => YeffoPrint_Stripe_Webhook_Secret::get(),
			'maintenance_webhook_url'    => esc_url_raw( rest_url( 'yeffoprint-core/v1/stripe/webhook' ) ),
			'telegram_bot_token'         => (string) get_option( $M::TELEGRAM_BOT_TOKEN_OPTION, '' ),
			'telegram_enabled'           => (bool) get_option( $M::TELEGRAM_ENABLED_OPTION, false ),
			'telegram_webhook_url'       => esc_url_raw( YeffoPrint_Telegram_Webhook_Secret::webhook_url() ),
			'telegram_status'            => YeffoPrint_Telegram_Webhook_Sync::last_message(),
		];
	}
}
