<?php namespace TierPricingTable\Addons\TierLabels\Settings;

use TierPricingTable\Settings\Settings as MainSettings;

class Settings {

	public function __construct() {

		add_filter( 'tiered_pricing_table/settings/sections', function ( $sections ) {

			$_sections = array();

			foreach ( $sections as $section ) {
				$_sections[] = $section;

				if ( $section->getSlug() === 'general' ) {
					$_sections[] = new LabelsSettingsSection();
				}
			}

			return $_sections;
		}, 10 );

		add_action( 'woocommerce_admin_field_tiered-pricing_tier-labels-crud', function () {
			?>
			<style>
				p.submit {
					display: none !important;
				}
			</style>
			<tr valign="top">
				<td colspan="2" class="forminp">
					<div id="tiered-pricing__feature__tier-labels"></div>
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

			if ( 'tier-labels' !== $section ) {
				return;
			}

			$assetFile = include( plugin_dir_path( __FILE__ ) . '../build/index.asset.php' );

			wp_enqueue_script( 'tiered-pricing/feature/tier-labels',
					plugins_url( 'build/index.js', dirname( __FILE__ . '../' ) ), $assetFile['dependencies'],
					$assetFile['version'], true );

			wp_set_script_translations( 'tiered-pricing/feature/tier-labels', 'tier-pricing-table',
					dirname( __FILE__, 5 ) . '/languages' );
		} );
	}
}
