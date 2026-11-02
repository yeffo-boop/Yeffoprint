<?php
/**
 * Payment reminder (customer) email — a Venmo/Zelle order has been On
 * hold for 24 hours with no payment. Sent once by
 * YeffoPrint_Payment_Reminder via class-email-payment-reminder.php;
 * shares the same header/footer/styles as every other email.
 *
 * @var WC_Order $order
 * @var string   $name
 * @var string   $method_label  "Venmo" or "Zelle".
 * @var float    $balance       Amount owed.
 * @var string   $instructions  HTML: where to send it.
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
	/* translators: 1: order number, 2: Venmo/Zelle */
	esc_html__( 'Just a friendly reminder that order #%1$s is still waiting on your %2$s payment. We’ll start on it as soon as the payment comes in.', 'yeffoprint-core' ),
	esc_html( $order->get_order_number() ),
	esc_html( $method_label )
);
?></p>

<table class="yp-payment-cta" role="presentation" cellpadding="0" cellspacing="0" width="100%">
	<tr><td>
		<span class="yp-payment-cta-label"><?php esc_html_e( 'Amount due', 'yeffoprint-core' ); ?></span>
		<span class="yp-payment-cta-amount"><?php echo wp_kses_post( wc_price( $balance ) ); ?></span>
		<span class="yp-payment-cta-sub">
			<?php
			/* translators: %s: order number */
			printf( esc_html__( 'Order #%s', 'yeffoprint-core' ), esc_html( $order->get_order_number() ) );
			?>
		</span>
	</td></tr>
</table>

<table class="yp-email-callout" role="presentation" cellpadding="0" cellspacing="0" width="100%"><tr><td>
	<span class="yp-email-callout-label"><?php esc_html_e( 'How to pay', 'yeffoprint-core' ); ?></span>
	<?php echo wp_kses_post( $instructions ); ?>
</td></tr></table>

<p><?php esc_html_e( 'Already sent it? Thank you, and you can ignore this email. If you sent it without your order number in the note, just reply with a screenshot and we’ll match it.', 'yeffoprint-core' ); ?></p>

<p>
<?php esc_html_e( 'Thanks,', 'yeffoprint-core' ); ?><br />
<?php echo esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ); ?>
</p>

<?php
do_action( 'woocommerce_email_footer', $email );
