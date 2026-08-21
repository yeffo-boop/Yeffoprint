<?php namespace TierPricingTable\Addons\GlobalTieredPricing\CPT\Form\Tabs;

use TierPricingTable\Addons\GlobalTieredPricing\CPT\Form\FormTab;
use TierPricingTable\Addons\GlobalTieredPricing\GlobalPricingRule;
use TierPricingTable\Forms\RegularPricingForm;
use TierPricingTable\Forms\TieredPricingRulesForm;

class Pricing extends FormTab {

	public function getId(): string {
		return 'pricing';
	}

	public function getTitle(): string {
		return __( 'Pricing', 'tier-pricing-table' );
	}

	public function getDescription(): string {
		return __( 'Configure regular and tiered pricing rules', 'tier-pricing-table' );
	}

	public function render( GlobalPricingRule $pricingRule ) {
		?>
		<div>
			<?php
				$this->renderSectionTitle( __( 'Base Pricing', 'tier-pricing-table' ), array(
						'description'      => __( 'Override the default regular product prices.',
								'tier-pricing-table' ),
						'only_for_premium' => true,
				) );

				RegularPricingForm::render( null, null, $pricingRule->getRegularPrice(), $pricingRule->getSalePrice(),
						$pricingRule->getPricingType(), $pricingRule->getDiscount(), $pricingRule->getDiscountType() );
			?>
		</div>

		<div>
			<?php
				$this->renderSectionTitle( __( 'Tiered Pricing', 'tier-pricing-table' ) );

				$this->renderHint( __( '<b>Mix & Match:</b> Combines the quantities of different products to reach tiered pricing thresholds, allowing discounts when products are purchased together. <br /><br />
<b>Individually:</b> Treats each product’s quantity separately in pricing calculations, ensuring that each product follows its own pricing tier independently without being combined with others.',
						'tier-pricing-table' ), array( 'show_icon' => false, 'only_for_new_rules' => true ) );

				$this->renderRadioOptions( array(
						'id'      => 'tpt_applying_type',
						'title'   => __( 'Calculation type', 'tier-pricing-table' ),
						'options' => array(
								'individual' => __( 'Individual', 'tier-pricing-table' ),
								'cross'      => __( 'Mix and Match', 'tier-pricing-table' ),
						),
						'value'   => $pricingRule->getApplyingType(),
				), true );

				global $post;

				TieredPricingRulesForm::render( $post->ID, null, null, $pricingRule->getTieredPricingType(),
						$pricingRule->getPercentageTieredPricingRules(), $pricingRule->getFixedTieredPricingRules() );
			?>
		</div>
		<?php
	}

	public function getIcon(): string {
		return '$';
	}
}