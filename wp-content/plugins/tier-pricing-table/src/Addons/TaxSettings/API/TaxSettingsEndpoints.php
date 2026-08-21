<?php namespace TierPricingTable\Addons\TaxSettings\API;

class TaxSettingsEndpoints {
	
	const OPTION_KEY = '_tpt_role_tax_settings';
	
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'registerEndpoints' ) );
	}
	
	public function registerEndpoints() {
		register_rest_route( 'tier-pricing-table/v1', '/tax-settings', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'getSettings' ),
			'permission_callback' => array( $this, 'checkPermission' ),
		) );
		
		register_rest_route( 'tier-pricing-table/v1', '/tax-settings', array(
			'methods'             => \WP_REST_Server::EDITABLE,
			'callback'            => array( $this, 'updateSettings' ),
			'permission_callback' => array( $this, 'checkPermission' ),
		) );
	}
	
	public function getSettings( \WP_REST_Request $request ) {
		$settings = get_option( self::OPTION_KEY, array() );
		
		return rest_ensure_response( $settings );
	}
	
	public function updateSettings( \WP_REST_Request $request ) {
		$params = $request->get_json_params();
		
		// Sanitize input
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
		
		update_option( self::OPTION_KEY, $sanitized_settings );
		
		return rest_ensure_response( $sanitized_settings );
	}
	
	public function checkPermission() {
		return current_user_can( 'manage_woocommerce' );
	}
}
