<?php
/**
 * Customer invoice / pending order (customer) email — theme override of
 * woocommerce/templates/emails/customer-invoice.php.
 *
 * Every visual change (colors, header/footer band, rounded card) lives
 * entirely in this same directory's email-header.php, email-footer.php,
 * and email-styles.php, which every email type already shares via the
 * woocommerce_email_header/_footer hooks below.
 *
 * Direct report: a Web Design Package order got the exact same copy as
 * a physical label/sticker order ("here's a summary of what's
 * included... complete payment"), which reads fine as far as it goes
 * but never actually says this is a website purchase — $is_web_design
 * below swaps in copy that sets the right expectation (what happens
 * once they pay) instead of leaving every order to read identically.
 *
 * @see https://woocommerce.com/document/template-structure/
 */

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Utilities\FeaturesUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$email_improvements_enabled = FeaturesUtil::feature_is_enabled( 'email_improvements' );
$is_web_design               = class_exists( 'YeffoPrint_Web_Design_Project_Meta' ) && YeffoPrint_Web_Design_Project_Meta::is_web_design_order( $order );

/**
 * Executes the e-mail header.
 *
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
<?php if ( $order->needs_payment() ) { ?>
	<p>
	<?php
	// Direct request: "can you make the payment link more obvious?" — the
	// sentence here now just sets context; the .yp-payment-cta card below
	// (email-styles.php) carries the actual call to action, in the site's
	// accent color, so it's the one thing on this email nothing else
	// competes with for attention.
	if ( $order->has_status( OrderStatus::FAILED ) ) {
		printf(
			/* translators: %s: Site title */
			esc_html__( 'Sorry, your order on %s was unsuccessful. Here’s a summary of what’s included — use the button below to try your payment again.', 'yeffoprint' ),
			esc_html( get_bloginfo( 'name', 'display' ) )
		);
	} elseif ( $is_web_design ) {
		printf(
			/* translators: %s: Site title */
			esc_html__( 'Your web design order has been created on %s. Once you complete payment below, we’ll be in touch to put together your project agreement — covering your timeline, milestones, and how revisions work — before we start building your staging site.', 'yeffoprint' ),
			esc_html( get_bloginfo( 'name', 'display' ) )
		);
	} else {
		printf(
			/* translators: %s: Site title */
			esc_html__( 'An order has been created for you on %s. Here’s a summary of what’s included — when you’re ready, use the button below to complete payment.', 'yeffoprint' ),
			esc_html( get_bloginfo( 'name', 'display' ) )
		);
	}
	?>
	</p>

	<table class="yp-payment-cta" role="presentation" cellpadding="0" cellspacing="0" width="100%">
		<tr><td>
			<span class="yp-payment-cta-label"><?php esc_html_e( 'Amount due', 'yeffoprint' ); ?></span>
			<span class="yp-payment-cta-amount"><?php echo wp_kses_post( wc_price( $order->get_total() ) ); ?></span>
			<a class="yp-payment-cta-button" href="<?php echo esc_url( $order->get_checkout_payment_url() ); ?>"><?php esc_html_e( 'Pay for this order →', 'yeffoprint' ); ?></a>
			<span class="yp-payment-cta-sub">
				<?php
				printf(
					/* translators: %s: order number */
					esc_html__( 'Order %s · secure checkout', 'yeffoprint' ),
					esc_html( $order->get_order_number() )
				);
				?>
			</span>
		</td></tr>
	</table>

<?php } else { ?>
	<p>
	<?php
	/* translators: %s Order date */
	printf( esc_html__( 'Here are the details of your order placed on %s:', 'woocommerce' ), esc_html( wc_format_datetime( $order->get_date_created() ) ) );
	?>
	</p>
	<?php
}
?>
<?php echo $email_improvements_enabled ? '</div>' : ''; ?>

<?php

/**
 * Hook for the woocommerce_email_order_details.
 *
 * @hooked WC_Emails::order_details() Shows the order details table.
 * @hooked WC_Structured_Data::generate_order_data() Generates structured data.
 * @hooked WC_Structured_Data::output_structured_data() Outputs structured data.
 * @since 2.5.0
 */
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

/**
 * Hook for the woocommerce_email_order_meta.
 *
 * @hooked WC_Emails::order_meta() Shows order meta data.
 */
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

/**
 * Hook for woocommerce_email_customer_details.
 *
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

/**
 * Executes the email footer.
 *
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action( 'woocommerce_email_footer', $email );
