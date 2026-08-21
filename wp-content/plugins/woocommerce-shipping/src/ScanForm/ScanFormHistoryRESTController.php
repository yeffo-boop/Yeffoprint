<?php
/**
 * ScanForm History REST Controller
 *
 * Handles REST API requests for USPS ScanForms History.
 *
 * @package Automattic\WCShipping\ScanForm
 */

namespace Automattic\WCShipping\ScanForm;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WCShipping\Connect\WC_Connect_Functions;
use Automattic\WCShipping\WCShippingRESTController;
use Automattic\WooCommerce\Utilities\OrderUtil;
use WP_Error;
use WP_REST_Response;
use WP_REST_Request;

/**
 * ScanForm History REST Controller class.
 */
class ScanFormHistoryRESTController extends WCShippingRESTController {

	/**
	 * ScanForm service.
	 *
	 * @var ScanFormService
	 */
	private ScanFormService $scanform_service;

	/**
	 * Class constructor.
	 *
	 * @param ScanFormService $scanform_service     ScanForm service instance.
	 */
	public function __construct( ScanFormService $scanform_service ) {
		$this->scanform_service = $scanform_service;
	}

	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $rest_base = 'scan-form';

	/**
	 * Register routes for ScanForm endpoints.
	 */
	public function register_routes() {
		// GET /wcshipping/v1/scan-form/history - Get ScanForm history with pagination.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/history',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_scan_form_history' ),
					'permission_callback' => array( WC_Connect_Functions::class, 'user_can_manage_labels' ),
					'args'                => array(
						'page'     => array(
							'default'           => 1,
							'type'              => 'integer',
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
						'per_page' => array(
							'default'           => 20,
							'type'              => 'integer',
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// GET /wcshipping/v1/scan-form/{scan_form_id}/labels - Get labels for a ScanForm.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<scan_form_id>[a-zA-Z0-9_]+)/labels',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_scan_form_labels' ),
					'permission_callback' => array( WC_Connect_Functions::class, 'user_can_manage_labels' ),
					'args'                => array(
						'scan_form_id' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	/**
	 * Get ScanForm history with pagination.
	 *
	 * @param WP_REST_Request $request Request object containing page and per_page parameters.
	 *
	 * @return WP_REST_Response Response with paginated ScanForm history.
	 */
	public function get_scan_form_history( WP_REST_Request $request ): WP_REST_Response {
		$page     = $request->get_param( 'page' ) ?? 1;
		$per_page = $request->get_param( 'per_page' ) ?? 20;
		$offset   = ( $page - 1 ) * $per_page;

		// Get paginated ScanForms directly from database.
		$paginated_result = $this->scanform_service->get_paginated_scan_forms( $per_page, $offset );
		$scan_forms_page  = $paginated_result['scan_forms'];
		$total            = $paginated_result['total'];
		$total_pages      = ceil( $total / $per_page );

		return new WP_REST_Response(
			array(
				'success'     => true,
				'scan_forms'  => $scan_forms_page,
				'total'       => $total,
				'page'        => $page,
				'per_page'    => $per_page,
				'total_pages' => $total_pages,
			),
			200
		);
	}

	/**
	 * Get labels for a specific ScanForm.
	 *
	 * @param WP_REST_Request $request Request object containing scan_form_id.
	 *
	 * @return WP_REST_Response|WP_Error Response with label details or error.
	 */
	public function get_scan_form_labels( WP_REST_Request $request ) {
		$scan_form_id = $request->get_param( 'scan_form_id' );

		// Validate scan_form_id format (alphanumeric and underscores only).
		if ( empty( $scan_form_id ) || ! preg_match( '/^[a-zA-Z0-9_]+$/', $scan_form_id ) ) {
			return new WP_Error(
				'invalid_scan_form_id',
				__( 'Invalid SCAN Form ID format', 'woocommerce-shipping' ),
				array( 'status' => 400 )
			);
		}

		global $wpdb;

		// Get the appropriate table and column names based on HPOS/legacy.
		$meta_table       = OrderUtil::get_table_for_order_meta();
		$meta_join_column = OrderUtil::custom_orders_table_usage_is_enabled() ? 'order_id' : 'post_id';

		// Query all orders that have this scan_form_id in their _wcshipping_scan_forms meta.
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

		// Find the scan form and collect label_ids.
		$label_ids = array();
		$order_ids = array();

		foreach ( $results as $row ) {
			$scan_forms = maybe_unserialize( $row->meta_value );
			if ( empty( $scan_forms ) || ! is_array( $scan_forms ) ) {
				continue;
			}

			foreach ( $scan_forms as $scan_form ) {
				if ( ( $scan_form['scan_form_id'] ?? '' ) === $scan_form_id ) {
					if ( ! empty( $scan_form['label_ids'] ) && is_array( $scan_form['label_ids'] ) ) {
						$label_ids = array_merge( $label_ids, $scan_form['label_ids'] );
					}
					$order_ids[] = (int) $row->order_id;
				}
			}
		}

		if ( empty( $label_ids ) ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'labels'  => array(),
				),
				200
			);
		}

		// Fetch label and shipment date data for these orders.
		$order_ids    = array_unique( $order_ids );
		$all_labels   = $this->scanform_service->get_order_meta_bulk( $order_ids, 'wcshipping_labels' );
		$all_dates    = $this->scanform_service->get_order_meta_bulk( $order_ids, '_wcshipping_shipment_dates' );
		$orders_cache = array();

		$labels_data = array();

		foreach ( $order_ids as $order_id ) {
			$order_labels = $all_labels[ $order_id ] ?? array();
			if ( empty( $order_labels ) || ! is_array( $order_labels ) ) {
				continue;
			}

			$shipment_dates = $all_dates[ $order_id ] ?? array();

			foreach ( $order_labels as $label ) {
				$label_id = $label['label_id'];

				// Only include labels that are in this ScanForm.
				if ( ! in_array( $label_id, $label_ids, true ) ) {
					continue;
				}

				// Load order object.
				if ( ! isset( $orders_cache[ $order_id ] ) ) {
					$orders_cache[ $order_id ] = wc_get_order( $order_id );
				}

				$order = $orders_cache[ $order_id ];
				if ( ! $order ) {
					continue;
				}

				// Get shipping date for this shipment.
				$shipment_id   = $label['id'];
				$shipment_key  = 'shipment_' . $shipment_id;
				$shipping_date = $shipment_dates[ $shipment_key ]['shipping_date'] ?? '-';

				$labels_data[] = array(
					'label_id'      => $label_id,
					'order_id'      => $order_id,
					'tracking'      => $label['tracking'],
					'created'       => $label['created'],
					'service_name'  => $label['service_name'],
					'order_number'  => $order->get_order_number(),
					'shipping_name' => $order->get_formatted_shipping_full_name(),
					'shipping_date' => $shipping_date,
				);
			}
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'labels'  => $labels_data,
			),
			200
		);
	}
}
