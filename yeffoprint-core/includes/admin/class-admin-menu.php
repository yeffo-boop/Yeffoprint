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

	/** Also read by YeffoPrint_Rewards (includes/rewards/class-rewards.php) — same reasoning as the announcement bar option above. */
	const REWARDS_POINTS_PER_DOLLAR_OPTION = 'yeffoprint_rewards_points_per_dollar';
	const REWARDS_DOLLARS_PER_POINT_OPTION = 'yeffoprint_rewards_dollars_per_point';
	const REWARDS_POINTS_PER_DOLLAR_DEFAULT = 1;
	const REWARDS_DOLLARS_PER_POINT_DEFAULT = 0.01;

	/**
	 * Also read by the tracking-providers/ classes — same reasoning as
	 * the rewards options above. Empty until an admin actually signs up
	 * for each carrier's developer program and pastes these in; the
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
	 * Direct request: pass processing fees on to the customer, at a
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
		add_menu_page(
			__( 'YeffoPrint', 'yeffoprint-core' ),
			__( 'YeffoPrint', 'yeffoprint-core' ),
			'manage_options',
			'yeffoprint',
			[ $this, 'render_dashboard' ],
			'dashicons-store',
			25
		);

		$this->settings_page_hook = (string) add_submenu_page(
			'yeffoprint',
			__( 'Settings', 'yeffoprint-core' ),
			__( 'Settings', 'yeffoprint-core' ),
			'manage_options',
			'yeffoprint-settings',
			[ $this, 'render_settings_page' ]
		);
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

		register_setting( 'yeffoprint_settings', self::REWARDS_POINTS_PER_DOLLAR_OPTION, [
			'type'              => 'number',
			'sanitize_callback' => [ $this, 'sanitize_positive_number' ],
			'default'           => self::REWARDS_POINTS_PER_DOLLAR_DEFAULT,
		] );

		register_setting( 'yeffoprint_settings', self::REWARDS_DOLLARS_PER_POINT_OPTION, [
			'type'              => 'number',
			'sanitize_callback' => [ $this, 'sanitize_positive_number' ],
			'default'           => self::REWARDS_DOLLARS_PER_POINT_DEFAULT,
		] );

		add_settings_section(
			'yeffoprint_rewards',
			__( 'Rewards', 'yeffoprint-core' ),
			'__return_false',
			'yeffoprint-settings'
		);

		add_settings_field(
			self::REWARDS_POINTS_PER_DOLLAR_OPTION,
			__( 'Points earned per $1 spent', 'yeffoprint-core' ),
			[ $this, 'render_rewards_points_per_dollar_field' ],
			'yeffoprint-settings',
			'yeffoprint_rewards'
		);

		add_settings_field(
			self::REWARDS_DOLLARS_PER_POINT_OPTION,
			__( 'Redemption value per point', 'yeffoprint-core' ),
			[ $this, 'render_rewards_dollars_per_point_field' ],
			'yeffoprint-settings',
			'yeffoprint_rewards'
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

		register_setting( 'yeffoprint_settings', self::SURCHARGE_GATEWAY_RATES_OPTION, [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize_surcharge_gateway_rates' ],
			'default'           => [],
			'show_in_rest'      => false, // An array-of-arrays option needs an explicit schema to be REST-visible; nothing here reads it over REST.
		] );

		add_settings_section(
			'yeffoprint_surcharge',
			__( 'Card Surcharge', 'yeffoprint-core' ),
			[ $this, 'render_surcharge_section_intro' ],
			'yeffoprint-settings'
		);

		add_settings_field(
			self::SURCHARGE_GATEWAY_RATES_OPTION,
			__( 'Per-gateway rates', 'yeffoprint-core' ),
			[ $this, 'render_surcharge_gateway_rates_field' ],
			'yeffoprint-settings',
			'yeffoprint_surcharge'
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

	public function render_surcharge_section_intro(): void {
		echo '<p>' . wp_kses(
			__( 'Adds a fee to the order total when the customer pays with a gateway given a rate below — direct request, to pass processing fees on to the customer, at whatever rate each gateway actually costs you (cards and Afterpay/BNPL gateways typically cost very different percentages). <strong>Before turning this on:</strong> credit card surcharging is banned outright in a few states (Connecticut, Massachusetts, Maine, and — as of a 2024 law — California, though its status has been challenged in court) and is capped by the card networks (currently 3% for Visa, 4% for Mastercard, or your actual processing cost if lower, whichever is less). It can never legally apply to a debit card — and this plugin has no way to tell a credit card from a debit card before checkout, since that only becomes known to your payment processor at the moment of payment, not to WooCommerce beforehand. Confirm with your payment processor or a lawyer that this is set up correctly for your state and card mix before relying on it.', 'yeffoprint-core' ),
			[ 'strong' => [] ]
		) . '</p>';
	}

	/** One row per currently-registered gateway: a rate (%) and an optional label override, defaulting blank/0 so nothing is surcharged until explicitly set. */
	public function render_surcharge_gateway_rates_field(): void {
		$saved    = YeffoPrint_Card_Surcharge::get_gateway_rates();
		$gateways = function_exists( 'WC' ) && WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];

		if ( ! $gateways ) {
			esc_html_e( 'No payment gateways are registered yet.', 'yeffoprint-core' );
			return;
		}

		$option = self::SURCHARGE_GATEWAY_RATES_OPTION;
		?>
		<table class="widefat" style="max-width:640px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Gateway', 'yeffoprint-core' ); ?></th>
					<th style="width:110px;"><?php esc_html_e( 'Rate', 'yeffoprint-core' ); ?></th>
					<th><?php esc_html_e( 'Label', 'yeffoprint-core' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $gateways as $gateway ) : ?>
					<?php
					$row   = $saved[ $gateway->id ] ?? [];
					$rate  = $row['rate'] ?? '';
					$label = $row['label'] ?? '';
					?>
					<tr>
						<td>
							<?php echo esc_html( $gateway->get_title() ); ?>
							<br /><span class="description">(<?php echo esc_html( 'yes' === $gateway->enabled ? __( 'enabled', 'yeffoprint-core' ) : __( 'disabled', 'yeffoprint-core' ) ); ?>)</span>
						</td>
						<td>
							<input
								type="number"
								step="0.01"
								min="0"
								max="10"
								style="width:80px;"
								name="<?php echo esc_attr( $option ); ?>[<?php echo esc_attr( $gateway->id ); ?>][rate]"
								value="<?php echo esc_attr( $rate ); ?>"
							/> %
						</td>
						<td>
							<input
								type="text"
								class="regular-text"
								placeholder="<?php echo esc_attr( self::SURCHARGE_LABEL_DEFAULT ); ?>"
								name="<?php echo esc_attr( $option ); ?>[<?php echo esc_attr( $gateway->id ); ?>][label]"
								value="<?php echo esc_attr( $label ); ?>"
							/>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'Blank or 0 turns the surcharge off for that gateway — leave every gateway except your actual credit card gateway(s) at 0, never a debit-only, bank-transfer, or manual gateway like Venmo/Zelle. Label is shown in the cart, checkout, and order emails as "Label (rate%)" — e.g. "Card Processing Fee (2.9%)" — and defaults to "Processing Fee" if left blank. Card network rules require this fee to be clearly disclosed before payment, not folded into another line item.', 'yeffoprint-core' ); ?></p>
		<?php
	}

	/**
	 * @return array<string, array{rate:float, label:string}> Only gateway
	 *   ids that actually exist right now (a stale id from a since-
	 *   removed/renamed gateway is dropped) and only rows with a positive
	 *   rate (a blanked-out row shouldn't linger as a 0-rate no-op entry).
	 */
	public function sanitize_surcharge_gateway_rates( $raw ): array {
		$known_ids = function_exists( 'WC' ) && WC()->payment_gateways()
			? array_keys( WC()->payment_gateways()->payment_gateways() )
			: [];

		$result = [];
		foreach ( (array) $raw as $gateway_id => $row ) {
			$gateway_id = sanitize_key( (string) $gateway_id );
			$rate       = max( 0, (float) ( $row['rate'] ?? 0 ) );

			if ( ! in_array( $gateway_id, $known_ids, true ) || $rate <= 0 ) {
				continue;
			}

			$result[ $gateway_id ] = [
				'rate'  => $rate,
				'label' => sanitize_text_field( (string) ( $row['label'] ?? '' ) ),
			];
		}

		return $result;
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

	/**
	 * Both rewards fields are positive rates, not free text — a
	 * negative or non-numeric value would let a customer earn negative
	 * points or redeem for an unbounded discount.
	 */
	public function sanitize_positive_number( $value ): float {
		return max( 0, (float) $value );
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

	public function render_rewards_points_per_dollar_field(): void {
		$value = get_option( self::REWARDS_POINTS_PER_DOLLAR_OPTION, self::REWARDS_POINTS_PER_DOLLAR_DEFAULT );
		?>
		<input
			type="number"
			step="0.01"
			min="0"
			name="<?php echo esc_attr( self::REWARDS_POINTS_PER_DOLLAR_OPTION ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
		/>
		<p class="description"><?php esc_html_e( 'How many points a customer earns for every $1 of merchandise (shipping and tax excluded), awarded once an order is paid.', 'yeffoprint-core' ); ?></p>
		<?php
	}

	public function render_rewards_dollars_per_point_field(): void {
		$value = get_option( self::REWARDS_DOLLARS_PER_POINT_OPTION, self::REWARDS_DOLLARS_PER_POINT_DEFAULT );
		?>
		<input
			type="number"
			step="0.001"
			min="0"
			name="<?php echo esc_attr( self::REWARDS_DOLLARS_PER_POINT_OPTION ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
		/>
		<p class="description"><?php esc_html_e( 'Dollar discount each point is worth when a customer redeems their balance. Default 0.01 means 100 points = $1.', 'yeffoprint-core' ); ?></p>
		<?php
	}

	public function render_dashboard(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'YeffoPrint', 'yeffoprint-core' ) . '</h1>';
		echo '<p>' . esc_html__( 'Templates, Materials, Sizes, Pricing Rules, Custom Orders, and Proofs are managed from this menu.', 'yeffoprint-core' ) . '</p></div>';
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
