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

<div class="tier-pricing-summary-table tier-pricing-summary-table--hidden tier-pricing-summary-table--detailed"
	 data-tier-pricing-table-summary
	 data-show-non-tiered="<?php echo esc_attr( isset( $showNonTiered ) ? $showNonTiered : 'no' ); ?>"
	 data-product-id="<?php echo esc_attr( $productId ); ?>">
	
	<?php if ( $title ) : ?>
		<h4 style="margin: 10px 0;">
			<?php echo esc_html( $title ); ?>
		</h4>
	<?php endif; ?>

	<div class="tiered-pricing-totals tiered-pricing-totals--advanced">
		<div style="display: flex; justify-content: space-between; border-top: 1px dashed #f5f5f5; border-bottom: 1px dashed #f5f5f5; padding: 5px 0;">
			<div>
				<span data-tier-pricing-table-summary-product-qty></span>
				<span style="font-size: .9em;">&times;</span>
				<span data-tier-pricing-table-summary-product-name></span>
			</div>
			<div>
				<del style="margin-right: 5px;"><span data-tier-pricing-table-summary-product-old-price></span></del>
				<span style="font-size: 1.15em" data-tier-pricing-table-summary-product-price></span>
			</div>
		</div>

		<div style="display: flex; justify-content: space-between; font-size: 1.3em; margin-top:5px">
			<div>
				<?php echo esc_html( $totalLabel ); ?>
			</div>
			<div>
				<span data-tier-pricing-table-summary-total-with-tax></span>
			</div>
		</div>

	</div>
</div>


<?php do_action( 'tiered_pricing_table/product_page/before_table_summary', $totalLabel, $eachLabel ); ?>
