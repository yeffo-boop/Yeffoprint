<?php namespace TierPricingTable\Addons\ReactProductEditorAddon\Sections;

use Automattic\WooCommerce\Admin\BlockTemplates\BlockTemplateInterface;
use Automattic\WooCommerce\Admin\Features\ProductBlockEditor\ProductTemplates\ProductFormTemplateInterface;
use Automattic\WooCommerce\Admin\Features\ProductBlockEditor\ProductTemplates\SectionInterface;
use TierPricingTable\Addons\ReactProductEditorAddon\TieredPricingGroup;

class MainSection {
	
	const ID = 'tiered-pricing-table/product-editor-main-section';
	
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
				'order'      => 10,
				'attributes' => array(
					'title'       => __( 'Tiered Pricing', 'tier-pricing-table' ),
					'description' => __( 'Specify tiered pricing and quantity limits.', 'tier-pricing-table' ),
					'blockGap'    => 'unit-40',
				),
			) );
		}
	}
	
	public function getSupportedProductTypes(): array {
		return apply_filters( 'tiered_pricing_table/product_editor/main-section/supported_types',
			array( 'product-variation', 'simple-product' ) );
	}
}
