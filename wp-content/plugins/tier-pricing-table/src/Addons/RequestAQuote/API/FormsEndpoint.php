<?php namespace TierPricingTable\Addons\RequestAQuote\API;

use WP_REST_Controller;
use WP_REST_Server;

class FormsEndpoint extends WP_REST_Controller {

	protected $namespace = 'tier-pricing-table/v1';
	protected $restBase = 'quote-forms';

	public function register_routes() {
		register_rest_route( $this->namespace, '/' . $this->restBase, array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_item' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			),
		) );
	}

	public function permissions_check( $request ) {
		return current_user_can( 'manage_woocommerce' );
	}

	public function get_items( $request ) {
		$forms = get_option( 'tier_pricing_table_quote_forms', array() );
		return rest_ensure_response( $forms );
	}

	public function create_item( $request ) {
		$forms = $request->get_param( 'forms' );

		if ( ! is_array( $forms ) ) {
			return new \WP_Error( 'invalid_data', 'Forms must be an array', array( 'status' => 400 ) );
		}

		update_option( 'tier_pricing_table_quote_forms', $forms );

		return rest_ensure_response( $forms );
	}
}
