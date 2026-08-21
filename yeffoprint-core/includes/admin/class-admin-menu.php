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
	 * Direct request: pass processing fees on to the customer. Opt-in
	 * per gateway (SURCHARGE_GATEWAYS_OPTION defaults to none checked),
	 * not a blanket "surcharge every card payment" switch — a store
	 * with, say, Venmo/Zelle plus a card gateway should never end up
	 * surcharging the former by a config accident.
	 */
	const SURCHARGE_RATE_OPTION     = 'yeffoprint_surcharge_rate_percent';
	const SURCHARGE_LABEL_OPTION    = 'yeffoprint_surcharge_label';
	const SURCHARGE_GATEWAYS_OPTION = 'yeffoprint_surcharge_gateway_ids';
	const SURCHARGE_LABEL_DEFAULT   = 'Card Processing Fee';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		// Runs after WordPress has built every submenu (including the
		// Custom Orders CPT one, auto-attached via show_in_menu =>
		// 'yeffoprint' in class-post-type-registry.php rather than
		// registered here) — a fixed late priority is simpler and just
		// as reliable as chasing an exact "after CPT registration" hook.
		add_action( 'admin_menu', [ $this, 'add_needs_attention_badge' ], 999 );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
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

		add_submenu_page(
			'yeffoprint',
			__( 'Settings', 'yeffoprint-core' ),
			__( 'Settings', 'yeffoprint-core' ),
			'manage_options',
			'yeffoprint-settings',
			[ $this, 'render_settings_page' ]
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

		register_setting( 'yeffoprint_settings', self::SURCHARGE_RATE_OPTION, [
			'type'              => 'number',
			'sanitize_callback' => [ $this, 'sanitize_positive_number' ],
			'default'           => 0,
		] );
		register_setting( 'yeffoprint_settings', self::SURCHARGE_LABEL_OPTION, [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => self::SURCHARGE_LABEL_DEFAULT,
		] );
		register_setting( 'yeffoprint_settings', self::SURCHARGE_GATEWAYS_OPTION, [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize_surcharge_gateways' ],
			'default'           => [],
			'show_in_rest'      => false, // An array option needs an explicit schema to be REST-visible; nothing here reads it over REST.
		] );

		add_settings_section(
			'yeffoprint_surcharge',
			__( 'Card Surcharge', 'yeffoprint-core' ),
			[ $this, 'render_surcharge_section_intro' ],
			'yeffoprint-settings'
		);

		add_settings_field(
			self::SURCHARGE_RATE_OPTION,
			__( 'Surcharge rate', 'yeffoprint-core' ),
			[ $this, 'render_surcharge_rate_field' ],
			'yeffoprint-settings',
			'yeffoprint_surcharge'
		);

		add_settings_field(
			self::SURCHARGE_LABEL_OPTION,
			__( 'Line item label', 'yeffoprint-core' ),
			[ $this, 'render_surcharge_label_field' ],
			'yeffoprint-settings',
			'yeffoprint_surcharge'
		);

		add_settings_field(
			self::SURCHARGE_GATEWAYS_OPTION,
			__( 'Apply to', 'yeffoprint-core' ),
			[ $this, 'render_surcharge_gateways_field' ],
			'yeffoprint-settings',
			'yeffoprint_surcharge'
		);
	}

	public function render_surcharge_section_intro(): void {
		echo '<p>' . wp_kses(
			__( 'Adds a fee to the order total when the customer pays with a gateway checked below — direct request, to pass processing fees on to the customer. <strong>Before turning this on:</strong> credit card surcharging is banned outright in a few states (Connecticut, Massachusetts, Maine, and — as of a 2024 law — California, though its status has been challenged in court) and is capped by the card networks (currently 3% for Visa, 4% for Mastercard, or your actual processing cost if lower, whichever is less). It can never legally apply to a debit card — and this plugin has no way to tell a credit card from a debit card before checkout, since that only becomes known to your payment processor at the moment of payment, not to WooCommerce beforehand. Confirm with your payment processor or a lawyer that this is set up correctly for your state and card mix before relying on it.', 'yeffoprint-core' ),
			[ 'strong' => [] ]
		) . '</p>';
	}

	public function render_surcharge_rate_field(): void {
		$value = get_option( self::SURCHARGE_RATE_OPTION, 0 );
		?>
		<input
			type="number"
			step="0.01"
			min="0"
			max="10"
			name="<?php echo esc_attr( self::SURCHARGE_RATE_OPTION ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
		/> %
		<p class="description"><?php esc_html_e( '0 turns the surcharge off entirely, regardless of which gateways are checked below.', 'yeffoprint-core' ); ?></p>
		<?php
	}

	public function render_surcharge_label_field(): void {
		$value = get_option( self::SURCHARGE_LABEL_OPTION, self::SURCHARGE_LABEL_DEFAULT );
		?>
		<input
			type="text"
			class="regular-text"
			name="<?php echo esc_attr( self::SURCHARGE_LABEL_OPTION ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
		/>
		<p class="description"><?php esc_html_e( 'Shown in the cart, checkout, and order emails as "Label (rate%)" — e.g. "Card Processing Fee (3%)". Card network rules require this fee to be clearly disclosed before payment, not folded into another line item.', 'yeffoprint-core' ); ?></p>
		<?php
	}

	public function render_surcharge_gateways_field(): void {
		$selected = YeffoPrint_Card_Surcharge::get_surcharged_gateway_ids();
		$gateways = function_exists( 'WC' ) && WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];

		if ( ! $gateways ) {
			esc_html_e( 'No payment gateways are registered yet.', 'yeffoprint-core' );
			return;
		}

		foreach ( $gateways as $gateway ) {
			?>
			<label style="display:block;margin-bottom:6px;">
				<input
					type="checkbox"
					name="<?php echo esc_attr( self::SURCHARGE_GATEWAYS_OPTION ); ?>[]"
					value="<?php echo esc_attr( $gateway->id ); ?>"
					<?php checked( in_array( $gateway->id, $selected, true ) ); ?>
				/>
				<?php echo esc_html( $gateway->get_title() ); ?>
				<span class="description">(<?php echo esc_html( 'yes' === $gateway->enabled ? __( 'enabled', 'yeffoprint-core' ) : __( 'disabled', 'yeffoprint-core' ) ); ?>)</span>
			</label>
			<?php
		}
		?>
		<p class="description"><?php esc_html_e( 'Only check your actual credit card gateway(s) — never a debit-only, bank-transfer, or manual gateway like Venmo/Zelle.', 'yeffoprint-core' ); ?></p>
		<?php
	}

	/** @return string[] Only gateway ids that actually exist right now — a stale id from a since-removed/renamed gateway is dropped rather than silently kept around. */
	public function sanitize_surcharge_gateways( $raw ): array {
		$submitted = array_map( 'sanitize_key', is_array( $raw ) ? $raw : [] );
		$known_ids = function_exists( 'WC' ) && WC()->payment_gateways()
			? array_keys( WC()->payment_gateways()->payment_gateways() )
			: [];

		return array_values( array_intersect( $submitted, $known_ids ) );
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
