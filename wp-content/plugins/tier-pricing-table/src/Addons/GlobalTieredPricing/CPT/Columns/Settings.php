<?php namespace TierPricingTable\Addons\GlobalTieredPricing\CPT\Columns;

use TierPricingTable\Addons\GlobalTieredPricing\GlobalPricingRule;
use TierPricingTable\CalculationLogic;

class Settings {
	
	public function getName(): string {
		return __( 'Priority Settings', 'tier-pricing-table' );
	}
	
	public function render( GlobalPricingRule $rule ) {
		
		$prioritySlug = $rule->getSettings()->getPriorityType();
		
		if ( $prioritySlug === 'default' ) {
			$realPriority = CalculationLogic::globalRulesOverrideProductLevelRules() ? 'override' : 'prefer-product';
		} else {
			$realPriority = $prioritySlug;
		}
		
		$priorities = array(
			'default'                   => __( 'Global', 'tier-pricing-table' ),
			'prefer-product'            => __( 'Prefer Product', 'tier-pricing-table' ),
			'prefer-role-based-product' => __( 'Prefer Product', 'tier-pricing-table' ),
			'override'                  => __( 'Override', 'tier-pricing-table' ),
			'flexible'                  => __( 'Flexible', 'tier-pricing-table' ),
		);
		
		
		if ( ! array_key_exists( $prioritySlug, $priorities ) ) {
			return;
		}
		
		?>
		<div style="margin-bottom: 12px;">
			<div style="display: flex; flex-wrap: wrap; gap: 4px;">
				<span style="display: inline-block; background: #e0f0fa; color: #0070bc; border: 1px solid #bae0ff; padding: 4px 10px; border-radius: 4px; font-size: 13px; font-weight: 500; line-height: 1.4;">
					<?php echo esc_html( $priorities[ $realPriority ] ); ?>
				</span>
			</div>

			<?php if ( $prioritySlug === 'flexible' ) : ?>
				<div style="margin-top: 8px; display: flex; flex-direction: column; gap: 4px; align-items: flex-start;">
					<span style="display: inline-block; padding: 2px 8px; background: #f0f0f1; border-radius: 3px; font-size: 12px; color: #3c434a; border: 1px solid #dcdcdc; line-height: 1.4;">
						<?php esc_html_e( 'Regular prices:', 'tier-pricing-table' ); ?> <b><?php echo esc_html( $priorities[ $rule->getSettings()->getRegularPricingPriority() ] ); ?></b>
					</span>
					<span style="display: inline-block; padding: 2px 8px; background: #f0f0f1; border-radius: 3px; font-size: 12px; color: #3c434a; border: 1px solid #dcdcdc; line-height: 1.4;">
						<?php esc_html_e( 'Tiered pricing:', 'tier-pricing-table' ); ?> <b><?php echo esc_html( $priorities[ $rule->getSettings()->getTieredPricingPriority() ] ); ?></b>
					</span>
					<span style="display: inline-block; padding: 2px 8px; background: #f0f0f1; border-radius: 3px; font-size: 12px; color: #3c434a; border: 1px solid #dcdcdc; line-height: 1.4;">
						<?php esc_html_e( 'Quantity limits:', 'tier-pricing-table' ); ?> <b><?php echo esc_html( $priorities[ $rule->getSettings()->getQuantityLimitsPriority() ] ); ?></b>
					</span>
				</div>
			<?php endif; ?>
		</div>
		
		<?php
	}
}
