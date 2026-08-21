<?php namespace TierPricingTable\Addons\TierLabels;

class TierLabelsManager {
	
	const LABELS_KEY = 'tiered_pricing_table/features/tier-labels/labels';
	
	protected static $instance = null;
	protected $labels = array();
	
	public static function getInstance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		
		return self::$instance;
	}
	
	private function __construct() {}
	
	public function saveLabels( array $labels ) {
		$labelsAsArray = array_map( function ( $label ) {
			return $label->toArray();
		}, $labels );
		
		update_option( self::LABELS_KEY, $labelsAsArray );
	}
	
	public function getLabels( $force = false ): array {
		
		if ( empty( $this->labels ) || $force ) {
			$customLabels = get_option( self::LABELS_KEY, array() );
			$instances    = array();
			
			foreach ( $customLabels as $label ) {
				$instances[] = TierLabel::fromArray( $label );
			}
			
			$this->labels = $instances;
		}
		
		return $this->labels;
	}
	
	public function getLabel( $id ): ?TierLabel {
		$labels = $this->getLabels();
		
		foreach ( $labels as $label ) {
			if ( $label->getId() === $id ) {
				return $label;
			}
		}
		
		return null;
	}
}