<?php namespace TierPricingTable\Addons\ReactProductEditorAddon;

use Automattic\WooCommerce\Admin\BlockTemplates\BlockInterface;

class TieredPricingGroup {
	
	const ID = 'tiered-pricing/group';
	
	public function __construct() {
		add_action( 'woocommerce_block_template_area_product-form_after_add_block_general',
			array( $this, 'addGroup' ) );
	}
	
	public function addGroup( BlockInterface $generalGroup ) {
		$parent = $generalGroup->get_parent();
		
		$parent->add_group( [
			'id'         => self::ID,
			'order'      => $generalGroup->get_order() + 5,
			'attributes' => [
				'title' => __( 'Tiered Pricing', 'tier-pricing-table' ),
			],
		] );
	}
}
