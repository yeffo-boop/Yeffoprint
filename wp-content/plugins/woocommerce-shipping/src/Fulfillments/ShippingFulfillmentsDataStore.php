<?php
/**
 * WooCommerce Shipping Fulfillments Data Store class.
 *
 * Extends the core FulfillmentsDataStore to return ShippingFulfillment objects
 * instead of the base Fulfillment objects.
 *
 * @package WCShipping\Fulfillments
 * @since   1.9.0
 */

declare( strict_types=1 );

namespace Automattic\WCShipping\Fulfillments;

defined( 'ABSPATH' ) || exit;

// Only extend the parent class if it exists (requires WooCommerce 10.1+)
// The Base\FulfillmentsDataStore alias is created by FulfillmentsClassResolver.php
if ( class_exists( Base\FulfillmentsDataStore::class ) ) {
	/**
	 * Shipping Fulfillments Data Store Class
	 *
	 * Extends the core FulfillmentsDataStore to return ShippingFulfillment objects
	 * which provide additional shipping-specific functionality.
	 *
	 * @since 1.9.0
	 */
	class ShippingFulfillmentsDataStore extends Base\FulfillmentsDataStore {

		/**
		 * Read fulfillments from the database.
		 *
		 * Overrides the parent method to return ShippingFulfillment objects
		 * instead of base Fulfillment objects.
		 *
		 * @param string $entity_type The entity type (e.g., 'WC_Order').
		 * @param string $entity_id The entity ID.
		 * @param bool   $with_deleted Whether to include deleted fulfillments.
		 *
		 * @return ShippingFulfillment[] Array of ShippingFulfillment objects.
		 *
		 * @throws \Exception If failed to read fulfillment data.
		 */
		public function read_fulfillments( string $entity_type, string $entity_id, bool $with_deleted = false ): array {
			// Read the fulfillment data from the database.
			global $wpdb;

			if ( ! $with_deleted ) {
				$fulfillment_data = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM {$wpdb->prefix}wc_order_fulfillments WHERE entity_type = %s AND entity_id = %s AND date_deleted IS NULL",
						$entity_type,
						$entity_id
					),
					ARRAY_A
				);
			} else {
				$fulfillment_data = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM {$wpdb->prefix}wc_order_fulfillments WHERE entity_type = %s AND entity_id = %s",
						$entity_type,
						$entity_id
					),
					ARRAY_A
				);
			}

			if ( is_wp_error( $fulfillment_data ) ) {
				throw new \Exception( esc_html__( 'Failed to read fulfillment data.', 'woocommerce-shipping' ) );
			}

			// Create ShippingFulfillment objects from the data.
			$fulfillments = array();
			foreach ( $fulfillment_data as $data ) {
				// Note: Don't initialize with ID, it will cause a re-read from the database.
				// Set the ID directly after the object is created.
				$fulfillment = new ShippingFulfillment();
				$fulfillment->set_id( $data['fulfillment_id'] );
				$fulfillment->set_props( $data );
				$fulfillment->apply_changes();
				$fulfillment->set_object_read( true );

				// Read the metadata for the fulfillment.
				$fulfillment->read_meta_data( true );

				$fulfillments[] = $fulfillment;
			}

			return $fulfillments;
		}

		/**
		 * Find a fulfillment by label ID.
		 *
		 * Searches all fulfillments to find one that contains a label with the specified ID.
		 * Returns the first matching fulfillment found.
		 *
		 * @param string $label_id The label ID to search for.
		 *
		 * @return ShippingFulfillment|null ShippingFulfillment object if found, null otherwise.
		 *
		 * @throws \Exception If failed to search fulfillment data.
		 */
		public function get_by_label_id( string $label_id ): ?ShippingFulfillment {
			global $wpdb;

			// First try the fast lookup using individual label_id meta entries
			$fulfillment_data = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT f.* FROM {$wpdb->prefix}wc_order_fulfillments f
				INNER JOIN {$wpdb->prefix}wc_order_fulfillment_meta fm 
				ON f.fulfillment_id = fm.fulfillment_id
				WHERE f.date_deleted IS NULL 
				AND fm.meta_key = %s 
				AND fm.meta_value = %s",
					'_shipping_label_id',
					$label_id
				),
				ARRAY_A
			);

			if ( is_wp_error( $fulfillment_data ) ) {
				throw new \Exception( esc_html__( 'Failed to search fulfillment data.', 'woocommerce-shipping' ) );
			}

			// If no results found, return null
			if ( empty( $fulfillment_data ) ) {
				return null;
			}

			// Create ShippingFulfillment object from the first result
			$data        = $fulfillment_data[0];
			$fulfillment = new ShippingFulfillment();
			$fulfillment->set_id( $data['fulfillment_id'] );
			$fulfillment->set_props( $data );
			$fulfillment->apply_changes();
			$fulfillment->set_object_read( true );

			// Read the metadata for the fulfillment
			$fulfillment->read_meta_data( true );

			return $fulfillment;
		}
	}
} else {
	/**
	 * Fallback Shipping Fulfillments Data Store Class
	 *
	 * Provides basic functionality when the parent FulfillmentsDataStore class
	 * is not available (WooCommerce < 10.1.0).
	 *
	 * @since 1.9.0
	 */
	class ShippingFulfillmentsDataStore {

		/**
		 * Read fulfillments from the database.
		 *
		 * Basic implementation for when parent class is not available.
		 *
		 * @param string $entity_type The entity type (e.g., 'WC_Order').
		 * @param string $entity_id The entity ID.
		 * @param bool   $with_deleted Whether to include deleted fulfillments.
		 *
		 * @return ShippingFulfillment[] Array of ShippingFulfillment objects.
		 *
		 * @throws \Exception If failed to read fulfillment data.
		 */
		public function read_fulfillments( string $entity_type, string $entity_id, bool $with_deleted = false ): array {

			return array();
		}

		/**
		 * Find a fulfillment by label ID.
		 *
		 * Searches all fulfillments to find one that contains a label with the specified ID.
		 * Returns the first matching fulfillment found.
		 *
		 * @param string $label_id The label ID to search for.
		 *
		 * @return ShippingFulfillment|null ShippingFulfillment object if found, null otherwise.
		 *
		 * @throws \Exception If failed to search fulfillment data.
		 */
		public function get_by_label_id( string $label_id ): ?ShippingFulfillment {
			return null;
		}
	}
}
