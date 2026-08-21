<?php namespace TierPricingTable\Settings\Sections;

use TierPricingTable\Settings\Settings;

abstract class SubsectionAbstract {
	
	abstract public function getTitle();
	
	abstract public function getDescription();
	
	abstract public function getSlug();
	
	abstract public function getSettings();
	
	public function getWrappedSettings() {
		
		$settings = $this->getSettings();
		
		array_unshift( $settings, array(
			'title'             => $this->getTitle(),
			'desc'              => $this->getDescription(),
			'id'                => Settings::SETTINGS_PREFIX . '_subsection_' . $this->getSlug(),
			'type'              => 'title',
			'custom_attributes' => $this->getCustomAttributes(),
		) );
		
		$settings[] = array(
			'type' => 'sectionend',
			'id'   => Settings::SETTINGS_PREFIX . '_end_subsection_' . $this->getSlug(),
		);
		
		return $settings;
	}
	
	protected function getCustomAttributes() {
		return array();
	}
}
