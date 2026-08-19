<?php

namespace Automattic\WCShipping\Connect;

class WC_Connect_Extension_Compatibility {
	/**
	 * Function called when a new tracking number is added to the order
	 *
	 * @param $order_id - order ID
	 * @param $carrier_id - carrier ID, as returned on the label objects returned by the server
	 * @param $tracking_number - tracking number string
	 */
	public static function on_new_tracking_number( $order_id, $carrier_id, $tracking_number, $service = '' ) {
		// Save label tracking numbers to the order in the format expected by the WC Shipment Tracking plugin, even if it's not installed.
		\Automattic\WCShipping\Integrations\WooCommerceShipmentTracking::add_tracking_number_to_order( $order_id, $tracking_number, $carrier_id, $service );
	}

	/**
	 * Checks if WooCommerce Shipping should email the tracking details, or if another extension is taking care of that already
	 *
	 * @param $order_id - order ID
	 * @return boolean true if WCS should send the tracking info, false otherwise
	 */
	public static function should_email_tracking_details( $order_id ) {
		if ( function_exists( 'wc_shipment_tracking' ) ) {
			$shipment_tracking = wc_shipment_tracking();
			if ( property_exists( $shipment_tracking, 'actions' )
				&& method_exists( $shipment_tracking->actions, 'get_tracking_items' ) ) {
				$shipment_tracking_items = $shipment_tracking->actions->get_tracking_items( $order_id );
				if ( ! empty( $shipment_tracking_items ) ) {
					return false;
				}
			}
		}

		/**
		 * Filter the flag indicating whether WooCommerce Shipping should add tracking info to emails.
		 *
		 * @param bool $send_email True if WCS should send the tracking info, false otherwise.
		 * @since 1.1.5
		 */
		return apply_filters( 'wcshipping_include_email_tracking_info', true );
	}
}
