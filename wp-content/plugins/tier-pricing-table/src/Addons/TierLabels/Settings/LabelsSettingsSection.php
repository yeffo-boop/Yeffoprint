<?php namespace TierPricingTable\Addons\TierLabels\Settings;

use TierPricingTable\Settings\Sections\SectionAbstract;

class LabelsSettingsSection extends SectionAbstract {
	
	public function getName(): string {
		return __( 'Badges & Labels', 'tier-pricing-table' );
	}
	
	public function getSlug(): string {
		return 'tier-labels';
	}
	
	public function getSettings(): array {
		return array(
			array(
				'type' => 'tiered-pricing_tier-labels-crud',
			),
		);
	}
	
	public function isIntegration(): bool {
		return false;
	}
}
