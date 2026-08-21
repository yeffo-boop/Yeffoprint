<?php

	use TierPricingTable\Addons\RequestAQuote\Models\RequestQuoteForm;

	/**
	 * Request a Quote Prompt Template.
	 *
	 * This template can be overridden by copying it to yourtheme/tiered-pricing-table/integrated/prompt.php.
	 *
	 * @var RequestQuoteForm $form
	 * @var int $productId
	 * @var string $buttonHtml
	 */

	if ( ! defined( 'ABSPATH' ) ) {
		exit;
	}
?>
<div class="tpt-quote-prompt-wrapper" style="margin-top: 15px; text-align: center;">
	<?php echo wp_kses_post( $buttonHtml ); ?>
</div>
