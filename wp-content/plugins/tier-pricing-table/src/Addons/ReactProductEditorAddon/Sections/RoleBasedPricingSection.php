<?php namespace TierPricingTable\Addons\ReactProductEditorAddon\Sections;

use Automattic\WooCommerce\Admin\BlockTemplates\BlockTemplateInterface;
use Automattic\WooCommerce\Admin\Features\ProductBlockEditor\ProductTemplates\ProductFormTemplateInterface;
use Automattic\WooCommerce\Admin\Features\ProductBlockEditor\ProductTemplates\SectionInterface;
use TierPricingTable\Addons\ReactProductEditorAddon\TieredPricingGroup;

class RoleBasedPricingSection {
	
	const ID = 'tiered-pricing-table/product-editor-role-based-pricing-section';
	
	/**
	 * Section instance
	 *
	 * @var ?SectionInterface
	 */
	public $sectionInstance = null;
	
	public function __construct() {
		add_action( 'woocommerce_block_template_register', array( $this, 'register' ) );
	}
	
	public function register( BlockTemplateInterface $template ) {
		
		if ( $template instanceof ProductFormTemplateInterface && in_array( $template->get_id(),
				$this->getSupportedProductTypes() ) ) {
			
			$group = $template->get_group_by_id( TieredPricingGroup::ID );
			
			if ( ! $group ) {
				return;
			}
			
			$this->sectionInstance = $group->add_section( array(
				'id'         => self::ID,
				'order'      => 20,
				'attributes' => array(
					'title'       => __( 'Role-Based Pricing', 'tier-pricing-table' ),
					'description' => __( 'Set up custom pricing for specific user roles.', 'tier-pricing-table' ),
					'blockGap'    => 'unit-40',
				),
			) );
		}
	}
	
	public function getSupportedProductTypes(): array {
		return apply_filters( 'tiered_pricing_table/product_editor/role-based-section/supported_types',
			array( 'product-variation', 'simple-product' ) );
	}
}
