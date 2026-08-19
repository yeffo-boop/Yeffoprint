<?php namespace TierPricingTable\Addons\RequestAQuote\Frontend\Adapters;

use TierPricingTable\Addons\RequestAQuote\Frontend\QuoteFormDisplay;
use TierPricingTable\Addons\RequestAQuote\Models\RequestQuoteForm;
use TierPricingTable\Core\ServiceContainer;
use TierPricingTable\PriceManager;
use TierPricingTable\PricingRule;

class AddToCartAdapter extends AbstractLayoutAdapter {
	
	protected bool $hasRendered = false;
	
	public function registerHooks() {
		// Hook into the single product page add to cart area
		add_action( 'woocommerce_after_add_to_cart_button', array( $this, 'renderButtonNextToCart' ), -10 );
		
		// Fallback for non-purchasable or out-of-stock products
		add_action( 'woocommerce_single_product_summary', array( $this, 'renderCustomCartForm' ), 31 );
		
		// Hook to hide the add to cart button via CSS if configured
		add_action( 'wp_head', array( $this, 'maybeHideAddToCartButton' ) );
	}
	
	public function renderButtonNextToCart() {
		global $product;
		
		if ( ! is_a( $product, 'WC_Product' ) ) {
			return;
		}
		
		$pricingRule = PriceManager::getPricingRule( $product->get_id() );
		$form        = $this->getValidFormForNextToCartPosition( $pricingRule );
		
		if ( ! $form ) {
			return;
		}
		
		$this->hasRendered = true;

		QuoteFormDisplay::addModalToRender( $form, $product->get_id() );
		
		$autoOpenQty = $form->getAutoOpenQuantity();
		$classes     = 'button alt wp-element-button tpt-request-quote-trigger tpt-quote-next-to-cart';
		$idAttr      = 'tpt-raq-link-' . esc_attr( $product->get_id() );
		$style       = 'margin-left: 10px;';
		
		echo ServiceContainer::getInstance()->getFileManager()->renderTemplate( 'frontend/integrated/quote-button.php',
			array(
				'form'        => $form,
				'productId'   => $product->get_id(),
				'classes'     => $classes,
				'idAttr'      => $idAttr,
				'autoOpenQty' => $autoOpenQty,
				'style'       => $style,
				'content'     => esc_html( $form->getPromptText() ),
			), plugin_dir_path( dirname( __DIR__ ) ) . 'views/' );
	}
	
	public function renderCustomCartForm() {
		if ( $this->hasRendered ) {
			return; // Standard button was already rendered
		}
		
		global $product;
		if ( ! is_a( $product, 'WC_Product' ) ) {
			return;
		}
		
		$pricingRule = PriceManager::getPricingRule( $product->get_id() );
		$form = $this->getValidFormForNextToCartPosition( $pricingRule );

		if ( ! $form || ! $form->getShowForNonPurchasable() ) {
			return;
		}

		$this->hasRendered = true; // Mark as rendered so we don't render again if called elsewhere
		
		QuoteFormDisplay::addModalToRender( $form, $product->get_id() );
		
		// Ensure standard woocommerce scripts know it's a form if needed, though mostly it's just for styling
		echo '<form class="cart tpt-custom-quote-cart" method="post" enctype="multipart/form-data" style="margin-top: 15px;">';
		
		$autoOpenQty = $form->getAutoOpenQuantity();
		$classes     = 'button alt wp-element-button tpt-request-quote-trigger tpt-quote-next-to-cart';
		$idAttr      = 'tpt-raq-link-' . esc_attr( $product->get_id() );

		echo ServiceContainer::getInstance()->getFileManager()->renderTemplate( 'frontend/integrated/quote-button.php',
			array(
				'form'        => $form,
				'productId'   => $product->get_id(),
				'classes'     => $classes,
				'idAttr'      => $idAttr,
				'autoOpenQty' => $autoOpenQty,
				'style'       => '',
				'content'     => esc_html( $form->getPromptText() ),
			), plugin_dir_path( dirname( __DIR__ ) ) . 'views/' );
			
		echo '</form>';
	}

	public function maybeHideAddToCartButton() {
		if ( ! is_product() ) {
			return;
		}
		
		$productId = get_the_ID();
		if ( ! $productId ) {
			return;
		}
		
		$pricingRule = PriceManager::getPricingRule( $productId );
		$form        = $this->getValidFormForNextToCartPosition( $pricingRule );
		
		if ( $form && $form->getHideAddToCart() ) {
			echo '<style>.single_add_to_cart_button { display: none !important; }</style>';
		}
	}
	
	/**
	 * Check if the given pricing rule has a valid form configured for the next_to_add_to_cart position.
	 *
	 * @param  PricingRule  $pricingRule
	 *
	 * @return RequestQuoteForm|false
	 */
	protected function getValidFormForNextToCartPosition( $pricingRule ) {
		$formId = $this->getAssignedFormId( $pricingRule, null );
		if ( ! $formId ) {
			return false;
		}
		
		$form = $this->getForm( $formId );
		if ( ! $form ) {
			return false;
		}
		
		if ( 'next_to_add_to_cart' !== $form->getDisplayPosition() ) {
			return false;
		}
		
		$overrides = $this->getEntityOverrides( $pricingRule, null );
		$form->setOverrides( $overrides );
		
		return $form;
	}
}
