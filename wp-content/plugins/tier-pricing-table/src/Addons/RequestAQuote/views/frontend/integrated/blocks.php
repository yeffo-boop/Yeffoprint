<?php
/**
 * Request a Quote Blocks Integrated Template.
 *
 * This template can be overridden by copying it to yourtheme/tiered-pricing-table/integrated/blocks.php.
 *
 * @var \TierPricingTable\Addons\RequestAQuote\Models\RequestQuoteForm $form
 * @var int $productId
 * @var string $blocksStyle
 * @var string $promptText
 * @var string $buttonHtml
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="tiered-pricing-block tpt-request-quote-integrated-block"
	 onclick="document.getElementById('tpt-raq-link-<?php echo esc_attr( $productId ); ?>').click();">
	<?php echo wp_kses_post( $buttonHtml ); ?>

	<?php if ( $blocksStyle === 'style-3' ) : ?>
		<div class="tiered-pricing-block-inner">
			<div class="tiered-pricing-block__price">
				<span><?php echo esc_html( $promptText ); ?></span>
			</div>
			<div class="tiered-pricing-block__quantity">
				<span><?php echo esc_html( $form->getIntegratedLabelText() ); ?></span>
			</div>
		</div>
	<?php elseif ( in_array( $blocksStyle, [ 'style-1', 'style-2' ] ) ) : ?>
		<div class="tiered-pricing-block__quantity">
			<span><?php echo esc_html( $form->getIntegratedLabelText() ); ?></span>
		</div>
		<div class="tiered-pricing-block__price">
			<span><?php echo esc_html( $promptText ); ?></span>
		</div>
	<?php else : // default, style-4, style-5, style-6 ?>
		<div class="tiered-pricing-block__price">
			<span><?php echo esc_html( $promptText ); ?></span>
		</div>
		<span class="tiered-pricing-block__quantity">
			<?php echo esc_html( $form->getIntegratedLabelText() ); ?>
		</span>
	<?php endif; ?>
</div>
