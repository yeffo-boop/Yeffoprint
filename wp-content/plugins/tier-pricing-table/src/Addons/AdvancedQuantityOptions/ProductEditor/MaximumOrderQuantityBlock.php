<?php namespace TierPricingTable\Addons\AdvancedQuantityOptions\ProductEditor;

use TierPricingTable\Addons\ReactProductEditorAddon\Block;
use TierPricingTable\Addons\ReactProductEditorAddon\Sections\MainSection;

class MaximumOrderQuantityBlock extends Block {
	
	public function getCustomBlockFolder(): ?string {
		return null;
	}
	
	public function getId(): string {
		return 'tiered-pricing-table/product-editor-maximum-quantity';
	}
	
	public function getBlockName(): string {
		return 'woocommerce/product-number-field';
	}
	
	public function getOrder(): int {
		return 40;
	}
	
	public function getSectionId(): string {
		return MainSection::ID;
	}
	
	public function getAttributes(): array {
		return array(
			'label'    => __( 'Maximum Order Quantity', 'tier-pricing-table' ),
			'property' => 'tiered_pricing_maximum_quantity',
			'min'      => 2,
		);
	}
	
	public function isCustomBlock(): bool {
		return false;
	}
	
	public function wrapToPremium(): bool {
		return true;
	}
}
