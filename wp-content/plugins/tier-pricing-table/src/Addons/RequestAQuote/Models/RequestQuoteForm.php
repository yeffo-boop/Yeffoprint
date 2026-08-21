<?php namespace TierPricingTable\Addons\RequestAQuote\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RequestQuoteForm {
	
	private array $data;
	private array $overrides = array();
	
	public function __construct( array $data ) {
		$this->data = $data;
	}
	
	public function setOverrides( array $overrides ) {
		$this->overrides = $overrides;
	}
	
	public function getId(): string {
		return isset( $this->data['id'] ) ? (string) $this->data['id'] : '';
	}
	
	public function getName(): string {
		return isset( $this->data['name'] ) ? (string) $this->data['name'] : '';
	}
	
	public function getPromptText(): string {
		return ! empty( $this->data['prompt_text'] ) ? (string) $this->data['prompt_text'] : __( 'Request a quote',
			'tier-pricing-table' );
	}
	
	public function getSuccessAction(): string {
		return isset( $this->data['success_action'] ) ? (string) $this->data['success_action'] : 'message';
	}
	
	public function getSuccessMessage(): string {
		return isset( $this->data['success_message'] ) ? (string) $this->data['success_message'] : __( 'Thank you for your request. We will contact you soon.',
			'tier-pricing-table' );
	}
	
	public function getSuccessRedirectUrl(): string {
		return isset( $this->data['success_redirect_url'] ) ? (string) $this->data['success_redirect_url'] : '';
	}
	
	public function getModalTitle(): string {
		return $this->data['modal_title'] ?? __( 'Request a Quote', 'tier-pricing-table' );
	}
	
	public function getDisplayPosition(): string {
		return $this->data['display_position'] ?? 'integrated';
	}
	
	public function getIntegratedLabelText(): string {
		if ( ! empty( $this->overrides['integrated_label_text'] ) ) {
			return $this->overrides['integrated_label_text'];
		}
		
		$label = $this->data['integrated_label_text'] ?? '';
		if ( empty( $label ) ) {
			return __( 'Custom Quantity', 'tier-pricing-table' );
		}
		
		return $label;
	}
	
	public function getHideAddToCart(): bool {
		return ! empty( $this->data['hide_add_to_cart'] );
	}
	
	public function getShowForNonPurchasable(): bool {
		return ! empty( $this->data['show_for_non_purchasable'] );
	}
	
	public function getSubmitButtonText(): string {
		return $this->data['submit_button_text'] ?? __( 'Submit Request', 'tier-pricing-table' );
	}
	
	public function getFields(): array {
		return isset( $this->data['fields'] ) && is_array( $this->data['fields'] ) ? $this->data['fields'] : array();
	}
	
	public function getAutoOpenQuantity(): string {
		if ( ! empty( $this->overrides['auto_open_quantity'] ) ) {
			return $this->overrides['auto_open_quantity'];
		}
		
		return isset( $this->data['auto_open_quantity'] ) ? (string) $this->data['auto_open_quantity'] : '';
	}
	
	public function isAdminEmailEnabled(): bool {
		return ! isset( $this->data['enable_admin_email'] ) || $this->data['enable_admin_email'] !== false;
	}
	
	public function isCustomerEmailEnabled(): bool {
		return ! isset( $this->data['enable_customer_email'] ) || $this->data['enable_customer_email'] !== false;
	}
	
	public function toArray(): array {
		$data = $this->data;
		
		$data['id']                    = $this->getId();
		$data['name']                  = $this->getName();
		$data['prompt_text']           = $this->getPromptText();
		$data['success_action']        = $this->getSuccessAction();
		$data['success_message']       = $this->getSuccessMessage();
		$data['success_redirect_url']  = $this->getSuccessRedirectUrl();
		$data['modal_title']           = $this->getModalTitle();
		$data['display_position']         = $this->getDisplayPosition();
		$data['hide_add_to_cart']         = $this->getHideAddToCart();
		$data['show_for_non_purchasable'] = $this->getShowForNonPurchasable();
		$data['integrated_label_text']    = $this->getIntegratedLabelText();
		$data['submit_button_text']    = $this->getSubmitButtonText();
		$data['auto_open_quantity']    = $this->getAutoOpenQuantity();
		$data['enable_admin_email']    = $this->isAdminEmailEnabled();
		$data['enable_customer_email'] = $this->isCustomerEmailEnabled();
		
		return $data;
	}
	
	/**
	 * Get a form by ID.
	 *
	 * @param  string  $id
	 *
	 * @return ?RequestQuoteForm
	 */
	public static function get( string $id ): ?RequestQuoteForm {
		$forms = self::getAll();
		foreach ( $forms as $form ) {
			if ( $form->getId() === $id ) {
				return $form;
			}
		}
		
		return null;
	}
	
	/**
	 * Get all forms.
	 *
	 * @return RequestQuoteForm[]
	 */
	public static function getAll(): array {
		$rawForms = get_option( 'tier_pricing_table_quote_forms', array() );
		$forms    = array();
		
		if ( ! is_array( $rawForms ) ) {
			return $forms;
		}
		
		foreach ( $rawForms as $rawForm ) {
			if ( is_array( $rawForm ) ) {
				$forms[] = new self( $rawForm );
			}
		}
		
		return $forms;
	}
}
