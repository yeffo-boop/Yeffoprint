<?php
/**
 * Class LabelPrintService
 *
 * @package Automattic\WCShipping
 */

namespace Automattic\WCShipping\LabelPurchase;

use Automattic\WCShipping\Connect\WC_Connect_API_Client;
use Automattic\WCShipping\Connect\WC_Connect_Logger;
use Automattic\WCShipping\LabelPurchase\LabelPurchaseService;
use Automattic\WCShipping\Utilities\AddressUtils;
use Automattic\WCShipping\Utils;
use WP_Error;

/**
 * Class to handle logics around printing labels.
 */
class LabelPrintService {
	/**
	 * Connect Server API client.
	 *
	 * @var WC_Connect_API_Client
	 */
	private $api_client;

	/**
	 * Logger utility.
	 *
	 * @var WC_Connect_Logger
	 */
	private $logger;

	/**
	 * Labels service.
	 *
	 * @var LabelPurchaseService
	 */
	private $label_purchase_service;

	/**
	 * Class constructor.
	 *
	 * @param WC_Connect_API_Client $api_client            Server API client instance.
	 * @param WC_Connect_Logger     $logger                Logger.
	 */
	public function __construct(
		WC_Connect_API_Client $api_client,
		WC_Connect_Logger $logger,
		LabelPurchaseService $label_purchase_service
	) {
		$this->api_client             = $api_client;
		$this->logger                 = $logger;
		$this->label_purchase_service = $label_purchase_service;
	}

	/**
	 * This function retrieve the test label PDF binary from the connect server.
	 *
	 * @param string $paper_size The size of the paper. Check connect server for the valid inputs.
	 * @return array|WP_Error
	 */
	public function get_label_preview_content( $paper_size ) {
		$params['paper_size'] = $paper_size;
		$params['carrier']    = 'usps';
		$params['labels']     = array(
			array( 'caption' => 'Test label 1' ),
			array( 'caption' => 'Test label 2' ),
		);

		// Note: Setting label_id_csv to null triggers a sample PDF. Do not pass label_id to the API.
		$response = $this->api_client->get_labels_preview_pdf( $params );

		if ( is_wp_error( $response ) ) {
			$error = new WP_Error(
				$response->get_error_code(),
				$response->get_error_message(),
				array( 'message' => $response->get_error_message() )
			);
			$this->logger->log( $error, __CLASS__ );

			return $error;
		}

		// Return the binaries of the PDF.
		return base64_encode( $response['body'] ); //phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Generate packing list HTML for a specific label.
	 *
	 * @param int $order_id WC Order ID.
	 * @param int $label_id Label ID.
	 * @return array|WP_Error REST response body.
	 */
	public function get_packing_list( int $order_id, int $label_id ) {
		$order = \wc_get_order( $order_id );
		if ( ! $order ) {
			$message = __( 'Order not found', 'woocommerce-shipping' );
			return new WP_Error(
				401,
				$message,
				array(
					'success' => false,
					'message' => $message,
				)
			);
		}

		$labels = $this->label_purchase_service->get_labels( $order_id );
		if ( is_wp_error( $labels ) ) {
			return $labels;
		}

		$label = null;
		foreach ( $labels['labels'] as $label_data ) {
			if ( $label_data['label_id'] === $label_id ) {
				$label = $label_data;
			}
		}

		if ( ! $label ) {
			$message = __( 'Label not found', 'woocommerce-shipping' );
			return new WP_Error(
				404,
				$message,
				array(
					'success' => false,
					'message' => $message,
				)
			);
		}

		$destinations = $this->label_purchase_service->get_shipments_destinations( $order_id );
		$origins      = $this->label_purchase_service->get_shipments_origins( $order_id );

		// Shipment ID
		$shipment_id     = $label['id'];
		$total_shipments = count( $destinations );
		$shipment_number = array_search( 'shipment_' . $shipment_id, array_keys( $destinations ) ) + 1;

		// Get shipment destination
		$destination = $destinations[ 'shipment_' . $shipment_id ];
		// Get shipment origin
		$origin = $origins[ 'shipment_' . $shipment_id ];

		// For return labels, addresses are reversed on the label itself
		// So we need to flip them to match what appears on the actual label
		$is_return_label = ! empty( $label['is_return'] ) && $label['is_return'];
		if ( $is_return_label ) {
			// Swap origin and destination for return labels
			$temp        = $origin;
			$origin      = $destination;
			$destination = $temp;
		}

		// Get store information
		$store_address = AddressUtils::address_array_to_formatted_html_string( $origin );

		// Get customer information
		$shipping_address = AddressUtils::address_array_to_formatted_html_string( $destination );

		// Get label items from shipments
		$shipments = $this->label_purchase_service->get_shipments( $order_id );

		$shipment_items = $shipments[ $shipment_id ];

		$label_items = $this->create_rows_from_shipment_items( $shipment_items, $order );

		$packing_slip_html = $this->render_packing_slip_html(
			array(
				'order_number'          => $order->get_order_number(),
				'shipment_number'       => $shipment_number,
				'total_shipments'       => $total_shipments,
				'label_details'         => array(
					'service_name' => $label['service_name'],
					'tracking'     => $label['tracking'],
					'package_name' => $label['package_name'],
				),
				'store_address_html'    => $store_address,
				'shipping_address_html' => $shipping_address,
				'items'                 => $label_items,
			)
		);

		$html = apply_filters( 'wcshipping_packing_list_html', $packing_slip_html, $order_id, $label_id );

		return array(
			'success' => true,
			'html'    => $html,
		);
	}

	/**
	 * Render the packing slip HTML shared across label and no-label flows.
	 *
	 * @param array $args Arguments used to populate the template.
	 * @return string HTML markup for the packing slip.
	 */
	private function render_packing_slip_html( array $args ): string {
		$defaults    = array(
			'order_number'          => '',
			'shipment_number'       => null,
			'total_shipments'       => null,
			'label_details'         => null,
			'store_address_html'    => '',
			'shipping_address_html' => '',
			'items'                 => array(),
		);
		$data        = wp_parse_args( $args, $defaults );
		$weight_unit = get_option( 'woocommerce_weight_unit' );

		ob_start();
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<meta charset="utf-8">
			<title><?php echo esc_html( __( 'Packing Slip', 'woocommerce-shipping' ) ); ?></title>
			<style>
				body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; font-size: 12px; }
				.container { max-width: 800px; margin: 0 auto; padding: 8px; }
				.header { text-align: center; margin-bottom: 8px; }
				.addresses { display: flex; justify-content: space-between; margin-bottom: 16px; }
				.address { flex: 1; }
				table { width: 100%; border-collapse: collapse; }
				th, td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
				th { background-color: #f8f9fa; }
				.footer { text-align: center; margin-top: 24px; }
				.item-cell { display: flex; align-items: center; gap: 8px; }
				.item-image { width: 32px; height: 32px; object-fit: cover; }
				.checkbox-cell { width: 24px; height: 24px; border: 1px solid #000; display: inline-block; vertical-align: middle; }
				@media print {
					.checkbox-cell { border-color: #000 !important; }
				}
			</style>
		</head>
		<body>
			<div class="container">
				<div class="header">
					<h1><?php echo esc_html( __( 'Packing Slip', 'woocommerce-shipping' ) ); ?></h1>
					<p>
						<?php
							// translators: %s is the order number.
							echo esc_html( sprintf( __( 'Order #%s', 'woocommerce-shipping' ), $data['order_number'] ) );
						?>
						<?php if ( $data['shipment_number'] && $data['total_shipments'] ) : ?>
							<br/>
							<?php
								// translators: %1$s is the shipment number, %2$s is the total shipments.
								echo esc_html( sprintf( __( 'Shipment #%1$s of %2$s', 'woocommerce-shipping' ), $data['shipment_number'], $data['total_shipments'] ) );
							?>
						<?php endif; ?>
					</p>
				</div>

				<?php if ( ! empty( $data['label_details'] ) && is_array( $data['label_details'] ) ) : ?>
					<?php $label_details = $data['label_details']; ?>
					<div class="label-info">
						<h3><?php echo esc_html( __( 'Shipping Label Details', 'woocommerce-shipping' ) ); ?></h3>
						<p>
							<?php
								// translators: %s is the service name.
								echo esc_html( sprintf( __( 'Service: %s', 'woocommerce-shipping' ), $label_details['service_name'] ?? '' ) );
							?>
							<br/>
							<?php
								// translators: %s is the tracking number.
								echo esc_html( sprintf( __( 'Tracking #: %s', 'woocommerce-shipping' ), $label_details['tracking'] ?? '' ) );
							?>
							<br/>
							<?php
								// translators: %s is the package name.
								echo esc_html( sprintf( __( 'Package: %s', 'woocommerce-shipping' ), $label_details['package_name'] ?? '' ) );
							?>
						</p>
					</div>
				<?php endif; ?>

				<div class="addresses">
					<div class="address">
						<h3><?php echo esc_html( __( 'From:', 'woocommerce-shipping' ) ); ?></h3>
						<p><?php echo wp_kses_post( $data['store_address_html'] ); ?></p>
					</div>
					<div class="address">
						<h3><?php echo esc_html( __( 'Ship To:', 'woocommerce-shipping' ) ); ?></h3>
						<p><?php echo wp_kses_post( $data['shipping_address_html'] ); ?></p>
					</div>
				</div>

				<table>
					<thead>
						<tr>
							<th><?php echo esc_html( __( 'Item', 'woocommerce-shipping' ) ); ?></th>
							<th><?php echo esc_html( __( 'SKU', 'woocommerce-shipping' ) ); ?></th>
							<th><?php echo esc_html( __( 'Variant', 'woocommerce-shipping' ) ); ?></th>
							<th><?php echo esc_html( __( 'Quantity', 'woocommerce-shipping' ) ); ?></th>
							<th><?php echo esc_html( __( 'Weight', 'woocommerce-shipping' ) ); ?></th>
							<th><?php echo esc_html( __( 'Dimensions', 'woocommerce-shipping' ) ); ?></th>
							<th><?php echo esc_html( __( 'Picked', 'woocommerce-shipping' ) ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $data['items'] as $item ) : ?>
						<tr>
							<td>
								<div class="item-cell">
									<?php
									echo wp_kses(
										$item['image'],
										array(
											'img' => array(
												'src'    => true,
												'alt'    => true,
												'class'  => true,
												'width'  => true,
												'height' => true,
												'sizes'  => true,
											),
										)
									);
									?>
									<?php echo esc_html( $item['name'] ); ?>
								</div>
							</td>
							<td><?php echo esc_html( $item['sku'] ); ?></td>
							<td><?php echo esc_html( $item['variation'] ? $item['variation'] : '-' ); ?></td>
							<td><?php echo esc_html( $item['quantity'] ); ?></td>
							<td><?php echo esc_html( $item['weight'] ? $item['weight'] . ' ' . $weight_unit : '-' ); ?></td>
							<td><?php echo esc_html( $item['dimensions'] ); ?></td>
							<td><div class="checkbox-cell"></div></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php if ( ! Utils::is_next() ) : ?>
				<div class="footer">
					<p>Powered by WooCommerce Shipping</p>
				</div>
			<?php endif; ?>
		</body>
		</html>
		<?php

		return ob_get_clean();
	}

	/**
	 * Generate packing list HTML for an order without requiring a shipping label.
	 *
	 * @param int    $order_id WC Order ID.
	 * @param string $format   Output format ('html' or 'pdf').
	 * @return array|WP_Error REST response body.
	 */
	public function get_packing_list_without_label( int $order_id, string $format = 'html' ) {
		$order = \wc_get_order( $order_id );
		if ( ! $order ) {
			$message = __( 'Order not found', 'woocommerce-shipping' );
			return new WP_Error(
				401,
				$message,
				array(
					'success' => false,
					'message' => $message,
				)
			);
		}

		// Get shipment items from order without label requirement
		$shipments = $this->label_purchase_service->get_shipments( $order_id );

		// Use the first shipment (index 0) or build from order items if none exist
		$shipment_items = ! empty( $shipments ) ? reset( $shipments ) : array();

		// Get store origin address from settings
		$origin_address = $this->get_store_origin_address();

		// Get shipping address from order
		$shipping_address = AddressUtils::address_array_to_formatted_html_string(
			array(
				'company'    => $order->get_shipping_company(),
				'address_1'  => $order->get_shipping_address_1(),
				'address_2'  => $order->get_shipping_address_2(),
				'city'       => $order->get_shipping_city(),
				'state'      => $order->get_shipping_state(),
				'postcode'   => $order->get_shipping_postcode(),
				'country'    => $order->get_shipping_country(),
				'first_name' => $order->get_shipping_first_name(),
				'last_name'  => $order->get_shipping_last_name(),
			)
		);

		// Create items array from order items if shipment_items is empty
		if ( empty( $shipment_items ) ) {
			$shipment_items = array();
			foreach ( $order->get_items() as $item_id => $item ) {
				if ( ! $item->get_product() || ! $item->get_product()->needs_shipping() ) {
					continue;
				}
				$shipment_items[] = array(
					'id'       => $item_id,
					'quantity' => $item->get_quantity(),
				);
			}
		}

		$label_items = $this->create_rows_from_shipment_items( $shipment_items, $order );

		$packing_slip_html = $this->render_packing_slip_html(
			array(
				'order_number'          => $order->get_order_number(),
				'store_address_html'    => $origin_address,
				'shipping_address_html' => $shipping_address,
				'items'                 => $label_items,
			)
		);

		$html = apply_filters( 'wcshipping_packing_list_without_label_html', $packing_slip_html, $order_id );

		// If PDF format is requested, convert HTML to PDF via Connect Server
		if ( 'pdf' === $format ) {
			$this->logger->log( "Converting packing slip to PDF for order $order_id", __CLASS__ );

			$request_params = array(
				'html'     => $html,
				'order_id' => $order_id,
			);

			// Call the Connect Server PDF endpoint
			$raw_response = $this->api_client->get_packing_slip_pdf( $request_params );

			if ( is_wp_error( $raw_response ) ) {
				$this->logger->log( $raw_response, __CLASS__ );
				return $raw_response;
			}

			if ( empty( $raw_response['body'] ) ) {
				$this->logger->log( 'PDF generation returned empty body', __CLASS__ );
				return new WP_Error(
					500,
					__( 'PDF generation failed', 'woocommerce-shipping' ),
					array(
						'success' => false,
						'message' => __( 'PDF generation returned empty response', 'woocommerce-shipping' ),
					)
				);
			}

			return array(
				'success'    => true,
				'b64Content' => base64_encode( $raw_response['body'] ),
				'mimeType'   => 'application/pdf',
			);
		}

		return array(
			'success' => true,
			'html'    => $html,
		);
	}

	/**
	 * Create label items array from shipment items.
	 *
	 * @param array     $shipment_items Array of shipment items.
	 * @param \WC_Order $order         WooCommerce order object.
	 * @return array                   Array of formatted label items.
	 */
	private function create_rows_from_shipment_items( array $shipment_items, \WC_Order $order ): array {
		$label_items = array();

		foreach ( $shipment_items as $item ) {
			/**
			 * @var \WC_Order_Item_Product $order_item
			 */
			$order_item = $order->get_item( $item['id'] );
			if ( ! $order_item || ! $order_item->get_product() ) {
				continue;
			}

			$variation_text = wc_display_item_meta(
				$order_item,
				array(
					'before'       => '',
					'after'        => '',
					'separator'    => ', ',
					'echo'         => false,
					'label_before' => '',
					'label_after'  => ': ',
					'autop'        => false,
				)
			);

			// Strip any remaining HTML tags for clean table display
			$variation_text = wp_strip_all_tags( $variation_text );

			// Default to order item quantity, when it's a single shipment, the order acuatlly doesn't have a shipment meta set.
			$quantity = $order_item->get_quantity();

			// Determine quantity: use count of subItems if present
			if ( isset( $item['subItems'] ) && is_array( $item['subItems'] ) ) {
				$quantity = max( count( $item['subItems'] ), 1 );
			}

			$label_items[] = array(
				'name'       => $order_item->get_name(),
				'sku'        => $order_item->get_product()->get_sku(),
				'quantity'   => $quantity,
				'weight'     => $order_item->get_product()->get_weight(),
				'dimensions' => wc_format_dimensions( $order_item->get_product()->get_dimensions( false ) ?? array() ),
				'variation'  => $variation_text,
				'image'      => $order_item->get_product()->get_image(
					'woocommerce_thumbnail',
					array(
						'class' => 'item-image',
					)
				),
			);
		}

		return $label_items;
	}

	/**
	 * Get store origin address from WooCommerce settings.
	 *
	 * @return string Formatted store address HTML.
	 */
	private function get_store_origin_address(): string {
		$store_address = array(
			'company'   => get_bloginfo( 'name' ),
			'address_1' => get_option( 'woocommerce_store_address' ),
			'address_2' => get_option( 'woocommerce_store_address_2' ),
			'city'      => get_option( 'woocommerce_store_city' ),
			'state'     => get_option( 'woocommerce_default_state' ),
			'postcode'  => get_option( 'woocommerce_store_postcode' ),
			'country'   => get_option( 'woocommerce_default_country' ),
		);

		// If we have a country code with state (e.g., 'US:CA'), split it
		if ( strpos( $store_address['country'], ':' ) !== false ) {
			list( $country, $state )  = explode( ':', $store_address['country'] );
			$store_address['country'] = $country;
			if ( empty( $store_address['state'] ) ) {
				$store_address['state'] = $state;
			}
		}

		return AddressUtils::address_array_to_formatted_html_string( $store_address );
	}
}
