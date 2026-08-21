<?php namespace TierPricingTable\Addons\ReactProductEditorAddon\Blocks;

use TierPricingTable\Addons\ReactProductEditorAddon\Block;
use TierPricingTable\Addons\ReactProductEditorAddon\Sections\RoleBasedPricingSection;

class RoleBasedPricing extends Block {
	
	public function getCustomBlockFolder(): string {
		return __DIR__ . '/../js/role-based-pricing';
	}
	
	public function getId(): string {
		return 'tiered-pricing-table/product-editor-role-based-pricing';
	}
	
	public function getBlockName(): string {
		return 'tiered-pricing-table/product-editor-role-based-pricing';
	}
	
	public function getOrder(): int {
		return 10;
	}
	
	public function getSectionId(): string {
		return RoleBasedPricingSection::ID;
	}
	
	public function getAttributes(): array {
		return array(
			'availableRoles' => wp_roles()->roles,
			'isPremium'      => tpt_fs()->can_use_premium_code(),
			'upgradeUrl'     => tpt_fs_activation_url(),
		);
	}
	
	public function isCustomBlock(): bool {
		return true;
	}
}
