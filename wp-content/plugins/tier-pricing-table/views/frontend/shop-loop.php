<?php
	
	use TierPricingTable\PricingTable;
	
	/**
	 * Shop loop template
	 *
	 * @var string $classes
	 * @var WC_Product $product
	 * @var array $settings
	 * @var string $eachLabel
	 */
	
	
if ( ! defined( 'WPINC' ) ) {
	die;
}
?>

<div class="<?php echo esc_html( $classes ); ?>">
	<?php PricingTable::getInstance()->renderPricingTable( $product->get_id(), null, $settings ); ?>
</div>
