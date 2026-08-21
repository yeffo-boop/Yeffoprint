<?php
/**
 * Admin Quote Request Email
 *
 * This template can be overridden by copying it to yourtheme/tiered-pricing-table/emails/admin-quote-request.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( $quotePost instanceof \TierPricingTable\Addons\RequestAQuote\Models\QuoteRequest ) {
	$productId = $quotePost->getProductId();
	$product    = wc_get_product( $productId );
} else {
	$product = false;
}

/*
 * @hooked WC_Emails::email_header() Output the email header
 */
do_action( 'woocommerce_email_header', $emailHeading, $email ); ?>

<p><?php esc_html_e( 'A new quote request has been submitted on your store.', 'tier-pricing-table' ); ?></p>

<h2><?php esc_html_e( 'Quote Request Details', 'tier-pricing-table' ); ?></h2>

<div style="margin-bottom: 40px;">
	<table class="td" cellspacing="0" cellpadding="6" style="width: 100%; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif;" border="1">
		<tbody>
			<tr>
				<th class="td" scope="row" style="text-align:left;"><?php esc_html_e( 'Product', 'tier-pricing-table' ); ?></th>
				<td class="td" style="text-align:left;"><?php echo $product ? wp_kses_post( $product->get_name() ) : esc_html__( 'Unknown', 'tier-pricing-table' ); ?></td>
			</tr>
			<?php
			$fields = $email->get_submitted_fields();
			if ( ! empty( $fields ) ) :
				foreach ( $fields as $field ) : ?>
					<tr>
						<th class="td" scope="row" style="text-align:left;"><?php echo esc_html( $field['label'] ); ?></th>
						<td class="td" style="text-align:left;"><?php echo wp_kses_post( nl2br( $field['value'] ) ); ?></td>
					</tr>
				<?php endforeach;
			endif;
			?>
		</tbody>
	</table>
</div>

<p>
	<?php if ( $quotePost instanceof \TierPricingTable\Addons\RequestAQuote\Models\QuoteRequest ) : ?>
		<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $quotePost->getId() . '&action=edit' ) ); ?>">
			<?php esc_html_e( 'View Request in Dashboard', 'tier-pricing-table' ); ?>
		</a>
	<?php else : ?>
		<a href="#">
			<?php esc_html_e( 'View Request in Dashboard', 'tier-pricing-table' ); ?>
		</a>
	<?php endif; ?>
</p>

<?php
/*
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action( 'woocommerce_email_footer', $email );
