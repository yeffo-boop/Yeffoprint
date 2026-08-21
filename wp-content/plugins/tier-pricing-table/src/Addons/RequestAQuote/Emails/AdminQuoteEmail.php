<?php namespace TierPricingTable\Addons\RequestAQuote\Emails;

use TierPricingTable\Addons\RequestAQuote\CPT\QuoteRequestCPT;
use TierPricingTable\Addons\RequestAQuote\Models\RequestQuoteForm;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AdminQuoteEmail extends \WC_Email {
	
	public function __construct() {
		$this->id             = 'tier_pricing_table_quote_request_admin';
		$this->title          = __( 'Quote Request (Admin)', 'tier-pricing-table' );
		$this->description    = __( 'This email is sent to the administrator when a customer requests a quote.',
			'tier-pricing-table' );
		$this->template_html  = 'emails/admin-quote-request.php';
		$this->template_plain = 'emails/plain/admin-quote-request.php';
		$this->template_base  = dirname( __DIR__ ) . '/views/';
		
		// Call parent constructor
		parent::__construct();
		
		$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
		
		add_action( 'tiered_pricing_table/request_quote/quote_request_submitted', array( $this, 'trigger' ), 10, 1 );
	}
	
	public function trigger( $postId ) {
		$this->object = new \TierPricingTable\Addons\RequestAQuote\Models\QuoteRequest( $postId );
		
		if ( ! $this->object || ! $this->object->getId() ) {
			return;
		}
		
		// Check form settings
		$formId = $this->object->getMeta('_form_id');
		$form   = RequestQuoteForm::get( $formId );
		
		if ( $form && ! $form->isAdminEmailEnabled() ) {
			return; // Admin email is disabled for this form
		}
		
		$this->setup_locale();
		
		$productId   = $this->object->getProductId();
		$product     = wc_get_product( $productId );
		$productName = $product ? $product->get_name() : __( 'Unknown Product', 'tier-pricing-table' );
		
		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(),
				$this->get_attachments() );
		}
		
		$this->restore_locale();
	}
	
	public function get_default_subject() {
		return __( '[{site_title}] New Quote Request for {product_name}', 'tier-pricing-table' );
	}
	
	public function get_default_heading() {
		return __( 'New Quote Request', 'tier-pricing-table' );
	}
	
	public function get_content_html() {
		return wc_get_template_html( $this->template_html, array(
			'quotePost'     => $this->object,
			'emailHeading'  => $this->get_heading(),
			'sent_to_admin' => true,
			'plain_text'    => false,
			'email'         => $this,
		), '', $this->template_base );
	}
	
	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, array(
			'quotePost'     => $this->object,
			'emailHeading'  => $this->get_heading(),
			'sent_to_admin' => true,
			'plain_text'    => true,
			'email'         => $this,
		), '', $this->template_base );
	}
	
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'    => array(
				'title'   => __( 'Enable/Disable', 'tier-pricing-table' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable this email notification', 'tier-pricing-table' ),
				'default' => 'yes',
			),
			'recipient'  => array(
				'title'       => __( 'Recipient(s)', 'tier-pricing-table' ),
				'type'        => 'text',
				// translators: %s: Admin email.
				'description' => sprintf( __( 'Enter recipients (comma separated) for this email. Defaults to %s.',
					'tier-pricing-table' ), '<code>' . esc_attr( get_option( 'admin_email' ) ) . '</code>' ),
				'placeholder' => '',
				'default'     => '',
				'desc_tip'    => true,
			),
			'subject'    => array(
				'title'       => __( 'Subject', 'tier-pricing-table' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => __( 'This controls the email subject line. Leave blank to use the default subject: ',
						'tier-pricing-table' ) . '<code>' . $this->get_default_subject() . '</code>',
				'placeholder' => $this->get_default_subject(),
				'default'     => '',
			),
			'heading'    => array(
				'title'       => __( 'Email heading', 'tier-pricing-table' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => __( 'This controls the main heading contained within the email notification. Leave blank to use the default heading: ',
						'tier-pricing-table' ) . '<code>' . $this->get_default_heading() . '</code>',
				'placeholder' => $this->get_default_heading(),
				'default'     => '',
			),
			'email_type' => array(
				'title'       => __( 'Email type', 'tier-pricing-table' ),
				'type'        => 'select',
				'description' => __( 'Choose which format of email to send.', 'tier-pricing-table' ),
				'default'     => 'html',
				'class'       => 'email_type wc-enhanced-select',
				'options'     => $this->get_email_type_options(),
				'desc_tip'    => true,
			),
		);
	}
	
	public function format_string( $string ) {
		$productName = __( 'Sample Product', 'tier-pricing-table' );
		
		if ( $this->object && $this->object instanceof \TierPricingTable\Addons\RequestAQuote\Models\QuoteRequest ) {
			$productId = $this->object->getProductId();
			$product   = wc_get_product( $productId );
			if ( $product ) {
				$productName = $product->get_name();
			}
		}
		
		$string = str_replace( '{product_name}', $productName, $string );
		
		return parent::format_string( $string );
	}
	
			public function get_submitted_fields() {
		if ( ! $this->object || ! ( $this->object instanceof \TierPricingTable\Addons\RequestAQuote\Models\QuoteRequest ) ) {
			return array(
				array( 'label' => __( 'Sample Name', 'tier-pricing-table' ), 'value' => 'John Doe' ),
				array( 'label' => __( 'Sample Email', 'tier-pricing-table' ), 'value' => 'john@example.com' ),
			);
		}
		
		$customFields = $this->object->getCustomFields();
		$fields = array();
		
		foreach ( $customFields as $key => $fieldData ) {
			if ( ! is_array( $fieldData ) ) {
				$fieldData = array( 'label' => ucfirst( str_replace('_', ' ', $key) ), 'value' => $fieldData );
			}
			$label = isset($fieldData['label']) && !empty($fieldData['label']) ? $fieldData['label'] : ucfirst( str_replace('_', ' ', $key) );
			$value = isset($fieldData['value']) ? $fieldData['value'] : '';
			
			$fields[] = array(
				'label' => $label,
				'value' => $value,
			);
		}
		
		return $fields;
	}
}
