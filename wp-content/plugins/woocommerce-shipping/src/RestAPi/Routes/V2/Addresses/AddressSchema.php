<?php
/**
 * Address schema
 *
 * @package Automattic\WCShipping
 */

declare( strict_types=1 );

namespace Automattic\WCShipping\RestApi\Routes\V2\Addresses;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use Automattic\WCShipping\RestApi\Routes\V2\AbstractSchema;

/**
 * Address Schema.
 */
class AddressSchema extends AbstractSchema {

	/**
	 * The schema identifier.
	 *
	 * @var string
	 */
	const IDENTIFIER = 'shipping_method';

	/**
	 * Return all properties for the item schema
	 *
	 * @return array
	 */
	public function get_item_schema_properties(): array {
		return array(
			'id'              => array(
				'description' => __( 'Unique identifier for the address.', 'woocommerce-shipping' ),
				'type'        => 'string',
				'context'     => self::VIEW_EDIT_EMBED_CONTEXT,
				'readonly'    => true,
			),
			'first_name'      => array(
				'description'       => __( 'First name.', 'woocommerce-shipping' ),
				'type'              => 'string',
				'context'           => self::VIEW_EDIT_CONTEXT,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'last_name'       => array(
				'description'       => __( 'Last name.', 'woocommerce-shipping' ),
				'type'              => 'string',
				'context'           => self::VIEW_EDIT_CONTEXT,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'company'         => array(
				'description'       => __( 'Company name.', 'woocommerce-shipping' ),
				'type'              => 'string',
				'context'           => self::VIEW_EDIT_CONTEXT,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'address_1'       => array(
				'description'       => __( 'Address line.', 'woocommerce-shipping' ),
				'type'              => 'string',
				'context'           => self::VIEW_EDIT_CONTEXT,
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'city'            => array(
				'description'       => __( 'City.', 'woocommerce-shipping' ),
				'type'              => 'string',
				'context'           => self::VIEW_EDIT_CONTEXT,
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'state'           => array(
				'description'       => __( 'State.', 'woocommerce-shipping' ),
				'type'              => 'string',
				'context'           => self::VIEW_EDIT_CONTEXT,
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'postcode'        => array(
				'description'       => __( 'Postal code.', 'woocommerce-shipping' ),
				'type'              => 'string',
				'context'           => self::VIEW_EDIT_CONTEXT,
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'country'         => array(
				'description' => __( 'Country code in ISO 3166-1 alpha-2 format.', 'woocommerce-shipping' ),
				'type'        => 'string',
				'context'     => self::VIEW_EDIT_CONTEXT,
				'required'    => true,
			),
			'email'           => array(
				'description'       => __( 'Email address.', 'woocommerce-shipping' ),
				'type'              => 'string',
				'format'            => 'email',
				'context'           => self::VIEW_EDIT_CONTEXT,
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'phone'           => array(
				'description'       => __( 'Phone number.', 'woocommerce-shipping' ),
				'type'              => 'string',
				'context'           => self::VIEW_EDIT_CONTEXT,
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'default_address' => array(
				'description'       => __( 'Indicates if this is the default address.', 'woocommerce-shipping' ),
				'type'              => 'boolean',
				'context'           => self::VIEW_EDIT_EMBED_CONTEXT,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'is_verified'     => array(
				'description'       => __( 'Indicates if the verified address (from address validation) is being used.', 'woocommerce-shipping' ),
				'type'              => 'boolean',
				'context'           => self::VIEW_EDIT_EMBED_CONTEXT,
				'readonly'          => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'is_approved'     => array(
				'description'       => __( 'Indicates if the address has been approved by the merchant.', 'woocommerce-shipping' ),
				'type'              => 'boolean',
				'context'           => self::VIEW_EDIT_EMBED_CONTEXT,
				'readonly'          => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
		);
	}

	/**
	 * Get the item response for an address.
	 *
	 * @param mixed           $address Address.
	 * @param WP_REST_Request $request Request object.
	 * @param array           $include_fields Fields to include in the response.
	 * @return array The item response.
	 */
	public function get_item_response( $address, WP_REST_Request $request, array $include_fields = array() ): array {
		return array(
			'id'                     => $address['id'] ?? '',
			'first_name'             => $address['first_name'] ?? '',
			'last_name'              => $address['last_name'] ?? '',
			'company'                => $address['company'] ?? '',
			'address_1'              => $address['address_1'] ?? '',
			'city'                   => $address['city'] ?? '',
			'state'                  => $address['state'] ?? '',
			'postcode'               => $address['postcode'] ?? '',
			'country'                => $address['country'] ?? '',
			'email'                  => $address['email'] ?? '',
			'phone'                  => $address['phone'] ?? '',
			'default_address'        => (bool) ( $address['default_address'] ?? false ),
			'default_return_address' => (bool) ( $address['default_return_address'] ?? false ),
			'is_verified'            => (bool) ( $address['is_verified'] ?? false ),
			'is_approved'            => (bool) ( $address['is_approved'] ?? false ),
		);
	}
}
