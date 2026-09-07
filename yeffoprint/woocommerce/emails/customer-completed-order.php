<?php
/**
 * Order completed (customer) email — theme override of
 * woocommerce/templates/emails/customer-completed-order.php.
 *
 * Direct report: WooCommerce's own stock body copy for this email
 * ("We have finished processing your order.") reads like a generic
 * fulfillment notice — accurate for most stores, where "Completed"
 * usually just means "shipped." On this store it means something more
 * specific: class-order-delivery-status.php's hourly sweep only moves
 * an order to Completed once every one of its shipments shows
 * carrier-confirmed delivery, so by the time this email fires the
 * package has actually arrived. The subject/heading get the same
 * delivery-specific treatment in
 * class-email-customer-completed-order.php; this file's only change is
 * this one line of body copy — everything else (colors, header/footer
 * band, rounded card) still lives entirely in this same directory's
 * email-header.php, email-footer.php, and email-styles.php.
 *
 * @see https://woocommerce.com/document/template-structure/
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$email_improvements_enabled = FeaturesUtil::feature_is_enabled( 'email_improvements' );

/*
 * @hooked WC_Emails::email_header() Output the email header
 */
do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php echo $email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>
<p>
<?php
if ( ! empty( $order->get_billing_first_name() ) ) {
	/* translators: %s: Customer first name */
	printf( esc_html__( 'Hi %s,', 'woocommerce' ), esc_html( $order->get_billing_first_name() ) );
} else {
	printf( esc_html__( 'Hi,', 'woocommerce' ) );
}
?>
</p>
<p><?php esc_html_e( 'Good news — your package has been delivered! We hope you love it.', 'yeffoprint' ); ?></p>
<?php if ( $email_improvements_enabled ) : ?>
	<p><?php esc_html_e( 'Here’s a reminder of what you’ve ordered:', 'woocommerce' ); ?></p>
<?php endif; ?>
<?php echo $email_improvements_enabled ? '</div>' : ''; ?>

<?php

/*
 * @hooked WC_Emails::order_details() Shows the order details table.
 * @hooked WC_Structured_Data::generate_order_data() Generates structured data.
 * @hooked WC_Structured_Data::output_structured_data() Outputs structured data.
 * @since 2.5.0
 */
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

/*
 * @hooked WC_Emails::order_meta() Shows order meta data.
 */
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

/*
 * @hooked WC_Emails::customer_details() Shows customer details
 * @hooked WC_Emails::email_address() Shows email address
 */
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( $additional_content ) {
	echo $email_improvements_enabled ? '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content">' : '';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo $email_improvements_enabled ? '</td></tr></table>' : '';
}

/*
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action( 'woocommerce_email_footer', $email );
