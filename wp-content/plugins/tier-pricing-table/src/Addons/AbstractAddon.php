<?php namespace TierPricingTable\Addons;

use TierPricingTable\Core\ServiceContainerTrait;
use TierPricingTable\Settings\CustomOptions\TPTFeatureFlagOption;
use TierPricingTable\Settings\CustomOptions\TPTSwitchOption;
use TierPricingTable\Settings\Settings;

abstract class AbstractAddon {
	
	use ServiceContainerTrait;
	
	public function __construct() {
		add_filter( 'tiered_pricing_table/settings/advanced_settings', array(
			$this,
			'addToAddonsSettings',
		) );
	}
	
	public function addToAddonsSettings( $addons ) {
		$addons[] = array(
			'title'   => $this->getName(),
			'id'      => Settings::SETTINGS_PREFIX . '_addon_' . $this->getSlug(),
			'default' => $this->isActiveByDefault() ? 'yes' : 'no',
			'desc'    => $this->getDescription(),
			'icon'    => $this->getIcon(),
			'type'    => TPTFeatureFlagOption::FIELD_TYPE,
		);
		
		return $addons;
	}
	
	public function isEnabled(): bool {
		
		$settings = $this->getContainer()->getSettings();
		$addonKey = '_addon_' . $this->getSlug();
		
		return $settings->get( $addonKey, $this->isActiveByDefault() ? 'yes' : 'no' ) === 'yes';
	}
	
	protected function isActiveByDefault(): bool {
		return true;
	}
	
	abstract public function getName();
	
	abstract public function getDescription();
	
	abstract public function getIcon(): string;
	
	abstract public function getSlug();
	
	abstract public function run();
}
