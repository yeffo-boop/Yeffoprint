<?php namespace TierPricingTable\Addons\PricingSummary;

use TierPricingTable\Addons\AbstractAddon;

class PricingSummaryAddon extends AbstractAddon {
	
	public function getName() {
		return __( 'Pricing Summary', 'tier-pricing-table' );
	}
	
	public function getDescription() {
		return __( 'Display a dynamic pricing summary showing the total cost of the selected quantity.', 'tier-pricing-table' );
	}
	
	public function getIcon(): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z"/></svg>';
	}
	
	public function getSlug() {
		return 'pricing-summary';
	}
	
	public function run() {
		$this->getContainer()->initService( PricingSummaryService::class );
		add_filter( 'tiered_pricing_table/settings/general_subsections', array( $this, 'addSettingsSubsection' ) );
	}
	
	public function addSettingsSubsection( $subsections ) {
		$subsections[] = SummarySubsection::class;
		return $subsections;
	}
}
