<?php namespace TierPricingTable\Addons\GlobalTieredPricing\CPT\Form\Tabs;

use TierPricingTable\Addons\GlobalTieredPricing\CPT\Form\FormTab;
use TierPricingTable\Addons\GlobalTieredPricing\GlobalPricingRule;

class Settings extends FormTab {
	
	public function getId(): string {
		return 'settings';
	}
	
	public function getTitle(): string {
		return __( 'Priority Settings', 'tier-pricing-table' );
	}
	
	public function getDescription(): string {
		return __( 'Manage how this rule interacts with product-level settings', 'tier-pricing-table' );
	}
	
	public function render( GlobalPricingRule $pricingRule ) {
		
		$this->renderSectionTitle( __( 'Priority Settings', 'tier-pricing-table' ) );
		
		$this->renderHint( __( 'Determine which rules take precedence when a product has both global and individual settings.',
			'tier-pricing-table' ) );
		
		$this->renderRadioOptions( array(
			'id'      => '_tpt_settings_priority_type',
			'title'   => __( 'Rule priority', 'tier-pricing-table' ),
			'options' => array(
				'default'        => __( 'Use global settings', 'tier-pricing-table' ),
				'prefer-product' => __( 'Prefer product-level rules',
					'tier-pricing-table' ),
				'override'       => __( 'Override product-level rules',
					'tier-pricing-table' ),
				'flexible'       => __( 'Custom component priorities',
					'tier-pricing-table' ),
			),
			'value'   => $pricingRule->getSettings()->getPriorityType(),
		) );
		
		?>

		<div class="tpt_settings_advanced_priority_settings hidden">
			<?php
				$this->renderSectionTitle( __( 'Base Prices Priority', 'tier-pricing-table' ) );
			?>
			<div class="tpt_settings_regular_pricing">
				<?php
					$this->renderRadioOptions( array(
						'id'      => '_tpt_settings_regular_pricing_priority_type',
						'title'   => __( 'Base prices', 'tier-pricing-table' ),
						'options' => array(
							'prefer-role-based-product' => __( 'Prefer product-level role prices',
								'tier-pricing-table' ),
							'override'                  => __( 'Override all product-level prices',
								'tier-pricing-table' ),
						),
						'value'   => $pricingRule->getSettings()->getRegularPricingPriority(),
					) );
					
					$this->renderHint( __( 'Prioritize product-specific role pricing over this global rule.',
						'tier-pricing-table' ) );
				?>
			</div>
			
			<?php
				$this->renderSectionTitle( __( 'Tiered Prices Priority', 'tier-pricing-table' ) );
			?>

			<div class="tpt_settings_tiered_pricing">
				<?php
					$this->renderRadioOptions( array(
						'id'      => '_tpt_settings_tiered_pricing_priority_type',
						'title'   => __( 'Tiered prices', 'tier-pricing-table' ),
						'options' => array(
							'prefer-product'            => __( 'Prefer product-level tiers',
								'tier-pricing-table' ),
							'prefer-role-based-product' => __( 'Prefer product-level role tiers',
								'tier-pricing-table' ),
							'override'                  => __( 'Override all product-level tiers',
								'tier-pricing-table' ),
						),
						'value'   => $pricingRule->getSettings()->getTieredPricingPriority(),
					) );
					
					$this->renderCheckbox( array(
						'title' => __( 'Mix & Match', 'tier-pricing-table' ),
						'id'    => '_tpt_settings_tiered_pricing_allow_mix_and_match',
						'value' => $pricingRule->getSettings()->isAllowTieredPricingMixAndMatch(),
						'label' => __( 'Enable Mix & Match for product-level tiers',
							'tier-pricing-table' ),
					) );
					
					$this->renderHint( __( 'Prioritize product-specific tiered pricing over this global rule, and optionally enable Mix & Match for those inherited tiers.',
						'tier-pricing-table' ) );
				?>
			</div>
			
			<?php
				$this->renderSectionTitle( __( 'Quantity Limits Priority', 'tier-pricing-table' ) );
			?>

			<div class="tpt_settings_quantity_limits">
				<?php
					$this->renderRadioOptions( array(
						'id'      => '_tpt_settings_quantity_limits_priority_type',
						'title'   => __( 'Quantity limits', 'tier-pricing-table' ),
						'options' => array(
							'prefer-product'            => __( 'Prefer product-level limits',
								'tier-pricing-table' ),
							'prefer-role-based-product' => __( 'Prefer product-level role limits',
								'tier-pricing-table' ),
							'override'                  => __( 'Override all product-level limits',
								'tier-pricing-table' ),
						),
						'value'   => $pricingRule->getSettings()->getQuantityLimitsPriority(),
					) );
					
					$this->renderHint( __( 'Prioritize product-specific quantity limits over this global rule.',
						'tier-pricing-table' ) );
				?>
			</div>
		</div>

		<script>
			jQuery(document).ready(function (jQuery) {
				jQuery('[name=_tpt_settings_priority_type]').on('change', function () {

					if (jQuery(this).val() === 'flexible') {
						jQuery('.tpt_settings_advanced_priority_settings').removeClass('hidden');
					} else {
						jQuery('.tpt_settings_advanced_priority_settings').addClass('hidden');
					}
				}).filter(':checked').trigger('change');
			});
		</script>
		
		<?php
	}
	
	public function getIcon(): string {
		return 'dashicons-admin-settings';
	}
}