<?php namespace TierPricingTable\Addons\Tools;

use TierPricingTable\Addons\AbstractAddon;
use TierPricingTable\Addons\Tools\API\ToolsEndpoints;
use TierPricingTable\Addons\Tools\Settings\Settings;

class ToolsAddon extends AbstractAddon {
	
	public function getName(): string {
		return __( 'Tools', 'tier-pricing-table' );
	}
	
	public function getDescription(): string {
		return __( 'Access utilities for managing and cleaning up tiered pricing data.', 'tier-pricing-table' );
	}
	
	public function getIcon(): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M22.7 19l-9.1-9.1c.9-2.3.4-5-1.5-6.9-2-2-5-2.4-7.4-1.3L9 6 6 9 1.6 4.7C.4 7.1.9 10.1 2.9 12.1c1.9 1.9 4.6 2.4 6.9 1.5l9.1 9.1c.4.4 1 .4 1.4 0l2.3-2.3c.5-.4.5-1.1.1-1.4z"/></svg>';
	}
	
	public function getSlug(): string {
		return 'tools';
	}
	
	public function run() {
		new ToolsEndpoints();
		new Settings();
		new AdminUserHooks();
	}
}
