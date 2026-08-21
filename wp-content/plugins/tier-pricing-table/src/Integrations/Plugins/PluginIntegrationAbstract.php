<?php namespace TierPricingTable\Integrations\Plugins;

use TierPricingTable\Core\ServiceContainerTrait;
use TierPricingTable\Settings\CustomOptions\TPTIntegrationOption;
use TierPricingTable\Settings\Settings;

abstract class PluginIntegrationAbstract {
	
	use ServiceContainerTrait;
	
	abstract public function getTitle(): string;
	
	abstract public function getDescription(): string;
	
	abstract public function getSlug(): string;
	
	abstract public function run();
	
	public function __construct() {
		
		add_filter( 'tiered_pricing_table/settings/integrations_settings', array(
			$this,
			'addToIntegrationsSettings',
		) );
		
		if ( $this->isEnabled() ) {
			$this->run();
		}
	}
	
	public function addToIntegrationsSettings( $integrations ) {
		$integrations[] = array(
			'title'                => $this->getTitle(),
			'id'                   => Settings::SETTINGS_PREFIX . '_integration_' . $this->getSlug(),
			'default'              => $this->isActiveByDefault() ? 'yes' : 'no',
			'desc'                 => $this->getDescription(),
			'type'                 => TPTIntegrationOption::FIELD_TYPE,
			'icon_url'             => $this->getIconURL(),
			'author_url'           => $this->getAuthorURL(),
			'integration_category' => $this->getIntegrationCategory(),
		);
		
		return $integrations;
	}
	
	public function getIconURL(): ?string {
		return $this->getContainer()->getFileManager()->locateAsset( 'admin/integrations/placeholder.png' );
	}
	
	public function getAuthorURL(): ?string {
		return null;
	}
	
	public function isEnabled(): bool {
		
		$isEnabledByDefault = $this->isActiveByDefault() ? 'yes' : 'no';
		
		return $this->getContainer()->getSettings()->get( '_integration_' . $this->getSlug(),
				$isEnabledByDefault ) === 'yes';
	}
	
	protected function isActiveByDefault(): bool {
		return true;
	}
	
	public function getIntegrationCategory(): string {
		return 'other';
	}
}
