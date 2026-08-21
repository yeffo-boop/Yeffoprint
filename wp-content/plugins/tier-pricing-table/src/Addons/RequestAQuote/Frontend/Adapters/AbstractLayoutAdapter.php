<?php namespace TierPricingTable\Addons\RequestAQuote\Frontend\Adapters;

use TierPricingTable\Addons\RequestAQuote\Frontend\QuoteFormDisplay;
use TierPricingTable\Core\ServiceContainer;
use TierPricingTable\PricingRule;
use TierPricingTable\Addons\RequestAQuote\Models\RequestQuoteForm;

abstract class AbstractLayoutAdapter {
	
	/**
	 * Register the necessary hooks for this adapter.
	 */
	abstract public function registerHooks();
	
	
	public function renderFormPrompt( PricingRule $pricingRule, $productId = null ) {
		if ( ! is_scalar( $productId ) ) {
			$productId = null;
		}
		// Try to find the associated form ID for this pricing rule.
		// For now, we will assume the form ID is attached to the pricing rule object or product meta
		$formId = $this->getAssignedFormId( $pricingRule, $productId );
		
		if ( ! $formId ) {
			return;
		}
		
		$form = $this->getForm( $formId );
		
		if ( ! $form ) {
			return;
		}
		
		$displayPosition = $form->getDisplayPosition();
		
		// This method only handles "after" position
		if ( 'after' !== $displayPosition ) {
			return;
		}
		
		$overrides = $this->getEntityOverrides( $pricingRule, $productId );
		$form->setOverrides( $overrides );
		
		ServiceContainer::getInstance()->getFileManager()->includeTemplate( 'frontend/integrated/prompt.php', array(
			'form'       => $form,
			'productId'  => $pricingRule->getProductId(),
			'buttonHtml' => $this->getQuoteButtonHtml( $form, $pricingRule->getProductId(), '', '' ),
		), plugin_dir_path( dirname( __DIR__ ) ) . 'views/' );
	}
	
	/**
	 * Renders the Request a Quote trigger button.
	 *
	 * @param  RequestQuoteForm  $form
	 * @param  int  $productId
	 * @param  string  $additionalClasses
	 * @param  string  $style
	 * @param  string  $idPrefix
	 * @param  bool  $isHidden  If true, the button is rendered visually hidden (useful for wrappers with onclick).
	 */
	protected function getQuoteButtonHtml(
		$form,
		$productId,
		string $additionalClasses = 'button alt wp-element-button',
		string $style = 'padding: 5px 10px; margin: 0;',
		string $idPrefix = 'tpt-raq-link',
		bool $isHidden = false
	): string {
		QuoteFormDisplay::addModalToRender( $form, $productId );
		$autoOpenQty = $form->getAutoOpenQuantity();
		
		if ( $isHidden ) {
			$style   = 'display:none;';
			$content = '';
		} else {
			$content = esc_html( $form->getPromptText() );
		}
		
		$classes = trim( 'tpt-request-quote-trigger ' . $additionalClasses );
		$idAttr  = $idPrefix . '-' . esc_attr( $productId );
		
		return ServiceContainer::getInstance()->getFileManager()->renderTemplate( 'frontend/integrated/quote-button.php',
			array(
				'form'        => $form,
				'productId'   => $productId,
				'classes'     => $classes,
				'idAttr'      => $idAttr,
				'autoOpenQty' => $autoOpenQty,
				'style'       => $style,
				'content'     => $content,
			), plugin_dir_path( dirname( __DIR__ ) ) . 'views/' );
	}
	
	/**
	 * Check if the given pricing rule has a valid form configured for the integrated position.
	 *
	 * @param  PricingRule  $pricingRule
	 *
	 * @return RequestQuoteForm|false
	 */
	protected function getValidFormForIntegratedPosition( PricingRule $pricingRule ) {
		$formId = $this->getAssignedFormId( $pricingRule, null );
		if ( ! $formId ) {
			return false;
		}
		
		$form = $this->getForm( $formId );
		if ( ! $form ) {
			return false;
		}
		
		if ( 'integrated' !== $form->getDisplayPosition() ) {
			return false;
		}
		
		$overrides = $this->getEntityOverrides( $pricingRule, null );
		$form->setOverrides( $overrides );
		
		return $form;
	}
	
	protected function getAssignedFormId( PricingRule $pricingRule ) {
		return $pricingRule->data['tier_pricing_table_quote_form_id'] ?? null;
	}
	
	protected function getEntityOverrides( PricingRule $pricingRule, $productId = null ) {
		return array(
			'auto_open_quantity'    => $pricingRule->data['tier_pricing_table_quote_auto_open_quantity'] ?? '',
			'integrated_label_text' => $pricingRule->data['tier_pricing_table_quote_integrated_label_text'] ?? '',
		);
	}
	
	/**
	 * Fetch a form by its ID.
	 *
	 * @param  string  $formId
	 *
	 * @return RequestQuoteForm|null
	 */
	protected function getForm( $formId ) {
		if ( ! tpt_fs()->can_use_premium_code__premium_only() && ! current_user_can( 'manage_options' ) ) {
			return null;
		}
		return RequestQuoteForm::get( $formId );
	}
}
