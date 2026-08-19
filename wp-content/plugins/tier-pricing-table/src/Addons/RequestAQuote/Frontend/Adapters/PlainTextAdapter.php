<?php namespace TierPricingTable\Addons\RequestAQuote\Frontend\Adapters;

use TierPricingTable\Core\ServiceContainer;
use TierPricingTable\PricingRule;

class PlainTextAdapter extends AbstractLayoutAdapter {

	public function registerHooks() {
		add_action( 'tiered_pricing_table/plain-text/after_lines', array( $this, 'renderFormPrompt' ), 10, 2 );
		add_action( 'tiered_pricing_table/plain-text/line', array( $this, 'render' ), 10, 2 );
	}

	public function render( PricingRule $pricingRule, $settings = array() ) {
		$form = $this->getValidFormForIntegratedPosition( $pricingRule );

		if ( ! $form ) {
			return;
		}
		ServiceContainer::getInstance()->getFileManager()->includeTemplate(
			'frontend/integrated/plain-text.php',
			array(
				'form' => $form,
				'productId' => $pricingRule->getProductId(),
				'buttonHtml' => $this->getQuoteButtonHtml( $form, $pricingRule->getProductId(), '', '' )
			),
			plugin_dir_path( dirname( __DIR__ ) ) . 'views/'
		);
	}
}
