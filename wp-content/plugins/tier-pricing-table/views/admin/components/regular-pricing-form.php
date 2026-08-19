<?php
	
	use TierPricingTable\Forms\Form;
	
if ( ! defined( 'WPINC' ) ) {
	die;
}
		/**
	 * Available variables
	 *
	 * @var float $regular_price
	 * @var float $sale_price
	 * @var float $discount
	 * @var float $discount_type
	 * @var string $pricing_type
	 *
	 * @var string $role
	 * @var string $loop
	 */

?>

<div class="tpt_regular_pricing_form <?php echo esc_attr( ! is_null( $loop ) ? 'form-row' : '' ); ?>">
	
	<p class="form-field">
		
		<label for="<?php echo esc_attr( Form::getFieldName( 'pricing_type', $role, $loop ) ); ?>">
			<?php esc_attr_e( 'Pricing type', 'tier-pricing-table' ); ?>
		</label>
		<?php if ( ! is_null( $loop ) ) : ?>
			<select name="<?php echo esc_attr( Form::getFieldName( 'pricing_type', $role, $loop ) ); ?>"
					id="<?php echo esc_attr( Form::getFieldName( 'pricing_type', $role, $loop ) ); ?>"
					data-tiered-price-pricing-type-selector>
				<option value="flat" <?php selected( 'flat', $pricing_type ); ?>>
					<?php esc_attr_e( 'Flat prices', 'tier-pricing-table' ); ?>
				</option>
				<option value="percentage" <?php selected( 'percentage', $pricing_type ); ?>>
					<?php esc_attr_e( 'Percentage discount', 'tier-pricing-table' ); ?>
				</option>
			</select>
		<?php else : ?>
			<label for="<?php echo esc_attr( Form::getFieldName( 'pricing_type', $role, $loop ) ); ?>-flat"
				   style="padding: 0; float: none; width: auto; margin: 0;">
				<input type="radio"
					   data-tiered-price-pricing-type-selector
					   style="margin-right: 3px;"
					   value="flat"
					<?php checked( 'flat', $pricing_type ); ?>
					   name="<?php echo esc_attr( Form::getFieldName( 'pricing_type', $role, $loop ) ); ?>"
					   id="<?php echo esc_attr( Form::getFieldName( 'pricing_type', $role, $loop ) ); ?>-flat"
				>
				<?php esc_attr_e( 'Flat prices', 'tier-pricing-table' ); ?>
			</label>
			
			<label for="<?php echo esc_attr( Form::getFieldName( 'pricing_type', $role, $loop ) ); ?>-percentage"
				   style="padding: 0; float: none; width: auto; margin: 0 5px 0 20px;">
				<input type="radio"
					   data-tiered-price-pricing-type-selector
					   value="percentage"
					   style="margin-right: 3px;"
					<?php checked( 'percentage', $pricing_type ); ?>
					   name="<?php echo esc_attr( Form::getFieldName( 'pricing_type', $role, $loop ) ); ?>"
					   id="<?php echo esc_attr( Form::getFieldName( 'pricing_type', $role, $loop ) ); ?>-percentage"
				>
				<?php esc_attr_e( 'Percentage discount', 'tier-pricing-table' ); ?>
			</label>
		<?php endif; ?>
	</p>
	
	<p class="form-field tiered_pricing_discount_field <?php echo 'flat' === $pricing_type ? 'hidden' : ''; ?>"
	   data-tiered-price-pricing-type
	   data-tiered-price-pricing-type-percentage>
		
		<label for="<?php echo esc_attr( Form::getFieldName( 'discount', $role, $loop ) ); ?>">
			<?php esc_attr_e( 'Discount (%)', 'tier-pricing-table' ); ?>
		</label>
		
		<input <?php echo ! tpt_fs()->can_use_premium_code() ? 'disabled' : ''; ?>
			type="number"
			min="0"
			max="100"
			step="any"
			value="<?php echo esc_attr( $discount ); ?>"
			placeholder="<?php esc_attr_e( 'Leave blank to not apply any', 'tier-pricing-table' ); ?>"
			id="<?php echo esc_attr( Form::getFieldName( 'discount', $role, $loop ) ); ?>"
			name="<?php echo esc_attr( Form::getFieldName( 'discount', $role, $loop ) ); ?>">
	</p>
	
	<p class="form-field tiered_pricing_discount_type_field <?php echo 'flat' === $pricing_type ? 'hidden' : ''; ?>"
	   data-tiered-price-pricing-type
	   data-tiered-price-pricing-type-percentage>
		
		<label for="<?php echo esc_attr( Form::getFieldName( 'discount_type', $role, $loop ) ); ?>">
			<?php esc_attr_e( 'Set discounted price as', 'tier-pricing-table' ); ?>
		</label>
		
		<label for="<?php echo esc_attr( Form::getFieldName( 'discount_type', $role, $loop ) ); ?>-sale_price"
			   style="padding: 0; float: none; width: auto; margin: 0;">
			<input type="radio"
				   style="margin-right: 3px;"
				   value="sale_price"
				<?php checked( 'sale_price', $discount_type ); ?>
				   name="<?php echo esc_attr( Form::getFieldName( 'discount_type', $role, $loop ) ); ?>"
				   id="<?php echo esc_attr( Form::getFieldName( 'discount_type', $role, $loop ) ); ?>-sale_price"
			>
			<?php esc_attr_e( 'Sale price', 'tier-pricing-table' ); ?>
		</label>
		
		
		<label for="<?php echo esc_attr( Form::getFieldName( 'discount_type', $role, $loop ) ); ?>-regular_price"
			   style="padding: 0; float: none; width: auto; margin: 0 5px 0 20px;">
			<input type="radio"
				   value="regular_price"
				   style="margin-right: 3px;"
				<?php checked( 'regular_price', $discount_type ); ?>
				   name="<?php echo esc_attr( Form::getFieldName( 'discount_type', $role, $loop ) ); ?>"
				   id="<?php echo esc_attr( Form::getFieldName( 'discount_type', $role, $loop ) ); ?>-regular_price"
			>
			<?php esc_attr_e( 'Regular price', 'tier-pricing-table' ); ?>
		</label>
	</p>
	
	<p class="form-field <?php echo 'percentage' === $pricing_type ? 'hidden' : ''; ?>"
	   data-tiered-price-pricing-type
	   data-tiered-price-pricing-type-flat>
		
		<label for="<?php echo esc_attr( Form::getFieldName( 'regular_price', $role, $loop ) ); ?>">
			<?php
				echo esc_attr( __( 'Regular price',
						'tier-pricing-table' ) . ' (' . get_woocommerce_currency_symbol() . ')' );
				?>
		</label>
		
		<input <?php echo ! tpt_fs()->can_use_premium_code() ? 'disabled' : ''; ?>
			type="text"
			value="<?php echo esc_attr( wc_format_localized_price( $regular_price ) ); ?>"
			placeholder="<?php esc_attr_e( 'Leave blank to keep unchanged', 'tier-pricing-table' ); ?>"
			class="wc_input_price"
			name="<?php echo esc_attr( Form::getFieldName( 'regular_price', $role, $loop ) ); ?>"
			id="<?php echo esc_attr( Form::getFieldName( 'regular_price', $role, $loop ) ); ?>">
	</p>
	
	<p class="form-field <?php echo 'percentage' === $pricing_type ? 'hidden' : ''; ?>"
	   data-tiered-price-pricing-type
	   data-tiered-price-pricing-type-flat>
		<label for="<?php echo esc_attr( Form::getFieldName( 'sale_price', $role, $loop ) ); ?>">
			<?php
				echo esc_attr( __( 'Sale price', 'tier-pricing-table' ) . ' (' . get_woocommerce_currency_symbol() . ')' );
			?>
		</label>
		
		<input <?php echo ! tpt_fs()->can_use_premium_code() ? 'disabled' : ''; ?>
			type="text"
			value="<?php echo esc_attr( wc_format_localized_price( $sale_price ) ); ?>"
			placeholder="<?php esc_attr_e( 'Leave blank to keep unchanged', 'tier-pricing-table' ); ?>"
			class="wc_input_price"
			id="<?php echo esc_attr( Form::getFieldName( 'sale_price', $role, $loop ) ); ?>"
			name="<?php echo esc_attr( Form::getFieldName( 'sale_price', $role, $loop ) ); ?>">
	</p>
</div>
