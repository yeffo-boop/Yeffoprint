<?php namespace TierPricingTable\Addons\ReactProductEditorAddon\Blocks;

use TierPricingTable\Addons\ReactProductEditorAddon\Block;
use TierPricingTable\Addons\ReactProductEditorAddon\Sections\AdvanceProductOptionSection;
use TierPricingTable\TierPricingTablePlugin;

class AdvanceProductOptions extends Block {
	
	public function getCustomBlockFolder(): string {
		return __DIR__ . '/../js/advance-product-options';
	}
	
	public function getId(): string {
		return 'tiered-pricing-table/product-editor-advance-product-options';
	}
	
	public function getBlockName(): string {
		return 'tiered-pricing-table/product-editor-advance-product-options';
	}
	
	public function getOrder(): int {
		return 30;
	}
	
	public function getSectionId(): string {
		return AdvanceProductOptionSection::ID;
	}
	
	public function getAttributes(): array {
		return array(
			'availableRoles'   => wp_roles()->roles,
			'availableLayouts' => TierPricingTablePlugin::getAvailablePricingLayouts(),
		);
	}
	
	public function isCustomBlock(): bool {
		return true;
	}
}
