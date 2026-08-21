<?php namespace TierPricingTable\Addons\TaxSettings\RoleBasedRulesIntegration;

use TierPricingTable\Addons\RoleBasedPricing\RoleBasedPriceManager;
use TierPricingTable\Forms\Form;

class RoleBasedRulesIntegration {

	public function __construct() {
		// Render UI
		add_action( 'tiered_pricing_table/admin/role_based_rules/after_tiered_pricing_rules_field',
				array( $this, 'renderTaxFields' ), 10, 3 );

		// Save Data
		add_action( 'tiered_pricing_table/role_based_rules/save_role_based_rules', array( $this, 'saveTaxFields' ), 10,
				4 );
	}

	public function renderTaxFields( $productId, $role, $loop ) {

		$taxStatus = RoleBasedPriceManager::getProductTaxStatus( $productId, $role );
		$taxClass  = RoleBasedPriceManager::getProductTaxClass( $productId, $role );

		$taxStatusId = Form::getFieldName( 'tax_status', $role, $loop );
		$taxClassId  = Form::getFieldName( 'tax_class', $role, $loop );

		$taxStatusOptions = array(
				''         => __( 'Default', 'tier-pricing-table' ),
				'taxable'  => __( 'Taxable', 'woocommerce' ),
				'shipping' => __( 'Shipping only', 'woocommerce' ),
				'none'     => _x( 'None', 'Tax status', 'woocommerce' ),
		);

		$taxClassOptions = array(
				''         => __( 'Default', 'tier-pricing-table' ),
				'standard' => __( 'Standard', 'woocommerce' ),
		);

		if ( function_exists( 'wc_get_product_tax_class_options' ) ) {
			$classes = wc_get_product_tax_class_options();
			foreach ( $classes as $value => $label ) {
				$taxClassOptions[ $value === '' ? 'standard' : $value ] = $label;
			}
		}

		?>
		<hr style="border-color: #f5f5f5;border-top: none;">
		<p class="form-field">
			<label for="<?php echo esc_attr( $taxStatusId ); ?>">
				<?php esc_html_e( 'Tax status', 'tier-pricing-table' ); ?>
			</label>
			<select name="<?php echo esc_attr( $taxStatusId ); ?>" id="<?php echo esc_attr( $taxStatusId ); ?>">
				<?php foreach ( $taxStatusOptions as $optionValue => $optionLabel ) : ?>
					<option value="<?php echo esc_attr( $optionValue ); ?>" <?php selected( $taxStatus,
							$optionValue ); ?>>
						<?php echo esc_html( $optionLabel ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>

		<p class="form-field">
			<label for="<?php echo esc_attr( $taxClassId ); ?>">
				<?php esc_html_e( 'Tax class', 'tier-pricing-table' ); ?>
			</label>
			<select name="<?php echo esc_attr( $taxClassId ); ?>" id="<?php echo esc_attr( $taxClassId ); ?>">
				<?php foreach ( $taxClassOptions as $optionValue => $optionLabel ) : ?>
					<option value="<?php echo esc_attr( $optionValue ); ?>" <?php selected( $taxClass,
							$optionValue ); ?>>
						<?php echo esc_html( $optionLabel ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
	}

	public function saveTaxFields( $productId, $data, $role, $loop ) {
		$taxStatus = sanitize_text_field( Form::getFieldValue( 'tax_status', $role, $loop, '', $data ) ?? '' );
		$taxClass  = sanitize_text_field( Form::getFieldValue( 'tax_class', $role, $loop, '', $data ) ?? '' );

		update_post_meta( $productId, "_{$role}_tiered_price_tax_status", $taxStatus );
		update_post_meta( $productId, "_{$role}_tiered_price_tax_class", $taxClass );
	}
}
