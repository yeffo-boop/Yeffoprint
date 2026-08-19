<?php namespace TierPricingTable\Addons\Tools\Settings;

use TierPricingTable\Settings\Settings as MainSettings;

class Settings {

	public function __construct() {

		add_filter( 'tiered_pricing_table/settings/sections', function ( $sections ) {

			$_sections = array();

			foreach ( $sections as $section ) {

				if ( $section->getSlug() === 'advanced' ) {
					$_sections[] = new ToolsSettingsSection();
				}

				$_sections[] = $section;
			}

			return $_sections;
		}, 10 );

		add_action( 'woocommerce_admin_field_tiered-pricing_tools-ui', function () {
			?>
			<style>
				p.submit {
					display: none !important;
				}
			</style>
			<tr valign="top">
				<td colspan="2" class="forminp">
					<div id="tiered-pricing__feature__tools"></div>
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

			if ( 'tools' !== $section ) {
				return;
			}

			$assetFile = include( plugin_dir_path( __FILE__ ) . '../build/index.asset.php' );

			wp_enqueue_script( 'tiered-pricing/feature/tools',
					plugins_url( 'build/index.js', dirname( __FILE__ . '../' ) ), $assetFile['dependencies'],
					$assetFile['version'], true );

			wp_set_script_translations( 'tiered-pricing/feature/tools', 'tier-pricing-table',
					dirname( __FILE__, 5 ) . '/languages' );

			wp_enqueue_style( 'wp-components' );
		} );
	}
}
