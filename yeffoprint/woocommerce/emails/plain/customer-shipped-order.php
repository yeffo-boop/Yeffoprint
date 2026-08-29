<?php
/**
 * "Your order has shipped!" customer email — plain-text part.
 *
 * Mirrors WooCommerce core's own templates/emails/plain/customer-processing-order.php
 * structure (no plain/ override existed in this theme before this email —
 * every other customer email here relies on WC's own core plain fallback,
 * but this is a wholly new custom status with no core equivalent, so both
 * the html and plain templates had to be authored from scratch). Shipping
 * details render as plain "Carrier: X" / "Tracking #: Y" lines per
 * shipment; the "Track your order" line itself still comes for free from
 * class-order-tracking.php's render_tracking_button() (hooked to
 * woocommerce_email_after_order_table, already plain-text aware).
 *
 * @see https://woocommerce.com/document/template-structure/
 */

defined( 'ABSPATH' ) || exit;

$shipments = YeffoPrint_Order_Tracking::get_shipments( $order );

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

if ( ! empty( $order->get_billing_first_name() ) ) {
	/* translators: %s: Customer first name */
	echo sprintf( esc_html__( 'Hi %s,', 'yeffoprint' ), esc_html( $order->get_billing_first_name() ) ) . "\n\n";
} else {
	echo esc_html__( 'Hi,', 'yeffoprint' ) . "\n\n";
}

echo esc_html__( 'Good news — your order is on its way! Here’s your tracking information:', 'yeffoprint' ) . "\n\n";

if ( $shipments ) {
	foreach ( $shipments as $index => $shipment ) {
		echo count( $shipments ) > 1
			? esc_html( sprintf( /* translators: %d: package number */ __( 'Package %d', 'yeffoprint' ), $index + 1 ) )
			: esc_html__( 'Shipping Details', 'yeffoprint' );
		echo "\n";
		echo esc_html__( 'Carrier:', 'yeffoprint' ) . ' ' . esc_html( $shipment['carrier_label'] ) . "\n";
		echo esc_html__( 'Tracking #:', 'yeffoprint' ) . ' ' . esc_html( $shipment['tracking_number'] ) . "\n\n";
	}
}

/*
 * @hooked WC_Emails::order_details() Shows the order details table.
 * @hooked YeffoPrint_Order_Tracking::render_tracking_button() Shows the tracking link.
 * @hooked WC_Structured_Data::generate_order_data() Generates structured data.
 * @hooked WC_Structured_Data::output_structured_data() Outputs structured data.
 */
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

echo "\n----------------------------------------\n\n";

/*
 * @hooked WC_Emails::order_meta() Shows order meta data.
 */
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

/*
 * @hooked WC_Emails::customer_details() Shows customer details
 * @hooked WC_Emails::email_address() Shows email address
 */
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

echo "\n\n----------------------------------------\n\n";

/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n----------------------------------------\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
