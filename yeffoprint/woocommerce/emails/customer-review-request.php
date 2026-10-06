<?php
/**
 * Review request (customer) email, sent a couple of days after an order
 * shows delivered by yeffoprint-core's class-review-request.php. Same
 * header/footer/styles as every other email in this theme; reuses the
 * Delivered email's yp-review-cta star card and the abandoned-cart
 * yp-cart-* item rows from email-styles.php.
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
<p><?php
printf(
	/* translators: %s: order number */
	esc_html__( 'Your order #%s arrived a couple of days ago, and we hope it turned out just how you pictured it. Would you take a minute to tell us how we did?', 'yeffoprint-core' ),
	esc_html( $order_number )
);
?></p>

<?php if ( $lines ) : ?>
<table class="yp-cart-items" role="presentation" cellpadding="0" cellspacing="0" width="100%">
	<?php foreach ( $lines as $line ) : ?>
	<tr>
		<?php if ( ! empty( $line['image'] ) ) : ?>
		<td class="yp-cart-thumb" width="84"><img src="<?php echo esc_url( $line['image'] ); ?>" alt="" width="72" /></td>
		<?php endif; ?>
		<td class="yp-cart-line"><span class="yp-cart-line-name"><?php echo esc_html( $line['name'] ); ?></span></td>
	</tr>
	<?php endforeach; ?>
	<?php if ( $more ) : ?>
	<tr><td class="yp-cart-line" colspan="2"><span class="yp-cart-line-meta"><?php
		/* translators: %d: number of other items */
		echo esc_html( sprintf( _n( '+ %d more item', '+ %d more items', $more, 'yeffoprint-core' ), $more ) );
	?></span></td></tr>
	<?php endif; ?>
</table>
<?php endif; ?>

<table class="yp-review-cta" role="presentation" cellpadding="0" cellspacing="0" width="100%">
	<tr><td>
		<span class="yp-review-cta-label"><?php esc_html_e( 'How did we do?', 'yeffoprint-core' ); ?></span>
		<span class="yp-review-cta-title"><?php esc_html_e( 'Tap a star to rate your order', 'yeffoprint-core' ); ?></span>
		<div class="yp-review-cta-stars"><?php
		foreach ( $star_urls as $rating => $url ) {
			printf(
				'<a class="yp-review-cta-star" href="%1$s" title="%2$s">&#9733;</a>',
				esc_url( $url ),
				/* translators: %d: star rating */
				esc_attr( sprintf( _n( '%d star', '%d stars', $rating, 'yeffoprint-core' ), $rating ) )
			);
		}
		?></div>
		<a class="yp-review-cta-button" href="<?php echo esc_url( $review_url ); ?>"><?php esc_html_e( 'Leave a quick review →', 'yeffoprint-core' ); ?></a>
		<span class="yp-review-cta-sub"><?php esc_html_e( 'Takes a minute. Add a photo of your labels if you like, we love seeing them!', 'yeffoprint-core' ); ?></span>
	</td></tr>
</table>

<p><?php esc_html_e( 'Something not right with your order? Just reply to this email and a real person will make it right.', 'yeffoprint-core' ); ?></p>

<?php
do_action( 'woocommerce_email_footer', $email );
