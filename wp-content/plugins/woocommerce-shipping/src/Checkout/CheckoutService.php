<?php
/**
 * CheckoutService class.
 *
 * Service class for checkout-related functionality.
 *
 * @package Automattic/WCShipping
 */

namespace Automattic\WCShipping\Checkout;

use Automattic\WCShipping\Connect\WC_Connect_Options;
use Automattic\WCShipping\LabelPurchase\AddressNormalizationService;
use Automattic\WCShipping\Logger;
use Automattic\WCShipping\StoreApi\StoreApiExtendSchema;
use Automattic\WCShipping\Utilities\AddressUtils;
use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Automattic\WooCommerce\StoreApi\Utilities\CartController;
use WC_Cart;
use WC_Customer;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Class CheckoutService
 */
class CheckoutService {
	/**
	 * The session variable key for destination address normalization status.
	 *
	 * @var string
	 */
	const IS_DESTINATION_NORMALIZED_VAR = 'wcshipping_is_destination_normalized';

	/**
	 * The address normalization service.
	 *
	 * @var AddressNormalizationService
	 */
	private AddressNormalizationService $address_normalization_service;

	/**
	 * CheckoutService constructor.
	 *
	 * @param AddressNormalizationService $address_normalization_service The address normalization service.
	 */
	public function __construct( AddressNormalizationService $address_normalization_service ) {
		$this->address_normalization_service = $address_normalization_service;
	}

	/**
	 * Get data to pass to checkout scripts.
	 *
	 * @return array
	 */
	public static function get_checkout_script_data(): array {
		$data                         = array();
		$data['store_api_identifier'] = StoreApiExtendSchema::IDENTIFIER;
		$data['is_blocks_checkout']   = intval( has_block( 'woocommerce/checkout' ) );
		$data['settings']             = self::get_checkout_settings();

		return $data;
	}

	/**
	 * Get checkout settings.
	 *
	 * @return array
	 */
	public static function get_checkout_settings(): array {
		$settings = array();

		$account_settings = WC_Connect_Options::get_option( 'account_settings' );

		$settings['is_checkout_address_validation_enabled'] = $account_settings['checkout_address_validation'] ?? false;

		return $settings;
	}

	/**
	 * Is address validation enabled?
	 *
	 * @return bool
	 */
	public static function is_address_validation_enabled(): bool {
		$settings = self::get_checkout_settings();

		return $settings['is_checkout_address_validation_enabled'];
	}

	/**
	 * Is this a checkout page?
	 *
	 * @return bool
	 */
	public static function is_checkout_page(): bool {
		return is_checkout() || has_block( 'woocommerce/checkout' );
	}

	/**
	 * Is this a classic checkout request?
	 *
	 * @return bool
	 */
	public static function is_classic_checkout(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return ! empty( $_POST ) && self::is_checkout_page();
	}

	/**
	 * Is this a classic checkout update_order_review request?
	 *
	 * @return bool
	 */
	public static function is_update_order_review_request(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request context check.
		if ( ! isset( $_REQUEST['wc-ajax'] ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request context check.
		return 'update_order_review' === wc_clean( wp_unslash( $_REQUEST['wc-ajax'] ) );
	}

	/**
	 * Validate the shipping address.
	 *
	 * @return array
	 */
	public function validate_shipping_address(): array {
		$response = array(
			'success'          => false,
			'notices'          => array(),
			'is_address_valid' => false,
		);

		$this->set_destination_normalized_session_var( false );

		$debug_prefix = __CLASS__ . '::' . __FUNCTION__ . '() - ';

		// Get the shipping address.
		$shipping_address = $this->get_cart_shipping_address();
		if ( empty( $shipping_address['country'] ) ) {
			Logger::debug( $debug_prefix . 'Could not get the shipping address' );

			return $response;
		}

		// Make sure the required fields are set before proceeding.
		$required_field_keys = AddressUtils::get_required_shipping_address_field_keys( $shipping_address['country'], 'shipping_' );
		foreach ( $required_field_keys as $field_key ) {
			if ( empty( $shipping_address[ $field_key ] ) ) {
				return $response;
			}
		}

		// Check if we have a cached response.
		$shipping_address_hash = md5( wp_json_encode( $shipping_address ) );
		$cached_response       = get_transient( 'wcshipping_av_' . $shipping_address_hash );
		if ( $cached_response ) {
			Logger::debug( $debug_prefix . 'Using cached response', $cached_response );

			$this->set_destination_normalized_session_var( ! empty( $cached_response['is_address_valid'] ) );

			return $cached_response;
		}

		// We don't want any validation responses for empty name/company fields, so let's fill them with a placeholder.
		$shipping_address['first_name'] = $shipping_address['first_name'] ?? 'noval';
		$shipping_address['last_name']  = $shipping_address['last_name'] ?? 'noval';
		$shipping_address['company']    = $shipping_address['company'] ?? 'noval';

		// Normalize the address.
		$address_normalization_response = $this->address_normalization_service->get_normalization_response( $shipping_address );
		if ( $address_normalization_response instanceof WP_Error ) {
			Logger::debug( $debug_prefix . $address_normalization_response->get_error_message() );

			return $response;
		}

		// Log the address normalization response.
		Logger::debug( $debug_prefix . 'Address normalization response', $address_normalization_response );

		// Indicate we were able to get a non-WP_Error normalization response.
		$response['success'] = true;

		// Maybe process returned errors.
		if ( ! empty( $address_normalization_response['errors'] ) ) {
			$response['notices'][] = new StoreNotice(
				__( 'We couldn\'t verify the shipping address you entered. Please review and ensure it\'s accurate before placing your order.', 'woocommerce-shipping' ),
				StoreNoticeTypes::WARNING
			);

			foreach ( $address_normalization_response['errors'] as $message ) {
				$response['notices'][] = new StoreNotice(
					/**
					 * Filters the checkout address normalization error message.
					 *
					 * @since 2.3.10
					 *
					 * @param string $message Address normalization error message.
					 */
					apply_filters( 'woocommerce_shipping_address_normalization_response_error_message', $message ),
					StoreNoticeTypes::WARNING
				);
			}

			$this->cache_address_validation_response( $shipping_address_hash, $response );

			return $response;
		}

		$suggested_address = $address_normalization_response['normalizedAddress'] ?? array();

		// If we don't have a suggested address, add a notice and return.
		if ( empty( $suggested_address ) || ! is_array( $suggested_address ) ) {
			$response['notices'][] = new StoreNotice( __( 'We couldn\'t verify the shipping address you entered. Please review and ensure it\'s accurate before placing your order.', 'woocommerce-shipping' ), StoreNoticeTypes::WARNING );

			$this->cache_address_validation_response( $shipping_address_hash, $response );

			return $response;
		}

		$field_keys_to_validate = AddressUtils::get_address_field_keys_to_validate( $shipping_address['country'], 'shipping_' );

		// Remove empty values, since they must belong to optional fields.
		foreach ( $field_keys_to_validate as $idx => $field_key ) {
			if ( empty( $shipping_address[ $field_key ] ) ) {
				unset( $field_keys_to_validate[ $idx ] );
			}
		}

		// Remove elements that are not in the required fields for the country.
		$suggested_address = array_intersect_key( $suggested_address, array_flip( $field_keys_to_validate ) );
		$shipping_address  = array_intersect_key( $shipping_address, array_flip( $field_keys_to_validate ) );

		// If the country is US and the shipping address postcode is simple (5 digits), we should adjust
		// the suggested address postcode to match to avoid unnecessary address validation notices.
		if ( 'US' === $shipping_address['country'] && 5 === strlen( $shipping_address['postcode'] ) ) {
			$suggested_address['postcode'] = substr( $suggested_address['postcode'], 0, 5 );
		}

		// Compare the entered address with the suggested address to determine if they are close.
		$response['is_address_valid'] = AddressUtils::are_addresses_close( $shipping_address, $suggested_address );

		// If the address is not valid, add a notice.
		if ( ! $response['is_address_valid'] ) {
			$response['notices'][] = self::get_suggested_address_store_notice( $suggested_address );
		}

		$this->set_destination_normalized_session_var( $response['is_address_valid'] );

		$this->cache_address_validation_response( $shipping_address_hash, $response );

		return $response;
	}

	/**
	 * Cache a response for a given hash.
	 *
	 * @param string $hash      The hash to cache the response under.
	 * @param array  $response The response to cache.
	 */
	private function cache_address_validation_response( string $hash, array $response ) {
		set_transient( 'wcshipping_av_' . $hash, $response, 24 * HOUR_IN_SECONDS );
	}

	/**
	 * Set the destination normalized session var.
	 *
	 * This is used to store the normalized address in the WC Session so that it can be used later.
	 *
	 * @param bool $value The value to set.
	 */
	public function set_destination_normalized_session_var( bool $value ) {
		if ( empty( WC()->session ) ) {
			return;
		}

		WC()->session->set( self::IS_DESTINATION_NORMALIZED_VAR, $value );
	}

	/**
	 * Get the destination normalized session value.
	 *
	 * @return bool
	 */
	public function get_destination_normalized_session_value(): bool {
		if ( empty( WC()->session ) ) {
			return false;
		}

		return WC()->session->get( self::IS_DESTINATION_NORMALIZED_VAR ) ?? false;
	}

	/**
	 * Get the cart shipping address.
	 *
	 * @return array
	 */
	public function get_cart_shipping_address(): array {

		$customer = self::get_cart_customer_instance();
		if ( ! $customer instanceof WC_Customer ) {
			return array();
		}

		$shipping_address = $customer->get_shipping();

		self::maybe_fill_missing_shipping_address_data( $shipping_address );

		return $shipping_address ?? array();
	}

	/**
	 * Maybe fill missing shipping address data.
	 *
	 * @param array $shipping_address The shipping address (passed by reference).
	 */
	public static function maybe_fill_missing_shipping_address_data( array &$shipping_address ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- We are just checking if the post data is set.
		if ( ! self::is_classic_checkout() || ! isset( $_POST['post_data'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WooCommerce handles nonce verification, and we sanitize it after decoding the URL.
		$sanitized_post_data = wc_clean( urldecode( wp_unslash( $_POST['post_data'] ) ) );

		$post_data = array();
		parse_str( $sanitized_post_data, $post_data );

		if ( empty( $post_data ) ) {
			return;
		}

		$prefix =
			! isset( $post_data['ship_to_different_address'] )
			|| true !== wc_string_to_bool( $post_data['ship_to_different_address'] )
				? 'billing_'
				: 'shipping_';

		$fields_to_fill = array(
			'first_name',
			'last_name',
			'company',
		);

		foreach ( $fields_to_fill as $field ) {
			if ( empty( $shipping_address[ $field ] ) && isset( $post_data[ $prefix . $field ] ) ) {
				$shipping_address[ $field ] = $post_data[ $prefix . $field ];
			}
		}
	}

	/**
	 * Get the cart customer instance.
	 *
	 * @return false|WC_Customer
	 */
	public static function get_cart_customer_instance() {
		$cart = self::get_cart_instance();
		if ( ! $cart instanceof WC_Cart ) {
			return false;
		}

		$customer = $cart->get_customer();
		if ( ! $customer instanceof WC_Customer ) {
			return false;
		}

		return $customer;
	}

	/**
	 * Get the cart instance.
	 *
	 * @return false|WC_Cart
	 */
	public static function get_cart_instance() {
		try {
			$cart = ( new CartController() )->get_cart_instance();
		} catch ( RouteException $e ) {
			return false;
		}

		return $cart;
	}

	/**
	 * Get suggested address store notice.
	 *
	 * @param array $address The address.
	 *
	 * @return StoreNotice
	 */
	public static function get_suggested_address_store_notice( array $address ): StoreNotice {

		// Sort the address fields.
		$address = AddressUtils::get_standardized_address( $address );

		// Save full address to JSON string for use in the apply suggested address button.
		$address_json = wp_json_encode( $address );

		// Remove empty values.
		$filtered_suggested_address = array_filter( $address );

		$address_markup = '<strong>' . esc_html( implode( ', ', $filtered_suggested_address ) ) . '</strong>';

		$button_markup = '<button class="button wp-element-button wcshipping_apply_suggested_address" data-suggested_address="' . esc_attr( $address_json ) . '">' . esc_html__( 'Apply suggested address', 'woocommerce-shipping' ) . '</button>';

		$notice_message = sprintf(
		/* translators: 1: address, 2: button */
			__( 'We couldn\'t verify your shipping address. Did you mean %1$s? %2$s', 'woocommerce-shipping' ),
			$address_markup,
			$button_markup
		);

		return new StoreNotice( $notice_message, StoreNoticeTypes::WARNING );
	}
}
