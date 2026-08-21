<?php namespace TierPricingTable\Addons\ReactProductEditorAddon;

use Automattic\WooCommerce\Admin\BlockTemplates\BlockTemplateInterface;
use Automattic\WooCommerce\Admin\Features\ProductBlockEditor\ProductTemplates\ProductFormTemplateInterface;
use TierPricingTable\Addons\ReactProductEditorAddon\Blocks\PremiumWrapper;
use TierPricingTable\Addons\ReactProductEditorAddon\Blocks\RoleBasedPricing;
use TierPricingTable\Addons\ReactProductEditorAddon\Blocks\UpgradeNotice;
use TierPricingTable\Addons\ReactProductEditorAddon\Sections\AdvanceProductOptionSection;
use TierPricingTable\Addons\ReactProductEditorAddon\Sections\MainSection;
use TierPricingTable\Addons\ReactProductEditorAddon\Sections\RoleBasedPricingSection;

class ProductEditor {
	
	/**
	 * Blocks
	 *
	 * @var Block[]
	 */
	protected $blocks;
	
	const GROUP_ID = 'tiered-pricing/group';
	
	/**
	 * Sections
	 *
	 * @var object
	 */
	protected $sections;
	
	public function __construct() {
		
		$blocks = array(
			new Blocks\TieredPricing(),
		);
		
		if ( ! tpt_fs()->can_use_premium_code() ) {
			$blocks[] = new UpgradeNotice();
		}
		
		$blocks[] = new Blocks\AdvanceProductOptions();
		$blocks[] = new Blocks\MinQuantity();
		$blocks[] = new RoleBasedPricing();
		$blocks[] = new PremiumWrapper();
		
		$this->blocks = apply_filters( 'tiered_pricing_table/product_editor/blocks', $blocks );
		
		$this->sections = apply_filters( 'tiered_pricing_table/product_editor/sections', array(
			new MainSection(),
			new AdvanceProductOptionSection(),
			new RoleBasedPricingSection(),
		) );
		
		new TieredPricingGroup();
		
		add_action( 'init', array( $this, 'registerBlocks' ) );
		add_filter( 'woocommerce_block_template_register', array( $this, 'addBlocks' ), 100 );
	}
	
	public function addBlocks( BlockTemplateInterface $template ) {
		
		if ( $template instanceof ProductFormTemplateInterface ) {
			
			$group = $template->get_group_by_id( self::GROUP_ID );
			
			if ( ! $group ) {
				return;
			}
			
			foreach ( $this->blocks as $block ) {
				
				$section = $template->get_section_by_id( $block->getSectionId() );
				
				if ( ! $section ) {
					continue;
				}
				
				$blockWrapper = $section;
				
				if ( $block->wrapToPremium() ) {
					$premiumWrapper = $section->add_block( array(
						'id'         => 'tiered-pricing-table/premium-wrapper',
						'blockName'  => 'tiered-pricing-table/premium-wrapper',
						'attributes' => array(
							'isPremium' => tpt_fs()->can_use_premium_code(),
						),
					) );
					
					$blockWrapper = $premiumWrapper;
				}
				
				$blockWrapper->add_block( [
					'id'         => $block->getId(),
					'order'      => $block->getOrder(),
					'blockName'  => $block->getBlockName(),
					'attributes' => $block->getAttributes(),
				] );
			}
		}
	}
	
	public function registerBlocks() {
		
		if ( version_compare( wc()->version, '10.8.0', '>' ) ) {
			return;
		}
		
		if ( ! class_exists( 'Automattic\WooCommerce\Admin\Features\ProductBlockEditor\BlockRegistry' ) ) {
			return;
		}
		
		if ( isset( $_GET['page'] ) && 'wc-admin' === $_GET['page'] ) {
			foreach ( $this->blocks as $block ) {
				$block->register();
			}
		}
	}
	
}
