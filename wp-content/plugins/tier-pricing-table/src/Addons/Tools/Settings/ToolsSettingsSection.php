<?php namespace TierPricingTable\Addons\Tools\Settings;

use TierPricingTable\Settings\Sections\SectionAbstract;

class ToolsSettingsSection extends SectionAbstract {
	
	public function getName(): string {
		return __( 'Tools', 'tier-pricing-table' );
	}
	
	public function getSlug(): string {
		return 'tools';
	}
	
	public function getSettings(): array {
		return array(
			array(
				'type' => 'tiered-pricing_tools-ui',
			),
		);
	}
	
	public function isIntegration(): bool {
		return false;
	}
}
