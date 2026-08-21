<?php
/**
 * Request a Quote Horizontal Table Integrated Template.
 *
 * This template can be overridden by copying it to yourtheme/tiered-pricing-table/integrated/horizontal-table.php.
 *
 * @var \TierPricingTable\Addons\RequestAQuote\Models\RequestQuoteForm $form
 * @var int $productId
 * @var bool $hasQty
 * @var bool $hasDiscount
 * @var bool $hasPrice
 * @var string $buttonHtml
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="tiered-pricing-horizontal-table-column tiered-pricing-horizontal-table__values tpt-request-quote-integrated-column"
	 style="cursor: pointer;"
	 onclick="document.getElementById('tpt-raq-table-<?php echo esc_attr( $productId ); ?>').click();">
	<?php if ( $hasQty ) : ?>
		<div class="tiered-pricing-horizontal-table-cell tiered-pricing-horizontal-table-cell--quantity">
			<span><?php echo esc_html( $form->getIntegratedLabelText() ); ?></span>
		</div>
	<?php endif; ?>

	<?php if ( $hasDiscount ) : ?>
		<div class="tiered-pricing-horizontal-table-cell tiered-pricing-horizontal-table-cell--discount">
			<span></span>
		</div>
	<?php endif; ?>

	<?php if ( $hasPrice ) : ?>
		<div class="tiered-pricing-horizontal-table-cell tiered-pricing-horizontal-table-cell--price">
			<span>
				<?php echo wp_kses_post( $buttonHtml ); ?>
			</span>
		</div>
	<?php endif; ?>
</div>
