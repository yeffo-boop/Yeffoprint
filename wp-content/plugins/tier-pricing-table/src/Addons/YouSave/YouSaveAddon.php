<?php namespace TierPricingTable\Addons\YouSave;

use TierPricingTable\Addons\AbstractAddon;

class YouSaveAddon extends AbstractAddon {
	
	public function getName() {
		return __( '"You Save" Badge', 'tier-pricing-table' );
	}
	
	public function getDescription() {
		return __( 'Display a badge showing customers how much they save when tiered pricing is applied.', 'tier-pricing-table' );
	}
	
	public function getIcon(): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M21.41 11.58l-9-9C12.05 2.22 11.55 2 11 2H4c-1.1 0-2 .9-2 2v7c0 .55.22 1.05.59 1.42l9 9c.36.36.86.58 1.41.58.55 0 1.05-.22 1.41-.59l7-7c.37-.36.59-.86.59-1.41 0-.55-.23-1.06-.59-1.42zM5.5 7C4.67 7 4 6.33 4 5.5S4.67 4 5.5 4 7 4.67 7 5.5 6.33 7 5.5 7z"/></svg>';
	}
	
	public function getSlug() {
		return 'you-save';
	}
	
	public function run() {
		$this->getContainer()->initService( YouSaveService::class );
		add_filter( 'tiered_pricing_table/settings/general_subsections', array( $this, 'addSettingsSubsection' ) );
	}
	
	public function addSettingsSubsection( $subsections ) {
		$subsections[] = YouSaveSubsection::class;
		return $subsections;
	}
}
