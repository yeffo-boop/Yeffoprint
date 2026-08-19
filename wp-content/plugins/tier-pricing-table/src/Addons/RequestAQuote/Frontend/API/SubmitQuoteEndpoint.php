<?php namespace TierPricingTable\Addons\RequestAQuote\Frontend\API;

use TierPricingTable\Addons\RequestAQuote\CPT\QuoteRequestCPT;
use TierPricingTable\Addons\RequestAQuote\Models\QuoteRequest;
use TierPricingTable\Addons\RequestAQuote\Models\RequestQuoteForm;
use TierPricingTable\PriceManager;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;
use WP_Error;

class SubmitQuoteEndpoint extends WP_REST_Controller {
	
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}
	
	public function register_routes() {
		register_rest_route( 'tier-pricing-table/v1', '/quote-request', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'submitQuote' ),
			'permission_callback' => '__return_true', // Publicly accessible
		) );
	}
	
	public function submitQuote( WP_REST_Request $request ) {
		$params    = $request->get_params();
		$formId    = isset( $params['form_id'] ) ? sanitize_text_field( $params['form_id'] ) : '';
		$productId = isset( $params['product_id'] ) ? intval( $params['product_id'] ) : 0;
		
		$form    = RequestQuoteForm::get( $formId );
		$product = wc_get_product( $productId );
		
		if ( ! $product || ! $form ) {
			return new WP_Error( 'invalid_data', __( 'Form ID or Product ID is missing.', 'tier-pricing-table' ),
				array( 'status' => 400 ) );
		}
		
		// Additional Validation: Check if the product has this form attached
		$pricingRule    = PriceManager::getPricingRule( $productId );
		$attachedFormId = $pricingRule->data['tier_pricing_table_quote_form_id'] ?? null;
		
		if ( (string) $attachedFormId !== (string) $form->getId() ) {
			return new WP_Error( 'invalid_form_attachment',
				__( 'This form is not available for the selected product.', 'tier-pricing-table' ),
				array( 'status' => 403 ) );
		}
		
		$globalSettings = get_option( 'tier_pricing_table_quote_global_settings', array() );
		$siteKey        = $globalSettings['recaptcha_site_key'] ?? null;
		$secretKey      = $globalSettings['recaptcha_secret_key'] ?? null;
		
		if ( ! empty( $siteKey ) && ! empty( $secretKey ) ) {
			$recaptchaResponse = isset( $params['g-recaptcha-response'] ) ? sanitize_text_field( $params['g-recaptcha-response'] ) : '';
			if ( empty( $recaptchaResponse ) ) {
				return new WP_Error( 'spam_detected',
					__( 'Anti-Spam verification failed. Please try again.', 'tier-pricing-table' ),
					array( 'status' => 400 ) );
			}
			
			$verifyUrl      = 'https://www.google.com/recaptcha/api/siteverify';
			$verifyResponse = wp_remote_post( $verifyUrl, array(
				'body' => array(
					'secret'   => $secretKey,
					'response' => $recaptchaResponse,
				),
			) );
			
			if ( is_wp_error( $verifyResponse ) ) {
				return new WP_Error( 'spam_verification_error',
					__( 'Could not verify reCAPTCHA.', 'tier-pricing-table' ), array( 'status' => 500 ) );
			}
			
			$verifyBody = wp_remote_retrieve_body( $verifyResponse );
			$verifyData = json_decode( $verifyBody );
			
			if ( ! $verifyData || ! isset( $verifyData->success ) || ! $verifyData->success ) {
				return new WP_Error( 'spam_detected',
					__( 'Anti-Spam verification failed. Token invalid.', 'tier-pricing-table' ),
					array( 'status' => 400 ) );
			}
			
			// Check score (v3 only)
			if ( isset( $verifyData->score ) && $verifyData->score < 0.5 ) {
				return new WP_Error( 'spam_detected',
					__( 'Anti-Spam verification failed. Score too low.', 'tier-pricing-table' ),
					array( 'status' => 400 ) );
			}
		} else {
			// If reCAPTCHA is not configured, fallback to basic nonce check
			$nonce = isset( $params['_wpnonce'] ) ? sanitize_text_field( $params['_wpnonce'] ) : '';
			
			// Try to get nonce from header if not in params
			if ( empty( $nonce ) ) {
				$nonce = $request->get_header( 'x_wp_nonce' );
			}
			
			if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
				return new WP_Error( 'invalid_nonce',
					__( 'Security check failed. Please refresh the page and try again.', 'tier-pricing-table' ),
					array( 'status' => 403 ) );
			}
		}
		
		$quote = new QuoteRequest();
		
		$quote->setProductId( $productId );
		// translators: 1: Product name, 2: Date.
		$quote->setTitle( sprintf( __( '%1$s - %2$s', 'tier-pricing-table' ), $product->get_name(),
			wp_date( 'Y-m-d H:i:s' ) ) );
		
		$content = "Quote Request for Product: " . $product->get_name() . " (ID: " . $productId . ")\n\n";
		
		$customFields  = array();
		$customerEmail = '';
		$quantity      = 1;
		
		// Capture all params into customFields except the internal ones
		foreach ( $params as $key => $value ) {
			if ( in_array( $key,
				array( 'form_id', 'product_id', 'variation_id', '_wpnonce', 'g-recaptcha-response' ) ) ) {
				continue;
			}
			
			if ( is_array( $value ) ) {
				$sanitizedValue = implode( ', ', array_map( 'sanitize_textarea_field', $value ) );
			} else {
				$sanitizedValue = sanitize_textarea_field( $value );
			}
			
			// Enforce max lengths to prevent payload abuse
			$maxLength = ( strpos( $key, 'message' ) !== false || strpos( $key, 'textarea' ) !== false ) ? 1500 : 255;
			if ( mb_strlen( $sanitizedValue ) > $maxLength ) {
				$sanitizedValue = mb_substr( $sanitizedValue, 0, $maxLength );
			}
			
			if ( $key === 'quantity' ) {
				$quantity = (int) $sanitizedValue;
			} elseif ( strpos( $key, 'email' ) !== false && empty( $customerEmail ) && is_email( $sanitizedValue ) ) {
				$customerEmail = $sanitizedValue;
			}
			
			$fieldConfig = array( 'type' => 'text', 'label' => ucfirst( str_replace( '_', ' ', $key ) ) );
			if ( $form ) {
				foreach ( $form->getFields() as $formField ) {
					if ( isset( $formField['name'] ) && $formField['name'] === $key ) {
						$fieldConfig = $formField;
						break;
					}
				}
			}
			
			$fieldConfig['value'] = $sanitizedValue;
			$customFields[ $key ] = $fieldConfig;
			
			$content .= ucfirst( $key ) . ": " . $sanitizedValue . "\n";
		}
		
		// Process Files
		$fileUploadResult = $this->processFileUploads( $form, $customFields, $content );
		if ( is_wp_error( $fileUploadResult ) ) {
			return $fileUploadResult;
		}

		$quote->setCustomFields( $customFields );
		$quote->setCustomerEmail( $customerEmail );
		$quote->setQuantity( $quantity );
		$quote->setContent( $content );
		$quote->updateMetaData( '_form_id',
			$formId ); // Save form ID as regular meta since it's not a primary property anymore
		
		if ( get_current_user_id() > 0 ) {
			$quote->setUserId( get_current_user_id() );
		}
		
		// Calculate tier price based on customer's context
		$pricingRule = PriceManager::getPricingRule( $productId );
		$tierPrice   = $pricingRule->getTierPrice( $quantity, false );
		
		if ( ! $tierPrice && $tierPrice <= 0 ) {
			$tierPrice = $product->get_price();
		}
		$quote->setPrice( $tierPrice );
		
		$postId = $quote->save();
		
		if ( ! $postId ) {
			return new WP_Error( 'insert_failed', __( 'Could not save the quote request.', 'tier-pricing-table' ),
				array( 'status' => 500 ) );
		}
		
		// Trigger event for emails and other integrations
		do_action( 'tiered_pricing_table/request_quote/quote_request_submitted', $postId );
		
		return rest_ensure_response( array(
			'success' => true,
			'message' => __( 'Your quote request has been submitted successfully!', 'tier-pricing-table' ),
			'post_id' => $postId,
		) );
	}

	private function processFileUploads( $form, &$customFields, &$content ) {
		if ( empty( $_FILES ) ) {
			return true;
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once( ABSPATH . 'wp-admin/includes/file.php' );
		}
		
		foreach ( $_FILES as $key => $file ) {
			if ( empty( $file['name'] ) || $file['error'] !== UPLOAD_ERR_OK ) {
				continue;
			}

			$fieldConfig = array( 'type' => 'file', 'label' => ucfirst( str_replace( '_', ' ', $key ) ) );
			if ( $form ) {
				foreach ( $form->getFields() as $formField ) {
					if ( isset( $formField['name'] ) && $formField['name'] === $key ) {
						$fieldConfig = $formField;
						break;
					}
				}
			}

			// Validate Max Size
			if ( ! empty( $fieldConfig['maxFileSize'] ) ) {
				$maxBytes = $fieldConfig['maxFileSize'] * 1024 * 1024;
				if ( $file['size'] > $maxBytes ) {
					// translators: 1: File name, 2: Max file size in MB.
					return new WP_Error( 'file_too_large', sprintf( __( 'File %1$s exceeds the maximum allowed size of %2$s MB.', 'tier-pricing-table' ), esc_html( $file['name'] ), esc_html( $fieldConfig['maxFileSize'] ) ), array( 'status' => 400 ) );
				}
			}

			// Validate Extension
			if ( ! empty( $fieldConfig['allowedTypes'] ) ) {
				$allowedExts = array_map( 'strtolower', array_map( 'trim', explode( ',', str_replace( '.', '', $fieldConfig['allowedTypes'] ) ) ) );
				$fileExt = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
				if ( ! in_array( $fileExt, $allowedExts, true ) ) {
					// translators: 1: File extension, 2: Allowed extensions.
					return new WP_Error( 'invalid_file_type', sprintf( __( 'File type .%1$s is not allowed. Allowed: %2$s', 'tier-pricing-table' ), esc_html( $fileExt ), esc_html( $fieldConfig['allowedTypes'] ) ), array( 'status' => 400 ) );
				}
			}

			// Upload
			$uploadOverrides = array( 'test_form' => false );
			$movefile = wp_handle_upload( $file, $uploadOverrides );

			if ( $movefile && ! isset( $movefile['error'] ) ) {
				$fieldConfig['value'] = $movefile['url'];
				$fieldConfig['file_path'] = $movefile['file'];
				$customFields[ $key ] = $fieldConfig;
				$content .= ucfirst( $key ) . ": " . $movefile['url'] . "\n";
			} else {
				return new WP_Error( 'upload_failed', $movefile['error'] ?? __( 'File upload failed.', 'tier-pricing-table' ), array( 'status' => 500 ) );
			}
		}

		return true;
	}
}