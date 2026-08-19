<?php
/**
 * Class Address
 *
 * @package Automattic\WCShipping
 */

namespace Automattic\WCShipping\Shipment;

use WC_Validation;
use WP_Error;

/**
 * Object that represent an address in WooCommerce Shipping.
 */
class Address {
	/**
	 * Address line
	 *
	 * @var string $address
	 */
	public $address;

	/**
	 * Address line 1
	 *
	 * @var string $address_1
	 */
	public $address_1;
	/**
	 * Address line 2
	 *
	 * @var string $address_2
	 */
	public $address_2;
	/**
	 * City name
	 *
	 * @var string $city
	 */
	public $city;
	/**
	 * 2 letters country code
	 *
	 * @var string $country_code
	 */
	public $country_code;
	/**
	 * State code
	 *
	 * @var string $state_code
	 */
	public $state_code;
	/**
	 * Postal code
	 *
	 * @var string $postcode
	 */
	public $postcode;

	/**
	 * Class constructor. Initialize the address object and also sanitize all fields upon creation.
	 *
	 * @param array $params Array of address fields. params: array(
	 * 'address' => '',
	 * 'address_1' => '',
	 * 'address_2' => '',
	 * 'city' => '',
	 * 'state' => '',
	 * 'country' => '',
	 * 'postcode' => ''
	 * )
	 */
	public function __construct( $params ) {
		$this->address      = $params['address'] ?? '';
		$this->address_1    = $params['address_1'] ?? '';
		$this->address_2    = $params['address_2'] ?? '';
		$this->city         = $params['city'] ?? '';
		$this->state_code   = $params['state'] ?? '';
		$this->country_code = $params['country'] ?? '';
		$this->postcode     = $params['postcode'] ?? '';
		$this->sanitize();
	}

	/**
	 * Validate the properties of this Address object. This function
	 * validate each property separately. Return false if any fails.
	 *
	 * @return true|WP_Error Return true if all address fields are valid or a WP_Error if a field is invalid.
	 */
	public function validate() {
		$validation_checks = array(
			array( $this, 'validate_city' ),
			array( $this, 'validate_country' ),
			array( $this, 'validate_state' ),
			array( $this, 'validate_postcode' ),
		);

		foreach ( $validation_checks as $check ) {
			$result = $check();
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Sanitize the properties of this Address object. This function
	 * sanitize each property separately.
	 */
	public function sanitize() {
		$this->address_1    = $this->sanitize_address( $this->address_1 );
		$this->address_2    = $this->sanitize_address( $this->address_2 );
		$this->address      = ! empty( $this->address ) ? $this->sanitize_address( $this->address ) : trim( $this->address_1 ) . ' ' . trim( $this->address_2 );
		$this->city         = $this->sanitize_address( $this->city );
		$this->state_code   = $this->sanitize_state_code( $this->state_code );
		$this->country_code = $this->sanitize_country_code( $this->country_code );
		$this->postcode     = $this->sanitize_postcode( $this->postcode );
	}

	/**
	 * Validate state
	 *
	 * If state is not required, then return true. If state is required, then
	 * validate if this is a valid state in the country.
	 *
	 * @return true|WP_Error
	 */
	public function validate_state() {
		// Don't validate if state is not required.
		if ( ! $this->is_state_required_for_country_code() ) {
			return true;
		}

		if ( empty( $this->state_code ) ) {
			return new WP_Error(
				'invalid_state',
				__( 'Missing state code.', 'woocommerce-shipping' )
			);
		}

		return true;
	}

	public function validate_city() {

		if ( empty( $this->city ) || strlen( $this->city ) <= 2 ) {
			return new WP_Error(
				'invalid_city',
				__( 'Invalid city provided. City name must be more than 2 characters long.', 'woocommerce-shipping' )
			);
		}

		return true;
	}

	/**
	 * Validate country code. This checks if the country code is 2 letters and also check if it
	 * is a valid country from the list: wc()->countries->get_countries().
	 *
	 * @return true|WP_Error
	 */
	public function validate_country() {

		if ( empty( $this->country_code ) || strlen( $this->country_code ) !== 2 ) {
			return new WP_Error(
				'invalid_country',
				__( 'Invalid country code provided. Country code must be 2 characters.', 'woocommerce-shipping' )
			);
		}

		// Note: fix this side effect. Move sanitization to constructor.
		$this->country_code = wc_strtoupper( $this->country_code );

		if ( ! in_array( $this->country_code, array_keys( wc()->countries->get_countries() ), true ) ) {
			return new WP_Error(
				'invalid_country',
				sprintf(
					/* translators: %s valid country codes */
					__( 'Invalid country code provided. Must be one of: %s', 'woocommerce-shipping' ),
					implode( ', ', array_keys( wc()->countries->get_countries() ) )
				)
			);
		}

		return true;
	}

	/**
	 * Validate the postcode.
	 *
	 * @return true|WP_Error
	 */
	public function validate_postcode() {
		$this->postcode = $this->sanitize_postcode( $this->postcode );

		if ( ! empty( $this->postcode ) && ! WC_Validation::is_postcode( $this->postcode, $this->country_code ) ) {
			return new WP_Error(
				'invalid_postcode',
				__( 'The provided postal code / ZIP is not valid', 'woocommerce-shipping' )
			);
		}

		return true;
	}

	/**
	 * Sanitize address.
	 *
	 * @param string $address Can be address line 1 or line 2.
	 * @return string The sanitized address.
	 */
	public function sanitize_address( $address ) {
		return wc_clean( $address );
	}

	/**
	 * Sanitize state code.
	 *
	 * @param string $state_code State code.
	 * @return string The sanitized state code.
	 */
	public function sanitize_state_code( $state_code ) {
		$state_code = wc_clean( wp_unslash( $state_code ) );

		// Remove any character that is not a letter, space or hyphen.
		$state_code = preg_replace( '/[^a-zA-Z\s-]/', '', $state_code );

		return wc_strtoupper( $state_code );
	}

	/**
	 * Sanitize country code.
	 *
	 * @param string $country_code Country code.
	 * @return string The sanitized country code.
	 */
	public function sanitize_country_code( $country_code ) {
		$country_code = $this->sanitize_two_letters_code( $country_code );
		return wc_strtoupper( $country_code );
	}

	/**
	 * Helper function to remove all non alphabet characters.
	 *
	 * @param string $input Any string input.
	 * @return string A string that only contain alphabets.
	 */
	private function sanitize_two_letters_code( $input ) {
		$input = wc_clean( wp_unslash( $input ) );
		$input = preg_replace( '/[^a-zA-Z]+/', '', $input );
		return $input;
	}

	/**
	 * Sanitize and format the postcode.
	 *
	 * @param string $postcode Value being sanitized.
	 * @return string
	 */
	public function sanitize_postcode( $postcode ) {
		if ( empty( $postcode ) ) {
			return '';
		}
		return wc_format_postcode( wc_clean( wp_unslash( $postcode ) ), $this->country_code );
	}

	/**
	 * Check if state is required for this country. Woo defaults (https://github.com/woocommerce/woocommerce/blob/882527fe054a5c074f79a6f06cea253e0a4c4c50/plugins/woocommerce/includes/class-wc-countries.php#L1644.)
	 * states to have required => true. If it's not explicitly overwritten by wc()->countries->get_country_locale() to false,
	 * then state is always required.
	 *
	 * @return boolean true if state is required.
	 */
	public function is_state_required_for_country_code() {
		$locale = $this->country_code ? wc()->countries->get_country_locale() : array();

		/**
		 * If not explictly overwritten to false, then default is true -- state is required.
		 */
		if ( empty( $locale ) || empty( $locale[ $this->country_code ] ) || empty( $locale[ $this->country_code ]['state'] ) ) {
			return true;
		}

		/**
		 * If "required" is not set in the get_country_locale() function, then it defaults to true.
		 */
		if ( ! array_key_exists( 'required', $locale[ $this->country_code ]['state'] ) ) {
			return true;
		}

		/**
		 * If ['state']['required'] is explicitly defined as "false". If so, then state is not required.
		 */
		if ( false === $locale[ $this->country_code ]['state']['required'] ) {
			return false;
		}

		// If ['state']['required'] is defined and not set to false, then it has to be required.
		return true;
	}
}
