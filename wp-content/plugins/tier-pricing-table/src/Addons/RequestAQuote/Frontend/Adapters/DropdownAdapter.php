<?php namespace TierPricingTable\Addons\RequestAQuote\Frontend\Adapters;

use TierPricingTable\Core\ServiceContainer;
use TierPricingTable\PricingRule;

class DropdownAdapter extends AbstractLayoutAdapter {

	public function registerHooks() {
		add_action( 'tiered_pricing_table/dropdown/after_options', array( $this, 'renderFormPrompt' ), 10, 1 );
		add_action( 'tiered_pricing_table/dropdown/options', array( $this, 'render' ), 10, 2 );
	}

	public function render( PricingRule $pricingRule, $settings = array() ) {
		$form = $this->getValidFormForIntegratedPosition( $pricingRule );
		if ( ! $form ) {
			return;
		}

		$productId = $pricingRule->getProductId();
		ServiceContainer::getInstance()->getFileManager()->includeTemplate(
			'frontend/integrated/dropdown.php',
			array(
				'form' => $form,
				'productId' => $productId,
				'buttonHtml' => $this->getQuoteButtonHtml( $form, $productId, 'button alt wp-element-button', 'padding: 5px 10px; margin: 0;' )
			),
			plugin_dir_path( dirname( __DIR__ ) ) . 'views/'
		);
	}
}
