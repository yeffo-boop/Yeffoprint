<?php namespace TierPricingTable\Addons\RequestAQuote\Frontend\Adapters;

use TierPricingTable\Core\ServiceContainer;
use TierPricingTable\PricingRule;

class TableAdapter extends AbstractLayoutAdapter {
	
	public function registerHooks() {
		add_action( 'tiered_pricing_table/tiered_pricing/rows', array( $this, 'render' ), 10, 3 );
		add_action( 'tiered_pricing_table/tiered_pricing/after', array( $this, 'renderFormPrompt' ), 10, 2 );
	}
	
	public function render( PricingRule $pricingRule, $settings = array(), $templateName = 'tiered-pricing-table' ) {
		
		$form = $this->getValidFormForIntegratedPosition( $pricingRule );
		
		if ( ! $form ) {
			return;
		}
		
		$isDivTable = strpos( $templateName, 'style-' ) !== false;
		
		$hasQty      = ! empty( $settings['quantity_column_title'] ) || ! isset( $settings['quantity_column_title'] );
		$hasDiscount = ! empty( $settings['discount_column_title'] ) || ! empty( $settings['show_discount_column'] );
		$hasPrice    = ! empty( $settings['price_column_title'] ) || ! isset( $settings['price_column_title'] );
		
		ServiceContainer::getInstance()->getFileManager()->includeTemplate( 'frontend/integrated/table.php', array(
			'form'        => $form,
			'productId'   => $pricingRule->getProductId(),
			'isDivTable'  => $isDivTable,
			'hasQty'      => $hasQty,
			'hasPrice'    => $hasPrice,
			'hasDiscount' => $hasDiscount,
			'buttonHtml'  => $this->getQuoteButtonHtml( $form, $pricingRule->getProductId(),
				$isDivTable ? 'button wp-element-button' : 'button wp-element-button alt',
				'padding: 5px 10px;margin:0; float:right', 'tpt-raq-table' ),
		), plugin_dir_path( dirname( __DIR__ ) ) . 'views/' );
	}
}
