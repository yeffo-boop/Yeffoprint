<?php namespace TierPricingTable\Addons\CustomColumns\API;

use TierPricingTable\Addons\CustomColumns\CustomColumnsManager;

class CustomColumnsEndpoints {
	
	const NAMESPACE = 'tier-pricing-table/features/custom-columns/v1';
	
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}
	
	public function registerRoutes() {
		
		$columnsManager = CustomColumnsManager::getInstance();
		
		register_rest_route( self::NAMESPACE, '/columns', array(
			array(
				'methods'             => 'GET',
				'callback'            => function () use ( $columnsManager ) {
					$rawColumns = $columnsManager->getRawColumns();
					
					$columnsArray = array();
					foreach ( $rawColumns as $slug => $data ) {
						$columnsArray[] = array(
							'slug' => $slug,
							'name' => $data['name'] ?? '',
							'type' => $data['type'] ?? 'text',
							'data_type' => $data['data_type'] ?? 'text'
						);
					}
					
					return rest_ensure_response( $columnsArray );
				},
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			),
			array(
				'methods'             => 'POST',
				'callback'            => function ( $request ) use ( $columnsManager ) {
					$columnsPayload = $request->get_param( 'columns' );
					
					if ( ! is_array( $columnsPayload ) ) {
						return rest_ensure_response( array( 'success' => false, 'message' => 'Invalid data format' ) );
					}

					$columnsToSave = array();
					foreach ( $columnsPayload as $col ) {
						if ( isset( $col['slug'] ) && isset( $col['name'] ) && isset( $col['type'] ) ) {
							$columnsToSave[ sanitize_key( $col['slug'] ) ] = array(
								'name' => sanitize_text_field( $col['name'] ),
								'type' => sanitize_text_field( $col['type'] ),
								'data_type' => isset($col['data_type']) ? sanitize_text_field( $col['data_type'] ) : 'text'
							);
						}
					}
					
					$columnsManager->updateRawColumns( $columnsToSave );
					
					return rest_ensure_response( array( 'success' => true ) );
				},
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			),
		) );
	}
}
