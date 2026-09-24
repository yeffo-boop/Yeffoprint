<?php
/**
 * Abandoned-cart reminder (customer) email — Email 1 and Email 2 from
 * yeffoprint-core's class-abandoned-carts.php. Same header/footer/styles
 * as every other email in this theme (see customer-proof-notice.php);
 * the yp-cart-* rules live in email-styles.php.
 *
 * $lines come pre-summarized from the cart (name, size · material,
 * quantity, line total, template image); a line with no image just
 * skips the thumbnail cell.
 *
 * @see https://woocommerce.com/document/template-structure/
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p><?php
/* translators: %s: customer's first name or "there" */
printf( esc_html__( 'Hi %s,', 'yeffoprint-core' ), esc_html( $name ) );
?></p>
<p><?php echo esc_html( $intro ); ?></p>

<?php if ( '' !== $coupon_code ) : ?>
<table class="yp-cart-code" role="presentation" cellpadding="0" cellspacing="0" width="100%">
	<tr><td>
		<span class="yp-cart-code-label"><?php esc_html_e( 'Your code', 'yeffoprint-core' ); ?></span>
		<span class="yp-cart-code-value"><?php echo esc_html( $coupon_code ); ?></span>
		<?php if ( '' !== $discount_note ) : ?>
			<span class="yp-cart-code-note"><?php echo esc_html( $discount_note ); ?></span>
		<?php endif; ?>
	</td></tr>
</table>
<?php endif; ?>

<table class="yp-cart-items" role="presentation" cellpadding="0" cellspacing="0" width="100%">
	<?php foreach ( $lines as $line ) : ?>
	<tr>
		<?php if ( ! empty( $line['image'] ) ) : ?>
		<td class="yp-cart-thumb" width="84"><img src="<?php echo esc_url( $line['image'] ); ?>" alt="" width="72" /></td>
		<?php endif; ?>
		<td class="yp-cart-line">
			<span class="yp-cart-line-name"><?php echo esc_html( $line['name'] ); ?></span>
			<?php if ( ! empty( $line['detail'] ) ) : ?>
				<span class="yp-cart-line-meta"><?php echo esc_html( $line['detail'] ); ?></span>
			<?php endif; ?>
			<span class="yp-cart-line-meta"><?php echo esc_html( $line['quantity'] ); ?></span>
		</td>
		<td class="yp-cart-price"><?php echo wp_kses_post( wc_price( (float) $line['total'] ) ); ?></td>
	</tr>
	<?php endforeach; ?>
	<tr>
		<td class="yp-cart-total-label" colspan="<?php echo ! empty( $lines[0]['image'] ) ? 2 : 1; ?>"><?php esc_html_e( 'Cart total', 'yeffoprint-core' ); ?></td>
		<td class="yp-cart-price yp-cart-total"><?php echo wp_kses_post( wc_price( (float) $total ) ); ?></td>
	</tr>
</table>

<table class="yp-proof-cta yp-cart-cta" role="presentation" cellpadding="0" cellspacing="0" width="100%">
	<tr><td>
		<a class="yp-proof-cta-button" href="<?php echo esc_url( $cta_url ); ?>"><?php echo esc_html( $button_label ); ?></a>
		<span class="yp-proof-cta-sub"><?php esc_html_e( 'Your cart opens right at checkout, just how you left it.', 'yeffoprint-core' ); ?></span>
	</td></tr>
</table>

<p><?php
if ( '' !== $telegram_url ) {
	printf(
		/* translators: %s: "message us on Telegram" link */
		esc_html__( 'Stuck on sizing or materials? Reply to this email or %s, a real person answers.', 'yeffoprint-core' ),
		'<a href="' . esc_url( $telegram_url ) . '">' . esc_html__( 'message us on Telegram', 'yeffoprint-core' ) . '</a>'
	);
} else {
	esc_html_e( 'Stuck on sizing or materials? Just reply to this email, a real person answers.', 'yeffoprint-core' );
}
?></p>

<p class="yp-cart-optout"><?php
echo esc_html( $is_last
	? __( "This is our last reminder about this cart.", 'yeffoprint-core' )
	: __( "You're getting this because you started an order with us.", 'yeffoprint-core' ) );
?> <a href="<?php echo esc_url( $optout_url ); ?>"><?php esc_html_e( "Don't remind me again", 'yeffoprint-core' ); ?></a></p>

<?php
do_action( 'woocommerce_email_footer', $email );
