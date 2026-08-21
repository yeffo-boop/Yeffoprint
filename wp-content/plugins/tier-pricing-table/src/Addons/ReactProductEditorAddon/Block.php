<?php namespace TierPricingTable\Addons\ReactProductEditorAddon;

use Automattic\WooCommerce\Admin\Features\ProductBlockEditor\BlockRegistry;

abstract class Block {
	
	abstract public function isCustomBlock(): bool;
	
	abstract public function getCustomBlockFolder(): ?string;
	
	abstract public function getBlockName(): string;
	
	abstract public function getSectionId(): string;
	
	abstract public function getOrder(): int;
	
	abstract public function getId(): string;
	
	public function wrapToPremium(): bool {
		return false;
	}
	
	abstract public function getAttributes(): array;
	
	public function register() {
		if ( $this->isCustomBlock() ) {
			BlockRegistry::get_instance()->register_block_type_from_metadata( $this->getCustomBlockFolder() );
		}
	}
}
