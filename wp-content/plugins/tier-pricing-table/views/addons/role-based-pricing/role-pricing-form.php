<?php

	use TierPricingTable\Addons\RoleBasedPricing\RoleBasedPricingRule;
	use TierPricingTable\Core\ServiceContainer;
	use TierPricingTable\Forms\MinimumOrderQuantityForm;
	use TierPricingTable\Forms\RegularPricingForm;
	use TierPricingTable\Forms\TieredPricingRulesForm;

	if ( ! defined( 'WPINC' ) ) {
		die;
	}

	/**
	 * Available variables
	 *
	 * @var WC_Product $product
	 * @var RoleBasedPricingRule $pricing_rule
	 * @var int $loop
	 */

	$fileManager = ServiceContainer::getInstance()->getFileManager();

?>

<?php if ( ! tpt_fs()->can_use_premium_code() ) : ?>
	<p style="color: red;    background: #f6f6f6;
    margin: 0;
    width: 100%;
    padding: 16px;">
		<?php esc_html_e( 'Premium feature.', 'tier-pricing-table' ); ?>
		<a target="_blank" href="<?php echo esc_attr( tpt_fs_activation_url() ); ?>">
			<?php esc_html_e( 'Upgrade to premium', 'tier-pricing-table' ); ?>
		</a>
	</p>
<?php endif; ?>

<?php
	if ( ! $product->is_type( 'variable' ) ) {
		RegularPricingForm::render( $pricing_rule->getRole(), $loop, $pricing_rule->getRegularPrice(),
				$pricing_rule->getSalePrice(), $pricing_rule->getPricingType(), $pricing_rule->getDiscount(),
				$pricing_rule->getDiscountType() );
		?>
		<hr style="border-color: #f5f5f5;border-top: none;">
		<?php
	}

	MinimumOrderQuantityForm::render( $pricing_rule->getRole(), $loop, $pricing_rule->getMinimumOrderQuantity() );

	do_action( 'tiered_pricing_table/admin/role_based_rules/after_minimum_order_quantity_field',
			$pricing_rule->getProductId(), $pricing_rule->getRole(), $loop );

?>
<hr style="border-color: #f5f5f5;border-top: none;">
<?php

	TieredPricingRulesForm::render( $pricing_rule->getProductId(), $pricing_rule->getRole(), $loop,
			$pricing_rule->getTieredPricingType(), $pricing_rule->getPercentageTieredPricingRules(),
			$pricing_rule->getFixedTieredPricingRules() );

	do_action( 'tiered_pricing_table/admin/role_based_rules/after_tiered_pricing_rules_field',
			$pricing_rule->getProductId(), $pricing_rule->getRole(), $loop );
?>
