<?php
/**
 * "Your order is delayed" (customer) email — direct request (2026-11-10,
 * printer down): "I need the ability to send order delayed emails to
 * customers". Jeff picked mockup B (mockups/order-delay-email/email-b.png)
 * "but add the coupon code" from C: apology, the items, where things
 * stand, keep / change labels / cancel options, an optional personal
 * note and an optional thank-you code. Sent from the admin app by
 * YeffoPrint_Order_Delay_Email.
 *
 * @var WC_Order $order
 * @var string   $name        Customer's first name or "there".
 * @var string   $ship_label  New estimated ship date, formatted, or ''.
 * @var string   $note        Personal note, or ''.
 * @var string   $code        Discount code, or ''.
 * @var string   $code_line   The heading over the code (e.g. "10% off your next order").
 * @var string   $change_url  Order change page link, or '' once labels can't be changed.
 * @var string   $signer      E.g. "Jeff at YeffoDesign".
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p><?php
/* translators: %s: customer's first name or "there" */
printf( esc_html__( 'Hi %s,', 'yeffoprint-core' ), esc_html( $name ) );
?></p>
<p><?php
printf(
	/* translators: %s: order number */
	esc_html__( 'We’re sorry to say your order #%s is running behind. Our label printer had a malfunction, and a technician is coming out to repair it.', 'yeffoprint-core' ),
	esc_html( $order->get_order_number() )
);
?></p>

<table class="yp-delay-items" role="presentation" cellpadding="0" cellspacing="0" width="100%">
	<?php foreach ( $order->get_items() as $item ) : ?>
		<tr><td><strong><?php echo esc_html( $item->get_name() ); ?></strong></td><td class="yp-delay-qty">&times; <?php echo esc_html( (string) $item->get_quantity() ); ?></td></tr>
	<?php endforeach; ?>
</table>

<table class="yp-away-email-notice" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 22px;"><tr><td style="background-color:#FBF0DB;border:1px solid #EFDCAE;border-radius:12px;padding:16px 18px;">
	<span class="yp-away-email-notice-eyebrow"><?php esc_html_e( 'Where things stand', 'yeffoprint-core' ); ?></span>
	<p class="yp-away-email-notice-body yp-delay-body"><?php
	if ( '' !== $ship_label ) {
		printf(
			/* translators: %s: date, e.g. "Tuesday, November 18" */
			esc_html__( 'We expect to ship your order by %s. If anything changes, we’ll let you know.', 'yeffoprint-core' ),
			'<strong>' . esc_html( $ship_label ) . '</strong>'
		);
	} else {
		esc_html_e( 'We’ll print your order as soon as the printer is repaired and email you tracking the moment it ships.', 'yeffoprint-core' );
	}
	?></p>
</td></tr></table>

<?php if ( '' !== $note ) : ?>
<p class="yp-delay-note">“<?php echo esc_html( $note ); ?>” – <?php echo esc_html( $signer ); ?></p>
<?php endif; ?>

<table class="yp-bot-callout yp-delay-options" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 22px;"><tr><td style="background-color:#FDF1F8;border:1px solid #F3C7E1;border-radius:12px;padding:16px 18px;">
	<span class="yp-delay-options-eyebrow"><?php esc_html_e( 'Your options', 'yeffoprint-core' ); ?></span>
	<ul>
		<li><strong><?php esc_html_e( 'Keep your order.', 'yeffoprint-core' ); ?></strong> <?php esc_html_e( 'Nothing to do. It keeps its place in line.', 'yeffoprint-core' ); ?></li>
		<?php if ( '' !== $change_url ) : ?>
		<li><strong><?php esc_html_e( 'Make changes.', 'yeffoprint-core' ); ?></strong> <?php
			printf(
				/* translators: %s: link to the order change page */
				esc_html__( 'Since it hasn’t printed yet, you can still %s.', 'yeffoprint-core' ),
				'<a href="' . esc_url( $change_url ) . '">' . esc_html__( 'update your labels', 'yeffoprint-core' ) . '</a>'
			);
		?></li>
		<?php endif; ?>
		<li><strong><?php esc_html_e( 'Cancel for a full refund.', 'yeffoprint-core' ); ?></strong> <?php esc_html_e( 'Just reply to this email and we’ll take care of it, no questions asked.', 'yeffoprint-core' ); ?></li>
	</ul>
</td></tr></table>

<?php if ( '' !== $code ) : ?>
<p><?php esc_html_e( 'To say thank you for your patience, here’s something off your next order:', 'yeffoprint-core' ); ?></p>
<table class="yp-addon-email-notice yp-delay-code" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 22px;"><tr><td style="background-color:#E5F7FD;border:1px solid #B8E9F7;border-radius:12px;padding:16px 18px;text-align:center;">
	<span class="yp-addon-email-notice-eyebrow"><?php echo esc_html( $code_line ); ?></span>
	<p class="yp-delay-code-value"><?php echo esc_html( $code ); ?></p>
	<p class="yp-addon-email-notice-body"><?php esc_html_e( 'Enter it at checkout.', 'yeffoprint-core' ); ?></p>
</td></tr></table>
<?php endif; ?>

<p><?php esc_html_e( 'Thank you for understanding. We really appreciate your patience while we get back up and running.', 'yeffoprint-core' ); ?></p>

<p>
<?php esc_html_e( 'Thanks,', 'yeffoprint-core' ); ?><br />
<?php echo esc_html( $signer ); ?>
</p>

<?php
do_action( 'woocommerce_email_footer', $email );
