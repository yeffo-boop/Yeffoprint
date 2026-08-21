<?php

	use TierPricingTable\Addons\RequestAQuote\Models\RequestQuoteForm;

	/**
	 * Request a Quote Button Template.
	 *
	 * This template can be overridden by copying it to yourtheme/tiered-pricing-table/integrated/quote-button.php.
	 *
	 * @var RequestQuoteForm $form
	 * @var int $productId
	 * @var string $classes
	 * @var string $idAttr
	 * @var string $autoOpenQty
	 * @var string $style
	 * @var string $content
	 */

	if ( ! defined( 'ABSPATH' ) ) {
		exit;
	}
?>
<a href="#" class="<?php echo esc_attr( $classes ); ?>"
   id="<?php echo esc_attr( $idAttr ); ?>"
   role="button"
   data-form-id="<?php echo esc_attr( $form->getId() ); ?>"
   data-product-id="<?php echo esc_attr( $productId ); ?>"
   data-auto-open-quantity="<?php echo esc_attr( $autoOpenQty ); ?>"
   style="<?php echo esc_attr( $style ); ?>">
	<?php echo wp_kses_post( $content ); ?>
</a>
