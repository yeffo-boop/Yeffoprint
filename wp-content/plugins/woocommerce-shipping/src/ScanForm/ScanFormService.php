<?php
/**
 * ScanForm Service class.
 *
 * @package Automattic\WCShipping\ScanForm
 */

namespace Automattic\WCShipping\ScanForm;

use Automattic\WooCommerce\Utilities\OrderUtil;
use DateTime;
use DateTimeZone;
use WP_Error;
use WP_REST_Request;

/**
 * ScanForm Service class.
 * Handle logic and database operations.
 */
class ScanFormService {

	/**
	 * Transient key for caching ScanForm history.
	 */
	const SCANFORM_HISTORY_TRANSIENT_KEY = 'wcshipping_scanform_history';

	/**
	 * Get paginated ScanForms with their orders.
	 *
	 * @param int $per_page Number of ScanForms per page.
	 * @param int $offset   Offset for pagination.
	 * @return array Array with 'scan_forms' and 'total' keys.
	 */
	public function get_paginated_scan_forms( int $per_page, int $offset ): array {
		$scan_forms = $this->get_all_scan_forms();

		return array(
			'scan_forms' => array_slice( $scan_forms, $offset, $per_page ),
			'total'      => count( $scan_forms ),
		);
	}

	/**
	 * Get all ScanForms from cache or database.
	 *
	 * Returns ScanForms with origin_address and label_count.
	 * Results are cached in a transient for performance.
	 *
	 * @return array List of ScanForm arrays.
	 */
	private function get_all_scan_forms(): array {
		$cached = get_transient( self::SCANFORM_HISTORY_TRANSIENT_KEY );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$scan_forms = $this->build_scan_forms_list();

		set_transient( self::SCANFORM_HISTORY_TRANSIENT_KEY, $scan_forms, WEEK_IN_SECONDS );

		return $scan_forms;
	}

	/**
	 * Clear the ScanForm history cache.
	 *
	 * @return void
	 */
	public function clear_history_cache(): void {
		delete_transient( self::SCANFORM_HISTORY_TRANSIENT_KEY );
	}

	/**
	 * Build the complete ScanForms list from database.
	 *
	 * @return array List of ScanForm arrays sorted by creation date.
	 */
	private function build_scan_forms_list(): array {
		$raw_data = $this->fetch_scan_forms_from_database();

		if ( empty( $raw_data['scan_forms'] ) ) {
			return array();
		}

		return $this->add_origin_addresses( $raw_data['scan_forms'], $raw_data['order_ids_map'] );
	}

	/**
	 * Fetch and deduplicate ScanForms from database.
	 *
	 * @return array Array with 'scan_forms' and 'order_ids_map' keys.
	 */
	private function fetch_scan_forms_from_database(): array {
		global $wpdb;

		$meta_table       = OrderUtil::get_table_for_order_meta();
		$meta_join_column = OrderUtil::custom_orders_table_usage_is_enabled() ? 'order_id' : 'post_id';

		$results = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT %i as order_id, meta_value
				FROM %i
				WHERE meta_key = %s
				ORDER BY %i DESC',
				$meta_join_column,
				$meta_table,
				'_wcshipping_scan_forms',
				$meta_join_column
			)
		);

		$scan_forms    = array();
		$order_ids_map = array();

		foreach ( $results as $row ) {
			$order_id        = (int) $row->order_id;
			$order_scanforms = maybe_unserialize( $row->meta_value );

			if ( empty( $order_scanforms ) || ! is_array( $order_scanforms ) ) {
				continue;
			}

			foreach ( $order_scanforms as $scan_form ) {
				if ( ! is_array( $scan_form ) || empty( $scan_form['scan_form_id'] ) ) {
					continue;
				}

				$scan_form_id = $scan_form['scan_form_id'];

				// Deduplicate by scan_form_id.
				if ( ! isset( $scan_forms[ $scan_form_id ] ) ) {
					$scan_forms[ $scan_form_id ]    = array(
						'scan_form_id' => $scan_form_id,
						'pdf_url'      => $scan_form['pdf_url'] ?? '',
						'created'      => $scan_form['created'] ?? '',
						'label_ids'    => array(),
					);
					$order_ids_map[ $scan_form_id ] = array();
				}

				// Merge label IDs from all orders containing this ScanForm.
				if ( ! empty( $scan_form['label_ids'] ) && is_array( $scan_form['label_ids'] ) ) {
					$scan_forms[ $scan_form_id ]['label_ids'] = array_unique(
						array_merge(
							$scan_forms[ $scan_form_id ]['label_ids'],
							$scan_form['label_ids']
						)
					);
				}

				// Track order IDs for this ScanForm.
				if ( ! in_array( $order_id, $order_ids_map[ $scan_form_id ], true ) ) {
					$order_ids_map[ $scan_form_id ][] = $order_id;
				}
			}
		}

		return array(
			'scan_forms'    => $scan_forms,
			'order_ids_map' => $order_ids_map,
		);
	}

	/**
	 * Add origin addresses and label counts to ScanForms.
	 *
	 * @param array $scan_forms    Deduplicated ScanForms keyed by ID.
	 * @param array $order_ids_map Map of scan_form_id to order IDs.
	 * @return array List of ScanForms sorted by creation date.
	 */
	private function add_origin_addresses( array $scan_forms, array $order_ids_map ): array {
		// Bulk fetch meta data for all orders.
		$all_order_ids    = array_unique( array_merge( ...array_values( $order_ids_map ) ) );
		$scan_forms_meta  = $this->get_order_meta_bulk( $all_order_ids, '_wcshipping_scan_forms' );
		$labels_meta      = $this->get_order_meta_bulk( $all_order_ids, 'wcshipping_labels' );
		$selected_origins = $this->get_order_meta_bulk( $all_order_ids, '_wcshipping_selected_origin' );

		// Add origin_address and label_count to each ScanForm.
		foreach ( $scan_forms as $scan_form_id => &$scan_form ) {
			$order_ids = $order_ids_map[ $scan_form_id ] ?? array();

			$scan_form['origin_address'] = $this->get_scan_form_origin_address_from_meta(
				$scan_form_id,
				$order_ids,
				$scan_forms_meta,
				$labels_meta,
				$selected_origins
			);

			$scan_form['label_count'] = count( $scan_form['label_ids'] );
		}
		unset( $scan_form );

		// Sort by created date (descending).
		$result = array_values( $scan_forms );
		usort(
			$result,
			function ( $a, $b ) {
				return strtotime( $b['created'] ?? '' ) <=> strtotime( $a['created'] ?? '' );
			}
		);

		return $result;
	}

	/**
	 * Get origin address for a ScanForm using pre-fetched meta data.
	 *
	 * @param string $scan_form_id     The ScanForm ID.
	 * @param array  $order_ids        The order IDs containing this ScanForm.
	 * @param array  $scan_forms_meta  Pre-fetched _wcshipping_scan_forms meta by order_id.
	 * @param array  $labels_meta      Pre-fetched wcshipping_labels meta by order_id.
	 * @param array  $selected_origins Pre-fetched _wcshipping_selected_origin meta by order_id.
	 * @return array|null Origin address data or null if not found.
	 */
	private function get_scan_form_origin_address_from_meta( string $scan_form_id, array $order_ids, array $scan_forms_meta, array $labels_meta, array $selected_origins ): ?array {
		foreach ( $order_ids as $order_id ) {
			// Get the ScanForm data for this order.
			$scan_forms = $scan_forms_meta[ $order_id ] ?? null;
			if ( empty( $scan_forms ) || ! is_array( $scan_forms ) ) {
				continue;
			}

			// Find the ScanForm with matching ID.
			$scan_form_data = null;
			foreach ( $scan_forms as $scan_form ) {
				if ( ( $scan_form['scan_form_id'] ?? '' ) === $scan_form_id ) {
					$scan_form_data = $scan_form;
					break;
				}
			}

			if ( empty( $scan_form_data['label_ids'] ) ) {
				continue;
			}

			// Get the first label ID from the ScanForm.
			$scan_form_label_id = $scan_form_data['label_ids'][0];
			$labels             = $labels_meta[ $order_id ] ?? null;

			if ( empty( $labels ) ) {
				continue;
			}

			// Find the shipment ID for this label.
			$shipment_id = null;
			foreach ( $labels as $label ) {
				if ( $label['label_id'] === $scan_form_label_id ) {
					$shipment_id = $label['id'];
					break;
				}
			}

			if ( null === $shipment_id ) {
				continue;
			}

			// Get the selected origins and match by shipment ID.
			$origins = $selected_origins[ $order_id ] ?? null;
			if ( ! is_array( $origins ) ) {
				continue;
			}

			$origin_key = 'shipment_' . $shipment_id;
			if ( isset( $origins[ $origin_key ] ) ) {
				return $origins[ $origin_key ];
			}
		}

		return null;
	}

	/**
	 * Validate and extract label IDs from a REST request.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return array|WP_Error Array of label IDs or WP_Error if validation fails.
	 */
	public function validate_label_ids( WP_REST_Request $request ) {
		$params    = $request->get_json_params();
		$label_ids = isset( $params['label_ids'] ) ? $params['label_ids'] : array();

		if ( empty( $label_ids ) ) {
			return new WP_Error(
				'no_labels',
				__( 'No labels provided', 'woocommerce-shipping' ),
				array( 'status' => 400 )
			);
		}

		return $label_ids;
	}

	/**
	 * Parse ScanForm error message to extract failed label IDs.
	 *
	 * Example errors:
	 * - "Already manifested (9): Label ID: 54321 (Shipment: shp_xxx), Label ID: 54322 (Shipment: shp_yyy)"
	 * - "No valid labels found. Labels must have EasyPost shipment IDs (shp_*) and be less than 90 days old."
	 *
	 * @param string $error_message The error message from the API.
	 * @param array  $submitted_labels All label IDs that were submitted.
	 *
	 * @return array Contains 'failed_labels' and 'valid_labels' arrays.
	 */
	public function parse_scan_form_error( string $error_message, array $submitted_labels ): array {
		$failed_labels = array();

		// Check if error is "No valid labels found" - all submitted labels are invalid.
		if ( stripos( $error_message, 'No valid labels found' ) !== false ) {
			return array(
				'failed_labels' => array_values( $submitted_labels ),
				'valid_labels'  => array(),
			);
		}

		// Extract label IDs from error message (pattern: "Label ID: 12345").
		if ( preg_match_all( '/Label ID:\s*(\d+)/', $error_message, $matches ) ) {
			// $matches[1] contains the captured label IDs.
			$failed_labels = array_map( 'intval', $matches[1] );

			// Only include labels that were actually submitted.
			$failed_labels = array_intersect( $failed_labels, $submitted_labels );
		}

		// Determine valid labels (submitted minus failed).
		$valid_labels = array_diff( $submitted_labels, $failed_labels );

		return array(
			'failed_labels' => array_values( $failed_labels ),
			'valid_labels'  => array_values( $valid_labels ),
		);
	}

	/**
	 * Check if a label is an envelope shipment.
	 *
	 * Envelope labels (is_letter = true) are created via PC Postage and are not
	 * compatible with USPS SCAN Forms. This is a temporary limitation while
	 * USPS Ship adds envelope support.
	 *
	 * @param array $label Label data.
	 *
	 * @return bool True if the label is an envelope shipment.
	 */
	public function is_envelope_label( array $label ): bool {
		return ! empty( $label['is_letter'] );
	}

	/**
	 * Check if a label is eligible for ScanForm creation.
	 *
	 * Based on EasyPost requirements:
	 * - Label must be for USPS carrier
	 * - Label must not be refunded
	 * - Label must have a tracking number
	 * - Label must be in created/purchased status
	 *
	 * Note: Shipping date check (10 days) is done separately in the main loops.
	 *
	 * @param array $label Label data.
	 *
	 * @return bool True if eligible, false otherwise.
	 */
	public function is_label_eligible( array $label ): bool {
		// Must have the 'PURCHASED' status.
		if ( ! isset( $label['status'] ) || 'purchased' !== strtolower( $label['status'] ) ) {
			return false;
		}

		// Must be USPS.
		if ( ! isset( $label['carrier_id'] ) || 'usps' !== strtolower( $label['carrier_id'] ) ) {
			return false;
		}

		// Must have tracking number.
		if ( empty( $label['tracking'] ) ) {
			return false;
		}

		// Must not be refunded.
		if ( isset( $label['refund'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Fetch meta data for multiple orders in bulk using wpdb.
	 *
	 * @param array  $order_ids Array of order IDs.
	 * @param string $meta_key  Meta key to fetch.
	 *
	 * @return array Associative array with order_id as key and unserialized meta_value as value.
	 */
	public function get_order_meta_bulk( array $order_ids, string $meta_key ): array {
		if ( empty( $order_ids ) ) {
			return array();
		}

		global $wpdb;

		$meta_table       = OrderUtil::get_table_for_order_meta();
		$is_hpos          = OrderUtil::custom_orders_table_usage_is_enabled();
		$meta_join_column = $is_hpos ? 'order_id' : 'post_id';

		// Prepare placeholders for IN clause.
		$placeholders = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );

		// Query to fetch meta data for all order IDs at once.
		$query = $wpdb->prepare(
			'SELECT %i as order_id, meta_value FROM %i WHERE meta_key = %s AND %i IN (' . $placeholders . ')', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$meta_join_column,
			$meta_table,
			$meta_key,
			$meta_join_column,
			...array_values( $order_ids )
		);

		$results = $wpdb->get_results( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Build associative array with order_id as key.
		$meta_data = array();
		foreach ( $results as $row ) {
			$meta_data[ $row->order_id ] = maybe_unserialize( $row->meta_value );
		}

		return $meta_data;
	}

	/**
	 * Get eligible shipments for orders with shipment dates today or later.
	 *
	 * Queries orders with _wcshipping_shipment_dates meta and returns a map of
	 * order IDs to their eligible shipments (shipment_key => shipment_data) where
	 * the shipment has a shipping_date >= today.
	 *
	 * Excludes labels from trashed orders.
	 *
	 * @return array Associative array: [ order_id => [ shipment_key => shipment_data ] ].
	 */
	public function get_orders_with_eligible_shipping_dates(): array {
		global $wpdb;

		// Get the appropriate table and column names based on HPOS/legacy.
		$is_hpos          = OrderUtil::custom_orders_table_usage_is_enabled();
		$meta_table       = OrderUtil::get_table_for_order_meta();
		$meta_join_column = $is_hpos ? 'order_id' : 'post_id';

		// Set up table and column names based on HPOS or legacy mode.
		$orders_table     = OrderUtil::get_table_for_orders();
		$orders_id_column = $is_hpos ? 'id' : 'ID';
		$status_column    = $is_hpos ? 'status' : 'post_status';

		// Query meta table for _wcshipping_shipment_dates joined with wcshipping_labels, excluding trashed orders.
		$query = $wpdb->prepare(
			'SELECT m1.%i as order_id, m1.meta_value as shipment_dates, m2.meta_value as wcshipping_labels
			FROM %i m1
			INNER JOIN %i m2 ON m1.%i = m2.%i AND m2.meta_key = %s
			INNER JOIN %i o ON m1.%i = o.%i
			WHERE m1.meta_key = %s AND o.%i != %s
			ORDER BY m1.%i DESC',
			$meta_join_column,
			$meta_table,
			$meta_table,
			$meta_join_column,
			$meta_join_column,
			'wcshipping_labels',
			$orders_table,
			$meta_join_column,
			$orders_id_column,
			'_wcshipping_shipment_dates',
			$status_column,
			'trash',
			$meta_join_column
		);

		$results = $wpdb->get_results( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( empty( $results ) ) {
			return array();
		}

		/**
		 * Timestamp for the earliest allowed shipment date.
		 * Using UTC timezone to align with EasyPost server.
		 *
		 * Filters can change this value to set a custom minimum date for
		 * validations or process rules that depend on the shipment date.
		 *
		 * @param int $timestamp Unix timestamp that defines the minimum shipment date.
		 */
		$today_utc         = new DateTime( 'today 00:00:00', new DateTimeZone( 'UTC' ) );
		$min_date          = apply_filters( 'wcshipping_usps_scanform_min_shipment_date_timestamp', $today_utc->getTimestamp() );
		$eligible_by_order = array(); // order_id => [ shipment_key => shipment_data ].

		// Collect shipments with shipping_date >= today, keyed by order and shipment key.
		foreach ( $results as $row ) {
			$shipment_dates  = maybe_unserialize( $row->shipment_dates );
			$shipping_labels = maybe_unserialize( $row->wcshipping_labels );

			if ( empty( $shipment_dates ) || ! is_array( $shipment_dates ) ) {
				continue;
			}

			if ( empty( $shipping_labels ) || ! is_array( $shipping_labels ) ) {
				continue;
			}

			foreach ( $shipment_dates as $shipment_key => $shipment_data ) {
				if ( ! is_array( $shipment_data ) || empty( $shipment_data['shipping_date'] ) ) {
					continue;
				}

				$shipping_date = strtotime( $shipment_data['shipping_date'] );

				if ( false !== $shipping_date && $shipping_date >= $min_date ) {
					$order_id = (int) $row->order_id;
					if ( ! isset( $eligible_by_order[ $order_id ] ) ) {
						$eligible_by_order[ $order_id ] = array();
					}
					$eligible_by_order[ $order_id ][ $shipment_key ] = $shipment_data;
				}
			}
		}

		return $eligible_by_order;
	}

	/**
	 * Save ScanForm information to orders that contain the labels.
	 *
	 * @param array $scan_form_data ScanForm data including ID, PDF URL, and creation date.
	 * @param array $order_labels   Array from server with order-to-label mapping.
	 *                               Format: [{order_id: 123, label_ids:[12,23,34]}, ...].
	 *
	 * @return void
	 */
	public function save_scan_form_to_orders( array $scan_form_data, array $order_labels ) {
		if ( empty( $scan_form_data ) || empty( $order_labels ) ) {
			return;
		}

		foreach ( $order_labels as $order_label_data ) {
			// Convert object to array if needed.
			$order_label_array = (array) $order_label_data;
			$order_id          = $order_label_array['order_id'] ?? null;
			$label_ids         = $order_label_array['label_ids'] ?? array();

			if ( empty( $order_id ) || empty( $label_ids ) ) {
				continue;
			}

			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			// Get existing ScanForms or initialize empty array.
			$scan_forms = $order->get_meta( '_wcshipping_scan_forms', true );
			if ( ! is_array( $scan_forms ) ) {
				$scan_forms = array();
			}

			// Add the new ScanForm with labels from this order.
			$scan_forms[] = array(
				'scan_form_id' => $scan_form_data['scan_form_id'],
				'pdf_url'      => $scan_form_data['pdf_url'],
				'created'      => $scan_form_data['created'],
				'label_ids'    => array_values( $label_ids ),
			);

			// Save to order meta.
			$order->update_meta_data( '_wcshipping_scan_forms', $scan_forms );
			$order->save();
		}

		// Clear the ScanForm history cache so the next request fetches fresh data.
		$this->clear_history_cache();
	}

	/**
	 * Generate a unique key for an origin address based on its address components.
	 *
	 * @param array $address Origin address data.
	 * @return string MD5 hash of the address components.
	 */
	public function get_origin_address_key( array $address ): string {
		// Use address components to generate a unique hash.
		$key_parts = array(
			strtolower( trim( $address['address'] ?? '' ) ),
			strtolower( trim( $address['address_2'] ?? '' ) ),
			strtolower( trim( $address['city'] ?? '' ) ),
			strtoupper( trim( $address['state'] ?? '' ) ),
			strtolower( trim( $address['postcode'] ?? '' ) ),
			strtoupper( trim( $address['country'] ?? '' ) ),
		);

		return md5( implode( '|', $key_parts ) );
	}
}
