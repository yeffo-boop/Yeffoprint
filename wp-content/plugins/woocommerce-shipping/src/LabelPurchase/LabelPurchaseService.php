<?php
/**
 * Class LabelPurchaseService
 *
 * @package Automattic\WCShipping
 */

namespace Automattic\WCShipping\LabelPurchase;

use Automattic\WCShipping\Connect\WC_Connect_Service_Settings_Store;
use Automattic\WCShipping\Connect\WC_Connect_API_Client;
use Automattic\WCShipping\Connect\WC_Connect_Logger;
use Automattic\WCShipping\Connect\WC_Connect_Utils;
use Automattic\WCShipping\Promo\PromoService;
use Automattic\WCShipping\Fulfillments\FulfillmentsService;
use Automattic\WCShipping\Utils;
use Automattic\WCShipping\Shipments\ShipmentsService;
use Automattic\WCShipping\Fulfillments\ShippingFulfillment;
use WP_Error;

/**
 * Class to handle label purchase requests.
 */
class LabelPurchaseService {

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
	 * Connect Label Service.
	 *
	 * @var View
	 */
	private $connect_label_service;

	/**
	 * Logger utility.
	 *
	 * @var WC_Connect_Logger
	 */
	private $logger;

	/**
	 * Promo service.
	 *
	 * @var PromoService
	 */
	private $promo_service;

	/**
	 * Fulfillments service.
	 *
	 * @var FulfillmentsService
	 */
	private $fulfillments_service;

	/**
	 * Selected rates key used to store selected rates in order meta.
	 *
	 * @var string
	 */
	const SELECTED_RATES_KEY = '_wcshipping_selected_rates';
	/**
	 * Selected hazmat key used to store selected hazmat in order meta.
	 *
	 * @var string
	 */
	const SELECTED_HAZMAT_KEY = '_wcshipping_selected_hazmat';

	/**
	 * Selected hazmat key used to store selected hazmat in order meta.
	 *
	 * @var string
	 */
	const SELECTED_ORIGIN_KEY = '_wcshipping_selected_origin';

	/**
	 * Selected hazmat key used to store selected hazmat in order meta.
	 *
	 * @var string
	 */
	const SELECTED_DESTINATION_KEY = '_wcshipping_selected_destination';

	/**
	 * Key used to store customs information in order meta.
	 *
	 * @var string
	 */
	const CUSTOMS_INFORMATION = '_wcshipping_customs_information';

	/**
	 * Key used to store order shipments in order meta.
	 *
	 * @var string
	 */
	const ORDER_SHIPMENTS = '_wcshipping-shipments';


	/**
	 * Key used to store shipment dates in order meta.
	 *
	 * @var string
	 */
	const SHIPMENT_DATES = '_wcshipping_shipment_dates';
	/**
	 * Key used to store package dimensions in order meta.
	 *
	 * @var string
	 */
	const PACKAGE_DIMENSIONS = '_wcshipping_package_dimensions';

	/**
	 * Class constructor.
	 *
	 * @param WC_Connect_Service_Settings_Store $settings_store        Server settings store instance.
	 * @param WC_Connect_API_Client             $api_client            Server API client instance.
	 * @param View                              $connect_label_service Connect Label Service instance.
	 * @param WC_Connect_Logger                 $logger                Server API client instance.
	 * @param PromoService                      $promo_service         Promo service instance.
	 * @param FulfillmentsService               $fulfillments_service  Fulfillments service instance.
	 */
	public function __construct(
		WC_Connect_Service_Settings_Store $settings_store,
		WC_Connect_API_Client $api_client,
		View $connect_label_service,
		WC_Connect_Logger $logger,
		PromoService $promo_service,
		FulfillmentsService $fulfillments_service
	) {
		$this->settings_store        = $settings_store;
		$this->api_client            = $api_client;
		$this->connect_label_service = $connect_label_service;
		$this->logger                = $logger;
		$this->promo_service         = $promo_service;
		$this->fulfillments_service  = $fulfillments_service;
	}

	/**
	 * Get labels for order.
	 *
	 * @param int $order_id WC Order ID.
	 * @return array REST response body.
	 */
	public function get_labels( $order_id ) {
		$response = $this->connect_label_service->get_label_payload( $order_id );
		if ( ! $response ) {
			$message = __( 'Order not found', 'woocommerce-shipping' );
			return new WP_Error(
				401,
				$message,
				array(
					'success' => false,
					'message' => $message,
				),
			);
		}

		return array(
			'success' => true,
			'labels'  => $response['currentOrderLabels'],
		);
	}

	/**
	 * Purchase labels for order.
	 *
	 * @param array  $origin      Origin address.
	 * @param array  $destination Destination address.
	 * @param array  $packages   Packages to purchase labels for.
	 * @param int    $order_id    WC Order ID.
	 * @param array  $selected_rate Selected rate. { rate: array, parent?: array }
	 * @param array  $selected_rate_options Selected rate options.
	 * @param array  $hazmat Selected HAZMAT category and if shipment includes HAZMAT.
	 * @param array  $customs Customs form information.
	 * @param array  $user_meta User meta array.
	 * @param array  $features_supported_by_client Features supported by client.
	 * @param array  $shipment_options Extra options.
	 * @param bool   $is_return Whether this is a return shipment.
	 * @param string $parent_shipment_id For return shipments: which shipment ID this is a return for.
	 * @return array|WP_Error REST response body.
	 */
	public function purchase_labels(
		$origin,
		$destination,
		$packages,
		$order_id,
		$selected_rate,
		$selected_rate_options,
		$hazmat,
		$customs,
		$user_meta = array(),
		$features_supported_by_client = array(),
		$shipment_options = array(),
		$is_return = false,
		$parent_shipment_id = null
	) {
		$settings         = $this->settings_store->get_account_settings();
		$service_names    = array_column( $packages, 'service_name' );
		$request_packages = $this->prepare_packages_for_purchase( $packages );

		if ( ! empty( $user_meta ) ) {
			$this->update_user_meta( $user_meta );
		}

		if ( Utils::should_use_fulfillment_api() ) {
			$fulfillment = $this->fulfillments_service->ensure_order_has_fulfillment( $order_id );
			// If there is only one fulfillment, we can use it directly
			if ( is_array( $fulfillment ) && count( $fulfillment ) === 1 ) {
				$fulfillment = $fulfillment[0];
			}
			// Todo: Take care of cases where there are multiple fulfillments.
		} else {
			/**
			 * Ensure the order has shipments.
			 * This will create data consistency between the shipments and the labels.
			 */
			$this->ensure_order_has_shipments( $order_id );
		}

		$origin_address_id = 'UNKNOWN_ORIGIN_ID';
		// Assuming only verified addresses are being used to purchase labels.
		$is_origin_address_verified = true;
		// Todo: To be updated via  woocommerce-shipping/issues/859
		if ( isset( $origin['id'] ) ) {
			$origin_address_id = $origin['id'];
			unset( $origin['id'] );
		}

		if ( isset( $origin['is_verified'] ) ) {
			$is_origin_address_verified = $origin['is_verified'];
			unset( $origin['is_verified'] );
		}

		// Extract label_date from shipment_options, default to null if not present
		$label_date = isset( $shipment_options['label_date'] ) ? $shipment_options['label_date'] : null;

		$label_response = $this->api_client->send_shipping_label_request(
			array(
				'async'                        => true,
				'email_receipt'                => $settings['email_receipts'] ?? false,
				'origin'                       => $origin,
				'destination'                  => $destination,
				'payment_method_id'            => $this->settings_store->get_selected_payment_method_id(),
				'order_id'                     => $order_id,
				'packages'                     => $request_packages,
				'features_supported_by_client' => $features_supported_by_client ?? array(),
				'shipment_options'             => array(
					'label_date' => $label_date,
				),
				'is_return'                    => $is_return,
			)
		);

		if ( is_wp_error( $label_response ) ) {
			$error = $this->restore_carrier_tos_error_code( $label_response );
			$this->logger->log( $error, __CLASS__ );
			return $error;
		}

		$purchased_labels_meta = $this->get_labels_meta_from_response( $label_response, $request_packages, $service_names, $order_id, $parent_shipment_id );

		if ( is_wp_error( $purchased_labels_meta ) ) {
			$this->logger->log( $purchased_labels_meta, __CLASS__ );
			return $purchased_labels_meta;
		}

		$selected_rate = array(
			'rate'             => array_merge(
				(array) $label_response->rates[0],
				array(
					'type' => $selected_rate['rate']['type'] ?? '',
				)
			),
			'parent'           => isset( $selected_rate['parent'] ) ? (array) $selected_rate['parent'] : null,
			'shipment_options' => $selected_rate_options,
		);

		$origin_address = array_merge(
			$origin,
			array(
				'id'          => $origin_address_id,
				'is_verified' => $is_origin_address_verified,
			),
		);

		$shipment_dates = array(
			'shipping_date'           => $label_date,
			'estimated_delivery_date' => null, // Coming soon
		);

		$hazmat_data = array_values( $hazmat )[0];

		$customs_data = array_values( $customs )[0];

		if ( Utils::should_use_fulfillment_api() && $fulfillment ) {
			return $this->store_purchased_label_to_fulfillment(
				$fulfillment,
				$purchased_labels_meta,
				$selected_rate,
				$hazmat_data,
				$origin_address,
				$destination,
				$customs_data,
				$shipment_dates
			);
		} else {
			$this->settings_store->add_labels_to_order( $order_id, $purchased_labels_meta );
		}

		// Trigger email notification for return labels.
		if ( $is_return ) {
			foreach ( $purchased_labels_meta as $label_meta ) {
				if ( ! empty( $label_meta['is_return'] ) && $label_meta['is_return'] ) {
					$attachments = array();

					// Try to get the PDF for attachment only if label is completed.
					if ( ! empty( $label_meta['label_id'] ) ) {
						// Check if label is ready (not in progress).
						if ( isset( $label_meta['status'] ) && 'PURCHASE_IN_PROGRESS' === $label_meta['status'] ) {
							// Schedule the email to be sent later when label is ready.
							if ( function_exists( 'as_schedule_single_action' ) ) {
								as_schedule_single_action(
									time() + 60, // Try again in 1 minute
									'wcshipping_send_return_label_email_delayed',
									array( $order_id, $label_meta ),
									'wcshipping'
								);
							} else {
								// Fallback to WP cron if Action Scheduler not available.
								wp_schedule_single_event(
									time() + 60,
									'wcshipping_send_return_label_email_delayed',
									array( $order_id, $label_meta )
								);
							}
						} else {
							// Label should be ready, try to get PDF.
							$pdf_attachment = $this->get_label_pdf_for_email( $label_meta['label_id'], $order_id );
							if ( ! is_wp_error( $pdf_attachment ) && ! empty( $pdf_attachment ) ) {
								$attachments[] = $pdf_attachment;
							}
						}
					}

					// Only send email now if label is not in progress.
					if ( ! isset( $label_meta['status'] ) || 'PURCHASE_IN_PROGRESS' !== $label_meta['status'] ) {
						/**
						 * Trigger return label email notification.
						 *
						 * @param int   $order_id The order ID.
						 * @param array $label_meta The label metadata.
						 * @param array $attachments Optional attachments.
						 */
						do_action( 'wcshipping_return_label_created', $order_id, $label_meta, $attachments );
					}

					// Don't clean up immediately - let the email system handle the file first.
					// Schedule cleanup for later.
					if ( ! empty( $attachments ) ) {
						foreach ( $attachments as $attachment ) {
							if ( function_exists( 'as_schedule_single_action' ) ) {
								as_schedule_single_action(
									time() + 300, // 5 minutes
									'wcshipping_cleanup_temp_file',
									array( $attachment ),
									'wcshipping'
								);
							} else {
								wp_schedule_single_event( time() + 300, 'wcshipping_cleanup_temp_file', array( $attachment ) );
							}
						}
					}
				}
			}
		}

		/**
		 * $hazmat looks like this:
		 * [
		 *   'shipment_0' => [
		 *     'category' => 'SOMECATEGORY'
		 *     'is_hazmat' => 'true'
		 *   ]
		 * ]
		 * so we can get the shipment key by getting the first key of the array
		 *
		 * @var string
		 */
		$shipment_key = array_keys( $hazmat )[0];

		$keyed_selected_rate = array(
			$shipment_key => $selected_rate,
		);

		$origin      = array(
			$shipment_key => $origin_address,
		);
		$destination = array(
			$shipment_key => $destination,
		);

		/**
		 * Extract package dimensions for storage.
		 *
		 * We store a snapshot of the current store units using `_snapshot` suffix fields.
		 * This distinguishes new (correct) data from legacy data where `package_weight_unit`
		 * was hardcoded to 'oz' regardless of the actual unit the value was stored in.
		 *
		 * Detection logic for frontend:
		 * - `_snapshot` fields exist: Trust them, value is in that unit
		 * - No `_snapshot` fields: Assume value is in current store unit
		 *   (Legacy `package_weight_unit` field is ignored as it was unreliable)
		 */
		$store_weight_unit    = strtolower( get_option( 'woocommerce_weight_unit', 'oz' ) );
		$store_dimension_unit = strtolower( get_option( 'woocommerce_dimension_unit', 'in' ) );

		$package_dimensions = array();
		foreach ( $packages as $index => $package ) {
			$dimensions_data = array();

			if ( isset( $package['weight'] ) ) {
				$dimensions_data['package_weight']               = $package['weight'];
				$dimensions_data['package_weight_unit_snapshot'] = $store_weight_unit;
			}

			if ( isset( $package['length'] ) || isset( $package['width'] ) || isset( $package['height'] ) ) {
				$dimensions_data['package_dimensions_unit_snapshot'] = $store_dimension_unit;
			}

			if ( isset( $package['length'] ) ) {
				$dimensions_data['package_length'] = $package['length'];
			}

			if ( isset( $package['width'] ) ) {
				$dimensions_data['package_width'] = $package['width'];
			}

			if ( isset( $package['height'] ) ) {
				$dimensions_data['package_height'] = $package['height'];
			}

			if ( ! empty( $dimensions_data ) ) {
				$package_dimensions[ $index ] = $dimensions_data;
			}
		}

		$selected_meta = $this->store_selected_meta(
			$order_id,
			array(
				self::SELECTED_RATES_KEY       => $keyed_selected_rate,
				self::SELECTED_HAZMAT_KEY      => $hazmat,
				self::SELECTED_ORIGIN_KEY      => $origin,
				self::SELECTED_DESTINATION_KEY => $destination,
				self::CUSTOMS_INFORMATION      => $customs,
				self::SHIPMENT_DATES           => array( $shipment_key => $shipment_dates ),
				self::PACKAGE_DIMENSIONS       => array(
					$shipment_key => $package_dimensions,
				),
			),
		);

		return array(
			'labels'               => $purchased_labels_meta,
			'selected_rates'       => $selected_meta[ self::SELECTED_RATES_KEY ],
			'selected_hazmat'      => $selected_meta[ self::SELECTED_HAZMAT_KEY ],
			'selected_origin'      => $selected_meta[ self::SELECTED_ORIGIN_KEY ],
			'selected_destination' => $selected_meta[ self::SELECTED_DESTINATION_KEY ],
			'customs_information'  => $selected_meta[ self::CUSTOMS_INFORMATION ],
			'shipment_dates'       => $selected_meta[ self::SHIPMENT_DATES ],
			'package_dimensions'   => $selected_meta[ self::PACKAGE_DIMENSIONS ],
			'success'              => true,
		);
	}

	/**
	 * Purchase labels for many shipments in one batch.
	 *
	 * Each shipment is dispatched in parallel via the BatchableApiClient (concurrency cap 5).
	 * Per-shipment failures are returned as WP_Error in the response map; they do not abort
	 * the rest of the batch. Successful purchases are persisted to the order's fulfillment
	 * record. The `is_return` email-receipt path used by the single-order flow is not yet
	 * fired for bulk purchases (deferred).
	 *
	 * @param array $origin    Shared origin address for the whole batch.
	 * @param array $shipments List of per-shipment payloads. Each item:
	 *                         { order_id, destination, packages, selected_rate,
	 *                           selected_rate_options, hazmat, customs,
	 *                           shipment_options?, is_return?,
	 *                           parent_shipment_id? }
	 *
	 * @return array|WP_Error Map of `order_<id>` => label meta array (success) or WP_Error (failure).
	 *                       Invalid shipments use a placeholder key `invalid_order_<index>`.
	 *                       String prefix avoids JSON-array coercion in clients when keys are numeric,
	 *                       and keeps the contract identifier-style for future Fulfillment-id keys.
	 *                       Returns a top-level WP_Error (`fulfillment_api_required`) when the
	 *                       fulfillment API is disabled. Bulk paths are fulfillment-only and
	 *                       have no legacy fallback.
	 */
	public function purchase_labels_batch( array $origin, array $shipments ) {
		if ( ! Utils::should_use_fulfillment_api() ) {
			$error = new WP_Error(
				'fulfillment_api_required',
				__( 'Bulk label purchase requires the fulfillment API. Enable it before using this endpoint.', 'woocommerce-shipping' ),
				array(
					'success' => false,
					'status'  => 400,
				)
			);
			$this->logger->log( $error, __CLASS__ );
			return $error;
		}

		$settings   = $this->settings_store->get_account_settings();
		$payment_id = $this->settings_store->get_selected_payment_method_id();

		// Strip origin metadata that the Connect Server does not accept on the wire.
		$origin_for_request = $origin;
		unset( $origin_for_request['id'], $origin_for_request['is_verified'] );

		$shipments_payload  = array();                  // Numerically-indexed payloads sent to the grouped endpoint.
		$context            = array();                  // "order_<id>" => { order_id, request_packages, service_names, parent_shipment_id }
		$shipments_by_id    = array();                  // "order_<id>" => original shipment input (kept for persistence-time fields).
		$fulfillments_by_id = array();                  // "order_<id>" => ShippingFulfillment resolved at preflight time.

		// Pre-dispatch results: shipments rejected before reaching the Connect Server, keyed by
		// either invalid_order_<index> (bad order_id) or order_<id> (preflight resolved no
		// usable fulfillment). The dispatch loop only sees shipments that survive preflight, so
		// the customer is never charged for a label we cannot persist.
		$pre_dispatch_results = array();

		foreach ( $shipments as $shipment_index => $shipment ) {
			$order_id = is_array( $shipment ) && isset( $shipment['order_id'] ) ? (int) $shipment['order_id'] : 0;
			if ( $order_id <= 0 ) {
				// Surface invalid shipments under a stable placeholder key so callers always get an
				// explicit result per input (matches the rate-quote batch path's behavior).
				$pre_dispatch_results[ "invalid_order_{$shipment_index}" ] = new WP_Error(
					'invalid_shipment_shape',
					__( 'Shipment is missing a valid `order_id` (must be a positive integer).', 'woocommerce-shipping' )
				);
				continue;
			}

			// Preflight fulfillment readiness BEFORE we ask the Connect Server to print a label.
			// If the order cannot be persisted (no shippable items, multiple existing fulfillments,
			// or a non-WC_Order id), skip the wire request entirely so the customer is not charged
			// for a label we cannot save against the order. Mirrors the single-order path which
			// resolves the fulfillment before send_shipping_label_request().
			$fulfillment = $this->fulfillments_service->ensure_order_has_fulfillment( $order_id );
			if ( is_array( $fulfillment ) && count( $fulfillment ) === 1 ) {
				$fulfillment = $fulfillment[0];
			}
			if ( ! $fulfillment instanceof ShippingFulfillment ) {
				$error = new WP_Error(
					'fulfillment_unavailable',
					__( 'Could not load or create a fulfillment record for the order.', 'woocommerce-shipping' ),
					array(
						'success'  => false,
						'order_id' => $order_id,
					)
				);
				$this->logger->log( $error, __CLASS__ );
				$pre_dispatch_results[ "order_{$order_id}" ] = $error;
				continue;
			}

			// Defensive: a malformed payload could send non-array `packages`/`shipment_options`,
			// which would TypeError inside array_column / prepare_packages_for_purchase. Coerce
			// so the batch keeps going for valid shipments rather than 500ing the whole request.
			$packages         = isset( $shipment['packages'] ) && is_array( $shipment['packages'] ) ? $shipment['packages'] : array();
			$service_names    = array_column( $packages, 'service_name' );
			$request_packages = $this->prepare_packages_for_purchase( $packages );
			$shipment_options = isset( $shipment['shipment_options'] ) && is_array( $shipment['shipment_options'] ) ? $shipment['shipment_options'] : array();
			$is_return        = ! empty( $shipment['is_return'] );

			// Forward the full shipment_options so signature_confirmation, saturday_delivery,
			// carbon_neutral, etc. survive the wire — the grouped endpoint accepts the same
			// shape the single-order path does. Dropping options here silently downgrades
			// merchant-paid services (e.g. signature requirement → theft/loss exposure).
			$shipments_payload[] = array(
				'order_id'         => $order_id,
				'origin'           => $origin_for_request,
				'destination'      => $shipment['destination'] ?? array(),
				'packages'         => $request_packages,
				'shipment_options' => $this->prepare_object_for_wire( $shipment_options ),
				'is_return'        => $is_return,
			);

			$context[ "order_{$order_id}" ] = array(
				'order_id'           => $order_id,
				'request_packages'   => $request_packages,
				'service_names'      => $service_names,
				'parent_shipment_id' => $shipment['parent_shipment_id'] ?? null,
			);

			$shipments_by_id[ "order_{$order_id}" ]    = $shipment;
			$fulfillments_by_id[ "order_{$order_id}" ] = $fulfillment;
		}

		if ( empty( $shipments_payload ) ) {
			return $pre_dispatch_results;
		}

		$response_map = $this->send_batch_purchase_request(
			$origin_for_request,
			$shipments_payload,
			$payment_id,
			(bool) ( $settings['email_receipts'] ?? false )
		);

		// A transport-level WP_Error from the wire (covering, e.g. all shipments at once)
		// fans out to per-order errors so callers can still walk the result map. Each
		// fanned entry gets its own WP_Error with the per-order id stamped into error_data
		// so downstream consumers that walk values (notifications, retry) keep order context.
		if ( is_wp_error( $response_map ) ) {
			$fanned = $pre_dispatch_results;
			foreach ( $context as $result_id => $ctx ) {
				$per_order        = $this->restore_carrier_tos_error_code( $response_map );
				$data             = (array) $per_order->get_error_data();
				$data['order_id'] = $ctx['order_id'];
				$per_order->add_data( $data );
				$fanned[ $result_id ] = $per_order;
				$this->logger->error( $per_order, __CLASS__ );
			}
			return $fanned;
		}

		$results = $pre_dispatch_results;
		foreach ( $context as $result_id => $ctx ) {
			$order_id = $ctx['order_id'];
			$entry    = $response_map[ $result_id ] ?? null;

			if ( null === $entry ) {
				$results[ $result_id ] = new WP_Error(
					'wcc_server_no_response',
					sprintf(
						/* translators: %d: WooCommerce order ID */
						__( 'The shipping server did not return a result for order #%d. Please retry; if the problem persists, contact support with this order ID.', 'woocommerce-shipping' ),
						$ctx['order_id']
					),
					array(
						'success'  => false,
						'order_id' => $ctx['order_id'],
					)
				);
				$this->logger->error( $results[ $result_id ], __CLASS__ );
				continue;
			}

			// Defensive: if a per-order entry is already a WP_Error, surface it as-is.
			// Without this branch the entry would slip past `is_batch_error_entry()`
			// (which only checks for `->error`), reach the meta extractor, and
			// silently become an empty-success result.
			if ( is_wp_error( $entry ) ) {
				$results[ $result_id ] = $this->restore_carrier_tos_error_code( $entry );
				$this->logger->error( $results[ $result_id ], __CLASS__ );
				continue;
			}

			// Per-order errors come back as `{ error: { code, message } }`. Map them
			// to WP_Error so the controller's serializer hits the same shape it
			// already returns for purchase failures (preserves the mobile app contract).
			if ( $this->is_batch_error_entry( $entry ) ) {
				$resolved_code         = $this->resolve_entry_value( $entry, 'code' );
				$resolved_message      = $this->resolve_entry_value( $entry, 'message' );
				$error_code            = '' !== $resolved_code ? $resolved_code : 'wcc_purchase_failed';
				$error_message         = '' !== $resolved_message ? $resolved_message : __( 'Label purchase failed.', 'woocommerce-shipping' );
				$results[ $result_id ] = new WP_Error(
					$error_code,
					$error_message,
					array(
						'success'  => false,
						'order_id' => $ctx['order_id'],
					)
				);
				$this->logger->error( $results[ $result_id ], __CLASS__ );
				continue;
			}

			$labels_meta = $this->get_labels_meta_from_response(
				$entry,
				$ctx['request_packages'],
				$ctx['service_names'],
				$ctx['order_id'],
				$ctx['parent_shipment_id']
			);

			if ( is_wp_error( $labels_meta ) ) {
				$this->logger->error( $labels_meta, __CLASS__ );
				$results[ $result_id ] = $labels_meta;
				continue;
			}

			// Persist the successful purchase against the order's fulfillment record. The bulk
			// path is fulfillment-only (WOOSHIP-2166), so there is no add_labels_to_order()
			// fallback here. Per-order failures above already skipped this block.
			$shipment_payload    = $shipments_by_id[ $result_id ] ?? array();
			$selected_rate_in    = isset( $shipment_payload['selected_rate'] ) && is_array( $shipment_payload['selected_rate'] ) ? $shipment_payload['selected_rate'] : array();
			$selected_rate_inner = isset( $selected_rate_in['rate'] ) && is_array( $selected_rate_in['rate'] ) ? $selected_rate_in['rate'] : array();
			$selected_options_in = isset( $shipment_payload['selected_rate_options'] ) && is_array( $shipment_payload['selected_rate_options'] ) ? $shipment_payload['selected_rate_options'] : array();
			$hazmat_in           = isset( $shipment_payload['hazmat'] ) && is_array( $shipment_payload['hazmat'] ) ? $shipment_payload['hazmat'] : array();
			$customs_in          = isset( $shipment_payload['customs'] ) && is_array( $shipment_payload['customs'] ) ? $shipment_payload['customs'] : array();
			$shipment_options_in = isset( $shipment_payload['shipment_options'] ) && is_array( $shipment_payload['shipment_options'] ) ? $shipment_payload['shipment_options'] : array();
			$shipping_label_date = $shipment_options_in['label_date'] ?? null;
			$destination_in      = isset( $shipment_payload['destination'] ) && is_array( $shipment_payload['destination'] ) ? $shipment_payload['destination'] : array();

			$selected_rate = array(
				'rate'             => array_merge(
					(array) ( $entry->rates[0] ?? new \stdClass() ),
					array(
						'type' => $selected_rate_inner['type'] ?? '',
					)
				),
				'parent'           => isset( $selected_rate_in['parent'] ) ? (array) $selected_rate_in['parent'] : null,
				'shipment_options' => $selected_options_in,
			);

			$origin_address = array_merge(
				$origin,
				array(
					'id'          => $origin['id'] ?? 'UNKNOWN_ORIGIN_ID',
					'is_verified' => $origin['is_verified'] ?? true,
				)
			);

			$shipment_dates = array(
				'shipping_date'           => $shipping_label_date,
				'estimated_delivery_date' => null,
			);

			// Pick the first hazmat/customs entry without re-indexing the array twice.
			$hazmat_first  = ! empty( $hazmat_in ) ? array_values( $hazmat_in )[0] : null;
			$customs_first = ! empty( $customs_in ) ? array_values( $customs_in )[0] : null;
			$hazmat_data   = is_array( $hazmat_first ) ? $hazmat_first : array();
			$customs_data  = is_array( $customs_first ) ? $customs_first : array();

			// Multi-package shipments would lose hazmat/customs entries beyond the first one.
			// Log so the operator sees a signal instead of silent data loss.
			if ( count( $hazmat_in ) > 1 || count( $customs_in ) > 1 ) {
				$this->logger->log(
					sprintf(
						'Bulk persistence kept only the first hazmat/customs entry for order %d (%d hazmat / %d customs entries received).',
						$order_id,
						count( $hazmat_in ),
						count( $customs_in )
					),
					__CLASS__
				);
			}

			// Fulfillment was resolved at preflight; orders without a usable record never
			// reached the dispatch loop, so the cached value is always a ShippingFulfillment.
			$fulfillment = $fulfillments_by_id[ $result_id ];

			$results[ $result_id ] = $this->store_purchased_label_to_fulfillment(
				$fulfillment,
				$labels_meta,
				$selected_rate,
				$hazmat_data,
				$origin_address,
				$destination_in,
				$customs_data,
				$shipment_dates
			);
		}

		return $results;
	}

	/**
	 * Send the grouped batch label-purchase request to the Connect Server.
	 *
	 * Uses the single-call endpoint (`POST /shipping/labels/batch`), which
	 * produces ONE BillingDaddy purchase across all shipments. This keeps the
	 * merchant-facing charge model consistent with the bulk-label flow: one batch
	 * with N line items instead of N per-label charges.
	 *
	 * @param array      $origin             Shared origin (already stripped for the wire).
	 * @param array      $shipments_payload  Per-shipment payloads keyed numerically.
	 * @param int|string $payment_method_id  Saved payment method id (the settings store may
	 *                                       return either an int or a string id depending on
	 *                                       the storage backend; we forward as-is).
	 * @param bool       $email_receipt      Whether the merchant has email receipts on.
	 *
	 * @return array<string, object|array|WP_Error|null>|WP_Error Per-order map keyed by `order_<id>`,
	 *               or a transport-level WP_Error covering the whole batch. Map values may be:
	 *               - object/array: a successful per-order entry the caller passes to the
	 *                 labels-meta extractor;
	 *               - WP_Error: a per-order failure the caller surfaces directly;
	 *               - null: a missing order slot (caller substitutes `wcc_server_no_response`).
	 */
	private function send_batch_purchase_request( array $origin, array $shipments_payload, $payment_method_id, bool $email_receipt ) {
		$body = array(
			'async'             => true,
			'email_receipt'     => $email_receipt,
			'payment_method_id' => $payment_method_id,
			'shipments'         => array_map(
				static function ( array $shipment ) use ( $origin ) {
					$shipment['origin'] = $origin;
					return $shipment;
				},
				$shipments_payload
			),
		);

		$response = $this->api_client->send_grouped_label_batch_request( $body );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Connect Server returns the per-order map directly. Cast top-level stdClass
		// to assoc array; per-order entries stay as stdClass and are handled by the
		// caller's walk.
		$response_array = is_array( $response ) ? $response : (array) $response;

		// Defensive: if the response carries no `order_<id>` keys but does carry a
		// top-level `code`/`message`/`error`/`success: false` envelope, surface it as
		// a transport-level WP_Error so the caller's fan-out reaches every shipment
		// with the actionable server message — instead of silently mapping each entry
		// to a generic `wcc_server_no_response`.
		$has_order_key = false;
		foreach ( $response_array as $key => $unused ) {
			if ( is_string( $key ) && 0 === strpos( $key, 'order_' ) ) {
				$has_order_key = true;
				break;
			}
		}
		if ( ! $has_order_key ) {
			return $this->wp_error_from_unexpected_batch_response( $response );
		}

		return $response_array;
	}

	/**
	 * Prepare an object-shaped Connect Server field for JSON encoding.
	 *
	 * Empty PHP arrays encode as JSON arrays (`[]`), but the Connect Server
	 * validates fields such as `shipment_options` as JSON objects (`{}`).
	 *
	 * @param mixed $value Value to encode as an object-shaped field.
	 * @return array|\stdClass
	 */
	private function prepare_object_for_wire( $value ) {
		if ( ! is_array( $value ) || empty( $value ) ) {
			return new \stdClass();
		}

		return $value;
	}

	/**
	 * Convert an unexpected top-level batch response (no `order_<id>` keys) into a
	 * WP_Error so the caller can fan it out to every shipment uniformly. Tries to
	 * preserve the server's code/message when present.
	 *
	 * @param mixed $response Raw decoded response from the Connect Server.
	 * @return WP_Error
	 */
	private function wp_error_from_unexpected_batch_response( $response ): WP_Error {
		$code    = '';
		$message = '';
		if ( is_object( $response ) ) {
			if ( property_exists( $response, 'code' ) ) {
				$code = (string) $response->code;
			}
			if ( property_exists( $response, 'message' ) ) {
				$message = (string) $response->message;
			} elseif ( property_exists( $response, 'error' ) && is_object( $response->error ) ) {
				$code    = property_exists( $response->error, 'code' ) ? (string) $response->error->code : $code;
				$message = property_exists( $response->error, 'message' ) ? (string) $response->error->message : $message;
			}
		} elseif ( is_array( $response ) ) {
			if ( isset( $response['code'] ) ) {
				$code = (string) $response['code'];
			}
			if ( isset( $response['message'] ) ) {
				$message = (string) $response['message'];
			} elseif ( isset( $response['error'] ) && is_array( $response['error'] ) ) {
				$code    = isset( $response['error']['code'] ) ? (string) $response['error']['code'] : $code;
				$message = isset( $response['error']['message'] ) ? (string) $response['error']['message'] : $message;
			}
		}

		if ( '' === $message ) {
			$message = __( 'Unexpected response shape from the WooCommerce Shipping server (no per-order entries).', 'woocommerce-shipping' );
		}

		return new WP_Error(
			'' !== $code ? $code : 'wcc_unexpected_batch_response',
			$message,
			array( 'success' => false )
		);
	}

	/**
	 * Detect a per-order error entry in the batch response.
	 *
	 * Connect Server returns errors as `{ error: { code, message } }`. The outer
	 * entry and the inner `error` may each independently arrive as an associative
	 * array or a stdClass depending on how the caller (or upstream code) decoded
	 * the JSON, so all four combinations must be handled.
	 *
	 * @param mixed $entry Per-order entry from the response map.
	 * @return bool
	 */
	private function is_batch_error_entry( $entry ): bool {
		$inner = $this->extract_inner_error( $entry );
		return is_array( $inner ) || is_object( $inner );
	}

	/**
	 * Read a field from a per-order error entry's `error` object regardless of
	 * whether the outer entry or the inner error arrived as array or stdClass.
	 * Returns empty string when the field is missing — the caller substitutes
	 * default code/message.
	 *
	 * @param mixed  $entry Per-order entry from the response map.
	 * @param string $field Field name on the inner `error` object.
	 * @return string Field value, or empty string if missing.
	 */
	private function resolve_entry_value( $entry, string $field ): string {
		$inner = $this->extract_inner_error( $entry );
		if ( is_array( $inner ) && isset( $inner[ $field ] ) ) {
			return (string) $inner[ $field ];
		}
		if ( is_object( $inner ) && isset( $inner->{$field} ) ) {
			return (string) $inner->{$field};
		}
		return '';
	}

	/**
	 * Pull the inner `error` payload out of a per-order entry whose outer shape
	 * may be either an array or a stdClass. Returns null when the entry is not
	 * an error envelope. The inner value is returned as-is (array or object) so
	 * the callers can inspect it without assuming a shape.
	 *
	 * Direct array-of-object indexing (`$arr['error']['code']`) is unsafe in
	 * mixed-shape decodings — accessing array offsets on a stdClass throws a
	 * PHP fatal — so this helper isolates the type-juggling.
	 *
	 * @param mixed $entry Per-order entry from the response map.
	 * @return array|object|null Inner `error` payload, or null if absent.
	 */
	private function extract_inner_error( $entry ) {
		if ( is_array( $entry ) && array_key_exists( 'error', $entry ) ) {
			return $entry['error'];
		}
		if ( is_object( $entry ) && isset( $entry->error ) ) {
			return $entry->error;
		}
		return null;
	}

	/**
	 * Wrap a WP_Error with the same error_data shape the single-order path returns,
	 * and restore the original carrier TOS code when FedExTosErrorInterceptor rewrote
	 * it to the UPS DAP code so the API client's TOS passthrough could keep it typed.
	 *
	 * Used by both single-order and batch paths so the error contract is consistent.
	 * Note: on the batch path FedEx TOS rewriting via FedExTosErrorInterceptor does not
	 * yet fire (BatchableApiClient uses Requests::request_multiple, which bypasses WP_Http
	 * hooks). UPS DAP TOS handling still works here; FedEx remap on the batch path is
	 * pending.
	 *
	 * @param WP_Error $label_response Error returned by the Connect Server response.
	 * @return WP_Error Normalized WP_Error.
	 */
	private function restore_carrier_tos_error_code( WP_Error $label_response ): WP_Error {
		$error_data            = (array) $label_response->get_error_data();
		$error_data['success'] = false;
		$error_data['message'] = $label_response->get_error_message();

		$error_code = $label_response->get_error_code();
		if (
			'missing_upsdap_terms_of_service_acceptance' === $error_code
			&& ! empty( $error_data['carrier_tos_code'] )
		) {
			$error_code = $error_data['carrier_tos_code'];
		}

		return new WP_Error( $error_code, $label_response->get_error_message(), $error_data );
	}

	/**
	 * Returns meta object for purchased labels to store with order.
	 *
	 * @param object $response           Purchase shipping label response from Connect Server.
	 * @param array  $packages          Packages for purchase label request body.
	 * @param array  $service_names     List of service names for packages.
	 * @param int    $order_id           WooCommerce order ID.
	 * @param string $parent_shipment_id For return labels: which shipment this is a return for.
	 * @return array|WP_Error Meta for purchased labels.
	 */
	private function get_labels_meta_from_response( $response, $packages, $service_names, $order_id, $parent_shipment_id = null ) {
		// Hard-fail on malformed entries instead of warn-and-iterate-zero-times, which
		// would have produced a "successful empty purchase" — the exact silent failure
		// the batch path is supposed to prevent.
		if ( ! is_object( $response ) || ! isset( $response->labels ) || ! is_iterable( $response->labels ) ) {
			return new WP_Error(
				'wcc_invalid_batch_entry',
				__( 'The shipping server returned an invalid response entry (missing labels array).', 'woocommerce-shipping' ),
				array(
					'success'  => false,
					'order_id' => $order_id,
				)
			);
		}

		$label_ids             = array();
		$purchased_labels_meta = array();
		$package_lookup        = $this->settings_store->get_package_lookup();
		foreach ( $response->labels as $index => $label_data ) {

			if ( isset( $label_data->error ) ) {
				$error = new WP_Error(
					$label_data->error->code,
					$label_data->error->message,
					array(
						'success' => false,
						'message' => $label_data->error->message,
					)
				);
				return $error;
			}

			/*
			 * Aknowledge the error returned on label level.
			 * In this case, error is a string and a property of the individual label object.
			 *
			 * Example:
			 * $label_data->label->error = "Rate not found";
			 */
			if ( isset( $label_data->label->error ) ) {
				$error = new WP_Error(
					'purchase_error',
					$label_data->label->error,
					array(
						'success' => false,
						'message' => $label_data->label->error,
					)
				);
				return $error;
			}

			$label_ids[] = $label_data->label->label_id;

			$label_meta = array(
				'label_id'               => $label_data->label->label_id,
				'tracking'               => $label_data->label->tracking_id,
				'refundable_amount'      => $label_data->label->refundable_amount,
				'created'                => $label_data->label->created,
				'carrier_id'             => $label_data->label->carrier_id,
				'service_name'           => $service_names[ $index ],
				'status'                 => $label_data->label->status,
				'is_return'              => $label_data->label->is_return ?? false,
				'commercial_invoice_url' => $label_data->label->commercial_invoice_url ?? '',
				'is_commercial_invoice_submitted_electronically' => $label_data->label->is_commercial_invoice_submitted_electronically ?? '',
			);

			$package = $packages[ $index ];
			$box_id  = $package['box_id'];
			if ( 'custom_box' === $box_id ) {
				$label_meta['package_name'] = __( 'Individual packaging', 'woocommerce-shipping' );
			} elseif ( isset( $package_lookup[ $box_id ] ) ) {
				$label_meta['package_name'] = $package_lookup[ $box_id ]['name'];
			} else {
				$label_meta['package_name'] = __( 'Unknown package', 'woocommerce-shipping' );
			}

			$label_meta['is_letter'] = isset( $package['is_letter'] ) ? $package['is_letter'] : false;
			$product_names           = array();
			$product_ids             = array();
			foreach ( $package['products'] as $product_id ) {
				$product       = \wc_get_product( $product_id );
				$product_ids[] = $product_id;

				if ( $product ) {
					$product_names[] = $product->get_title();
				} else {
					$order           = \wc_get_order( $order_id );
					$product_names[] = WC_Connect_Utils::get_product_name_from_order( $product_id, $order );
				}
			}

			$label_meta['product_names'] = $product_names;
			$label_meta['product_ids']   = $product_ids;
			$label_meta['id']            = $package['id']; // internal shipment id.

			// Store parent shipment ID for return labels
			if ( null !== $parent_shipment_id && '' !== $parent_shipment_id ) {
				$label_meta['parent_shipment_id'] = $parent_shipment_id;
			}

			array_unshift( $purchased_labels_meta, $label_meta );
		}
		return $purchased_labels_meta;
	}

	/**
	 * Prepares packages request for Connect Server.
	 *
	 * @param array $packages Packages from purchase request.
	 * @return array Prepared packages request payload.
	 */
	private function prepare_packages_for_purchase( $packages ) {
		$last_box_id     = '';
		$last_service_id = '';
		$last_carrier_id = '';
		foreach ( $packages as $index => $package ) {
			unset( $package['service_name'] );
			$packages[ $index ] = $package;

			if ( empty( $last_box_id ) && ! empty( $package['box_id'] ) ) {
				$last_box_id = $package['box_id'];
			}

			if ( empty( $last_service_id ) && ! empty( $package['service_id'] ) ) {
				$last_service_id = $package['service_id'];
			}

			if ( empty( $last_carrier_id ) && ! empty( $package['carrier_id'] ) ) {
				$last_carrier_id = $package['carrier_id'];
			}
		}

		// Store most recently used box/service/carrier.
		if ( ! empty( $last_box_id ) ) {
			update_user_meta( get_current_user_id(), 'wcshipping_last_box_id', $last_box_id );
		}

		if ( ! empty( $last_service_id ) && '' !== $last_service_id ) {
			update_user_meta( get_current_user_id(), 'wcshipping_last_service_id', $last_service_id );
		}

		if ( ! empty( $last_carrier_id ) && '' !== $last_carrier_id ) {
			update_user_meta( get_current_user_id(), 'wcshipping_last_carrier_id', $last_carrier_id );
		}

		return $packages;
	}

	/**
	 * Store user meta.
	 *
	 * @param array $user_meta User meta array.
	 */
	public function update_user_meta( $user_meta ) {
		if ( empty( $user_meta ) ) {
			return;
		}
		foreach ( $user_meta as $key => $value ) {
			update_user_meta( get_current_user_id(), 'wcshipping_' . $key, $value );
		}
	}

	public function get_status( $label_id ) {
		return $this->api_client->get_label_status( $label_id );
	}

	public function update_order_label( int $order_id, $label_data ) {
		// Due to the async nature of the purchase process, we need to do the promotion decrement here, to only do it after the status changes to PURCHASED.

		if ( isset( $label_data->promo_id ) ) {
			$this->promo_service->maybe_decrement_promotion_remaining( $order_id, $label_data );
		}

		return $this->settings_store->update_label_order_meta_data( $order_id, $label_data );
	}

	/**
	 *
	 * @param $order_id int
	 * @param $selected_meta [
	 *    'selected_rate' => [],
	 *   'hazmat' => []
	 *   'origin' => []
	 *   'destination' => []
	 * ]
	 *
	 * @return array
	 */
	private function store_selected_meta( $order_id, $selected_meta ): array {
		$order = \wc_get_order( $order_id );
		foreach ( $selected_meta as $key => $value ) {
			$selected_state = $order->get_meta( $key );
			$selected_state = array_merge( empty( $selected_state ) ? array() : $selected_state, $value );
			$order->update_meta_data( $key, $selected_state );
		}
		$order->save();

		return $selected_meta;
	}

	/**
	 * @return object|WP_Error
	 */
	public function refund_label( int $order_id, int $label_id ) {
		$response = $this->api_client->send_shipping_label_refund_request( $label_id );

		if ( isset( $response->error ) ) {
			$response = new WP_Error(
				property_exists( $response->error, 'code' ) ? $response->error->code : 'refund_error',
				property_exists( $response->error, 'message' ) ? $response->error->message : ''
			);
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$label_refund = (object) array(
			'label_id' => (int) $response->label->id,
			'refund'   => $response->refund,
		);

		$this->settings_store->update_label_order_meta_data( $order_id, $label_refund );

		return $response;
	}

	/**
	 * Get shipments destinations.
	 *
	 * @param int $order_id Order ID.
	 * @return array Array of destinations by shipment id.
	 */
	public function get_shipments_destinations( int $order_id ) {
		$order = \wc_get_order( $order_id );
		return $order->get_meta( self::SELECTED_DESTINATION_KEY );
	}

	/**
	 * Get shipments origins.
	 *
	 * @param int $order_id Order ID.
	 * @return array Array of origins by shipment id.
	 */
	public function get_shipments_origins( int $order_id ) {
		$order = \wc_get_order( $order_id );
		return $order->get_meta( self::SELECTED_ORIGIN_KEY );
	}


	/**
	 * Get shipments from order, build it from order items if only 1 shipment is present.
	 *
	 * Todo: refactor in  WOOSHIP-1603
	 *
	 * @param int $order_id Order ID.
	 * @return array Array of shipments.
	 */
	public function get_shipments( int $order_id ) {
		$order = \wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return array();
		}

		$shipments = $order->get_meta( self::ORDER_SHIPMENTS );
		// Single shipment orders does not have shipments meta set, so we build it from the order items
		if ( empty( $shipments ) ) {
			$shipments    = array();
			$shipments[0] = ShipmentsService::build_shipment_from_order_items( $order );
		}
		return $shipments;
	}

	/**
	 * Ensure the order has shipments.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	private function ensure_order_has_shipments( $order_id ) {
		// If the order doesn't have shipments, create and store it
		$order = \wc_get_order( $order_id );
		if ( $order instanceof \WC_Order ) {
			$shipments = $order->get_meta( self::ORDER_SHIPMENTS );
			if ( empty( $shipments ) ) {
				$shipments    = array();
				$shipments[0] = ShipmentsService::build_shipment_from_order_items( $order );
				$order->update_meta_data( self::ORDER_SHIPMENTS, $shipments );
				$order->save();
			}
		}
	}

	/**
	 * Get label PDF as a temporary file for email attachment.
	 *
	 * @param int $label_id The label ID.
	 * @param int $order_id The order ID.
	 * @return string|WP_Error Path to temporary PDF file or error.
	 */
	private function get_label_pdf_for_email( $label_id, $order_id ) {
		// Get paper size with fallback.
		$paper_size = $this->settings_store->get_preferred_paper_size();
		if ( empty( $paper_size ) ) {
			$paper_size = 'letter'; // Default fallback.
		}

		// Prepare parameters for PDF request.
		$params = array(
			'paper_size' => $paper_size,
			'labels'     => array(
				array(
					'label_id' => intval( $label_id ),
				),
			),
		);

		// Get PDF from API.
		$response = $this->api_client->get_labels_print_pdf( $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Check if response has the expected format.
		if ( ! is_array( $response ) ) {
			return new WP_Error( 'invalid_pdf_response', __( 'Invalid PDF response format', 'woocommerce-shipping' ) );
		}

		// Extract the body from the response.
		$pdf_data = wp_remote_retrieve_body( $response );

		// Check if body contains PDF data.
		if ( empty( $pdf_data ) || substr( $pdf_data, 0, 4 ) !== '%PDF' ) {
			return new WP_Error( 'invalid_pdf_data', __( 'Response does not contain valid PDF data', 'woocommerce-shipping' ) );
		}

		// Create temporary file.
		$upload_dir = wp_upload_dir();
		$temp_dir   = trailingslashit( $upload_dir['basedir'] ) . 'wcshipping_temp/';

		// Create temp directory if it doesn't exist.
		if ( ! file_exists( $temp_dir ) ) {
			wp_mkdir_p( $temp_dir );
		}

		// Generate filename.
		$filename = sprintf( 'return-label-order-%d-label-%d.pdf', $order_id, $label_id );
		$filepath = $temp_dir . $filename;

		// Save PDF to temporary file.
		$result = file_put_contents( $filepath, $pdf_data );

		if ( false === $result ) {
			return new WP_Error( 'pdf_save_error', __( 'Failed to save PDF file', 'woocommerce-shipping' ) );
		}

		return $filepath;
	}

	/**
	 * Store purchased label data to fulfillment.
	 *
	 * @param ShippingFulfillment $fulfillment Fulfillment object instance.
	 * @param array               $purchased_labels_meta Array of purchased label metadata.
	 *                            Structure: [
	 *                                [
	 *                                    'label_id' => string,
	 *                                    'tracking' => string,
	 *                                    'refundable_amount' => float,
	 *                                    'created' => string (timestamp),
	 *                                    'carrier_id' => string,
	 *                                    'service_name' => string,
	 *                                    'status' => string,
	 *                                    'commercial_invoice_url' => string,
	 *                                    'is_commercial_invoice_submitted_electronically' => bool,
	 *                                    'package_name' => string,
	 *                                    'is_letter' => bool,
	 *                                    'product_names' => array of strings,
	 *                                    'product_ids' => array of integers,
	 *                                    'id' => string (internal shipment id)
	 *                                ],
	 *                                ...
	 *                            ]
	 * @param array               $selected_rate Selected shipping rate data.
	 *                            Structure: [
	 *                                'rate' => [
	 *                                    'id' => string,
	 *                                    'carrier_id' => string,
	 *                                    'service_id' => string,
	 *                                    'rate' => float,
	 *                                    'currency' => string,
	 *                                    'type' => string,
	 *                                    ...additional rate properties from API response
	 *                                ],
	 *                                'parent' => array|null (parent rate data if applicable),
	 *                                'shipment_options' => array (selected rate options)
	 *                            ]
	 * @param array               $hazmat_config HAZMAT configuration.
	 *                            Structure: [
	 *                                'category' => string (HAZMAT category),
	 *                                'is_hazmat' => string ('true'|'false')
	 *                            ]
	 * @param array               $origin_address Origin address data.
	 *                            Structure: [
	 *                                'id' => string (address ID),
	 *                                'is_verified' => bool,
	 *                                'name' => string,
	 *                                'company' => string,
	 *                                'address' => string,
	 *                                'address_2' => string,
	 *                                'city' => string,
	 *                                'state' => string,
	 *                                'postcode' => string,
	 *                                'country' => string,
	 *                                'phone' => string
	 *                            ]
	 * @param array               $destination Destination address data.
	 *                            Structure: [
	 *                                'name' => string,
	 *                                'company' => string,
	 *                                'address' => string,
	 *                                'address_2' => string,
	 *                                'city' => string,
	 *                                'state' => string,
	 *                                'postcode' => string,
	 *                                'country' => string,
	 *                                'phone' => string
	 *                            ]
	 * @param array               $customs Customs form information.
	 *                            Structure: [
	 *                                'contents_type' => string,
	 *                                'restriction_type' => string,
	 *                                'restriction_comments' => string,
	 *                                'non_delivery_option' => string,
	 *                                'customs_items' => [
	 *                                    [
	 *                                        'description' => string,
	 *                                        'quantity' => int,
	 *                                        'value' => float,
	 *                                        'weight' => float,
	 *                                        'hs_tariff_number' => string,
	 *                                        'origin_country' => string
	 *                                    ],
	 *                                    ...
	 *                                ]
	 *                            ]
	 * @param array               $shipment_dates Shipment date information.
	 *                            Structure: [
	 *                                'shipping_date' => string|null (label date),
	 *                                'estimated_delivery_date' => string|null (estimated delivery)
	 *                            ]
	 * @return array Response array with success status and stored data.
	 */
	protected function store_purchased_label_to_fulfillment(
		$fulfillment,
		$purchased_labels_meta,
		$selected_rate,
		$hazmat_config,
		$origin_address,
		$destination,
		$customs,
		$shipment_dates
	) {
		/**
		 * A successfully purchased label fulfills this shipment. Failed batch
		 * entries never call this method, so their preflight fulfillment shells
		 * stay unfulfilled.
		 */
		$fulfillment->set_status( 'fulfilled' );
		$fulfillment->set_labels( $purchased_labels_meta );
		$fulfillment->set_shipping_label_rate( $selected_rate );
		$fulfillment->set_shipping_label_hazmat( $hazmat_config );
		$fulfillment->set_selected_origin( $origin_address );
		$fulfillment->set_shipping_label_destination( $destination );
		$fulfillment->set_shipping_label_customs( $customs );
		$fulfillment->set_shipping_label_dates( $shipment_dates );
		$fulfillment->save();

		return array_merge(
			$fulfillment->get_shipping_data(),
			array(
				'success' => true,
			)
		);
	}
}
