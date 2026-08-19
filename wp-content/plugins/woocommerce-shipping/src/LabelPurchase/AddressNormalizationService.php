<?php
/**
 * Class AddressNormalizationService
 *
 * @package Automattic\WCShipping
 */

namespace Automattic\WCShipping\LabelPurchase;

use Automattic\WCShipping\Connect\WC_Connect_Service_Settings_Store;
use Automattic\WCShipping\Connect\WC_Connect_API_Client;
use Automattic\WCShipping\Connect\WC_Connect_Logger;
use Automattic\WCShipping\OriginAddresses\OriginAddressService;
use WP_Error;

/**
 * Class to handle address normalization requests.
 */
class AddressNormalizationService {

	/**
	 * Destination address normalization hash key.
	 *
	 * @var string
	 */
	const DESTINATION_NORMALIZED_HASH_KEY = '_wcshipping_destination_normalized_hash';

	/**
	 * Connect Server settings store.
	 *
	 * @var WC_Connect_Service_Settings_Store
	 */
	private $settings_store;

	/**
	 * Connect Server API client.
	 *
	 * @var WC_Connect_API_Client
	 */
	private $api_client;

	/**
	 * Logging utility.
	 *
	 * @var WC_Connect_Logger
	 */
	private $logger;

	/**
	 * Origin address service.
	 *
	 * @var OriginAddressService
	 */
	private $origin_address_service;

	/**
	 * Class constructor.
	 *
	 * @param WC_Connect_Service_Settings_Store $settings_store Server settings store instance.
	 * @param WC_Connect_API_Client             $api_client     Server API client instance.
	 * @param WC_Connect_Logger                 $logger         Logging utility.
	 * @param OriginAddressService              $origin_address_service Origin address service.
	 */
	public function __construct( WC_Connect_Service_Settings_Store $settings_store, WC_Connect_API_Client $api_client, WC_Connect_Logger $logger, OriginAddressService $origin_address_service ) {
		$this->settings_store         = $settings_store;
		$this->api_client             = $api_client;
		$this->logger                 = $logger;
		$this->origin_address_service = $origin_address_service;
	}

	/**
	 * Confirm and update origin address in store.
	 *
	 * @param array $address Origin shipping address.
	 *
	 * @return array|WP_Error REST response body.
	 */
	public function update_origin_address( $address ) {
		$formatted_address = $this->format_address_for_client( $address );
		$address           = $this->origin_address_service->update_origin_addresses( $formatted_address );

		if ( empty( $address ) ) {
			return new WP_Error(
				'origin_address_update_failed',
				'Address could not be updated',
				array(
					'message' => 'Address could not be updated',
					'address' => $address,
				)
			);
		}

		return array(
			'success'    => true,
			'address'    => $this->format_address_for_client( $address ),
			'isVerified' => true, // Only verified addresses are allowed to be set as origin.
		);
	}

	/**
	 * Confirm and update shipping destination in store.
	 *
	 * @param int|string $order_id    WC order ID.
	 * @param array      $address     Destination shipping address.
	 * @param bool       $is_verified Is destination address normalized/verified.
	 *
	 * @return array REST response body.
	 */
	public function update_destination_address( $order_id, $address, $is_verified ) {
		$formatted_address = $this->format_address_for_client( $address );
		$result            = $this->settings_store->update_destination_address( $order_id, $formatted_address );
		if ( $result ) {
			$this->set_is_destination_address_normalized( $order_id, (bool) $is_verified );
		}

		return array(
			'success'    => $result,
			'address'    => $formatted_address,
			'isVerified' => (bool) $is_verified && $result,
		);
	}

	/**
	 * Checks whether destination address has already been verified/normalized.
	 *
	 * @param int|string $order_id WC order ID.
	 *
	 * @return array|WP_Error REST response body.
	 */
	public function is_destination_address_verified( $order_id ) {
		$order       = wc_get_order( $order_id );
		$address     = $order->get_address( 'shipping' );
		$is_verified = $this->is_destination_address_normalized( $order_id );
		if ( true === $is_verified ) {
			return array(
				'success'           => true,
				'normalizedAddress' => $address,
				'isVerified'        => true,
			);
		}

		$response = $this->normalize_address( $address );
		if ( is_wp_error( $response ) ) {
			$error = new WP_Error(
				$response->get_error_code(),
				$response->get_error_message(),
				array( 'message' => $response->get_error_message() )
			);
			$this->logger->log( $error, __CLASS__ );

			return $error;
		}

		if ( isset( $response->field_errors ) ) {
			$error_message = isset( $response->field_errors->general )
				? $response->field_errors->general
				: __( 'Address normalization failed', 'woocommerce-shipping' );

			$error = new WP_Error(
				'address_normalization_failed',
				$error_message,
				$response->field_errors
			);
			$this->logger->log( $error, __CLASS__ );

			return array(
				'success'    => false,
				'errors'     => $response->field_errors,
				'isVerified' => false,
			);
		}

		return array(
			'success'                => true,
			'normalizedAddress'      => $response->normalized,
			'isTrivialNormalization' => isset( $response->is_trivial_normalization ) ? $response->is_trivial_normalization : false,
			'isVerified'             => false,
		);
	}

	/**
	 * Requests server to normalize address.
	 *
	 * @param array  $address Address to normalize.
	 * @param string $type    Address role on the wire — `'origin'` or
	 *                        `'destination'` (default). Forwarded to the
	 *                        connect server so origin saves don't get
	 *                        labeled as destinations. See WOOSHIP-2230.
	 *
	 * @return array|WP_Error REST response body.
	 */
	public function get_normalization_response( $address, $type = 'destination' ) {
		$response = $this->normalize_address( $address, $type );
		if ( is_wp_error( $response ) ) {
			$error = new WP_Error(
				$response->get_error_code(),
				$response->get_error_message(),
				array( 'message' => $response->get_error_message() )
			);
			$this->logger->log( $error, __CLASS__ );

			return $error;
		}

		if ( isset( $response->field_errors ) ) {
			$error_message = __( 'Address normalization failed', 'woocommerce-shipping' );

			// If there is a general error message, use that.
			if ( isset( $response->field_errors->general ) ) {
				$error_message = $response->field_errors->general;
				// If there is an address error message, use that.
			} elseif ( isset( $response->field_errors->address ) ) {
				$error_message = $response->field_errors->address;
			}

			$this->logger->log( $error_message, __CLASS__ );

			return array(
				'success'                => false,
				'errors'                 => $response->field_errors,
				'isTrivialNormalization' => false,
				'address'                => $address,
				'warnings'               => $response->warnings,
			);
		}

		return array(
			'success'                => true,
			'normalizedAddress'      => $response->normalized,
			'isTrivialNormalization' => isset( $response->is_trivial_normalization ) ? $response->is_trivial_normalization : false,
			'address'                => $address,
			'warnings'               => $response->warnings,
		);
	}

	/**
	 * Uses the stored hash to verify if the address matches the current normalized address.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return bool True if address is normalized, false otherwise.
	 */
	public function is_destination_address_normalized( int $order_id ): bool {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		$stored_hash = $order->get_meta( self::DESTINATION_NORMALIZED_HASH_KEY, true );
		if ( ! $stored_hash ) {
			return false;
		}

		return $stored_hash === $this->get_destination_address_hash( $order_id );
	}

	/**
	 * Sets the order meta with the current address hash if verified, or deletes it if not.
	 *
	 * @param int  $order_id    Order ID.
	 * @param bool $is_verified True if address is verified, false otherwise.
	 */
	public function set_is_destination_address_normalized( int $order_id, bool $is_verified ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( $is_verified ) {
			$current_hash = $this->get_destination_address_hash( $order_id );
			$order->update_meta_data( self::DESTINATION_NORMALIZED_HASH_KEY, $current_hash );
		} else {
			$order->delete_meta_data( self::DESTINATION_NORMALIZED_HASH_KEY );
		}

		$order->save();
	}

	/**
	 * Generates a hash for the destination address based on its fields.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return string|null Hash of the destination address, or null if order or address are not found.
	 */
	private function get_destination_address_hash( int $order_id ): ?string {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return null;
		}

		$address = $order->get_address( 'shipping' );
		if ( ! $address ) {
			return null;
		}

		$hash_fields = array( 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' );
		$hash_data   = array();

		foreach ( $hash_fields as $field ) {
			$hash_data[ $field ] = preg_replace( '/\s+/', '', strtolower( $address[ $field ] ?? '' ) );
		}

		return md5( http_build_query( $hash_data ) );
	}
	/**
	 * Returns normalization response for address from server.
	 *
	 * @param array  $address Address to normalize.
	 * @param string $type    `'origin'` or `'destination'` — chooses which
	 *                        key wraps the request body. The connect
	 *                        server's `normalizeRequest` validator accepts
	 *                        either (per WOOSHIP-2230 / the matching
	 *                        APIWOO server PR); historically it only
	 *                        accepted `destination`, which is why that
	 *                        stays the default.
	 *
	 * @return object|WP_Error REST reponse object.
	 */
	private function normalize_address( $address, $type = 'destination' ) {
		$request_body = $this->format_address_for_connect_server( $address );
		$wrapper_key  = 'origin' === $type ? 'origin' : 'destination';

		$response = $this->api_client->send_address_normalization_request( array( $wrapper_key => $request_body ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response->normalized ) ) {
			$response->normalized = $this->format_address_for_client( $response->normalized, $address );
		}

		if ( ! isset( $response->warnings ) ) {
			$response->warnings = array();
		}

		return $response;
	}

	/**
	 * Formats address request body to support required server validation.
	 *
	 * @param array $address {
	 *     WooCommerce-style address fields.
	 *
	 *     @type string $address_1       (Required) Street address, will be converted to 'address'
	 *     @type string $city            (Required) City name
	 *     @type string $state           (Required) State code
	 *     @type string $postcode        (Required) Postal code
	 *     @type string $country         (Required) Country code
	 *     @type string $address_2       (Optional) Secondary address line
	 *     @type string $first_name      (Optional, Required if company is present) First name, combined with last_name into 'name' if both present
	 *     @type string $last_name       (Optional, Required if company is present) Last name, combined with first_name into 'name' if both present
	 *     @type string $company         (Optional, Required if name parts are not present) Company name
	 *     @type string $phone           (Optional) Will be removed in output
	 *     @type string $email           (Optional) Will be removed in output
	 *     @type string $id              (Optional) Will be removed in output
	 *     @type bool   $default_address (Optional) Will be removed in output
	 *     @type bool   $is_verified     (Optional) Will be removed in output
	 * }
	 *
	 * @return array {
	 *     Formatted request body for Connect Server.
	 *
	 *     @type string $address        (Required) Street address, copied from address_1 if present
	 *     @type string $city           (Required) City name
	 *     @type string $state          (Required) State code
	 *     @type string $postcode       (Required) Postal code
	 *     @type string $country        (Required) Country code
	 *     @type string $address_2      (Optional) Secondary address line, defaults to empty string if not set
	 *     @type string $name           (Optional) Full name, combined from first_name and last_name if both present, defaults to empty string
	 *     @type string $company        (Optional) Company name
	 * }
	 */
	private function format_address_for_connect_server( $address ) {
		$request_body = $address;

		// Map `address_1` -> `address` if the latter isn't already populated.
		// `isset()` on `address_1` is fine here: a `null` `address_1` carries
		// no value to copy.
		if ( isset( $request_body['address_1'] ) && empty( $request_body['address'] ) ) {
			$request_body['address'] = wc_clean( $request_body['address_1'] );
		}

		if ( ! isset( $request_body['address_2'] ) ) {
			$request_body['address_2'] = '';
		}

		// Synthesize `name` from `first_name` + `last_name` when neither is
		// null/empty (combining null produces a leading or trailing space).
		if (
			! empty( $request_body['first_name'] ) &&
			! empty( $request_body['last_name'] ) &&
			empty( $request_body['name'] )
		) {
			$request_body['name'] = wc_clean(
				$request_body['first_name'] . ' ' . $request_body['last_name']
			);
		}

		if ( ! isset( $request_body['name'] ) ) {
			$request_body['name'] = '';
		}

		// Strip every client-side field the server doesn't accept. We use a
		// single `unset()` rather than per-key `if ( isset() ) { unset(); }`
		// guards because PHP's `isset()` returns `false` for keys explicitly
		// set to `null`, which previously let null-valued fields slip past
		// the strip and get rejected by the Connect Server's Joi validator
		// (e.g. `"destination.last_name" is not allowed`). `unset()` is a
		// no-op on missing keys, so the unconditional call is safe.
		unset(
			$request_body['address_1'],
			$request_body['first_name'],
			$request_body['last_name'],
			$request_body['phone'],
			$request_body['email'],
			$request_body['id'],
			$request_body['default_address'],
			$request_body['is_verified'],
			$request_body['is_approved'],
			$request_body['default_return_address']
		);

		return $request_body;
	}

	/**
	 * Formats address response from server format to WooCommerce format.
	 *
	 * @param array $address {
	 *     Server-formatted address fields.
	 *
	 *     @type string $address        Street address line 1
	 *     @type string $address_2      Secondary address line
	 *     @type string $city           City name
	 *     @type string $state          State code
	 *     @type string $postcode       Postal code
	 *     @type string $country        Country code
	 *     @type string $name           Full name (will be split into first_name and last_name)
	 *     @type string $company        Company name
	 * }
	 * @param array $original_address {
	 *     Optional. Original address with additional fields to preserve.
	 *
	 *     @type string $phone           Phone number
	 *     @type string $email           Email address
	 *     @type string $id              Address ID
	 *     @type bool   $default_address Whether this is the default address
	 * }
	 * @return array {
	 *     WooCommerce-formatted address fields.
	 *
	 *     @type string $address_1       Street address line 1 (converted from address)
	 *     @type string $address_2       Secondary address line
	 *     @type string $city            City name
	 *     @type string $state           State code
	 *     @type string $postcode        Postal code
	 *     @type string $country         Country code
	 *     @type string $first_name      First name (split from name)
	 *     @type string $last_name       Last name (split from name)
	 *     @type string $company         Company name
	 *     @type string $phone           Phone number (if in original_address)
	 *     @type string $email           Email address (if in original_address)
	 *     @type string $id              Address ID (if in original_address)
	 *     @type bool   $default_address Whether this is the default address (if in original_address)
	 * }
	 */
	private function format_address_for_client( $address, $original_address = array() ) {
		$response_body = (array) $address;
		if ( isset( $response_body['address'] ) ) {
			$response_body['address_1'] = wc_clean( $response_body['address'] );
			unset( $response_body['address'] );
		}

		if ( isset( $response_body['name'] ) ) {
			list( $first_name, $last_name ) = explode( ' ', $response_body['name'], 2 );
			$response_body['first_name']    = wc_clean( $first_name );
			$response_body['last_name']     = wc_clean( $last_name );
			unset( $response_body['name'] );
		}

		if ( isset( $original_address['phone'] ) ) {
			$response_body['phone'] = wc_clean( $original_address['phone'] );
		}

		if ( isset( $original_address['email'] ) ) {
			$response_body['email'] = sanitize_email( $original_address['email'] );
		}

		if ( isset( $original_address['id'] ) ) {
			$response_body['id'] = wc_clean( $original_address['id'] );
		}

		if ( isset( $original_address['default_address'] ) ) {
			$response_body['default_address'] = (bool) wc_clean( $original_address['default_address'] );
		}

		return $response_body;
	}
}
