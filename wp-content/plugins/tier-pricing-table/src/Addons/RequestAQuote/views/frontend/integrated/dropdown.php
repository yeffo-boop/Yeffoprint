<?php
/**
 * Request a Quote Dropdown Integrated Template.
 *
 * This template can be overridden by copying it to yourtheme/tiered-pricing-table/integrated/dropdown.php.
 *
 * @var \TierPricingTable\Addons\RequestAQuote\Models\RequestQuoteForm $form
 * @var int $productId
 * @var string $buttonHtml
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<li class="tiered-pricing-dropdown-option tpt-request-quote-integrated-dropdown-option"
	onclick="document.getElementById('tpt-raq-link-<?php echo esc_attr( $productId ); ?>').click();">

	<div class="tiered-pricing-dropdown-option__quantity">
		<strong><?php echo esc_html( $form->getIntegratedLabelText() ); ?></strong>
	</div>

	<div class="tiered-pricing-dropdown-option__pricing">
		<div class="tiered-pricing-option-price">
			<?php echo wp_kses_post( $buttonHtml ); ?>
		</div>
	</div>
</li>
