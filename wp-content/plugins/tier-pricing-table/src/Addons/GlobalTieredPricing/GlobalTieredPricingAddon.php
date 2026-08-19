<?php namespace TierPricingTable\Addons\GlobalTieredPricing;

use TierPricingTable\Addons\AbstractAddon;
use TierPricingTable\Addons\GlobalTieredPricing\CPT\GlobalTieredPricingCPT;

class GlobalTieredPricingAddon extends AbstractAddon {
	
	public function getName(): string {
		return __( 'Global pricing rules', 'tier-pricing-table' );
	}
	
	public function run() {
		
		// Enable pricing service
		add_filter( 'tiered_pricing_table/services/pricing_service_enabled', '__return_true' );
		
		new LookupService();
		new GlobalTieredPricingCPT();
		new GlobalTieredPricingCartManager();
		new PricingService();
		
		GlobalPricingRulesRepository::getInstance();
		
		add_action( 'tiered_pricing_table/admin/pricing_tab_end', array(
			$this,
			'showMessageOnProductsTieredPricingTab',
		), 999 );
	}
	
	public function showMessageOnProductsTieredPricingTab() {
		$globalRules = GlobalTieredPricingCPT::getGlobalRules( false );
		
		if ( empty( $globalRules ) ) {
			$this->getContainer()->getFileManager()->includeTemplate( 'addons/global-rules/tiered-pricing-tab.php' );
		}
	}
	
	public function getDescription(): string {
		return __( 'Create reusable pricing rules and apply them globally.', 'tier-pricing-table' );
	}
	
	public function getIcon(): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/></svg>';
	}
	
	public function getSlug(): string {
		return 'global-tier-pricing';
	}
}
