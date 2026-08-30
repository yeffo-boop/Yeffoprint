<?php
/**
 * Top-level "YeffoPrint" wp-admin menu.
 *
 * Through Phase 7 (docs/ARCHITECTURE.md), every YeffoPrint post type
 * attached here as its own classic submenu (show_in_menu => 'yeffoprint',
 * class-post-type-registry.php) and this class also registered a
 * classic Settings-API page directly. Phase 8 retired all of that from
 * the sidebar: the custom admin app now has a screen for every one of
 * them, so this menu collapses to a single top-level link straight
 * into that app (register_menu() below) — nothing left to expand into
 * a flyout. The classic Settings page (render_settings_page() and
 * friends) stays fully functional as an unlinked fallback, same as
 * every classic CPT editor.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_Menu {

	/**
	 * Also read by yeffoprint_core_get_announcement_bar_text()
	 * (includes/api/template-api.php) as its own default — kept here,
	 * not duplicated, since this class owns the option's registration.
	 */
	const ANNOUNCEMENT_BAR_OPTION  = 'yeffoprint_announcement_bar_text';
	const ANNOUNCEMENT_BAR_DEFAULT = 'Free proofing on every fully custom order.';

	/**
	 * Also read by YeffoPrint_Rewards (includes/rewards/class-rewards.php)
	 * — same reasoning as the announcement bar option above. Registered
	 * and edited on the dedicated Rewards page (class-rewards-admin.php),
	 * not here — the constants stay on this class since several other
	 * classes already reference them from here.
	 */
	const REWARDS_POINTS_PER_DOLLAR_OPTION = 'yeffoprint_rewards_points_per_dollar';
	const REWARDS_DOLLARS_PER_POINT_OPTION = 'yeffoprint_rewards_dollars_per_point';
	const REWARDS_POINTS_PER_DOLLAR_DEFAULT = 1;
	const REWARDS_DOLLARS_PER_POINT_DEFAULT = 0.01;

	/**
	 * Also read by YeffoPrint_Referrals (includes/rewards/class-
	 * referrals.php) — same reasoning as the options above. Flat points
	 * per successful referral (a referred customer's first paid order),
	 * not a percentage of that order — simpler to advertise ("refer a
	 * friend, earn 500 points") than a cut of a purchase size the
	 * referrer has no control over.
	 */
	const REFERRAL_POINTS_OPTION  = 'yeffoprint_referral_points';
	const REFERRAL_POINTS_DEFAULT = 500;

	/**
	 * Also read by the tracking-providers/ classes — same reasoning as
	 * the rewards options above. Registered and edited on this Settings
	 * page (unlike Rewards/Card Surcharge, tracking credentials didn't
	 * warrant their own dedicated page). Empty until an admin actually
	 * signs up for each carrier's developer program and pastes these in; the
	 * tracking page works before that too (class-order-tracking.php's
	 * direct carrier-site links), just without the live in-page timeline.
	 */
	const UPS_CLIENT_ID_OPTION      = 'yeffoprint_ups_client_id';
	const UPS_CLIENT_SECRET_OPTION  = 'yeffoprint_ups_client_secret';
	const USPS_CONSUMER_KEY_OPTION    = 'yeffoprint_usps_consumer_key';
	const USPS_CONSUMER_SECRET_OPTION = 'yeffoprint_usps_consumer_secret';

	/**
	 * Also read by YeffoPrint_Card_Surcharge (includes/woocommerce/
	 * class-card-surcharge.php) — same reasoning as the options above.
	 * Registered and edited on the dedicated Card Surcharge page
	 * (class-surcharge-admin.php), not here. Direct request: pass
	 * processing fees on to the customer, at a
	 * different rate per gateway (cards vs. Afterpay, etc. each cost a
	 * different percentage to accept). One option, keyed by gateway id,
	 * rather than a separate rate/label option per gateway — a fixed
	 * set of options can't grow to fit however many gateways this store
	 * ends up with. Defaults to an empty array, so nothing is ever
	 * surcharged until an admin explicitly sets a rate for that specific
	 * gateway — a store with, say, Venmo/Zelle plus card gateways should
	 * never end up surcharging the former by a config accident.
	 *
	 * Shape: `[ gateway_id => [ 'rate' => float, 'label' => string ] ]`
	 * — a gateway missing from this array, or with `rate` at or below 0,
	 * is never surcharged.
	 */
	const SURCHARGE_GATEWAY_RATES_OPTION = 'yeffoprint_surcharge_gateway_rates';
	const SURCHARGE_LABEL_DEFAULT         = 'Processing Fee';

	/**
	 * Also read by class-template-schema-controller.php — same reasoning
	 * as the options above. Direct request: a kill switch for the
	 * configurator's live, per-keystroke on-label text preview, for
	 * whenever an admin is mid-way through adjusting field positions
	 * (Template editor) and doesn't want customers seeing not-yet-
	 * correct alignment in the meantime. Off doesn't touch anything
	 * else — customers can still fill in every field, add variants, and
	 * check out; they just don't see live on-image text on Label View
	 * until this is switched back on. Vial View (never live text to
	 * begin with) is unaffected either way.
	 */
	const LIVE_PREVIEW_ENABLED_OPTION = 'yeffoprint_live_preview_enabled';

	/**
	 * Also read by class-field-schema.php. Direct request: "I want to
	 * use the default template preset I made as the template for all
	 * current and future labels. IF I add a field there, it adds to all
	 * templates." Stores a yp_field_preset post id; when set, every
	 * yp_template reads that preset's field_schema instead of its own
	 * (YeffoPrint_Field_Schema::resolve_effective_id()) — a live
	 * override read at fetch time, not a one-time copy like the
	 * existing "Insert Preset" feature. 0 (the default) means off: every
	 * Template keeps its own independent fields, unchanged from before
	 * this option existed.
	 */
	const DEFAULT_FIELD_PRESET_ID_OPTION = 'yeffoprint_default_field_preset_id';

	/**
	 * Also read by yeffoprint/blocks/promo-banner's render.php — same
	 * reasoning as the options above. Direct request: a homepage promo
	 * banner an admin can turn on and pick a theme for
	 * (YeffoPrint_Promo_Themes::all()) — and, per a follow-up direct
	 * request ("select more than one active promo banner and have it
	 * slide through the active ones"), *multiple* themes can each carry
	 * their own offer/code at once, rotating on the frontend. Stored as
	 * one option, a map of theme slug => {offer, code} — a theme is
	 * "active" purely by having both filled in for it (get_active_
	 * banners() below), the exact same gate the single-banner version
	 * already used, just applied per theme instead of once globally.
	 * PROMO_ENABLED_OPTION is still the master switch on top of that,
	 * unchanged: off means nothing shows regardless of how many themes
	 * have an offer/code saved.
	 */
	const PROMO_ENABLED_OPTION = 'yeffoprint_promo_enabled';
	const PROMO_BANNERS_OPTION = 'yeffoprint_promo_banners';

	/**
	 * The single-banner options PROMO_BANNERS_OPTION replaced — no
	 * longer registered as settings or shown in either admin surface,
	 * kept only so get_promo_banners() can migrate a site's pre-existing
	 * selection into the new shape exactly once. See that method.
	 */
	const PROMO_THEME_OPTION_LEGACY = 'yeffoprint_promo_theme';
	const PROMO_CODE_OPTION_LEGACY  = 'yeffoprint_promo_code';
	const PROMO_OFFER_OPTION_LEGACY = 'yeffoprint_promo_offer';

	/** Also read by class-contact-controller.php — same reasoning as the options above. */
	const CONTACT_RECIPIENT_EMAIL_OPTION  = 'yeffoprint_contact_recipient_email';
	const CONTACT_RECIPIENT_EMAIL_DEFAULT = 'yeffo@yeffoprint.com';

	/**
	 * Also read by functions.php's wp_footer splash-screen renderer —
	 * same reasoning as the options above. Direct request, for a brand-
	 * new site: a dismissible "we've upgraded" splash on the homepage,
	 * with a kill switch here for once it's no longer needed — rather
	 * than deleting/re-adding code each time, an admin just unchecks
	 * this box. Off by default: nothing to show until an admin has
	 * actually picked a screenshot below.
	 */
	const SPLASH_ENABLED_OPTION  = 'yeffoprint_splash_enabled';
	const SPLASH_IMAGE_ID_OPTION = 'yeffoprint_splash_image_id';

	/**
	 * Also read by YeffoPrint_Dashboard_Widgets (includes/admin/class-
	 * dashboard-widgets.php) — same reasoning as the options above.
	 * Direct request: how many days after an order/custom-order-request
	 * date the dashboard's Pending Orders/Pending Proofs/Awaiting
	 * Approval tables flag a row as overdue. Registered and edited here
	 * since it's a general operational setting, not specific enough to
	 * any one section to warrant its own dedicated page the way Rewards/
	 * Card Surcharge got.
	 */
	const DASHBOARD_DUE_DATE_DAYS_OPTION  = 'yeffoprint_dashboard_due_date_days';
	const DASHBOARD_DUE_DATE_DAYS_DEFAULT = 7;

	/**
	 * The maintenance-plan subscription is sold via a direct Stripe
	 * Payment Link, not a WooCommerce product — this is where that link
	 * (and the Stripe webhook signing secret that authenticates the
	 * matching webhook, class-stripe-webhook-secret.php) get pasted in,
	 * since neither one is generated by this site. See
	 * includes/maintenance/ and docs/ARCHITECTURE.md.
	 */
	const MAINTENANCE_PAYMENT_LINK_OPTION = 'yeffoprint_maintenance_payment_link';

	/**
	 * Also read by includes/telegram/* — same reasoning as the options
	 * above. The bot token can also be set via a
	 * YEFFOPRINT_TELEGRAM_BOT_TOKEN wp-config.php constant instead
	 * (checked first, see YeffoPrint_Telegram_Settings::get_bot_token())
	 * for admins who'd rather keep it out of the database; this option
	 * stays the default path so the bot can be turned on entirely from
	 * Settings, no code access required. Saving either option here
	 * re-registers (or tears down) the Telegram webhook automatically —
	 * see class-telegram-webhook-sync.php.
	 */
	const TELEGRAM_BOT_TOKEN_OPTION = 'yeffoprint_telegram_bot_token';
	const TELEGRAM_ENABLED_OPTION   = 'yeffoprint_telegram_enabled';

	/**
	 * Also read by class-telegram-admin-alerts.php — same reasoning as
	 * the Telegram options above. A new paid order (or custom design
	 * request) and a Contact form submission get pushed here. Message
	 * /whoami to the bot from your own Telegram account to get this
	 * number.
	 */
	const TELEGRAM_ADMIN_CHAT_ID_OPTION = 'yeffoprint_telegram_admin_chat_id';

	/**
	 * Also read by includes/telegram/class-telegram-login.php. Direct
	 * request: "allow users to login to the site using their telegram
	 * account" — Telegram's own official Login Widget, not a hand-rolled
	 * scheme. Reuses the same bot token above rather than needing its
	 * own credentials (unlike Google/Discord/Apple, which each need a
	 * separate app registration) — the one extra setup step is entirely
	 * on Telegram's side: the bot's domain has to be authorized for the
	 * widget via @BotFather's `/setdomain` command, or Telegram simply
	 * refuses to render/authenticate it. Independent of TELEGRAM_ENABLED_
	 * OPTION above (that one is specifically "respond to bot messages")
	 * — the widget only needs a valid token, not the message webhook.
	 */
	const TELEGRAM_LOGIN_ENABLED_OPTION = 'yeffoprint_telegram_login_enabled';

	/**
	 * Also read by includes/accounts/class-social-login.php — same
	 * reasoning as the options above. Direct request: "allow users to
	 * login with Google/Apple/Discord" — Google and Discord shipped
	 * first (free, few-minutes developer-app registration each); Apple
	 * followed once the owner was ready for its bigger setup lift (paid
	 * Apple Developer Program membership, domain verification). Each
	 * provider stays off (and its button hidden) until an admin both
	 * checks its box and pastes in a real Client ID/Secret.
	 */
	const GOOGLE_LOGIN_ENABLED_OPTION  = 'yeffoprint_google_login_enabled';
	const GOOGLE_CLIENT_ID_OPTION      = 'yeffoprint_google_client_id';
	const GOOGLE_CLIENT_SECRET_OPTION  = 'yeffoprint_google_client_secret';
	const DISCORD_LOGIN_ENABLED_OPTION = 'yeffoprint_discord_login_enabled';
	const DISCORD_CLIENT_ID_OPTION     = 'yeffoprint_discord_client_id';
	const DISCORD_CLIENT_SECRET_OPTION = 'yeffoprint_discord_client_secret';

	/**
	 * Also read by includes/accounts/class-social-login.php — same
	 * reasoning as the options above. Apple's own OAuth flow needs more
	 * than a client id/secret pair: a Services ID (the "client id" Apple
	 * actually calls it), a 10-character Team ID, a Key ID naming which
	 * of that team's private keys to use, and the private key itself
	 * (the .p8 file's contents, downloaded once from Apple and never
	 * shown again). There's no ENABLED_KEY_SECRET option here the way
	 * Google/Discord have one — class-social-login.php's
	 * generate_apple_client_secret() signs a fresh, short-lived JWT from
	 * these four values on every token exchange instead of storing a
	 * long-lived secret, which is what sidesteps Apple's usual "rotate
	 * the client secret every 6 months" operational burden entirely.
	 */
	const APPLE_LOGIN_ENABLED_OPTION = 'yeffoprint_apple_login_enabled';
	const APPLE_CLIENT_ID_OPTION     = 'yeffoprint_apple_client_id';
	const APPLE_TEAM_ID_OPTION       = 'yeffoprint_apple_team_id';
	const APPLE_KEY_ID_OPTION        = 'yeffoprint_apple_key_id';
	const APPLE_PRIVATE_KEY_OPTION   = 'yeffoprint_apple_private_key';

	/** Hook suffix for the Settings screen, captured from add_submenu_page()'s own return value — see enqueue_settings_assets(). */
	private $settings_page_hook = '';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		// Runs after WordPress has built every submenu (including the
		// Custom Orders CPT one, auto-attached via show_in_menu =>
		// 'yeffoprint' in class-post-type-registry.php rather than
		// registered here) — a fixed late priority is simpler and just
		// as reliable as chasing an exact "after CPT registration" hook.
		add_action( 'admin_menu', [ $this, 'add_needs_attention_badge' ], 999 );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_settings_assets' ] );
	}

	public function register_menu(): void {
		// Render callback is the new custom admin app's shell
		// (YeffoPrint_Admin_App::render(), includes/admin-app/), not
		// render_dashboard() below — see docs/ARCHITECTURE.md's admin-
		// dashboard plan. render_dashboard()/quick_links() stay in place,
		// unused: kept as dead code rather than deleted, same "safety net,
		// not a dead end" reasoning as every classic editor class Phase 8
		// unlinked but didn't remove.
		$dashboard_hook = (string) add_menu_page(
			__( 'YeffoDesign', 'yeffoprint-core' ),
			__( 'YeffoDesign', 'yeffoprint-core' ),
			'manage_options',
			'yeffoprint',
			[ 'YeffoPrint_Admin_App', 'render' ],
			'dashicons-store',
			25
		);

		// Phase 8 (docs/ARCHITECTURE.md): every other classic submenu that
		// used to live under this one (Design Setup, Pricing Rules, Custom
		// Orders, Proofs, Field Presets, Maintenance Subscribers, Web
		// Design Packages, Settings below) is gone now that the custom
		// admin app has a screen for all of them — see
		// class-post-type-registry.php's own Phase 8 comments for the
		// CPT side of this. With zero submenus left at the 'yeffoprint'
		// parent slug, WordPress's top-level sidebar icon is just a plain
		// link straight to add_menu_page()'s own callback above — no
		// flyout, nothing to click through. That also means the
		// duplicate-"YeffoPrint"-submenu quirk this class used to work
		// around by registering an explicit "Dashboard" self-link
		// (WP only auto-inserts that duplicate once *other* submenu
		// siblings exist) can no longer trigger, so that explicit
		// registration was removed rather than left as a pointless
		// second route to the exact same callback.
		$this->settings_page_hook = (string) add_submenu_page(
			null, // Reachable at its direct URL, deliberately not shown in any menu — same "unlinked fallback" treatment as every classic CPT screen.
			__( 'Settings', 'yeffoprint-core' ),
			__( 'Settings', 'yeffoprint-core' ),
			'manage_options',
			'yeffoprint-settings',
			[ $this, 'render_settings_page' ]
		);

		// The 'yeffoprint' page hook now belongs to the new app shell
		// (YeffoPrint_Admin_App::set_hook_suffix() below), which enqueues
		// its own assets entirely and needs none of the classic reskin's
		// stylesheet — that page renders no postboxes/list tables/wrap
		// div for it to style. Settings is still a plain Settings-API
		// page, so it keeps using the classic reskin as before.
		YeffoPrint_Admin_App::set_hook_suffix( $dashboard_hook );
		YeffoPrint_Admin_Shell::register_page_hook( $this->settings_page_hook );
	}

	/** wp.media, for the splash screenshot picker below — only on the Settings screen itself, not every wp-admin page. */
	public function enqueue_settings_assets( string $hook ): void {
		if ( ! $this->settings_page_hook || $hook !== $this->settings_page_hook ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_script(
			'yeffoprint-core-vial-mockup-picker', // Generic wp.media picker script from Phase 4 — reused as-is (see class-material-size-editor.php/class-proof-editor.php for the same pattern).
			YEFFOPRINT_CORE_URL . 'assets/admin/vial-mockup-picker.js',
			[ 'media-editor' ],
			yeffoprint_core_asset_version( 'assets/admin/vial-mockup-picker.js' ),
			true
		);
	}

	public function register_settings(): void {
		register_setting( 'yeffoprint_settings', self::ANNOUNCEMENT_BAR_OPTION, [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => self::ANNOUNCEMENT_BAR_DEFAULT,
		] );

		add_settings_section(
			'yeffoprint_announcement_bar',
			__( 'Announcement Bar', 'yeffoprint-core' ),
			'__return_false',
			'yeffoprint-settings'
		);

		add_settings_field(
			self::ANNOUNCEMENT_BAR_OPTION,
			__( 'Announcement text', 'yeffoprint-core' ),
			[ $this, 'render_announcement_bar_field' ],
			'yeffoprint-settings',
			'yeffoprint_announcement_bar'
		);

		register_setting( 'yeffoprint_settings', self::UPS_CLIENT_ID_OPTION, [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ] );
		register_setting( 'yeffoprint_settings', self::UPS_CLIENT_SECRET_OPTION, [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ] );
		register_setting( 'yeffoprint_settings', self::USPS_CONSUMER_KEY_OPTION, [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ] );
		register_setting( 'yeffoprint_settings', self::USPS_CONSUMER_SECRET_OPTION, [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ] );

		add_settings_section(
			'yeffoprint_tracking',
			__( 'Shipment Tracking', 'yeffoprint-core' ),
			[ $this, 'render_tracking_section_intro' ],
			'yeffoprint-settings'
		);

		add_settings_field(
			self::UPS_CLIENT_ID_OPTION,
			__( 'UPS Client ID', 'yeffoprint-core' ),
			[ $this, 'render_ups_client_id_field' ],
			'yeffoprint-settings',
			'yeffoprint_tracking'
		);

		add_settings_field(
			self::UPS_CLIENT_SECRET_OPTION,
			__( 'UPS Client Secret', 'yeffoprint-core' ),
			[ $this, 'render_ups_client_secret_field' ],
			'yeffoprint-settings',
			'yeffoprint_tracking'
		);

		add_settings_field(
			self::USPS_CONSUMER_KEY_OPTION,
			__( 'USPS Consumer Key', 'yeffoprint-core' ),
			[ $this, 'render_usps_consumer_key_field' ],
			'yeffoprint-settings',
			'yeffoprint_tracking'
		);

		add_settings_field(
			self::USPS_CONSUMER_SECRET_OPTION,
			__( 'USPS Consumer Secret', 'yeffoprint-core' ),
			[ $this, 'render_usps_consumer_secret_field' ],
			'yeffoprint-settings',
			'yeffoprint_tracking'
		);

		register_setting( 'yeffoprint_settings', self::LIVE_PREVIEW_ENABLED_OPTION, [
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => true,
		] );

		add_settings_section(
			'yeffoprint_live_preview',
			__( 'Label Configurator', 'yeffoprint-core' ),
			'__return_false',
			'yeffoprint-settings'
		);

		add_settings_field(
			self::LIVE_PREVIEW_ENABLED_OPTION,
			__( 'Live preview', 'yeffoprint-core' ),
			[ $this, 'render_live_preview_field' ],
			'yeffoprint-settings',
			'yeffoprint_live_preview'
		);

		register_setting( 'yeffoprint_settings', self::PROMO_ENABLED_OPTION, [
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
		] );

		register_setting( 'yeffoprint_settings', self::PROMO_BANNERS_OPTION, [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize_promo_banners' ],
			'default'           => [],
		] );

		add_settings_section(
			'yeffoprint_promo',
			__( 'Homepage Promo', 'yeffoprint-core' ),
			[ $this, 'render_promo_section_intro' ],
			'yeffoprint-settings'
		);

		add_settings_field(
			self::PROMO_ENABLED_OPTION,
			__( 'Show promo banner', 'yeffoprint-core' ),
			[ $this, 'render_promo_enabled_field' ],
			'yeffoprint-settings',
			'yeffoprint_promo'
		);

		add_settings_field(
			self::PROMO_BANNERS_OPTION,
			__( 'Banners', 'yeffoprint-core' ),
			[ $this, 'render_promo_banners_field' ],
			'yeffoprint-settings',
			'yeffoprint_promo'
		);

		register_setting( 'yeffoprint_settings', self::CONTACT_RECIPIENT_EMAIL_OPTION, [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_email',
			'default'           => self::CONTACT_RECIPIENT_EMAIL_DEFAULT,
		] );

		add_settings_section(
			'yeffoprint_contact',
			__( 'Contact Form', 'yeffoprint-core' ),
			'__return_false',
			'yeffoprint-settings'
		);

		add_settings_field(
			self::CONTACT_RECIPIENT_EMAIL_OPTION,
			__( 'Send messages to', 'yeffoprint-core' ),
			[ $this, 'render_contact_recipient_email_field' ],
			'yeffoprint-settings',
			'yeffoprint_contact'
		);

		register_setting( 'yeffoprint_settings', self::SPLASH_ENABLED_OPTION, [
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
		] );

		register_setting( 'yeffoprint_settings', self::SPLASH_IMAGE_ID_OPTION, [
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		] );

		add_settings_section(
			'yeffoprint_splash',
			__( 'Splash Screen', 'yeffoprint-core' ),
			[ $this, 'render_splash_section_intro' ],
			'yeffoprint-settings'
		);

		add_settings_field(
			self::SPLASH_ENABLED_OPTION,
			__( 'Show splash screen', 'yeffoprint-core' ),
			[ $this, 'render_splash_enabled_field' ],
			'yeffoprint-settings',
			'yeffoprint_splash'
		);

		add_settings_field(
			self::SPLASH_IMAGE_ID_OPTION,
			__( 'Screenshot', 'yeffoprint-core' ),
			[ $this, 'render_splash_image_field' ],
			'yeffoprint-settings',
			'yeffoprint_splash'
		);

		register_setting( 'yeffoprint_settings', self::DASHBOARD_DUE_DATE_DAYS_OPTION, [
			'type'              => 'integer',
			'sanitize_callback' => [ $this, 'sanitize_due_date_days' ],
			'default'           => self::DASHBOARD_DUE_DATE_DAYS_DEFAULT,
		] );

		add_settings_section(
			'yeffoprint_dashboard',
			__( 'Dashboard', 'yeffoprint-core' ),
			'__return_false',
			'yeffoprint-settings'
		);

		add_settings_field(
			self::DASHBOARD_DUE_DATE_DAYS_OPTION,
			__( 'Order due date', 'yeffoprint-core' ),
			[ $this, 'render_dashboard_due_date_days_field' ],
			'yeffoprint-settings',
			'yeffoprint_dashboard'
		);

		register_setting( 'yeffoprint_settings', self::MAINTENANCE_PAYMENT_LINK_OPTION, [
			'type'              => 'string',
			'sanitize_callback' => 'esc_url_raw',
			'default'           => '',
		] );

		register_setting( 'yeffoprint_settings', YeffoPrint_Stripe_Webhook_Secret::OPTION_KEY, [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		] );

		add_settings_section(
			'yeffoprint_maintenance',
			__( 'Maintenance Subscription', 'yeffoprint-core' ),
			[ $this, 'render_maintenance_section_intro' ],
			'yeffoprint-settings'
		);

		add_settings_field(
			self::MAINTENANCE_PAYMENT_LINK_OPTION,
			__( 'Payment Link URL', 'yeffoprint-core' ),
			[ $this, 'render_maintenance_payment_link_field' ],
			'yeffoprint-settings',
			'yeffoprint_maintenance'
		);

		add_settings_field(
			YeffoPrint_Stripe_Webhook_Secret::OPTION_KEY,
			__( 'Stripe webhook signing secret', 'yeffoprint-core' ),
			[ $this, 'render_maintenance_webhook_secret_field' ],
			'yeffoprint-settings',
			'yeffoprint_maintenance'
		);

		register_setting( 'yeffoprint_settings', self::TELEGRAM_BOT_TOKEN_OPTION, [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		] );

		register_setting( 'yeffoprint_settings', self::TELEGRAM_ENABLED_OPTION, [
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
		] );

		add_settings_section(
			'yeffoprint_telegram',
			__( 'Telegram Bot', 'yeffoprint-core' ),
			[ $this, 'render_telegram_section_intro' ],
			'yeffoprint-settings'
		);

		add_settings_field(
			self::TELEGRAM_ENABLED_OPTION,
			__( 'Bot is active', 'yeffoprint-core' ),
			[ $this, 'render_telegram_enabled_field' ],
			'yeffoprint-settings',
			'yeffoprint_telegram'
		);

		add_settings_field(
			self::TELEGRAM_BOT_TOKEN_OPTION,
			__( 'Bot token', 'yeffoprint-core' ),
			[ $this, 'render_telegram_bot_token_field' ],
			'yeffoprint-settings',
			'yeffoprint_telegram'
		);

		register_setting( 'yeffoprint_settings', self::TELEGRAM_ADMIN_CHAT_ID_OPTION, [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		] );

		add_settings_field(
			self::TELEGRAM_ADMIN_CHAT_ID_OPTION,
			__( 'Your chat ID (for alerts)', 'yeffoprint-core' ),
			[ $this, 'render_telegram_admin_chat_id_field' ],
			'yeffoprint-settings',
			'yeffoprint_telegram'
		);

		register_setting( 'yeffoprint_settings', self::TELEGRAM_LOGIN_ENABLED_OPTION, [
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
		] );

		add_settings_field(
			self::TELEGRAM_LOGIN_ENABLED_OPTION,
			__( 'Log in with Telegram', 'yeffoprint-core' ),
			[ $this, 'render_telegram_login_enabled_field' ],
			'yeffoprint-settings',
			'yeffoprint_telegram'
		);

		register_setting( 'yeffoprint_settings', self::GOOGLE_LOGIN_ENABLED_OPTION, [ 'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => false ] );
		register_setting( 'yeffoprint_settings', self::GOOGLE_CLIENT_ID_OPTION, [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );
		register_setting( 'yeffoprint_settings', self::GOOGLE_CLIENT_SECRET_OPTION, [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );
		register_setting( 'yeffoprint_settings', self::DISCORD_LOGIN_ENABLED_OPTION, [ 'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => false ] );
		register_setting( 'yeffoprint_settings', self::DISCORD_CLIENT_ID_OPTION, [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );
		register_setting( 'yeffoprint_settings', self::DISCORD_CLIENT_SECRET_OPTION, [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );

		register_setting( 'yeffoprint_settings', self::APPLE_LOGIN_ENABLED_OPTION, [ 'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => false ] );
		register_setting( 'yeffoprint_settings', self::APPLE_CLIENT_ID_OPTION, [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );
		register_setting( 'yeffoprint_settings', self::APPLE_TEAM_ID_OPTION, [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );
		register_setting( 'yeffoprint_settings', self::APPLE_KEY_ID_OPTION, [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );
		// sanitize_textarea_field, not sanitize_text_field — a PEM private key's line breaks are meaningful; sanitize_text_field would collapse them.
		register_setting( 'yeffoprint_settings', self::APPLE_PRIVATE_KEY_OPTION, [ 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '' ] );

		add_settings_section(
			'yeffoprint_social_login',
			__( 'Social Login', 'yeffoprint-core' ),
			[ $this, 'render_social_login_section_intro' ],
			'yeffoprint-settings'
		);

		add_settings_field( self::GOOGLE_LOGIN_ENABLED_OPTION, __( 'Google', 'yeffoprint-core' ), [ $this, 'render_google_enabled_field' ], 'yeffoprint-settings', 'yeffoprint_social_login' );
		add_settings_field( self::GOOGLE_CLIENT_ID_OPTION, __( 'Google Client ID', 'yeffoprint-core' ), [ $this, 'render_google_client_id_field' ], 'yeffoprint-settings', 'yeffoprint_social_login' );
		add_settings_field( self::GOOGLE_CLIENT_SECRET_OPTION, __( 'Google Client Secret', 'yeffoprint-core' ), [ $this, 'render_google_client_secret_field' ], 'yeffoprint-settings', 'yeffoprint_social_login' );
		add_settings_field( self::DISCORD_LOGIN_ENABLED_OPTION, __( 'Discord', 'yeffoprint-core' ), [ $this, 'render_discord_enabled_field' ], 'yeffoprint-settings', 'yeffoprint_social_login' );
		add_settings_field( self::DISCORD_CLIENT_ID_OPTION, __( 'Discord Client ID', 'yeffoprint-core' ), [ $this, 'render_discord_client_id_field' ], 'yeffoprint-settings', 'yeffoprint_social_login' );
		add_settings_field( self::DISCORD_CLIENT_SECRET_OPTION, __( 'Discord Client Secret', 'yeffoprint-core' ), [ $this, 'render_discord_client_secret_field' ], 'yeffoprint-settings', 'yeffoprint_social_login' );
		add_settings_field( self::APPLE_LOGIN_ENABLED_OPTION, __( 'Apple', 'yeffoprint-core' ), [ $this, 'render_apple_enabled_field' ], 'yeffoprint-settings', 'yeffoprint_social_login' );
		add_settings_field( self::APPLE_CLIENT_ID_OPTION, __( 'Apple Services ID', 'yeffoprint-core' ), [ $this, 'render_apple_client_id_field' ], 'yeffoprint-settings', 'yeffoprint_social_login' );
		add_settings_field( self::APPLE_TEAM_ID_OPTION, __( 'Apple Team ID', 'yeffoprint-core' ), [ $this, 'render_apple_team_id_field' ], 'yeffoprint-settings', 'yeffoprint_social_login' );
		add_settings_field( self::APPLE_KEY_ID_OPTION, __( 'Apple Key ID', 'yeffoprint-core' ), [ $this, 'render_apple_key_id_field' ], 'yeffoprint-settings', 'yeffoprint_social_login' );
		add_settings_field( self::APPLE_PRIVATE_KEY_OPTION, __( 'Apple Private Key (.p8 contents)', 'yeffoprint-core' ), [ $this, 'render_apple_private_key_field' ], 'yeffoprint-settings', 'yeffoprint_social_login' );
	}

	public function render_maintenance_section_intro(): void {
		?>
		<p><?php esc_html_e( 'The maintenance/monitoring subscription is sold via a Stripe Payment Link, created directly in your Stripe Dashboard (separate from WooPayments) — paste that link and the webhook signing secret here once both are set up.', 'yeffoprint-core' ); ?></p>
		<?php
	}

	public function render_maintenance_payment_link_field(): void {
		$value = get_option( self::MAINTENANCE_PAYMENT_LINK_OPTION, '' );
		?>
		<input
			type="url"
			class="regular-text"
			name="<?php echo esc_attr( self::MAINTENANCE_PAYMENT_LINK_OPTION ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			placeholder="https://buy.stripe.com/..."
		/>
		<p class="description"><?php esc_html_e( 'The "Subscribe to Maintenance" button on the Web Design page links here. Left blank, it links to the Contact page instead.', 'yeffoprint-core' ); ?></p>
		<?php
	}

	public function render_maintenance_webhook_secret_field(): void {
		$value = YeffoPrint_Stripe_Webhook_Secret::get();
		?>
		<input
			type="password"
			class="regular-text"
			autocomplete="off"
			name="<?php echo esc_attr( YeffoPrint_Stripe_Webhook_Secret::OPTION_KEY ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			placeholder="whsec_..."
		/>
		<p class="description">
			<?php echo wp_kses(
				sprintf(
					/* translators: %s: the webhook endpoint URL to paste into Stripe */
					__( 'From the webhook endpoint you create in the Stripe Dashboard, pointed at: <code>%s</code>', 'yeffoprint-core' ),
					esc_html( rest_url( 'yeffoprint-core/v1/stripe/webhook' ) )
				),
				[ 'code' => [] ]
			); ?>
		</p>
		<?php
	}

	public function render_telegram_section_intro(): void {
		?>
		<p><?php
			echo wp_kses(
				sprintf(
					/* translators: %s: the webhook endpoint URL Telegram is pointed at */
					__( 'Answers FAQs and order-status questions for customers on Telegram. Create a bot with @BotFather, paste its token below, and check "Bot is active" — saving this page registers the webhook automatically (<code>%s</code>). Run <code>wp yeffoprint telegram sync-webhook</code> to retry it manually.', 'yeffoprint-core' ),
					esc_html( YeffoPrint_Telegram_Webhook_Secret::webhook_url() )
				),
				[ 'code' => [] ]
			);
			$status = YeffoPrint_Telegram_Webhook_Sync::last_message();
			if ( $status ) {
				echo ' ' . esc_html( $status );
			}
		?></p>
		<?php
	}

	public function render_telegram_enabled_field(): void {
		$enabled = (bool) get_option( self::TELEGRAM_ENABLED_OPTION, false );
		?>
		<input type="hidden" name="<?php echo esc_attr( self::TELEGRAM_ENABLED_OPTION ); ?>" value="0" />
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( self::TELEGRAM_ENABLED_OPTION ); ?>"
				value="1"
				<?php checked( $enabled ); ?>
			/> <?php esc_html_e( 'Respond to messages sent to the bot', 'yeffoprint-core' ); ?>
		</label>
		<?php
	}

	public function render_telegram_bot_token_field(): void {
		$value = get_option( self::TELEGRAM_BOT_TOKEN_OPTION, '' );
		?>
		<input
			type="password"
			class="regular-text"
			autocomplete="off"
			name="<?php echo esc_attr( self::TELEGRAM_BOT_TOKEN_OPTION ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			placeholder="123456789:AA..."
		/>
		<p class="description"><?php esc_html_e( 'From @BotFather on Telegram. Can also be set via a YEFFOPRINT_TELEGRAM_BOT_TOKEN constant in wp-config.php instead.', 'yeffoprint-core' ); ?></p>
		<?php
	}

	public function render_telegram_admin_chat_id_field(): void {
		$value = get_option( self::TELEGRAM_ADMIN_CHAT_ID_OPTION, '' );
		?>
		<input
			type="text"
			class="regular-text"
			name="<?php echo esc_attr( self::TELEGRAM_ADMIN_CHAT_ID_OPTION ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			placeholder="123456789"
		/>
		<p class="description"><?php esc_html_e( 'Message /whoami to the bot from your own Telegram account to get this number. New paid orders, custom design requests, and Contact form messages get sent here.', 'yeffoprint-core' ); ?></p>
		<?php
	}

	public function render_telegram_login_enabled_field(): void {
		$enabled = (bool) get_option( self::TELEGRAM_LOGIN_ENABLED_OPTION, false );
		?>
		<input type="hidden" name="<?php echo esc_attr( self::TELEGRAM_LOGIN_ENABLED_OPTION ); ?>" value="0" />
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( self::TELEGRAM_LOGIN_ENABLED_OPTION ); ?>"
				value="1"
				<?php checked( $enabled ); ?>
			/> <?php esc_html_e( 'Show a "Log in with Telegram" button on the login/account pages', 'yeffoprint-core' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Uses the same bot token above — no separate app registration needed. One extra step on Telegram\'s side, though: message @BotFather with /setdomain and authorize this site\'s domain, or Telegram will refuse to render the button.', 'yeffoprint-core' ); ?></p>
		<?php
	}

	public function render_social_login_section_intro(): void {
		?>
		<p><?php
			echo wp_kses(
				sprintf(
					/* translators: 1: Google redirect URI, 2: Discord redirect URI, 3: Apple redirect URI */
					__( 'Lets customers sign up/log in with an existing Google, Discord, or Apple account instead of a password. For each provider you enable, register a developer app and set its redirect/return URL to the exact URL shown below, then paste in the credentials it gives you. Google: <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer">Google Cloud Console</a>, redirect URI <code>%1$s</code>. Discord: <a href="https://discord.com/developers/applications" target="_blank" rel="noopener noreferrer">Discord Developer Portal</a>, redirect URI <code>%2$s</code>. Apple: <a href="https://developer.apple.com/account/resources/identifiers/list/serviceId" target="_blank" rel="noopener noreferrer">Apple Developer — Identifiers</a>, return URL <code>%3$s</code> (requires a paid Apple Developer Program membership and verifying this domain).', 'yeffoprint-core' ),
					esc_html( YeffoPrint_Social_Login::callback_url( 'google' ) ),
					esc_html( YeffoPrint_Social_Login::callback_url( 'discord' ) ),
					esc_html( YeffoPrint_Social_Login::callback_url( 'apple' ) )
				),
				[ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ], 'code' => [] ]
			);
		?></p>
		<?php
	}

	public function render_google_enabled_field(): void {
		$this->render_provider_enabled_field( self::GOOGLE_LOGIN_ENABLED_OPTION );
	}

	public function render_discord_enabled_field(): void {
		$this->render_provider_enabled_field( self::DISCORD_LOGIN_ENABLED_OPTION );
	}

	/** Shared checkbox renderer for the two provider "on" switches above — same field, different option name each time. */
	private function render_provider_enabled_field( string $option ): void {
		$enabled = (bool) get_option( $option, false );
		?>
		<input type="hidden" name="<?php echo esc_attr( $option ); ?>" value="0" />
		<label>
			<input type="checkbox" name="<?php echo esc_attr( $option ); ?>" value="1" <?php checked( $enabled ); ?> />
			<?php esc_html_e( 'Show this button on the login form', 'yeffoprint-core' ); ?>
		</label>
		<?php
	}

	public function render_google_client_id_field(): void {
		$this->render_secret_field( self::GOOGLE_CLIENT_ID_OPTION );
	}

	public function render_google_client_secret_field(): void {
		$this->render_secret_field( self::GOOGLE_CLIENT_SECRET_OPTION );
	}

	public function render_discord_client_id_field(): void {
		$this->render_secret_field( self::DISCORD_CLIENT_ID_OPTION );
	}

	public function render_discord_client_secret_field(): void {
		$this->render_secret_field( self::DISCORD_CLIENT_SECRET_OPTION );
	}

	public function render_apple_enabled_field(): void {
		$this->render_provider_enabled_field( self::APPLE_LOGIN_ENABLED_OPTION );
	}

	public function render_apple_client_id_field(): void {
		$this->render_secret_field( self::APPLE_CLIENT_ID_OPTION, 'com.yeffoprint.web' );
	}

	public function render_apple_team_id_field(): void {
		$this->render_secret_field( self::APPLE_TEAM_ID_OPTION );
	}

	public function render_apple_key_id_field(): void {
		$this->render_secret_field( self::APPLE_KEY_ID_OPTION );
	}

	/** A multi-line PEM key, not a single-line secret — its own field rather than render_secret_field()'s single-line <input>. */
	public function render_apple_private_key_field(): void {
		$value = get_option( self::APPLE_PRIVATE_KEY_OPTION, '' );
		?>
		<textarea
			class="large-text code"
			rows="8"
			autocomplete="off"
			spellcheck="false"
			name="<?php echo esc_attr( self::APPLE_PRIVATE_KEY_OPTION ); ?>"
			placeholder="-----BEGIN PRIVATE KEY-----&#10;...&#10;-----END PRIVATE KEY-----"
		><?php echo esc_textarea( $value ); ?></textarea>
		<p class="description"><?php esc_html_e( 'The full contents of the .p8 file Apple gives you when you create the key — downloadable only once, so keep a copy somewhere safe.', 'yeffoprint-core' ); ?></p>
		<?php
	}

	/** Never below 1 — a same-day due date would flag every fresh order/request as overdue immediately. */
	public function sanitize_due_date_days( $value ): int {
		return max( 1, (int) $value );
	}

	public function render_dashboard_due_date_days_field(): void {
		$value = get_option( self::DASHBOARD_DUE_DATE_DAYS_OPTION, self::DASHBOARD_DUE_DATE_DAYS_DEFAULT );
		?>
		<input
			type="number"
			min="1"
			step="1"
			style="width:80px;"
			name="<?php echo esc_attr( self::DASHBOARD_DUE_DATE_DAYS_OPTION ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
		/> <?php esc_html_e( 'days', 'yeffoprint-core' ); ?>
		<p class="description"><?php esc_html_e( 'How long after an order or custom design request comes in before the dashboard flags it as overdue. Applies to Pending Orders, Pending Proofs, and Awaiting Customer Approval alike.', 'yeffoprint-core' ); ?></p>
		<?php
	}

	public function render_contact_recipient_email_field(): void {
		$value = get_option( self::CONTACT_RECIPIENT_EMAIL_OPTION, self::CONTACT_RECIPIENT_EMAIL_DEFAULT );
		?>
		<input
			type="email"
			class="regular-text"
			name="<?php echo esc_attr( self::CONTACT_RECIPIENT_EMAIL_OPTION ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
		/>
		<p class="description"><?php esc_html_e( 'Every Contact form submission is emailed here. Replying to that email replies straight to the customer — their address is set as the Reply-To, not the From.', 'yeffoprint-core' ); ?></p>
		<?php
	}

	public function render_splash_section_intro(): void {
		esc_html_e( 'A dismissible welcome screen shown once per visit on the homepage — useful right after a relaunch, to point visitors at the Contact form if they run into anything. Each visitor sees it once per browser session; it comes back if they close the tab and return later, until it\'s switched off here.', 'yeffoprint-core' );
	}

	public function render_splash_enabled_field(): void {
		$enabled = (bool) get_option( self::SPLASH_ENABLED_OPTION, false );
		?>
		<input type="hidden" name="<?php echo esc_attr( self::SPLASH_ENABLED_OPTION ); ?>" value="0" />
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( self::SPLASH_ENABLED_OPTION ); ?>"
				value="1"
				<?php checked( $enabled ); ?>
			/>
			<?php esc_html_e( 'Show it on the homepage', 'yeffoprint-core' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Turn this off once it\'s no longer needed — nothing else about the site changes either way.', 'yeffoprint-core' ); ?></p>
		<?php
	}

	/** Same generic wp.media picker as class-material-size-editor.php's "Vial mockup image" field — see enqueue_settings_assets() above. */
	public function render_splash_image_field(): void {
		$image_id = (int) get_option( self::SPLASH_IMAGE_ID_OPTION, 0 );
		$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'medium' ) : '';
		?>
		<span id="yp-vial-mockup-preview"><?php if ( $image_url ) : ?><img src="<?php echo esc_url( $image_url ); ?>" alt="" style="max-width:100%;height:auto;" /><?php endif; ?></span>
		<p>
			<input type="hidden" id="yp-vial-mockup-id" name="<?php echo esc_attr( self::SPLASH_IMAGE_ID_OPTION ); ?>" value="<?php echo esc_attr( $image_id ); ?>" />
			<button type="button" class="button" id="yp-vial-mockup-select"><?php esc_html_e( 'Select screenshot', 'yeffoprint-core' ); ?></button>
			<button type="button" class="button-link" id="yp-vial-mockup-remove" <?php echo $image_id ? '' : 'style="display:none;"'; ?>><?php esc_html_e( 'Remove', 'yeffoprint-core' ); ?></button>
		</p>
		<p class="description"><?php esc_html_e( 'A screenshot of the new site — shown alongside the "we\'ve upgraded" message. A wide screenshot (e.g. the homepage) works best.', 'yeffoprint-core' ); ?></p>
		<?php
	}

	/**
	 * Standard WordPress Settings API checkbox idiom: the hidden input
	 * (same name, rendered first) submits "0" whenever the checkbox is
	 * unchecked — browsers send both when checked, but only the last
	 * same-named field of the two survives into $_POST either way, so
	 * this always submits a value instead of a plain checkbox's "just
	 * omit the key when unchecked" (which options.php would otherwise
	 * read as "leave the previous value alone", not "turn it off").
	 */
	public function render_live_preview_field(): void {
		$enabled = (bool) get_option( self::LIVE_PREVIEW_ENABLED_OPTION, true );
		?>
		<input type="hidden" name="<?php echo esc_attr( self::LIVE_PREVIEW_ENABLED_OPTION ); ?>" value="0" />
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( self::LIVE_PREVIEW_ENABLED_OPTION ); ?>"
				value="1"
				<?php checked( $enabled ); ?>
			/>
			<?php esc_html_e( 'Show customers the live, per-keystroke text preview on Label View', 'yeffoprint-core' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Turn this off while adjusting field alignment on the Template editor\'s position preview so customers don\'t see not-yet-correct positioning in the meantime. Vial View, all form fields, pricing, and checkout keep working normally either way — this only hides the live on-image text on Label View. Turn it back on the same way once alignment looks right.', 'yeffoprint-core' ); ?></p>
		<?php
	}

	public function render_promo_section_intro(): void {
		esc_html_e( 'Themed banners between the header and the hero on the homepage. Fill in an offer and code for any theme below to make it active — active themes rotate automatically if there\'s more than one. Off by default, and this whole section stays off until at least one theme has both fields filled in.', 'yeffoprint-core' );
	}

	/** Same hidden-input-plus-checkbox idiom as render_live_preview_field() above. */
	public function render_promo_enabled_field(): void {
		$enabled = (bool) get_option( self::PROMO_ENABLED_OPTION, false );
		?>
		<input type="hidden" name="<?php echo esc_attr( self::PROMO_ENABLED_OPTION ); ?>" value="0" />
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( self::PROMO_ENABLED_OPTION ); ?>"
				value="1"
				<?php checked( $enabled ); ?>
			/>
			<?php esc_html_e( 'Show it on the homepage', 'yeffoprint-core' ); ?>
		</label>
		<?php
	}

	/**
	 * One row per known theme (YeffoPrint_Promo_Themes::all(), fixed at
	 * 13 — no add/remove UI needed here the way a true repeater would,
	 * since the full set of possible themes is already the full set of
	 * rows) rather than a dynamic list of "added" banners — this classic
	 * page is an unlinked fallback behind the admin-app's real Settings
	 * screen (Phase 8), so it gets the plain-HTML-forms version of this
	 * same idea instead of the admin-app's JS-driven one.
	 */
	public function render_promo_banners_field(): void {
		$saved = self::get_promo_banners();
		?>
		<table class="widefat" style="max-width:700px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Theme', 'yeffoprint-core' ); ?></th>
					<th><?php esc_html_e( 'Offer', 'yeffoprint-core' ); ?></th>
					<th><?php esc_html_e( 'Promo code', 'yeffoprint-core' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( YeffoPrint_Promo_Themes::all() as $slug => $theme ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $theme['label'] ); ?></th>
						<td>
							<input
								type="text"
								class="regular-text"
								name="<?php echo esc_attr( self::PROMO_BANNERS_OPTION . '[' . $slug . '][offer]' ); ?>"
								value="<?php echo esc_attr( $saved[ $slug ]['offer'] ?? '' ); ?>"
								placeholder="<?php esc_attr_e( '15% off', 'yeffoprint-core' ); ?>"
							/>
						</td>
						<td>
							<input
								type="text"
								class="regular-text"
								name="<?php echo esc_attr( self::PROMO_BANNERS_OPTION . '[' . $slug . '][code]' ); ?>"
								value="<?php echo esc_attr( $saved[ $slug ]['code'] ?? '' ); ?>"
								placeholder="<?php esc_attr_e( 'SUMMERWEEN26', 'yeffoprint-core' ); ?>"
							/>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'A theme is only active once both its Offer and Promo code are filled in. This plugin doesn\'t create the coupon itself, so make sure a matching WooCommerce coupon (Marketing → Coupons) with this exact code actually exists and is active before turning the banner on. Two or more active themes rotate automatically on the homepage.', 'yeffoprint-core' ); ?></p>
		<?php
	}

	/** Settings API sanitize_callback — the raw nested $_POST array for PROMO_BANNERS_OPTION[<slug>][offer|code]. */
	public function sanitize_promo_banners( $value ): array {
		return self::clean_promo_banners( is_array( $value ) ? $value : [] );
	}

	/**
	 * Trims to known theme slugs only (a removed/renamed theme's old
	 * data is dropped rather than lingering forever) and drops any entry
	 * that ends up with neither field filled in, so an admin clearing
	 * both fields back out actually removes that theme from rotation
	 * rather than leaving an empty-but-present entry behind.
	 *
	 * @return array<string, array{offer:string, code:string}>
	 */
	public static function clean_promo_banners( array $raw ): array {
		$clean = [];

		foreach ( YeffoPrint_Promo_Themes::all() as $slug => $theme ) {
			$offer = sanitize_text_field( (string) ( $raw[ $slug ]['offer'] ?? '' ) );
			$code  = sanitize_text_field( (string) ( $raw[ $slug ]['code'] ?? '' ) );

			if ( '' !== $offer || '' !== $code ) {
				$clean[ $slug ] = [ 'offer' => $offer, 'code' => $code ];
			}
		}

		return $clean;
	}

	public static function save_promo_banners( array $raw ): void {
		update_option( self::PROMO_BANNERS_OPTION, self::clean_promo_banners( $raw ) );
	}

	/**
	 * @return array<string, array{offer:string, code:string}> Keyed by
	 *  theme slug. Migrates a site's pre-existing single-banner selection
	 *  (PROMO_THEME_OPTION_LEGACY etc.) into this shape exactly once —
	 *  get_option()'s `false` default (distinct from an explicitly saved
	 *  empty array) is the one-shot guard, same "auto-create on first
	 *  use" idiom YeffoPrint_Pricing_Rule::get_active_rule_id() already
	 *  uses for its own option.
	 */
	public static function get_promo_banners(): array {
		$stored = get_option( self::PROMO_BANNERS_OPTION, false );

		if ( false !== $stored ) {
			return is_array( $stored ) ? $stored : [];
		}

		$legacy_theme = sanitize_key( (string) get_option( self::PROMO_THEME_OPTION_LEGACY, '' ) );
		$legacy_offer = trim( (string) get_option( self::PROMO_OFFER_OPTION_LEGACY, '' ) );
		$legacy_code  = trim( (string) get_option( self::PROMO_CODE_OPTION_LEGACY, '' ) );

		$migrated = [];
		if ( null !== YeffoPrint_Promo_Themes::get( $legacy_theme ) && '' !== $legacy_offer && '' !== $legacy_code ) {
			$migrated[ $legacy_theme ] = [ 'offer' => $legacy_offer, 'code' => $legacy_code ];
		}

		update_option( self::PROMO_BANNERS_OPTION, $migrated );

		return $migrated;
	}

	/**
	 * Active themes, in YeffoPrint_Promo_Themes::all()'s own definition
	 * order — the rotation order on the frontend. No separate admin-
	 * configurable ordering: with only 13 possible themes and no request
	 * for reordering specifically, a fixed, predictable order (roughly
	 * calendar order, with the always-on Web Design theme last) needs no
	 * extra UI of its own.
	 *
	 * @return array<int, array{slug:string, theme:array, offer:string, code:string}>
	 */
	public static function active_promo_banners(): array {
		if ( ! get_option( self::PROMO_ENABLED_OPTION, false ) ) {
			return [];
		}

		$saved  = self::get_promo_banners();
		$active = [];

		foreach ( YeffoPrint_Promo_Themes::all() as $slug => $theme ) {
			if ( empty( $saved[ $slug ]['offer'] ) || empty( $saved[ $slug ]['code'] ) ) {
				continue;
			}

			$active[] = [
				'slug'  => $slug,
				'theme' => $theme,
				'offer' => $saved[ $slug ]['offer'],
				'code'  => $saved[ $slug ]['code'],
			];
		}

		return $active;
	}

	public function render_tracking_section_intro(): void {
		echo '<p>' . wp_kses(
			sprintf(
				/* translators: 1: developer.ups.com link, 2: developer.usps.com link */
				__( 'Powers the live tracking timeline on the order-tracking page (/track-order/) and its link in order emails. Optional — the tracking page and email link work without these, showing a direct link to the carrier\'s own tracking site instead of an in-page timeline. Get credentials from <a href="%1$s" target="_blank" rel="noopener noreferrer">UPS\'s Developer Kit</a> and <a href="%2$s" target="_blank" rel="noopener noreferrer">USPS\'s Developer Portal</a>.', 'yeffoprint-core' ),
				'https://developer.ups.com/',
				'https://developer.usps.com/'
			),
			[ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ]
		) . '</p>';
	}

	public function render_ups_client_id_field(): void {
		$this->render_secret_field( self::UPS_CLIENT_ID_OPTION );
	}

	public function render_ups_client_secret_field(): void {
		$this->render_secret_field( self::UPS_CLIENT_SECRET_OPTION );
	}

	public function render_usps_consumer_key_field(): void {
		$this->render_secret_field( self::USPS_CONSUMER_KEY_OPTION );
	}

	public function render_usps_consumer_secret_field(): void {
		$this->render_secret_field( self::USPS_CONSUMER_SECRET_OPTION );
	}

	/** Shared renderer for the four carrier-credential fields above — same field, just a different option name each time. */
	private function render_secret_field( string $option, string $placeholder = '' ): void {
		?>
		<input
			type="password"
			class="regular-text"
			autocomplete="off"
			name="<?php echo esc_attr( $option ); ?>"
			value="<?php echo esc_attr( get_option( $option ) ); ?>"
			<?php if ( '' !== $placeholder ) : ?>placeholder="<?php echo esc_attr( $placeholder ); ?>"<?php endif; ?>
		/>
		<?php
	}

	public function render_announcement_bar_field(): void {
		$value = get_option( self::ANNOUNCEMENT_BAR_OPTION, self::ANNOUNCEMENT_BAR_DEFAULT );
		?>
		<input
			type="text"
			class="regular-text"
			name="<?php echo esc_attr( self::ANNOUNCEMENT_BAR_OPTION ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
		/>
		<p class="description"><?php esc_html_e( 'Shown in the thin bar above the header, on every page. Leave blank to hide the bar entirely.', 'yeffoprint-core' ); ?></p>
		<?php
	}

	/**
	 * Currently unused (register_menu() above points the 'yeffoprint'
	 * page at YeffoPrint_Admin_App::render() instead) — kept for a real
	 * Dashboard home view inside the new admin app in a later phase, see
	 * docs/ARCHITECTURE.md.
	 *
	 * Real landing page (was a single placeholder paragraph, then just a
	 * quick-links grid) — now leads with YeffoPrint_Dashboard_Widgets'
	 * own Pending Orders/Pending Proofs/Awaiting Approval tables (direct
	 * request: "in one screen"), with the quick-links grid still below
	 * as secondary navigation. The old single "N custom orders need a
	 * proof" banner is gone — the new Pending Proofs table below is a
	 * strict superset of what it told you (every one of those orders,
	 * with customer/date, not just a count), and add_needs_attention_
	 * badge() still puts that same count on the sidebar independently.
	 */
	public function render_dashboard(): void {
		?>
		<div class="wrap yp-dashboard">
			<h1><?php esc_html_e( 'YeffoDesign', 'yeffoprint-core' ); ?></h1>

			<?php ( new YeffoPrint_Dashboard_Widgets() )->render(); ?>

			<h2><?php esc_html_e( 'Quick Links', 'yeffoprint-core' ); ?></h2>
			<div class="yp-dashboard__grid">
				<?php foreach ( $this->quick_links() as $link ) : ?>
					<a class="yp-dashboard__card" href="<?php echo esc_url( $link['url'] ); ?>">
						<span class="yp-dashboard__card-title"><?php echo esc_html( $link['title'] ); ?></span>
						<span class="yp-dashboard__card-desc"><?php echo esc_html( $link['description'] ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/** @return array<int, array{title:string, description:string, url:string}> */
	private function quick_links(): array {
		return [
			[
				'title'       => __( 'Design Setup', 'yeffoprint-core' ),
				'description' => __( 'Templates, Sizes, Materials, Sticker Sizes', 'yeffoprint-core' ),
				'url'         => admin_url( 'edit.php?post_type=yp_template' ),
			],
			[
				'title'       => __( 'Pricing Rules', 'yeffoprint-core' ),
				'description' => __( 'Base price, design fee, bulk discount tiers', 'yeffoprint-core' ),
				'url'         => admin_url( 'edit.php?post_type=yp_pricing_rule' ),
			],
			[
				'title'       => __( 'Field Presets', 'yeffoprint-core' ),
				'description' => __( 'Reusable customization field sets for Templates', 'yeffoprint-core' ),
				'url'         => admin_url( 'edit.php?post_type=yp_field_preset' ),
			],
			[
				'title'       => __( 'Custom Orders', 'yeffoprint-core' ),
				'description' => __( 'Fully custom design and sticker requests', 'yeffoprint-core' ),
				'url'         => admin_url( 'edit.php?post_type=yp_custom_order' ),
			],
			[
				'title'       => __( 'Proofs', 'yeffoprint-core' ),
				'description' => __( 'Upload a proof against a custom order', 'yeffoprint-core' ),
				'url'         => admin_url( 'edit.php?post_type=yp_proof' ),
			],
			[
				'title'       => __( 'Rewards', 'yeffoprint-core' ),
				'description' => __( 'Points/referral rates, manual balance adjustments', 'yeffoprint-core' ),
				'url'         => admin_url( 'admin.php?page=yeffoprint-rewards' ),
			],
			[
				'title'       => __( 'Card Surcharge', 'yeffoprint-core' ),
				'description' => __( 'Per-gateway card processing fee rates', 'yeffoprint-core' ),
				'url'         => admin_url( 'admin.php?page=yeffoprint-surcharge' ),
			],
			[
				'title'       => __( 'Settings', 'yeffoprint-core' ),
				'description' => __( 'Announcement bar, promo banner, tracking, contact form', 'yeffoprint-core' ),
				'url'         => admin_url( 'admin.php?page=yeffoprint-settings' ),
			],
		];
	}

	public function render_settings_page(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'YeffoPrint Settings', 'yeffoprint-core' ) . '</h1>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'yeffoprint_settings' );
		do_settings_sections( 'yeffoprint-settings' );
		submit_button();
		echo '</form></div>';
	}

	/**
	 * The in-admin notification asked for: a Comments/Orders-style
	 * bubble count on both "Custom Orders" and the top-level
	 * "YeffoPrint" menu (so it's visible even collapsed), counting every
	 * Custom Order currently in "Design in progress" — the one status
	 * that always means "staff owes this customer a proof," whether
	 * that's because the order is brand new or because the customer
	 * just requested changes on the last one (class-proof-approval-
	 * controller.php's request_changes() sends it back to this exact
	 * status). One shared count rather than two separate ones: the
	 * action that clears either case is identical (upload a new proof),
	 * so there's nothing a split count would let staff do differently.
	 */
	public function add_needs_attention_badge(): void {
		global $submenu, $menu;

		$count = $this->count_needing_a_proof();
		if ( ! $count ) {
			return;
		}

		$badge = sprintf(
			' <span class="awaiting-mod count-%1$d"><span class="pending-count">%1$d</span></span>',
			$count
		);

		// Phase 8: $submenu['yeffoprint'] no longer has a Custom Orders
		// entry to find (or any entries at all) now that the classic CPT
		// submenus are hidden — this block is a permanent no-op left in
		// place rather than removed, since !empty() on an unset array key
		// is notice-safe and the top-level badge below still works fine
		// on its own.
		if ( ! empty( $submenu['yeffoprint'] ) ) {
			foreach ( $submenu['yeffoprint'] as &$item ) {
				if ( isset( $item[2] ) && 'edit.php?post_type=yp_custom_order' === $item[2] ) {
					$item[0] .= $badge;
					break;
				}
			}
			unset( $item );
		}

		foreach ( $menu as &$top_level_item ) {
			if ( isset( $top_level_item[2] ) && 'yeffoprint' === $top_level_item[2] ) {
				$top_level_item[0] .= $badge;
				break;
			}
		}
		unset( $top_level_item );
	}

	private function count_needing_a_proof(): int {
		$query = new \WP_Query( [
			'post_type'      => 'yp_custom_order',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [
				[
					'key'   => YeffoPrint_Custom_Order_Meta::STATUS,
					'value' => 'design_in_progress',
				],
			],
		] );

		return (int) $query->found_posts;
	}
}
