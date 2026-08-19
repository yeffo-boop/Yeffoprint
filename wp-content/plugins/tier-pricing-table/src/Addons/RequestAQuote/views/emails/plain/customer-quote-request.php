<?php
/**
 * Customer Quote Request Email (Plain text)
 *
 * This template can be overridden by copying it to yourtheme/tiered-pricing-table/emails/plain/customer-quote-request.php.
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

echo "= " . esc_html( $emailHeading ) . " =\n\n";

echo esc_html__( 'Thank you for your quote request. We have received it and will process it shortly. Here are the details of your request:', 'tier-pricing-table' ) . "\n\n";

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";

echo esc_html__( 'Product', 'tier-pricing-table' ) . ': ' . ( $product ? wp_kses_post( $product->get_name() ) : esc_html__( 'Unknown', 'tier-pricing-table' ) ) . "\n";

$fields = $email->get_submitted_fields();
if ( ! empty( $fields ) ) {
	foreach ( $fields as $field ) {
		echo esc_html( $field['label'] ) . ': ' . wp_strip_all_tags( $field['value'] ) . "\n";
	}
}

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

echo esc_html__( 'We will be in touch soon!', 'tier-pricing-table' ) . "\n\n";

echo "\n----------------------------------------\n\n";

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
