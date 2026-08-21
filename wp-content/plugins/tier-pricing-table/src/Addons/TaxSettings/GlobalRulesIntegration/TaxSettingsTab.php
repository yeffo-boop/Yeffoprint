<?php namespace TierPricingTable\Addons\TaxSettings\GlobalRulesIntegration;

use TierPricingTable\Addons\GlobalTieredPricing\CPT\Form\FormTab;
use TierPricingTable\Addons\GlobalTieredPricing\GlobalPricingRule;

class TaxSettingsTab extends FormTab {

	public function getId() {
		return 'tax-settings';
	}

	public function getTitle(): string {
		return __( 'Tax', 'tier-pricing-table' );
	}

	public function getDescription(): string {
		return __( 'Set tax status and class', 'tier-pricing-table' );
	}

	public function render( GlobalPricingRule $pricingRule ) {
		?>
		<div class="tiered-pricing-form-row">
			<div>
				<?php
					$this->renderSectionTitle( __( 'Tax settings', 'tier-pricing-table' ), array(
							'description'      => __( 'Override the default tax status and class for products matching this rule.', 'tier-pricing-table' ),
							'only_for_premium' => true,
					) );

					$this->renderSelect( array(
							'id'       => 'tpt_tax_status',
							'title'    => __( 'Tax status', 'tier-pricing-table' ),
							'options'  => array(
									''         => __( 'Default', 'tier-pricing-table' ),
									'taxable'  => __( 'Taxable', 'tier-pricing-table' ),
									'shipping' => __( 'Shipping only', 'tier-pricing-table' ),
									'none'     => __( 'None', 'tier-pricing-table' ),
							),
							'value'    => $pricingRule->getTaxStatus(),
							'disabled' => ! tpt_fs()->can_use_premium_code__premium_only(),
					), false );

					$tax_classes     = \WC_Tax::get_tax_classes();
					$classes_options = array(
							''         => __( 'Default', 'tier-pricing-table' ),
							'standard' => __( 'Standard', 'tier-pricing-table' ),
					);
					if ( ! empty( $tax_classes ) ) {
						foreach ( $tax_classes as $class ) {
							$classes_options[ \WC_Tax::format_tax_rate_class( $class ) ] = $class;
						}
					}

					$this->renderSelect( array(
							'id'       => 'tpt_tax_class',
							'title'    => __( 'Tax class', 'tier-pricing-table' ),
							'options'  => $classes_options,
							'value'    => $pricingRule->getTaxClass(),
							'disabled' => ! tpt_fs()->can_use_premium_code__premium_only(),
					), false );
				?>
			</div>
		</div>
		<?php
	}

	public function getIcon(): string {
		return '%';
	}
}
