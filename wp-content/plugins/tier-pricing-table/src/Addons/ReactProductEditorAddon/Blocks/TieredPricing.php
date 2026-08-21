<?php namespace TierPricingTable\Addons\ReactProductEditorAddon\Blocks;

use TierPricingTable\Addons\ReactProductEditorAddon\Block;
use TierPricingTable\Addons\ReactProductEditorAddon\Sections\MainSection;

class TieredPricing extends Block {
	
	public function getCustomBlockFolder(): string {
		return __DIR__ . '/../js/tiered-pricing';
	}
	
	public function getId(): string {
		return 'tiered-pricing-table/product-editor-tiered-pricing';
	}
	
	public function getBlockName(): string {
		return 'tiered-pricing-table/product-editor-tiered-pricing';
	}
	
	public function getOrder(): int {
		return 10;
	}
	
	public function getSectionId(): string {
		return MainSection::ID;
	}
	
	public function getAttributes(): array {
		return array(
			'isPremium' => tpt_fs()->can_use_premium_code(),
		);
	}
	
	public function isCustomBlock(): bool {
		return true;
	}
}
