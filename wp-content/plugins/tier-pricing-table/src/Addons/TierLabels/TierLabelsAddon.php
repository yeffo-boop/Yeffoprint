<?php namespace TierPricingTable\Addons\TierLabels;

use TierPricingTable\Addons\AbstractAddon;
use TierPricingTable\Addons\TierLabels\Admin\TierLabelsAdmin;
use TierPricingTable\Addons\TierLabels\API\TierLabelsEndpoints;
use TierPricingTable\Addons\TierLabels\Frontend\DisplayManager;
use TierPricingTable\Addons\TierLabels\Settings\Settings;

class TierLabelsAddon extends AbstractAddon {
	
	public function getName(): string {
		return __( 'Badges & Labels', 'tier-pricing-table' );
	}
	
	public function getDescription(): string {
		return __( 'Add custom labels like "Best Value" to specific pricing tiers.', 'tier-pricing-table' );
	}
	
	public function getIcon(): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M17.63 5.84C17.27 5.33 16.67 5 16 5L5 5.01C3.9 5.01 3 5.9 3 7v10c0 1.1.9 1.99 2 1.99L16 19c.67 0 1.27-.33 1.63-.84L22 12l-4.37-6.16z"/></svg>';
	}
	
	public function getSlug(): string {
		return 'tier-labels';
	}
	
	public function run() {
		
		$manager = TierLabelsManager::getInstance();
		
		new TierLabelsEndpoints();
		new TierLabelsAdmin( $manager );
		new Settings();
		new DisplayManager();
	}
}