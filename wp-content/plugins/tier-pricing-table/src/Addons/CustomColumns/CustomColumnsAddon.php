<?php namespace TierPricingTable\Addons\CustomColumns;

use TierPricingTable\Addons\AbstractAddon;
use TierPricingTable\Addons\CustomColumns\Admin\CustomColumnsAdmin;
use TierPricingTable\Addons\CustomColumns\API\CustomColumnsEndpoints;

class CustomColumnsAddon extends AbstractAddon {

	/**
	 * Columns Manager
	 *
	 * @var CustomColumnsManager
	 */
	public $columnsManager;

	public function getName(): string {
		return __( 'Custom table columns', 'tier-pricing-table' );
	}

	public function getDescription(): string {
		return __( 'Add custom columns to your pricing table.', 'tier-pricing-table' );
	}
	
	public function getIcon(): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M4 3h16c.55 0 1 .45 1 1v16c0 .55-.45 1-1 1H4c-.55 0-1-.45-1-1V4c0-.55.45-1 1-1zm0 2v3h16V5H4zm0 5v10h4V10H4zm6 0v10h4V10h-4zm6 0v10h4V10h-4z"/></svg>';
	}

	public function getSlug(): string {
		return 'custom-columns';
	}

	public function run() {
		$this->columnsManager = CustomColumnsManager::getInstance();
		
		new CustomColumnsEndpoints();
		new CustomColumnsAdmin();
	}
}
