<?php namespace TierPricingTable\Addons\ReactProductEditorAddon;

use TierPricingTable\Addons\AbstractAddon;

class ReactProductEditorAddon extends AbstractAddon {
	
	public function getName(): string {
		return __( 'WooCommerce Product Editor integration', 'tier-pricing-table' );
	}
	
	public function getDescription(): string {
		return __( 'Enable support for WooCommerce\'s React-based product editor.', 'tier-pricing-table' );
	}
	
	public function getIcon(): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M9.4 16.6L4.8 12l4.6-4.6L8 6l-6 6 6 6 1.4-1.4zm5.2 0l4.6-4.6-4.6-4.6L16 6l6 6-6 6-1.4-1.4z"/></svg>';
	}
	
	public function getSlug(): string {
		return 'woocommerce-react-product-editor';
	}
	
	public function run() {
		new ProductEditor();
	}
	
	protected function isActiveByDefault(): bool {
		return false;
	}
}
