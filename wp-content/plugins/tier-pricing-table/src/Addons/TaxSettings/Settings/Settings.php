<?php namespace TierPricingTable\Addons\TaxSettings\Settings;

class Settings {

	public function __construct() {

		add_filter( 'woocommerce_get_settings_tax', function ( $settings, $current_section ) {
			if ( '' === $current_section ) {
				$settings[] = array(
						'type' => 'tiered-pricing_tax-ui',
				);
			}

			return $settings;
		}, 10, 2 );

		add_action( 'woocommerce_settings_save_tax', function () {
			if ( isset( $_POST['tpt_role_tax_settings'] ) ) {
				$params = json_decode( stripslashes( $_POST['tpt_role_tax_settings'] ), true );

				$sanitized_settings = array();

				if ( is_array( $params ) ) {
					foreach ( $params as $role => $data ) {
						if ( is_array( $data ) ) {
							$sanitized_settings[ sanitize_key( $role ) ] = array(
									'tax_exempt'         => isset( $data['tax_exempt'] ) ? (bool) $data['tax_exempt'] : false,
									'tax_class'          => isset( $data['tax_class'] ) ? sanitize_text_field( $data['tax_class'] ) : 'default',
									'display_shop'       => isset( $data['display_shop'] ) ? sanitize_text_field( $data['display_shop'] ) : 'default',
									'display_cart'       => isset( $data['display_cart'] ) ? sanitize_text_field( $data['display_cart'] ) : 'default',
									'prices_include_tax' => isset( $data['prices_include_tax'] ) ? sanitize_text_field( $data['prices_include_tax'] ) : 'default',
									'price_suffix'       => isset( $data['price_suffix'] ) ? sanitize_text_field( $data['price_suffix'] ) : '',
							);
						}
					}
				}
				update_option( '_tpt_role_tax_settings', $sanitized_settings );
			}
		} );

		add_action( 'woocommerce_admin_field_tiered-pricing_tax-ui', function () {
			?>
			<tr valign="top">
				<td colspan="2" class="forminp">
					<div id="tiered-pricing__feature__tax"></div>
				</td>
			</tr>
			<?php
		} );

		add_action( 'admin_enqueue_scripts', function () {

			$page        = isset( $_GET['page'] ) ? sanitize_text_field( $_GET['page'] ) : '';
			$settingsTab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : '';
			$section     = isset( $_GET['section'] ) ? sanitize_text_field( $_GET['section'] ) : '';

			if ( 'wc-settings' !== $page || 'tax' !== $settingsTab || '' !== $section ) {
				return;
			}

			if ( ! file_exists( plugin_dir_path( __FILE__ ) . '../build/index.asset.php' ) ) {
				return;
			}

			$assetFile = include( plugin_dir_path( __FILE__ ) . '../build/index.asset.php' );

			wp_enqueue_script( 'tiered-pricing/feature/tax',
					plugins_url( 'build/index.js', dirname( __FILE__ . '../' ) ), $assetFile['dependencies'],
					$assetFile['version'], true );

			wp_set_script_translations( 'tiered-pricing/feature/tax', 'tier-pricing-table',
					dirname( __FILE__, 5 ) . '/languages' );

			$roles          = wp_roles()->get_names();
			$formattedRoles = array();
			foreach ( $roles as $value => $label ) {
				$formattedRoles[] = array( 'value' => $value, 'label' => translate_user_role( $label ) );
			}

			wp_localize_script( 'tiered-pricing/feature/tax', 'tptTaxSettings', array(
					'roles'      => $formattedRoles,
					'isPremium'  => function_exists( 'tpt_fs' ) && tpt_fs()->can_use_premium_code(),
					'upgradeUrl' => function_exists( 'tpt_fs' ) ? tpt_fs()->get_upgrade_url() : '#',
			) );

			wp_enqueue_style( 'wp-components' );
		} );
	}
}
