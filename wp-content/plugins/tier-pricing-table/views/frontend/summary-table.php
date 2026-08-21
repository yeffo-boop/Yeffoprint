<?php
	/**
	 * Summary table
	 *
	 * @var int $productId
	 * @var string $title
	 * @var string $totalLabel
	 * @var string $eachLabel
	 * @var string $showNonTiered
	 */
	
if ( ! defined( 'WPINC' ) ) {
	die;
}
	
	do_action( 'tiered_pricing_table/product_page/before_table_summary', $totalLabel, $eachLabel );

?>
<div class="clear"></div>
<div class="tier-pricing-summary-table tier-pricing-summary-table--hidden"
	 data-tier-pricing-table-summary
	 data-show-non-tiered="<?php echo esc_attr( isset( $showNonTiered ) ? $showNonTiered : 'no' ); ?>"
	 data-product-id="<?php echo esc_attr( $productId ); ?>">
	<?php if ( $title ) : ?>
		<h4 style=" margin: 20px 0;"><?php echo esc_html( $title ); ?></h4>
	<?php endif; ?>
	<div class="tier-pricing-summary-table__top">
		<div><span data-tier-pricing-table-summary-product-qty></span>x</div>
		<div data-tier-pricing-table-summary-product-price></div>
	</div>
	<div class="tier-pricing-summary-table__bottom">
		<div><b><span data-tier-pricing-table-summary-product-name></span></b></div>
		<div class="tier-pricing-summary-table__total" data-tier-pricing-table-summary-total>
		</div>
	</div>
</div>

<?php do_action( 'tiered_pricing_table/product_page/before_table_summary', $totalLabel, $eachLabel ); ?>
