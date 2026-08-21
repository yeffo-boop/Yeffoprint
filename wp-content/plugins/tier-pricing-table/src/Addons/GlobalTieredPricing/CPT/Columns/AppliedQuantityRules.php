<?php namespace TierPricingTable\Addons\GlobalTieredPricing\CPT\Columns;

use TierPricingTable\Addons\GlobalTieredPricing\GlobalPricingRule;

class AppliedQuantityRules {

	public function getName(): string {
		return __( 'Quantity limits', 'tier-pricing-table' );
	}

	public function render( GlobalPricingRule $rule ) {

		$notSetLabel = __( 'N/A', 'tier-pricing-table' );

		$minimum = $rule->getMinimum() ? $rule->getMinimum() : $notSetLabel;

		?>
		<div style="margin-bottom: 12px;">
			<div style="display: flex; flex-wrap: wrap; gap: 4px;">
				<span style="display: inline-block; padding: 2px 8px; background: #f0f0f1; border-radius: 3px; font-size: 12px; color: #3c434a; border: 1px solid #dcdcdc; line-height: 1.4;">
					<?php esc_html_e( 'Minimum:', 'tier-pricing-table' ); ?> <b><?php echo esc_html( $minimum ? esc_html( $minimum ) : $notSetLabel ); ?></b>
				</span>
			</div>
		</div>
		<?php
	}
}
