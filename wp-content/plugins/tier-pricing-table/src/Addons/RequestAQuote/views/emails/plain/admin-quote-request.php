<?php
/**
 * Admin Quote Request Email (Plain text)
 *
 * This template can be overridden by copying it to yourtheme/tiered-pricing-table/emails/plain/admin-quote-request.php.
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

echo esc_html__( 'A new quote request has been submitted on your store.', 'tier-pricing-table' ) . "\n\n";

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";

echo esc_html__( 'Product', 'tier-pricing-table' ) . ': ' . ( $product ? wp_kses_post( $product->get_name() ) : esc_html__( 'Unknown', 'tier-pricing-table' ) ) . "\n";

$fields = $email->get_submitted_fields();
if ( ! empty( $fields ) ) {
	foreach ( $fields as $field ) {
		echo esc_html( $field['label'] ) . ': ' . wp_strip_all_tags( $field['value'] ) . "\n";
	}
}

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

if ( $quotePost instanceof \TierPricingTable\Addons\RequestAQuote\Models\QuoteRequest ) {
	echo esc_html__( 'View Request in Dashboard', 'tier-pricing-table' ) . ': ' . esc_url( admin_url( 'post.php?post=' . $quotePost->getId() . '&action=edit' ) ) . "\n\n";
} else {
	echo esc_html__( 'View Request in Dashboard', 'tier-pricing-table' ) . ": #\n\n";
}

echo "\n----------------------------------------\n\n";

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
