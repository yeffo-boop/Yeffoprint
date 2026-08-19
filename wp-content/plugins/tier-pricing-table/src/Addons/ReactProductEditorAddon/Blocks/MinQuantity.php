<?php namespace TierPricingTable\Addons\ReactProductEditorAddon\Blocks;

use TierPricingTable\Addons\ReactProductEditorAddon\Block;
use TierPricingTable\Addons\ReactProductEditorAddon\Sections\MainSection;

class MinQuantity extends Block {
	
	public function getCustomBlockFolder(): ?string {
		return null;
	}
	
	public function getId(): string {
		return 'tiered-pricing-table/product-editor-min-quantity';
	}
	
	public function getBlockName(): string {
		return 'woocommerce/product-number-field';
	}
	
	public function getOrder(): int {
		return 30;
	}
	
	public function getSectionId(): string {
		return MainSection::ID;
	}
	
	public function getAttributes(): array {
		return array(
			'label'    => __( 'Minimum Order Quantity', 'tier-pricing-table' ),
			'property' => 'tiered_pricing_minimum_quantity',
			'min'      => 1,
		);
	}
	
	public function isCustomBlock(): bool {
		return false;
	}
	
	public function wrapToPremium(): bool {
		return ! tpt_fs()->can_use_premium_code();
	}
}
