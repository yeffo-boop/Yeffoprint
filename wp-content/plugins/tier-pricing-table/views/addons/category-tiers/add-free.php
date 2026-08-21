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
		<p class="form-field" data-tiered-price-type-percentage data-tiered-price-type>
		<span data-price-rules-wrapper>
			<span data-price-rules-container>
				<span data-price-rules-input-wrapper style="display: flex">
					<input type="number" style="margin-right: 10px;" min="2"
						   placeholder="<?php esc_attr_e( 'Quantity', 'tier-pricing-table' ); ?>"
						   class="price-quantity-rule price-quantity-rule--simple"
						   disabled>
					<input type="number" max="99"
						   placeholder="<?php esc_attr_e( 'Percentage discount', 'tier-pricing-table' ); ?>"
						   class="price-quantity-rule--simple"
						   disabled>
			</span>
		</span>
	</span>
		</p>
		<p style="color: red">
			<?php 
			esc_html_e( 'Available only in the premium version (You can disable this block in the settings)',
				'tier-pricing-table' ); 
			?>
			<a target="_blank"
			   href="<?php echo esc_attr( tpt_fs_activation_url() ); ?>">
								<?php 
			   esc_html_e( 'Upgrade',
					'tier-pricing-table' ); 
								?>
			</a>
		</p>
		<br>
	</td>
</tr>
