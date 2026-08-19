<?php namespace TierPricingTable\Addons\CatalogPrices;

use TierPricingTable\Addons\AbstractAddon;

class CatalogPricesAddon extends AbstractAddon {
	
	public function getName(): string {
		return __( 'Shop & Categories Price Format', 'tier-pricing-table' );
	}
	
	public function getDescription() {
		return __( 'Manage how tiered pricing appears in catalogs, loops, and widgets.', 'tier-pricing-table' );
	}
	
	public function getIcon(): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm16-4H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H8V4h12v12zM10 9h8v2h-8zm0 3h4v2h-4zm0-6h8v2h-8z"/></svg>';
	}
	
	public function getSlug() {
		return 'catalog-prices';
	}
	
	public function run() {
		$this->getContainer()->initService( CatalogPricesService::class );
		add_filter( 'tiered_pricing_table/settings/general_subsections', array( $this, 'addSettingsSubsection' ) );
	}
	
	public function addSettingsSubsection( $subsections ) {
		$subsections[] = CatalogPricesSubsection::class;
		return $subsections;
	}
}
