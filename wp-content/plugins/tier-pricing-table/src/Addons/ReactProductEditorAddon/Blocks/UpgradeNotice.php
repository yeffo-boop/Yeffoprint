<?php namespace TierPricingTable\Addons\ReactProductEditorAddon\Blocks;

use TierPricingTable\Addons\ReactProductEditorAddon\Block;
use TierPricingTable\Addons\ReactProductEditorAddon\Sections\MainSection;

class UpgradeNotice extends Block {
	
	public function getCustomBlockFolder(): ?string {
		return __DIR__ . '/../js/upgrade-notice';
	}
	
	public function getId(): string {
		return 'tiered-pricing-table/upgrade-notice';
	}
	
	public function getBlockName(): string {
		return 'tiered-pricing-table/upgrade-notice';
	}
	
	public function getOrder(): int {
		return 30;
	}
	
	public function getSectionId(): string {
		return MainSection::ID;
	}
	
	public function getAttributes(): array {
		return array(
			'upgradeUrl' => tpt_fs_activation_url(),
		);
	}
	
	public function isCustomBlock(): bool {
		return true;
	}
}
