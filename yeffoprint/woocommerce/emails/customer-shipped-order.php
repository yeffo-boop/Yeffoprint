<?php
/**
 * "Your order has shipped!" customer email — theme template for
 * yeffoprint-core/includes/woocommerce/class-email-customer-shipped-order.php.
 *
 * Same header/footer/order-table/customer-details structure every
 * other customer order email here already uses (see
 * customer-processing-order.php's own docblock) — the only new content
 * is the shipping-details box below, one per shipment
 * (YeffoPrint_Order_Tracking::get_shipments() — carrier + tracking
 * number). Reuses class-order-item-meta.php's existing
 * .yp-email-fields/.yp-email-fields-box/.yp-email-fields-heading/
 * .yp-email-fields-rows classes (email-styles.php) — the same bordered,
 * uppercase-label box already used for per-item customization detail —
 * so this needed no new CSS at all.
 *
 * "Track your order" itself isn't rendered here — class-order-tracking.php's
 * render_tracking_button() already appends it to every customer order
 * email (hooked to woocommerce_email_after_order_table) the moment a
 * shipment exists, this one included, for free.
 *
 * @see https://woocommerce.com/document/template-structure/
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;

defined( 'ABSPATH' ) || exit;

$email_improvements_enabled = FeaturesUtil::feature_is_enabled( 'email_improvements' );
$shipments                  = YeffoPrint_Order_Tracking::get_shipments( $order );

/*
 * @hooked WC_Emails::email_header() Output the email header
 */
do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php echo $email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>
<p>
<?php
if ( ! empty( $order->get_billing_first_name() ) ) {
	/* translators: %s: Customer first name */
	printf( esc_html__( 'Hi %s,', 'yeffoprint' ), esc_html( $order->get_billing_first_name() ) );
} else {
	esc_html_e( 'Hi,', 'yeffoprint' );
}
?>
</p>
<p><?php esc_html_e( 'Good news — your order is on its way! Here’s your tracking information:', 'yeffoprint' ); ?></p>
<?php echo $email_improvements_enabled ? '</div>' : ''; ?>

<?php if ( $shipments ) : ?>
<table class="yp-email-fields" cellspacing="0" cellpadding="0" width="100%">
	<?php foreach ( $shipments as $index => $shipment ) : ?>
	<tr>
		<td class="yp-email-fields-box">
			<span class="yp-email-fields-heading"><?php echo count( $shipments ) > 1 ? esc_html( sprintf( /* translators: %d: package number */ __( 'Package %d', 'yeffoprint' ), $index + 1 ) ) : esc_html__( 'Shipping Details', 'yeffoprint' ); ?></span>
			<table class="yp-email-fields-rows" cellspacing="0" cellpadding="0" width="100%">
				<tr>
					<td class="yp-email-field-label"><?php esc_html_e( 'Carrier', 'yeffoprint' ); ?></td>
					<td class="yp-email-field-value"><?php echo esc_html( $shipment['carrier_label'] ); ?></td>
				</tr>
				<tr>
					<td class="yp-email-field-label"><?php esc_html_e( 'Tracking #', 'yeffoprint' ); ?></td>
					<td class="yp-email-field-value"><?php echo esc_html( $shipment['tracking_number'] ); ?></td>
				</tr>
			</table>
		</td>
	</tr>
	<?php endforeach; ?>
</table>
<?php endif; ?>

<?php
/*
 * @hooked WC_Emails::order_details() Shows the order details table.
 * @hooked YeffoPrint_Order_Tracking::render_tracking_button() Shows the "Track your order" button.
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
