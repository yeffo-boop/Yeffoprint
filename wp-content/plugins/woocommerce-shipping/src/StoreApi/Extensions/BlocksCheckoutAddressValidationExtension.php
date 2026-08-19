<?php
/**
 * BlocksCheckoutAddressValidationExtension class.
 *
 * Extends the WooCommerce Store API to add address validation to the checkout block.
 *
 * @package Automattic/WCShipping
 */

namespace Automattic\WCShipping\StoreApi\Extensions;

use Automattic\WCShipping\Checkout\CheckoutService;
use Automattic\WCShipping\Shipment\Address;
use Automattic\WCShipping\StoreApi\AbstractStoreApiExtension;
use Automattic\WCShipping\Utilities\AddressUtils;
use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema;
use WC_Customer;

defined( 'ABSPATH' ) || exit;

/**
 * Class BlocksCheckoutAddressValidationExtension
 */
class BlocksCheckoutAddressValidationExtension extends AbstractStoreApiExtension {
	/**
	 * The checkout service.
	 *
	 * @var CheckoutService
	 */
	private CheckoutService $checkout_service;

	/**
	 * BlocksCheckoutAddressValidationExtension constructor.
	 *
	 * @param ExtendSchema    $extend_schema    The ExtendSchema instance.
	 * @param CheckoutService $checkout_service The checkout service.
	 */
	public function __construct( ExtendSchema $extend_schema, CheckoutService $checkout_service ) {
		parent::__construct( $extend_schema );

		$this->checkout_service = $checkout_service;
	}

	/**
	 * Get the endpoint to extend.
	 *
	 * Should return one of the keys from the $endpoints array.
	 *
	 * @return string
	 */
	public function get_endpoint(): string {
		return self::$endpoints['cart'];
	}

	/**
	 * The data callback method.
	 *
	 * This is where you can define the data this endpoint should return.
	 *
	 * @return array
	 */
	public function data_callback(): array {
		$data = array(
			'notices' => array(),
		);

		// If checkout address validation is disabled, return early.
		if ( ! CheckoutService::is_address_validation_enabled() ) {
			return $data;
		}

		// Validate the shipping address.
		$response = $this->checkout_service->validate_shipping_address();
		if ( ! $response['success'] || empty( $response['notices'] ) ) {
			return $data;
		}

		// Get the HTML formatter.
		$html_formatter = self::$extend_schema->get_formatter( 'html' );

		// Format the notices.
		foreach ( $response['notices'] as $notice ) {
			$notice_message = $notice->get_message();
			$notice_data    = $notice->get_data();

			$notice->set_message( $html_formatter->format( $notice_message ) );

			if ( ! empty( $notice_data ) ) {
				$notice->set_data( $html_formatter->format( $notice_data ) );
			}

			$data['notices'][] = $notice->to_array();
		}

		return $data;
	}

	/**
	 * The schema callback method.
	 *
	 * This is where you can define the schema for the endpoint.
	 *
	 * @return array
	 */
	public function schema_callback(): array {
		return array(
			'notices' => array(
				'description' => __( 'WC Shipping checkout address validation notices', 'woocommerce-shipping' ),
				'type'        => array( 'array' ),
				'context'     => array( 'view' ),
				'readonly'    => true,
			),
		);
	}

	/**
	 * The update callback method.
	 *
	 * This is where you can listen for updates to the endpoint and handle accordingly.
	 *
	 * @param array $data Data to update.
	 *
	 * @return void
	 */
	public function update_callback( array $data ): void {
		if ( ! isset( $data['action'] ) || 'apply_suggested_shipping_address' !== $data['action'] ) {
			return;
		}

		// Get the suggested address.
		$suggested_address = ! empty( $data['suggested_address'] )
			? json_decode( $data['suggested_address'], true )
			: false;

		// Return if no suggested address.
		if ( empty( $suggested_address ) || ! is_array( $suggested_address ) ) {
			throw new RouteException( 'error_applying_suggested_address', 'error' );
		}

		// Get the cart customer.
		$customer = CheckoutService::get_cart_customer_instance();
		if ( ! $customer instanceof WC_Customer ) {
			throw new RouteException( 'error_applying_suggested_address', 'error' );
		}

		$address = new Address( $suggested_address );

		// Validate the address.
		$validate_address = $address->validate();
		if ( is_wp_error( $validate_address ) ) {
			throw new RouteException( 'error_applying_suggested_address', 'error' );
		}

		// Sanitize the address.
		$address->sanitize();

		$address_1 = $address->address_1;
		$address_2 = $address->address_2;
		$city      = $address->city;
		$state     = $address->state_code;
		$postcode  = $address->postcode;
		$country   = $address->country_code;

		// Set the cart customer shipping address.
		$customer->set_shipping_address_1( $address_1 );
		$customer->set_shipping_address_2( $address_2 );
		$customer->set_shipping_city( $city );
		$customer->set_shipping_state( $state );
		$customer->set_shipping_postcode( $postcode );
		$customer->set_shipping_country( $country );

		// Maybe set the billing address.
		if ( ! empty( $data['use_shipping_as_billing'] ) ) {
			$customer->set_billing_address_1( $address_1 );
			$customer->set_billing_address_2( $address_2 );
			$customer->set_billing_city( $city );
			$customer->set_billing_state( $state );
			$customer->set_billing_postcode( $postcode );
			$customer->set_billing_country( $country );
		}

		// Save the cart customer.
		$customer->save();
	}

	/**
	 * Get the schema type to extend the endpoint with.
	 *
	 * Should return one of the keys from the $schema_types array.
	 *
	 * @return string
	 */
	public function get_schema_type(): string {
		return self::$schema_types['array_a'];
	}
}
