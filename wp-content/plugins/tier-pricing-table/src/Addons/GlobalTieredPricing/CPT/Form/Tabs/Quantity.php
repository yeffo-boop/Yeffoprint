<?php namespace TierPricingTable\Addons\GlobalTieredPricing\CPT\Form\Tabs;

use TierPricingTable\Addons\GlobalTieredPricing\CPT\Form\FormTab;
use TierPricingTable\Addons\GlobalTieredPricing\GlobalPricingRule;
use TierPricingTable\Forms\MinimumOrderQuantityForm;

class Quantity extends FormTab {

	public function getId(): string {
		return 'quantity';
	}

	public function getTitle(): string {
		return __( 'Quantity limits', 'tier-pricing-table' );
	}

	public function getDescription(): string {
		return __( 'Set minimum, maximum, and quantity step limits', 'tier-pricing-table' );
	}

	public function render( GlobalPricingRule $pricingRule ) {

		$this->renderSectionTitle( __( 'Quantity Limits', 'tier-pricing-table' ), array(
				'only_for_premium' => true,
				'description'      => __( 'Applies to each product individually. If mix & match is enabled, the minimum quantity requirement will be shared across variations of a variable product.',
						'tier-pricing-table' ),
		) );

		MinimumOrderQuantityForm::render( null, null, $pricingRule->getMinimum() );

		do_action( 'tiered_pricing_table/global_pricing/after_minimum_order_quantity_field', $pricingRule->getId(),
				$pricingRule );

		$this->renderSectionTitle( __( 'Variable Product Minimum Quantity', 'tier-pricing-table' ), array(
				'description'      => __( 'Determine whether the minimum quantity requirement applies to each variation separately, or to the combined total of all variations in the cart.', 'tier-pricing-table' ),
		) );

		$mixAndMatchValue = $pricingRule->getMixAndMatchMinQuantity();
		$mixAndMatchString = is_null( $mixAndMatchValue ) ? '' : ( $mixAndMatchValue ? 'yes' : 'no' );

		woocommerce_wp_select( array(
				'id'          => 'mix_and_match_minimum',
				'value'       => $mixAndMatchString,
				'options'     => array(
						''    => __( 'Global Settings', 'tier-pricing-table' ),
						'no'  => __( 'Individual variation', 'tier-pricing-table' ),
						'yes' => __( 'Variable product total (Mix & match)', 'tier-pricing-table' ),
				),
				'label'       => __( 'Calculate minimum quantity by', 'tier-pricing-table' ),
		) );

		?>
		<style>
			#mix_and_match_minimum {
				width: 75% !important;
			}
		</style>
		<?php
	}

	public function getIcon(): string {
		return 'dashicons-database';
	}
}
