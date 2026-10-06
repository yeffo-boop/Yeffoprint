<?php
/**
 * Short payment (customer) email — a Venmo/Zelle payment came in for
 * less than the order total. Sent by YeffoPrint_Partial_Payments via
 * class-email-payment-balance.php; shares the same header/footer/styles
 * as every other email.
 *
 * @var WC_Order $order
 * @var string   $name
 * @var string   $method_label  "Venmo", "Zelle", …
 * @var float    $last_amount   The payment that just came in.
 * @var float    $received      Everything received so far.
 * @var float    $total         Order total.
 * @var float    $balance       Still owed.
 * @var string   $instructions  HTML: where to send the rest.
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
	/* translators: 1: amount received, 2: Venmo/Zelle, 3: order number, 4: order total */
	esc_html__( 'Thanks, we received your %2$s payment of %1$s for order #%3$s. The order total is %4$s, so there’s a small balance left before we can start on it.', 'yeffoprint-core' ),
	wp_kses_post( wc_price( $last_amount ) ),
	esc_html( $method_label ),
	esc_html( $order->get_order_number() ),
	wp_kses_post( wc_price( $total ) )
);
?></p>

<table class="yp-payment-cta" role="presentation" cellpadding="0" cellspacing="0" width="100%">
	<tr><td>
		<span class="yp-payment-cta-label"><?php esc_html_e( 'Remaining balance', 'yeffoprint-core' ); ?></span>
		<span class="yp-payment-cta-amount"><?php echo wp_kses_post( wc_price( $balance ) ); ?></span>
		<span class="yp-payment-cta-sub">
			<?php
			printf(
				/* translators: 1: amount received so far, 2: order total */
				esc_html__( 'Received %1$s of %2$s', 'yeffoprint-core' ),
				wp_kses_post( wc_price( $received ) ),
				wp_kses_post( wc_price( $total ) )
			);
			?>
		</span>
	</td></tr>
</table>

<table class="yp-email-callout" role="presentation" cellpadding="0" cellspacing="0" width="100%"><tr><td>
	<span class="yp-email-callout-label"><?php esc_html_e( 'How to pay the rest', 'yeffoprint-core' ); ?></span>
	<?php echo wp_kses_post( $instructions ); ?>
</td></tr></table>

<p><?php esc_html_e( 'Once the rest comes in, your order moves into production right away. If you think this is a mistake, just reply to this email.', 'yeffoprint-core' ); ?></p>

<p>
<?php esc_html_e( 'Thanks,', 'yeffoprint-core' ); ?><br />
<?php echo esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ); ?>
</p>

<?php
do_action( 'woocommerce_email_footer', $email );
