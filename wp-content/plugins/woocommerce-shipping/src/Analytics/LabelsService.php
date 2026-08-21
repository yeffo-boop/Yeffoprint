<?php

namespace Automattic\WCShipping\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Utilities\OrderUtil;

class LabelsService {

	const LABELS_TRANSIENT_KEY = 'wcshipping_labels_report';

	const LABELS_TRANSIENT_EXPIRATION_IN_SECONDS = MINUTE_IN_SECONDS * 30;

	/**
	 * Fetch labels from the database
	 *
	 * @return array
	 */
	public function fetch_labels_from_database(): array {
		global $wpdb;
		$table_name  = OrderUtil::get_table_for_order_meta();
		$column_name = OrderUtil::custom_orders_table_usage_is_enabled() ? 'order_id' : 'post_id';
		$db_results  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT %i, meta_value FROM %i WHERE meta_key = %s',
				$column_name,
				$table_name,
				'wcshipping_labels',
			),
			ARRAY_A
		);

		$results = array();

		foreach ( $db_results as $meta ) {
			$labels = maybe_unserialize( $meta['meta_value'] );
			if ( empty( $labels ) ) {
				continue;
			}

			foreach ( $labels as $label ) {

				if ( isset( $label['error'] ) || // ignore the error labels
				! isset( $label['rate'] ) ) { // labels where purchase hasn't completed for any reason
					continue;
				}

				$results[] = array_merge( $label, array( 'order_id' => $meta[ $column_name ] ) );
			}
		}

		// Sort the results by created_date in descending order
		usort(
			$results,
			function ( $a, $b ) {
				return $b['created_date'] - $a['created_date'];
			}
		);

		return $results;
	}

	/**
	 * Get all labels
	 *
	 * @return array|null
	 */
	public function get_all_labels(): ?array {
		$all_labels = get_transient( self::LABELS_TRANSIENT_KEY );

		if ( ! empty( $all_labels ) ) {
			return $all_labels;
		}

		$results = $this->fetch_labels_from_database();

		set_transient(
			self::LABELS_TRANSIENT_KEY,
			$results,
			self::LABELS_TRANSIENT_EXPIRATION_IN_SECONDS
		);

		return $results;
	}

	/**
	 * Get labels for a given period
	 *
	 * @param array $query
	 * @param array $fields
	 *
	 * $query:
	 * array(
	 *     'before'   => string, // ISO date string
	 *     'after'    => string, // ISO date string
	 *     'offset'   => int,    // Offset for pagination
	 *     'per_page' => int,    // Number of items per page
	 * )
	 *
	 * $fields:
	 * array(
	 *     'before', // string | ISO date string
	 *     'after',  // string | ISO date string
	 *     'offset', // int | Offset for pagination
	 *     'per_page', // int Number of items per page, if none-positive number, all items are returned
	 * )
	 *
	 * @return array {
	 *     'rows' => array, // Array of labels with selected fields
	 *     'meta' => array {
	 *         'pages'         => int,   // Total number of pages
	 *         'total_count'   => int,   // Total number of labels
	 *         'total_cost'    => float, // Sum of all label rates
	 *         'total_refunds' => float, // Sum of all label refunds
	 *     }
	 * }
	 */
	public function get_labels_for_period( array $query, array $fields ): array {
		$labels = $this->get_all_labels();
		// find labels between before and after
		// Convert ISO date strings to millisecond timestamps
		$after_timestamp  = strtotime( urldecode( $query['after'] ) ) * 1000;
		$before_timestamp = strtotime( urldecode( $query['before'] ) ) * 1000;

		// Filter labels within the date range, comparing millisecond timestamps
		$labels = array_filter(
			$labels,
			function ( $label ) use ( $after_timestamp, $before_timestamp ) {
				$created_date = (int) $label['created_date']; // Ensure integer comparison
				return $created_date >= $after_timestamp && $created_date <= $before_timestamp;
			}
		);

		$length = ( is_int( $query['per_page'] ) && $query['per_page'] > 0 ) ? $query['per_page'] : null;
		$rows   = array_slice( $labels, $query['offset'], $length );

		$rows = array_map(
			function ( $label ) use ( $fields ) {
				$formatted_label = array();
				foreach ( $fields as $field ) {
					if ( 'order_id' === $field ) {
						$formatted_label[ $field ] = intval( $label[ $field ] );
					} elseif ( 'refund' === $field ) {
						$formatted_label[ $field ]           = isset( $formatted_label[ $field ] ) ? (array) $formatted_label[ $field ] : array();
						$formatted_label[ $field ]['status'] = $this->get_label_refund_status( $label );
					} else {
						$formatted_label[ $field ] = $label[ $field ];
					}
				}
				return $formatted_label;
			},
			$rows
		);

		return array(
			'rows' => $rows,
			'meta' => array(
				'pages'         => ( $length !== null )
					? ceil( count( $labels ) / $length )
					: 1,
				'total_count'   => count( $labels ),
				'total_cost'    => array_sum( array_column( $labels, 'rate' ) ),
				'total_refunds' => array_sum(
					array_map(
						function ( $label ) {
							$refund = isset( $label['refund'] ) ? (array) $label['refund'] : '';
							return ! empty( $refund ) && 'complete' === $refund['status'] ? 1 : 0;
						},
						$labels
					)
				),
			),
		);
	}

	/**
	 * Get the refund status of a label
	 *
	 * @param array $label
	 * @return string Possible return values are '', 'Rejected', 'Complete', or 'Requested'
	 */
	public function get_label_refund_status( array $label ): string {
		// Hasn't been requested yet.
		if ( empty( $label['refund'] ) ) {
			return '';
		}

		$refund = (array) $label['refund'];

		if ( isset( $refund['status'] ) &&
			( 'rejected' === $refund['status'] || 'complete' === $refund['status'] ) ) {
			return ucfirst( $refund['status'] );
		}

		// Pending is the default status.
		return __( 'Requested', 'woocommerce-shipping' );
	}
}
