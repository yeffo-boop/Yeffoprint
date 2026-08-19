<?php
/**
 * FulfillmentNotificationType class.
 *
 * Class to define the types of fulfillment notifications.
 *
 * @package Automattic\WCShipping\Fulfillments
 * @since   1.9.0
 */

declare( strict_types=1 );

namespace Automattic\WCShipping\Fulfillments;

defined( 'ABSPATH' ) || exit;

/**
 * Enum class for fulfillment notification types.
 *
 * Defines the types of notifications that can be sent for fulfillment events.
 * This allows the invoker to explicitly control which notification type should be sent,
 * rather than having the system decide based on context.
 */
final class FulfillmentNotificationType {

	/**
	 * Notification type for when a fulfillment is created.
	 *
	 * @var string
	 */
	public const CREATED = 'created';

	/**
	 * Notification type for when a fulfillment is updated.
	 *
	 * @var string
	 */
	public const UPDATED = 'updated';

	/**
	 * Notification type for when a fulfillment is deleted.
	 *
	 * @var string
	 */
	public const DELETED = 'deleted';

	/**
	 * No notification should be sent.
	 *
	 * @var string
	 */
	public const NONE = 'none';

	/**
	 * Get all valid notification types.
	 *
	 * @return array<string> Array of valid notification type constants.
	 */
	public static function get_all(): array {
		return array(
			self::CREATED,
			self::UPDATED,
			self::DELETED,
			self::NONE,
		);
	}

	/**
	 * Check if a notification type is valid.
	 *
	 * @param string|null $type The notification type to validate.
	 * @return bool True if valid, false otherwise.
	 */
	public static function is_valid( ?string $type ): bool {
		if ( null === $type ) {
			return true; // null is treated as NONE
		}
		return in_array( $type, self::get_all(), true );
	}
}
