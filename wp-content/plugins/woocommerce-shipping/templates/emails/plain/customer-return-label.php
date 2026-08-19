<?php
/**
 * Customer return label email (plain text)
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/plain/customer-return-label.php.
 *
 * @package WooCommerce_Shipping/Templates/Emails/Plain
 * @version 1.0.0
 *
 * @phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo '= ' . esc_html( $email_heading ) . " =\n\n";

/* translators: %s: Customer first name */
echo sprintf( esc_html__( 'Hi %s,', 'woocommerce-shipping' ), esc_html( $order->get_billing_first_name() ) ) . "\n\n";

echo esc_html__( 'Your return label has been created and is attached to this email. Please print the label and attach it to your return package.', 'woocommerce-shipping' ) . "\n\n";

if ( ! empty( $label_data ) ) {
	echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
	echo esc_html__( 'RETURN LABEL DETAILS', 'woocommerce-shipping' ) . "\n";
	echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

	if ( ! empty( $label_data['tracking'] ) ) {
		echo esc_html__( 'Tracking Number:', 'woocommerce-shipping' ) . ' ' . esc_html( $label_data['tracking'] ) . "\n";
	}

	if ( ! empty( $label_data['carrier_id'] ) ) {
		echo esc_html__( 'Carrier:', 'woocommerce-shipping' ) . ' ' . esc_html( strtoupper( $label_data['carrier_id'] ) ) . "\n";
	}

	if ( ! empty( $label_data['service_name'] ) ) {
		echo esc_html__( 'Service:', 'woocommerce-shipping' ) . ' ' . esc_html( $label_data['service_name'] ) . "\n";
	}

	if ( ! empty( $label_data['created_date'] ) ) {
		echo esc_html__( 'Created:', 'woocommerce-shipping' ) . ' ' . esc_html( date_i18n( wc_date_format(), strtotime( $label_data['created_date'] ) ) ) . "\n";
	}

	echo "\n";
}

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html__( 'RETURN INSTRUCTIONS', 'woocommerce-shipping' ) . "\n";
echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

echo '1. ' . esc_html__( 'Print the attached return label', 'woocommerce-shipping' ) . "\n";
echo '2. ' . esc_html__( 'Securely package your items', 'woocommerce-shipping' ) . "\n";
echo '3. ' . esc_html__( 'Attach the label to the outside of your package', 'woocommerce-shipping' ) . "\n";
echo '4. ' . esc_html__( 'Drop off your package at any authorized shipping location', 'woocommerce-shipping' ) . "\n\n";

if ( ! empty( $label_data['tracking'] ) ) {
	$tracking_url = '';
	if ( ! empty( $label_data['carrier_id'] ) ) {
		switch ( $label_data['carrier_id'] ) {
			case 'fedex':
				$tracking_url = 'https://www.fedex.com/apps/fedextrack/?action=track&tracknumbers=' . $label_data['tracking'];
				break;
			case 'usps':
				$tracking_url = 'https://tools.usps.com/go/TrackConfirmAction.action?tLabels=' . $label_data['tracking'];
				break;
			case 'ups':
				$tracking_url = 'https://www.ups.com/track?loc=en_US&tracknum=' . $label_data['tracking'];
				break;
			case 'dhlexpress':
				$tracking_url = 'https://www.dhl.com/en/express/tracking.html?AWB=' . $label_data['tracking'];
				break;
		}
	}

	if ( $tracking_url ) {
		echo esc_html__( 'Track your return:', 'woocommerce-shipping' ) . ' ' . esc_url( $tracking_url ) . "\n\n";
	}
}

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html__( 'RETURNED ITEMS', 'woocommerce-shipping' );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

// Filter order items to show only returned items.
add_filter( 'woocommerce_order_get_items', array( $email, 'filter_return_label_items' ), 10, 2 );

// Temporarily remove the tracking info hook to prevent showing all labels.
// We need to remove all callbacks for this action.
global $wp_filter;
$saved_filters            = array();
$saved_order_meta_filters = array();

// For return label emails, we need to prevent shipment tracking from showing.
if ( isset( $GLOBALS['wcshipping_sending_return_label_email'] ) && $GLOBALS['wcshipping_sending_return_label_email'] ) {
	// Remove the after order table hooks (this is where tracking usually appears).
	if ( isset( $wp_filter['woocommerce_email_after_order_table'] ) ) {
		$saved_filters = $wp_filter['woocommerce_email_after_order_table']->callbacks;
		$wp_filter['woocommerce_email_after_order_table']->callbacks = array();
	}

	// Also remove order meta hooks that might contain tracking.
	if ( isset( $wp_filter['woocommerce_email_order_meta'] ) ) {
		$saved_order_meta_filters = $wp_filter['woocommerce_email_order_meta']->callbacks;
		// Only keep WC core callbacks, remove others.
		foreach ( $wp_filter['woocommerce_email_order_meta']->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $key => $callback ) {
				// Remove callbacks that might be from shipment tracking.
				if ( strpos( $key, 'shipment_tracking' ) !== false || strpos( $key, 'tracking' ) !== false ) {
					unset( $wp_filter['woocommerce_email_order_meta']->callbacks[ $priority ][ $key ] );
				}
			}
		}
	}
}

/*
 * @hooked WC_Emails::order_details() Shows the order details table.
 * @hooked WC_Structured_Data::generate_order_data() Generates structured data.
 * @hooked WC_Structured_Data::output_structured_data() Outputs structured data.
 * @since 2.5.0
 */
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

// Remove the filter after use.
remove_filter( 'woocommerce_order_get_items', array( $email, 'filter_return_label_items' ), 10 );

echo "\n";

/*
 * @hooked WC_Emails::order_meta() Shows order meta data.
 */
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

/*
 * @hooked WC_Emails::customer_details() Shows customer details
 * @hooked WC_Emails::email_address() Shows email address
 */
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

// Restore the saved filters.
if ( isset( $GLOBALS['wcshipping_sending_return_label_email'] ) && $GLOBALS['wcshipping_sending_return_label_email'] ) {
	if ( ! empty( $saved_filters ) && isset( $wp_filter['woocommerce_email_after_order_table'] ) ) {
		$wp_filter['woocommerce_email_after_order_table']->callbacks = $saved_filters;
	}
	if ( ! empty( $saved_order_meta_filters ) && isset( $wp_filter['woocommerce_email_order_meta'] ) ) {
		$wp_filter['woocommerce_email_order_meta']->callbacks = $saved_order_meta_filters;
	}
}

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
