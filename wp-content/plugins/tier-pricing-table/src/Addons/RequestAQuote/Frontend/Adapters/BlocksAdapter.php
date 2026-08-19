<?php namespace TierPricingTable\Addons\RequestAQuote\Frontend\Adapters;

use TierPricingTable\Core\ServiceContainer;
use TierPricingTable\PricingRule;

class BlocksAdapter extends AbstractLayoutAdapter {

	public function registerHooks() {
		// Default
		add_action( 'tiered_pricing_table/blocks/after_blocks', array( $this, 'renderFormPrompt' ), 10, 1 );
		// Integrated
		add_action( 'tiered_pricing_table/blocks/blocks', array( $this, 'render' ), 10, 2 );
	}

	public function render( PricingRule $pricingRule, $settings = array() ) {
		$form = $this->getValidFormForIntegratedPosition( $pricingRule );
		if ( ! $form ) {
			return;
		}

		$blocksStyle = $settings['blocks_style'] ?? 'default';
		$promptText  = $form->getPromptText();
		$formId      = $form->getId();
		$productId   = $pricingRule->getProductId();

		ServiceContainer::getInstance()->getFileManager()->includeTemplate(
			'frontend/integrated/blocks.php',
			array(
				'form' => $form,
				'productId' => $productId,
				'blocksStyle' => $blocksStyle,
				'promptText' => $promptText,
				'buttonHtml' => $this->getQuoteButtonHtml( $form, $productId, '', '', 'tpt-raq-link', true )
			),
			plugin_dir_path( dirname( __DIR__ ) ) . 'views/'
		);
	}
}
