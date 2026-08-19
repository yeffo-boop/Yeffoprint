<?php namespace TierPricingTable\Addons\AdvancedQuantityOptions\ProductEditor;

use TierPricingTable\Addons\ReactProductEditorAddon\Block;
use TierPricingTable\Addons\ReactProductEditorAddon\Sections\MainSection;

class QuantityStepBlock extends Block {
	
	public function getCustomBlockFolder(): ?string {
		return null;
	}
	
	public function getId(): string {
		return 'tiered-pricing-table/product-editor-quantity-step';
	}
	
	public function getBlockName(): string {
		return 'woocommerce/product-number-field';
	}
	
	public function getOrder(): int {
		return 50;
	}
	
	public function getSectionId(): string {
		return MainSection::ID;
	}
	
	public function getAttributes(): array {
		return array(
			'label'    => __( 'Quantity step', 'tier-pricing-table' ),
			'property' => 'tiered_pricing_quantity_step',
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
