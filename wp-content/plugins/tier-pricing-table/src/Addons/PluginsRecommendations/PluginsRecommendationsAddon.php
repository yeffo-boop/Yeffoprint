<?php namespace TierPricingTable\Addons\PluginsRecommendations;

use TierPricingTable\Addons\AbstractAddon;

class PluginsRecommendationsAddon extends AbstractAddon {
	
	public function getName(): string {
		return __( 'Plugins recommendations', 'tier-pricing-table' );
	}
	
	public function getDescription(): string {
		return __( 'Show recommendations for related plugins and addons.', 'tier-pricing-table' );
	}
	
	public function getIcon(): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M20.5 11H19V7c0-1.1-.9-2-2-2h-4V3.5C13 2.12 11.88 1 10.5 1S8 2.12 8 3.5V5H4c-1.1 0-1.99.9-1.99 2v3.8H.5c-.83 0-1.5.67-1.5 1.5s.67 1.5 1.5 1.5h1.5v3.8c0 1.1.9 2 2 2h4v1.5c0 1.38 1.12 2.5 2.5 2.5s2.5-1.12 2.5-2.5V20h4c1.1 0 2-.9 2-2v-4h1.5c.83 0 1.5-.67 1.5-1.5s-.67-1.5-1.5-1.5z"/></svg>';
	}
	
	public function getSlug(): string {
		return 'plugins-recommendations';
	}
	
	public function run() {
		new ConditionalLogicForProductAddons();
		new CancellationSurveysPlugin();
		new BulkPriceEditorPlugin();
	}
}
