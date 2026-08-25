<?php
/**
 * Top-level "YeffoPrint" wp-admin menu.
 *
 * Post types register with show_in_menu => 'yeffoprint' (see
 * class-post-type-registry.php) and attach as submenus here. Richer
 * dashboard content lands in a later phase — see PROJECT_SPEC.md §17.
 *
 * The announcement bar text (below) is the first real Site Setting,
 * on its own explicitly-labeled "Settings" submenu — not on the
 * top-level dashboard page itself, which is only reachable through
 * WordPress's own default same-labeled ("YeffoPrint") self-link
 * submenu item it adds automatically once other real submenus exist
 * (the CPT ones above), easy to miss/mistake for just a toggle rather
 * than a page. A distinctly-named submenu is worth it even for one
 * field.
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
	 * Also read by yeffoprint/blocks/promo-banner's render.php — same
	 * reasoning as the options above. Direct request: a seasonal
	 * homepage promo banner an admin can turn on, pick a theme for
	 * (YeffoPrint_Promo_Themes::all()), and set the active code/offer
	 * on — off by default, and off again the moment PROMO_CODE_OPTION
	 * or PROMO_OFFER_OPTION is blank, so there's no way to end up with
	 * a live banner advertising a code that was never actually set.
	 */
	const PROMO_ENABLED_OPTION = 'yeffoprint_promo_enabled';
	const PROMO_THEME_OPTION   = 'yeffoprint_promo_theme';
	const PROMO_CODE_OPTION    = 'yeffoprint_promo_code';
	const PROMO_OFFER_OPTION   = 'yeffoprint_promo_offer';
	const PROMO_THEME_DEFAULT  = 'summer';
	const PROMO_OFFER_DEFAULT  = '15% off';

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
		$dashboard_hook = (string) add_menu_page(
			__( 'YeffoPrint', 'yeffoprint-core' ),
			__( 'YeffoPrint', 'yeffoprint-core' ),
			'manage_options',
			'yeffoprint',
			[ $this, 'render_dashboard' ],
			'dashicons-store',
			25
		);

		// Left implicit before, this is WordPress's own well-known trap:
		// with no submenu registered at the parent's own slug, WP quietly
		// inserts one labeled the same as the parent ("YeffoPrint" again)
		// — clicking the top-level sidebar item itself just expands/
		// collapses the flyout, and only that *duplicate-labeled* entry
		// inside it actually navigates anywhere. Harmless when this page
		// was a placeholder; confusing now that real "what needs your
		// attention today" content (YeffoPrint_Dashboard_Widgets) lives
		// here — direct report: a click that "just toggles the submenu."
		// Registering it explicitly, at this same 'yeffoprint' slug,
		// replaces WP's auto-inserted duplicate with a distinctly-labeled
		// one instead.
		add_submenu_page(
			'yeffoprint',
			__( 'Dashboard', 'yeffoprint-core' ),
			__( 'Dashboard', 'yeffoprint-core' ),
			'manage_options',
			'yeffoprint',
			[ $this, 'render_dashboard' ]
		);

		$this->settings_page_hook = (string) add_submenu_page(
			'yeffoprint',
			__( 'Settings', 'yeffoprint-core' ),
			__( 'Settings', 'yeffoprint-core' ),
			'manage_options',
			'yeffoprint-settings',
			[ $this, 'render_settings_page' ]
		);

		// Neither page is a CPT screen, so class-admin-shell.php can't
		// recognize either one via its own POST_TYPES list — this is
		// what actually gets the reskin's shared stylesheet loading here.
		YeffoPrint_Admin_Shell::register_page_hook( $dashboard_hook );
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

		register_setting( 'yeffoprint_settings', self::PROMO_THEME_OPTION, [
			'type'              => 'string',
			'sanitize_callback' => [ $this, 'sanitize_promo_theme' ],
			'default'           => self::PROMO_THEME_DEFAULT,
		] );

		register_setting( 'yeffoprint_settings', self::PROMO_CODE_OPTION, [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		] );

		register_setting( 'yeffoprint_settings', self::PROMO_OFFER_OPTION, [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => self::PROMO_OFFER_DEFAULT,
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
			self::PROMO_THEME_OPTION,
			__( 'Theme', 'yeffoprint-core' ),
			[ $this, 'render_promo_theme_field' ],
			'yeffoprint-settings',
			'yeffoprint_promo'
		);

		add_settings_field(
			self::PROMO_OFFER_OPTION,
			__( 'Offer', 'yeffoprint-core' ),
			[ $this, 'render_promo_offer_field' ],
			'yeffoprint-settings',
			'yeffoprint_promo'
		);

		add_settings_field(
			self::PROMO_CODE_OPTION,
			__( 'Promo code', 'yeffoprint-core' ),
			[ $this, 'render_promo_code_field' ],
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
		esc_html_e( 'A seasonal banner between the header and the hero on the homepage — pick a theme, fill in this promotion\'s offer and code, and turn it on. Off by default, and it won\'t show even when on until both Offer and Promo code are filled in.', 'yeffoprint-core' );
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

	public function render_promo_theme_field(): void {
		$selected = (string) get_option( self::PROMO_THEME_OPTION, self::PROMO_THEME_DEFAULT );
		?>
		<select name="<?php echo esc_attr( self::PROMO_THEME_OPTION ); ?>">
			<?php foreach ( YeffoPrint_Promo_Themes::all() as $slug => $theme ) : ?>
				<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $selected, $slug ); ?>><?php echo esc_html( $theme['label'] ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	public function render_promo_offer_field(): void {
		$value = get_option( self::PROMO_OFFER_OPTION, self::PROMO_OFFER_DEFAULT );
		?>
		<input
			type="text"
			class="regular-text"
			name="<?php echo esc_attr( self::PROMO_OFFER_OPTION ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			placeholder="<?php echo esc_attr( self::PROMO_OFFER_DEFAULT ); ?>"
		/>
		<p class="description"><?php esc_html_e( 'Short phrase describing the deal, e.g. "15% off" or "20% off everything" — dropped straight into the theme\'s own headline (e.g. "Ring in the New Year with 15% off").', 'yeffoprint-core' ); ?></p>
		<?php
	}

	public function render_promo_code_field(): void {
		$value = get_option( self::PROMO_CODE_OPTION, '' );
		?>
		<input
			type="text"
			class="regular-text"
			name="<?php echo esc_attr( self::PROMO_CODE_OPTION ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			placeholder="<?php echo esc_attr( 'SUMMERWEEN26' ); ?>"
		/>
		<p class="description"><?php esc_html_e( 'Shown in the banner exactly as typed — this plugin doesn\'t create the coupon itself, so make sure a matching WooCommerce coupon (Marketing → Coupons) with this exact code actually exists and is active before turning the banner on.', 'yeffoprint-core' ); ?></p>
		<?php
	}

	/** Falls back to the default theme for an unrecognized/removed slug, rather than saving something get() can never resolve. */
	public function sanitize_promo_theme( $value ): string {
		$value = sanitize_key( (string) $value );
		return null !== YeffoPrint_Promo_Themes::get( $value ) ? $value : self::PROMO_THEME_DEFAULT;
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
	private function render_secret_field( string $option ): void {
		?>
		<input
			type="password"
			class="regular-text"
			autocomplete="off"
			name="<?php echo esc_attr( $option ); ?>"
			value="<?php echo esc_attr( get_option( $option ) ); ?>"
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
			<h1><?php esc_html_e( 'YeffoPrint', 'yeffoprint-core' ); ?></h1>

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
