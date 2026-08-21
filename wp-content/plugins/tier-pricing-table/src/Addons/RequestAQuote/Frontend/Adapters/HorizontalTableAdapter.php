<?php namespace TierPricingTable\Addons\RequestAQuote\Frontend\Adapters;

use TierPricingTable\Core\ServiceContainer;
use TierPricingTable\PricingRule;

class HorizontalTableAdapter extends AbstractLayoutAdapter {

	public function registerHooks() {
		add_action( 'tiered_pricing_table/horizontal-table/after_columns', array( $this, 'render' ), 10, 2 );
	}

	public function render( PricingRule $pricingRule, $settings = array() ) {
		$form = $this->getValidFormForIntegratedPosition( $pricingRule );
		if ( ! $form ) {
			return;
		}

		$productId = $pricingRule->getProductId();

		$hasQty      = ! empty( $settings['quantity_column_title'] ) || ! isset( $settings['quantity_column_title'] );
		$hasDiscount = ! empty( $settings['discount_column_title'] ) || ! empty( $settings['show_discount_column'] );
		$hasPrice    = ! empty( $settings['price_column_title'] ) || ! isset( $settings['price_column_title'] );
		ServiceContainer::getInstance()->getFileManager()->includeTemplate(
			'frontend/integrated/horizontal-table.php',
			array(
				'form' => $form,
				'productId' => $productId,
				'hasQty' => $hasQty,
				'hasDiscount' => $hasDiscount,
				'hasPrice' => $hasPrice,
				'buttonHtml' => $this->getQuoteButtonHtml( $form, $productId, 'button wp-element-button', 'padding: 5px 10px;margin:0', 'tpt-raq-table' )
			),
			plugin_dir_path( dirname( __DIR__ ) ) . 'views/'
		);
	}
}
