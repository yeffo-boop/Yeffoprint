<?php
/**
 * Dedicated "Card Surcharge" admin page. Split out of the general
 * Settings page (direct request) so the per-gateway rate table, which
 * needed its own explanatory section anyway, isn't buried among
 * unrelated settings.
 *
 * The option itself (YeffoPrint_Admin_Menu::SURCHARGE_GATEWAY_RATES_OPTION)
 * stays defined on YeffoPrint_Admin_Menu since class-card-surcharge.php
 * already reads it from there — only the registration UI moved here.
 *
 * Superseded as the primary UI by the custom admin app's own Card
 * Surcharge screen (docs/ARCHITECTURE.md, Phase 7) — this classic page
 * is kept fully functional but unlinked from any menu (Phase 8) as a
 * fallback, not deleted.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Surcharge_Admin {

	private const SETTINGS_GROUP = 'yeffoprint_surcharge_settings';
	private const SETTINGS_PAGE  = 'yeffoprint-surcharge';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	public function register_menu(): void {
		// Phase 8 (docs/ARCHITECTURE.md): parent null — reachable at its
		// direct URL, deliberately not shown in any menu, now that the
		// custom admin app has its own Card Surcharge screen. Same
		// unlinked-fallback treatment as every other classic YeffoPrint page.
		$hook = (string) add_submenu_page(
			null,
			__( 'Card Surcharge', 'yeffoprint-core' ),
			__( 'Card Surcharge', 'yeffoprint-core' ),
			'manage_options',
			self::SETTINGS_PAGE,
			[ $this, 'render_page' ]
		);

		// Not a CPT screen — see class-admin-shell.php's own docblock on register_page_hook().
		YeffoPrint_Admin_Shell::register_page_hook( $hook );
	}

	public function register_settings(): void {
		register_setting( self::SETTINGS_GROUP, YeffoPrint_Admin_Menu::SURCHARGE_GATEWAY_RATES_OPTION, [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize_gateway_rates' ],
			'default'           => [],
			'show_in_rest'      => false, // An array-of-arrays option needs an explicit schema to be REST-visible; nothing here reads it over REST.
		] );

		add_settings_section(
			'yeffoprint_surcharge',
			'',
			[ $this, 'render_section_intro' ],
			self::SETTINGS_PAGE
		);

		add_settings_field(
			YeffoPrint_Admin_Menu::SURCHARGE_GATEWAY_RATES_OPTION,
			__( 'Per-gateway rates', 'yeffoprint-core' ),
			[ $this, 'render_gateway_rates_field' ],
			self::SETTINGS_PAGE,
			'yeffoprint_surcharge'
		);
	}

	public function render_page(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Card Surcharge', 'yeffoprint-core' ) . '</h1>';
		echo '<form method="post" action="options.php">';
		settings_fields( self::SETTINGS_GROUP );
		do_settings_sections( self::SETTINGS_PAGE );
		submit_button();
		echo '</form></div>';
	}

	public function render_section_intro(): void {
		echo '<p>' . wp_kses(
			__( 'Adds a fee to the order total when the customer pays with a gateway given a rate below — direct request, to pass processing fees on to the customer, at whatever rate each gateway actually costs you (cards and Afterpay/BNPL gateways typically cost very different percentages). <strong>Before turning this on:</strong> credit card surcharging is banned outright in a few states (Connecticut, Massachusetts, Maine, and — as of a 2024 law — California, though its status has been challenged in court) and is capped by the card networks (currently 3% for Visa, 4% for Mastercard, or your actual processing cost if lower, whichever is less). It can never legally apply to a debit card — and this plugin has no way to tell a credit card from a debit card before checkout, since that only becomes known to your payment processor at the moment of payment, not to WooCommerce beforehand. Confirm with your payment processor or a lawyer that this is set up correctly for your state and card mix before relying on it.', 'yeffoprint-core' ),
			[ 'strong' => [] ]
		) . '</p>';
	}

	/** One row per currently-registered gateway: a rate (%) and an optional label override, defaulting blank/0 so nothing is surcharged until explicitly set. */
	public function render_gateway_rates_field(): void {
		$saved    = YeffoPrint_Card_Surcharge::get_gateway_rates();
		$gateways = function_exists( 'WC' ) && WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];

		if ( ! $gateways ) {
			esc_html_e( 'No payment gateways are registered yet.', 'yeffoprint-core' );
			return;
		}

		$option = YeffoPrint_Admin_Menu::SURCHARGE_GATEWAY_RATES_OPTION;
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
								placeholder="<?php echo esc_attr( YeffoPrint_Admin_Menu::SURCHARGE_LABEL_DEFAULT ); ?>"
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
	public function sanitize_gateway_rates( $raw ): array {
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
}
