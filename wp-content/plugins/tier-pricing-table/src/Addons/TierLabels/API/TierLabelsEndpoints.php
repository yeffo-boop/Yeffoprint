<?php namespace TierPricingTable\Addons\TierLabels\API;

use TierPricingTable\Addons\TierLabels\TierLabel;
use TierPricingTable\Addons\TierLabels\TierLabelsManager;

class TierLabelsEndpoints {
	
	const NAMESPACE = 'tier-pricing-table/features/tier-labels/v1';
	
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}
	
	public function registerRoutes() {
		
		$labelsManager = TierLabelsManager::getInstance();
		
		register_rest_route( self::NAMESPACE, '/labels', array(
			array(
				'methods'             => 'GET',
				'callback'            => function () use ( $labelsManager ) {
					$labels = $labelsManager->getLabels();
					
					$labelsArray = array_map( function ( $label ) {
						return $label->toArray();
					}, $labels );
					
					return rest_ensure_response( $labelsArray );
				},
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			),
			array(
				'methods'             => 'POST',
				'callback'            => function ( $request ) use ( $labelsManager ) {
					$labels         = $request->get_param( 'labels' );
					$labelInstances = array();
					
					foreach ( $labels as $label ) {
						$labelInstance = TierLabel::fromArray( $label );
						
						if ( $labelInstance->isValid() ) {
							$labelInstances[] = $labelInstance;
						}
					}
					
					$labelsManager->saveLabels( $labelInstances );
					
					return rest_ensure_response( array( 'success' => true ) );
				},
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			),
		) );
	}
}