<?php namespace TierPricingTable\Addons\RequestAQuote\Settings;

use TierPricingTable\Settings\Sections\SectionAbstract;

class RequestAQuoteSettingsSection extends SectionAbstract {
	
	public function getName(): string {
		return __( 'Request a Quote', 'tier-pricing-table' );
	}
	
	public function getSlug(): string {
		return 'request-a-quote';
	}
	
	public function getSettings(): array {
		return array(
			array(
				'type' => 'tiered-pricing_request-a-quote-ui',
			),
		);
	}
	
	public function isIntegration(): bool {
		return false;
	}
}
