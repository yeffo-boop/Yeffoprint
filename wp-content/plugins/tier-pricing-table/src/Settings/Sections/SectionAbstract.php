<?php namespace TierPricingTable\Settings\Sections;

use TierPricingTable\Settings\Settings;

abstract class SectionAbstract {
	
	abstract public function getName();
	
	abstract public function getSlug();
	
	abstract public function getSettings();
	
	public function getSectionCSS(): string {
		return '';
	}
	
	public function isActive(): bool {
		
		if ( isset( $_GET['section'] ) ) {
			return $_GET['section'] === $this->getSlug();
		} else {
			return $this->getSlug() === Settings::DEFAULT_SECTION;
		}
	}
	
	public function getURL(): string {
		return add_query_arg( array( 'section' => $this->getSlug() ) );
	}
	
	public function isIntegration(): bool {
		return false;
	}
	
}
