<?php namespace TierPricingTable\Addons\TaxSettings\GlobalRulesIntegration;

use TierPricingTable\Addons\GlobalTieredPricing\GlobalPricingRule;

class TaxSettingsColumn {

	public function getName(): string {
		return __( 'Tax settings', 'tier-pricing-table' );
	}

	public function render( GlobalPricingRule $rule ) {
		$notSetLabel = __( 'N/A', 'tier-pricing-table' );

		$taxStatus = $rule->getTaxStatus() ? $rule->getTaxStatus() : $notSetLabel;
		$taxClass  = $rule->getTaxClass() ? $rule->getTaxClass() : $notSetLabel;

		?>
		<div style="margin-bottom: 12px;">
			<div style="display: flex; flex-wrap: wrap; gap: 4px;">
				<span style="display: inline-block; padding: 2px 8px; background: #f0f0f1; border-radius: 3px; font-size: 12px; color: #3c434a; border: 1px solid #dcdcdc; line-height: 1.4;">
					<?php esc_html_e( 'Status:', 'tier-pricing-table' ); ?> <b><?php echo esc_html( $taxStatus ); ?></b>
				</span>
				<span style="display: inline-block; padding: 2px 8px; background: #f0f0f1; border-radius: 3px; font-size: 12px; color: #3c434a; border: 1px solid #dcdcdc; line-height: 1.4;">
					<?php esc_html_e( 'Class:', 'tier-pricing-table' ); ?> <b><?php echo esc_html( $taxClass ); ?></b>
				</span>
			</div>
		</div>
		<?php
	}
}
