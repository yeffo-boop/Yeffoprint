<?php namespace TierPricingTable\Addons\RequestAQuote\Settings;

use TierPricingTable\Settings\Settings as MainSettings;

class Settings {

	public function __construct() {

		add_filter( 'tiered_pricing_table/settings/sections', function ( $sections ) {

			$_sections = array();

			foreach ( $sections as $section ) {
				$_sections[] = $section;

				if ( $section->getSlug() === 'general' ) {
					$_sections[] = new RequestAQuoteSettingsSection();
				}
			}

			return $_sections;
		}, 15 );

		add_action( 'woocommerce_admin_field_tiered-pricing_request-a-quote-ui', function () {
			?>
			<style>
				p.submit {
					display: none !important;
				}
			</style>
			<tr valign="top">
				<td colspan="2" class="forminp">
					<div id="tiered-pricing__feature__request-a-quote"></div>
				</td>
			</tr>
			<?php
		} );

		add_action( 'admin_enqueue_scripts', function () {

			$settingsTab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : '';
			$section     = isset( $_GET['section'] ) ? sanitize_text_field( $_GET['section'] ) : '';

			if ( MainSettings::SETTINGS_PAGE !== $settingsTab ) {
				return;
			}

			if ( 'request-a-quote' !== $section ) {
				return;
			}

			$assetFile = include( plugin_dir_path( __FILE__ ) . '../build/index.asset.php' );

			wp_enqueue_script( 'tiered-pricing/feature/request-a-quote',
					plugins_url( 'build/index.js', dirname( __FILE__ . '../' ) ), $assetFile['dependencies'],
					$assetFile['version'], true );

			$isPremium  = tpt_fs()->can_use_premium_code__premium_only() ? 'true' : 'false';
			$upgradeUrl = tpt_fs()->get_upgrade_url();
			$script     = "window.tptRequestAQuote = { isPremium: {$isPremium}, upgradeUrl: '" . esc_url( $upgradeUrl ) . "' };";
			wp_add_inline_script( 'tiered-pricing/feature/request-a-quote', $script, 'before' );

			wp_set_script_translations( 'tiered-pricing/feature/request-a-quote', 'tier-pricing-table',
					dirname( __FILE__, 5 ) . '/languages' );

			wp_enqueue_style( 'wp-components' );
		} );
	}
}
