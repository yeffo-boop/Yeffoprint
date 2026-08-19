<?php
/**
 * Abstract REST Schema.
 *
 * Holds schema for REST API routes.
 *
 * @package Automattic\WCShipping
 */

declare( strict_types=1 );

namespace Automattic\WCShipping\RestApi\Routes\V2;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;

/**
 * Abstract REST Schema for WCShipping REST API V2.
 *
 * Provides common functionality for all V2 schema controllers including
 * property generation, context filtering, and validation.
 *
 * @see Automattic\WooCommerce\Internal\RestApi\Routes\V4\AbstractSchema
 */
abstract class AbstractSchema {
	/**
	 * The schema item identifier.
	 *
	 * @var string
	 */
	const IDENTIFIER = '';

	/**
	 * Context for the item schema - view, edit, and embed.
	 *
	 * @var array
	 */
	const VIEW_EDIT_EMBED_CONTEXT = array( 'view', 'edit', 'embed' );

	/**
	 * Context for the item schema - view and edit only.
	 *
	 * @var array
	 */
	const VIEW_EDIT_CONTEXT = array( 'view', 'edit' );

	/**
	 * Get the item schema.
	 *
	 * @return array The item schema.
	 * @since 10.2.0
	 */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => static::IDENTIFIER,
			'type'       => 'object',
			'properties' => $this->get_item_schema_properties(),
		);
	}

	/**
	 * Get the item response.
	 *
	 * @param mixed           $item WordPress representation of the item.
	 * @param WP_REST_Request $request Request object.
	 * @param array           $include_fields Fields to include in the response.
	 * @return array The item response.
	 */
	abstract public function get_item_response( $item, WP_REST_Request $request, array $include_fields = array() ): array;

	/**
	 * Return all properties for the item schema.
	 *
	 * @return array The schema properties.
	 * @since 10.2.0
	 */
	public function get_item_schema_properties(): array {
		return array();
	}

	/**
	 * Return all writable properties for the item schema.
	 *
	 * @return array The schema properties.
	 * @since 10.2.0
	 */
	public function get_writable_item_schema_properties(): array {
		return array_filter( $this->get_item_schema_properties(), array( $this, 'filter_writable_props' ) );
	}

	/**
	 * Filter schema properties to only return writable ones.
	 *
	 * @param array $schema The schema property to check.
	 * @return bool True if the property is writable, false otherwise.
	 * @since 10.2.0
	 */
	protected function filter_writable_props( array $schema ): bool {
		return empty( $schema['readonly'] );
	}
}
