<?php
/**
 * Cancelled order (admin) email — theme override, copied unchanged from
 * woocommerce/templates/emails/admin-cancelled-order.php.
 *
 * This file's actual content/copy is identical to WooCommerce's own
 * default — every visual change (colors, header/footer band, rounded
 * card) lives entirely in this same directory's email-header.php,
 * email-footer.php, and email-styles.php, which every email type
 * already shares via the woocommerce_email_header/_footer hooks below.
 * This copy exists so this specific email's wording is easy to find
 * and edit later without hunting through the WooCommerce plugin itself
 * — not because anything here needed to change today.
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

<?php
echo $email_improvements_enabled ? '<div class="email-introduction">' : '';
/* translators: %1$s: Order number. %2$s: Customer full name */
$text = __( 'Notification to let you know &mdash; order #%1$s belonging to %2$s has been cancelled:', 'woocommerce' );
if ( $email_improvements_enabled ) {
	/* translators: %1$s: Order number. %2$s: Customer full name */
	$text = __( 'We’re getting in touch to let you know that order #%1$s from %2$s has been cancelled.', 'woocommerce' );
}
?>
<p><?php printf( esc_html( $text ), esc_html( $order->get_order_number() ), esc_html( $order->get_formatted_billing_full_name() ) ); ?></p>
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
	echo $email_improvements_enabled ? '<table border="0" cellpadding="0" cellspacing="0" width="100%"><tr><td class="email-additional-content">' : '';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo $email_improvements_enabled ? '</td></tr></table>' : '';
}

/*
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action( 'woocommerce_email_footer', $email );
