<?php
/**
 * Request a Quote Options Integrated Template.
 *
 * This template can be overridden by copying it to yourtheme/tiered-pricing-table/integrated/options.php.
 *
 * @var \TierPricingTable\Addons\RequestAQuote\Models\RequestQuoteForm $form
 * @var int $productId
 * @var string $optionsStyle
 * @var string $buttonHtml
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="tiered-pricing-option tpt-request-quote-integrated-option"
	 onclick="document.getElementById('tpt-raq-link-<?php echo esc_attr( $productId ); ?>').click();">

	<?php if ( in_array( $optionsStyle, array( 'style-3', 'style-4' ) ) ): ?>
	<div class="tiered-pricing-option-inner">
		<?php endif; ?>

		<div class="tiered-pricing-option__checkbox">
			<div class="tiered-pricing-option-checkbox"></div>
		</div>

		<div class="tiered-pricing-option__quantity">
			<strong><?php echo esc_html( $form->getIntegratedLabelText() ); ?></strong>
		</div>

		<div class="tiered-pricing-option__pricing">
			<div class="tiered-pricing-option-price">
				<?php echo wp_kses_post( $buttonHtml ); ?>
			</div>
		</div>

		<?php if ( in_array( $optionsStyle, array( 'style-3', 'style-4' ) ) ): ?>
	</div>
<?php endif; ?>
</div>
