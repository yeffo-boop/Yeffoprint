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

		update_option( YeffoPrint_Shippo_Settings::API_KEY_OPTION, sanitize_text_field( (string) ( $params['shippo_api_key'] ?? '' ) ) );
		update_option( YeffoPrint_Shippo_Settings::SHIP_FROM_PHONE_OPTION, sanitize_text_field( (string) ( $params['shippo_ship_from_phone'] ?? '' ) ) );
		update_option( YeffoPrint_Shippo_Settings::DEFAULT_WEIGHT_OZ_OPTION, max( 0.1, (float) ( $params['shippo_default_weight_oz'] ?? 4 ) ) );
		update_option( YeffoPrint_Shippo_Settings::DEFAULT_LENGTH_IN_OPTION, max( 0.1, (float) ( $params['shippo_default_length_in'] ?? 8 ) ) );
		update_option( YeffoPrint_Shippo_Settings::DEFAULT_WIDTH_IN_OPTION, max( 0.1, (float) ( $params['shippo_default_width_in'] ?? 6 ) ) );
		update_option( YeffoPrint_Shippo_Settings::DEFAULT_HEIGHT_IN_OPTION, max( 0.1, (float) ( $params['shippo_default_height_in'] ?? 1 ) ) );

		update_option( $M::LIVE_PREVIEW_ENABLED_OPTION, (bool) ( $params['live_preview_enabled'] ?? false ) );

		// 0 is "off" (every Template keeps its own fields) — validated
		// against a real, current yp_field_preset rather than trusted
		// outright, same defense class-field-schema.php's own read-side
		// resolve_effective_id() already applies.
		$default_field_preset_id = absint( $params['default_field_preset_id'] ?? 0 );
		if ( $default_field_preset_id && 'yp_field_preset' !== get_post_type( $default_field_preset_id ) ) {
			$default_field_preset_id = 0;
		}
		update_option( $M::DEFAULT_FIELD_PRESET_ID_OPTION, $default_field_preset_id );

		update_option( $M::PROMO_ENABLED_OPTION, (bool) ( $params['promo_enabled'] ?? false ) );
		$M::save_promo_banners( is_array( $params['promo_banners'] ?? null ) ? $params['promo_banners'] : [] );

		update_option( $M::CONTACT_RECIPIENT_EMAIL_OPTION, sanitize_email( (string) ( $params['contact_recipient_email'] ?? '' ) ) );

		update_option( $M::SPLASH_ENABLED_OPTION, (bool) ( $params['splash_enabled'] ?? false ) );
		update_option( $M::SPLASH_IMAGE_ID_OPTION, absint( $params['splash_image_id'] ?? 0 ) );

		update_option( $M::DASHBOARD_DUE_DATE_DAYS_OPTION, max( 1, (int) ( $params['dashboard_due_date_days'] ?? $M::DASHBOARD_DUE_DATE_DAYS_DEFAULT ) ) );

		update_option( $M::MAINTENANCE_PAYMENT_LINK_OPTION, esc_url_raw( (string) ( $params['maintenance_payment_link'] ?? '' ) ) );
		update_option( YeffoPrint_Stripe_Webhook_Secret::OPTION_KEY, sanitize_text_field( (string) ( $params['maintenance_webhook_secret'] ?? '' ) ) );

		update_option( $M::TELEGRAM_BOT_TOKEN_OPTION, sanitize_text_field( (string) ( $params['telegram_bot_token'] ?? '' ) ) );
		update_option( $M::TELEGRAM_ENABLED_OPTION, (bool) ( $params['telegram_enabled'] ?? false ) );
		update_option( $M::TELEGRAM_BOT_USERNAME_OPTION, ltrim( sanitize_text_field( (string) ( $params['telegram_bot_username'] ?? '' ) ), '@' ) );
		update_option( $M::TELEGRAM_ADMIN_CHAT_ID_OPTION, sanitize_text_field( (string) ( $params['telegram_admin_chat_id'] ?? '' ) ) );
		update_option( $M::TELEGRAM_LOGIN_ENABLED_OPTION, (bool) ( $params['telegram_login_enabled'] ?? false ) );

		update_option( $M::GOOGLE_LOGIN_ENABLED_OPTION, (bool) ( $params['google_login_enabled'] ?? false ) );
		update_option( $M::GOOGLE_CLIENT_ID_OPTION, sanitize_text_field( (string) ( $params['google_client_id'] ?? '' ) ) );
		update_option( $M::GOOGLE_CLIENT_SECRET_OPTION, sanitize_text_field( (string) ( $params['google_client_secret'] ?? '' ) ) );
		update_option( $M::DISCORD_LOGIN_ENABLED_OPTION, (bool) ( $params['discord_login_enabled'] ?? false ) );
		update_option( $M::DISCORD_CLIENT_ID_OPTION, sanitize_text_field( (string) ( $params['discord_client_id'] ?? '' ) ) );
		update_option( $M::DISCORD_CLIENT_SECRET_OPTION, sanitize_text_field( (string) ( $params['discord_client_secret'] ?? '' ) ) );

		update_option( $M::APPLE_LOGIN_ENABLED_OPTION, (bool) ( $params['apple_login_enabled'] ?? false ) );
		update_option( $M::APPLE_CLIENT_ID_OPTION, sanitize_text_field( (string) ( $params['apple_client_id'] ?? '' ) ) );
		update_option( $M::APPLE_TEAM_ID_OPTION, sanitize_text_field( (string) ( $params['apple_team_id'] ?? '' ) ) );
		update_option( $M::APPLE_KEY_ID_OPTION, sanitize_text_field( (string) ( $params['apple_key_id'] ?? '' ) ) );
		// sanitize_textarea_field, not sanitize_text_field — a PEM private key's line breaks are meaningful.
		update_option( $M::APPLE_PRIVATE_KEY_OPTION, sanitize_textarea_field( (string) ( $params['apple_private_key'] ?? '' ) ) );

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
			'shippo_api_key'             => YeffoPrint_Shippo_Settings::get_api_key(),
			'shippo_ship_from_phone'     => (string) get_option( YeffoPrint_Shippo_Settings::SHIP_FROM_PHONE_OPTION, '' ),
			'shippo_default_package'     => YeffoPrint_Shippo_Settings::get_default_package(),
			'shippo_webhook_url'         => esc_url_raw( YeffoPrint_Shippo_Webhook_Secret::webhook_url() ),
			'shippo_webhook_status'      => YeffoPrint_Shippo_Webhook_Sync::last_message(),
			'live_preview_enabled'       => (bool) get_option( $M::LIVE_PREVIEW_ENABLED_OPTION, true ),
			'default_field_preset_id'    => (int) get_option( $M::DEFAULT_FIELD_PRESET_ID_OPTION, 0 ),
			// {id, title} per published preset, for the dropdown — reuses
			// get_presets() (already built for the Template editor's
			// "Insert Preset" list) rather than a second query.
			'field_presets'              => array_map( static function ( array $preset ): array {
				return [ 'id' => $preset['id'], 'title' => $preset['name'] ];
			}, YeffoPrint_Field_Schema::get_presets() ),
			'promo_enabled'              => (bool) get_option( $M::PROMO_ENABLED_OPTION, false ),
			'promo_banners'              => $M::get_promo_banners(),
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
			'telegram_bot_username'      => YeffoPrint_Telegram_Settings::get_bot_username(),
			'telegram_webhook_url'       => esc_url_raw( YeffoPrint_Telegram_Webhook_Secret::webhook_url() ),
			'telegram_status'            => YeffoPrint_Telegram_Webhook_Sync::last_message(),
			'telegram_admin_chat_id'     => (string) get_option( $M::TELEGRAM_ADMIN_CHAT_ID_OPTION, '' ),
			'telegram_login_enabled'     => (bool) get_option( $M::TELEGRAM_LOGIN_ENABLED_OPTION, false ),
			'google_login_enabled'       => (bool) get_option( $M::GOOGLE_LOGIN_ENABLED_OPTION, false ),
			'google_client_id'           => (string) get_option( $M::GOOGLE_CLIENT_ID_OPTION, '' ),
			'google_client_secret'       => (string) get_option( $M::GOOGLE_CLIENT_SECRET_OPTION, '' ),
			'google_redirect_uri'        => esc_url_raw( YeffoPrint_Social_Login::callback_url( 'google' ) ),
			'discord_login_enabled'      => (bool) get_option( $M::DISCORD_LOGIN_ENABLED_OPTION, false ),
			'discord_client_id'          => (string) get_option( $M::DISCORD_CLIENT_ID_OPTION, '' ),
			'discord_client_secret'      => (string) get_option( $M::DISCORD_CLIENT_SECRET_OPTION, '' ),
			'discord_redirect_uri'       => esc_url_raw( YeffoPrint_Social_Login::callback_url( 'discord' ) ),
			'apple_login_enabled'        => (bool) get_option( $M::APPLE_LOGIN_ENABLED_OPTION, false ),
			'apple_client_id'            => (string) get_option( $M::APPLE_CLIENT_ID_OPTION, '' ),
			'apple_team_id'              => (string) get_option( $M::APPLE_TEAM_ID_OPTION, '' ),
			'apple_key_id'               => (string) get_option( $M::APPLE_KEY_ID_OPTION, '' ),
			'apple_private_key'          => (string) get_option( $M::APPLE_PRIVATE_KEY_OPTION, '' ),
			'apple_redirect_uri'         => esc_url_raw( YeffoPrint_Social_Login::callback_url( 'apple' ) ),
		];
	}
}
