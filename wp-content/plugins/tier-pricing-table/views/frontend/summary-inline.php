<?php
	/**
	 * Summary inline
	 *
	 * @var int $productId
	 * @var string $totalLabel
	 * @var string $eachLabel
	 * @var string $title
	 * @var string $showNonTiered
	 */
	
if ( ! defined( 'WPINC' ) ) {
	die;
}
	
	do_action( 'tiered_pricing_table/summary/before_inline_summary', $totalLabel, $eachLabel );

?>
<div class="clear"></div>
<div class="tier-pricing-summary-table tier-pricing-summary-table--inline tier-pricing-summary-table--hidden"
	 data-tier-pricing-table-summary
	 data-show-non-tiered="<?php echo esc_attr( isset( $showNonTiered ) ? $showNonTiered : 'no' ); ?>"
	 data-product-id="<?php echo esc_attr( $productId ); ?>">
	<?php if ( $title ) : ?>
		<h4 style="margin: 20px 0;"><?php echo esc_html( $title ); ?></h4>
	<?php endif; ?>
	<b><span class="tier-pricing-summary-table-inline__label"><?php echo esc_html( $totalLabel ); ?></span></b> <span
			data-tier-pricing-table-summary-total></span>
	<br>
	<b><span class="tier-pricing-summary-table-inline__label"><?php echo esc_html( $eachLabel ); ?></span></b> <span
			data-tier-pricing-table-summary-product-price></span>
	<br>
</div>

<?php do_action( 'tiered_pricing_table/summary/after_inline_summary', $totalLabel, $eachLabel ); ?>
