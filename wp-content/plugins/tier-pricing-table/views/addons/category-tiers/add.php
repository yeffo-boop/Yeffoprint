<?php if ( ! defined( 'WPINC' ) ) {
	die;
}
	
	/**
	 * Available variables
	 *
	 * @var array $rules
	 */
	
	$prefix = 'category';

?>

<tr class="form-field">
	<th scope="row" valign="top">
		<label for="attribute_label">Tiered pricing</label>
	</th>
	<td>
		
		<style>
			.tpt-category-tiered-pricing-rule {
				display: flex;
				margin-bottom: 15px;
				gap: 10px;
			}

			.tpt-category-tiered-pricing-rule__quantity input, .tpt-category-tiered-pricing-rule__discount input {
				width: 170px !important;
			}

			.tpt-category-tiered-pricing-add-new {
				margin-top: 15px;
				margin-bottom: 15px;
			}
   
			.remove-price-rule {
				display: inline-block;
				position: relative;
				padding: 2px 0 2px 5px;
				outline: none;
				cursor: pointer;
			}
		</style>
		
		<div class="tpt-category-tiered-pricing">
			<div class="tpt-category-tiered-pricing-rules">
				<div class="tpt-category-tiered-pricing-rule">
					<div class="tpt-category-tiered-pricing-rule__quantity">
						<input type="number" style="margin-right: 10px;" min="2"
							   placeholder="<?php esc_attr_e( 'Quantity', 'tier-pricing-table' ); ?>"
							   class="price-quantity-rule price-quantity-rule--simple"
							   name="tiered_price_percent_quantity_<?php echo esc_attr($prefix); ?>[]">
					</div>
					<div class="tpt-category-tiered-pricing-rule__discount">
						<input type="number" max="99"
							   placeholder="<?php esc_attr_e( 'Percentage discount', 'tier-pricing-table' ); ?>"
							   class="price-quantity-rule--simple"
							   name="tiered_price_percent_discount_<?php echo esc_attr($prefix); ?>[]"
							   step="any" min="0">
					</div>
					<div class="tpt-category-tiered-pricing-rule__remove">
						<span class="notice-dismiss remove-price-rule">
					</div>
				</div>
			</div>
			<div class="tpt-category-tiered-pricing-add-new">
				<button class="button">
					<?php esc_html_e( 'New tier', 'tier-pricing-table' ); ?>
				</button>
			</div>
		</div>
		
		<p class="description">
			<?php
				esc_attr_e( 'Assign percentage discounts for products that have this category. Rules can be overridden
                in product.', 'tier-pricing-table' );
				?>
			<?php echo wc_help_tip( 'if you are not using this feature you can disable this functionality in the settings to do not complicate the interface' ); ?>
		</p>
		<br>
	</td>
</tr>

<script>
	jQuery('.tpt-category-tiered-pricing-add-new').click(function (e) {
		var $rules = jQuery(e.target).closest('.tpt-category-tiered-pricing').find('.tpt-category-tiered-pricing-rules');

		var $rule = $rules.find('.tpt-category-tiered-pricing-rule').first().clone();

		$rule.find('input').val('');

		$rules.append($rule);
	});

	jQuery('body').on('click', '.tpt-category-tiered-pricing-rule__remove', function (e) {

		e.preventDefault();

		var $rule = jQuery(e.target).closest('.tpt-category-tiered-pricing-rule');

		var $rules = jQuery(e.target).closest('.tpt-category-tiered-pricing-rules');

		if ($rules.find('.tpt-category-tiered-pricing-rule').length < 2) {
			$rule.find('input').val('');
		} else {
			$rule.remove();
		}
	});
</script>