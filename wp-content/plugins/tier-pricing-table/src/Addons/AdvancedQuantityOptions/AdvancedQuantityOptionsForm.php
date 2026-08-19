<?php namespace TierPricingTable\Addons\AdvancedQuantityOptions;

use TierPricingTable\Forms\Form;

class AdvancedQuantityOptionsForm {
	
	public function __construct() {
		add_action( 'admin_head', array( $this, 'includeAssets' ) );
	}
	
	public function includeAssets() {
		?>
		<style>
			.tiered_pricing_advanced_quantity_options {
				overflow: hidden;
			}

			.tiered_pricing_advanced_quantity_options__toggle-advanced-options {
				padding-left: 163px;
				font-size: 12px;
				margin-bottom: 10px;
			}

			.tiered_pricing_advanced_quantity_options__toggle-advanced-options a {
				text-decoration: none;
			}

			/* Icon */
			.tiered_pricing_advanced_quantity_options__toggle-advanced-options a span {
				font-size: 12px;
				line-height: 11px;
				height: 12px;
				width: 12px;
				vertical-align: middle;
				transition: all .1s;
			}

			.tiered_pricing_advanced_quantity_options__toggle-advanced-options--open a span {
				transform: rotate(180deg);
				line-height: 14px;
			}

			.tiered_pricing_advanced_quantity_options__advanced-options {
				overflow: hidden;
				display: none;
				position: relative;
			}

			.tiered_pricing_advanced_quantity_options__advanced-options--visible {
				display: block;
			}

			/* Variations adjustment */
			.variable_pricing .tiered_pricing_advanced_quantity_options__advanced-options input {
				margin-top: 6px;
			}

			.variable_pricing .tiered_pricing_advanced_quantity_options__toggle-advanced-options {
				padding-left: 0;
			}
		</style>
		<script>
			jQuery(document).ready(function ($) {
				jQuery(document).on('click', '.tiered_pricing_advanced_quantity_options__toggle-advanced-options > a', function (e) {
					e.preventDefault();
					$(this).parent().toggleClass('tiered_pricing_advanced_quantity_options__toggle-advanced-options--open');
					$(this).closest('.tiered_pricing_advanced_quantity_options').find('.tiered_pricing_advanced_quantity_options__advanced-options').toggleClass('tiered_pricing_advanced_quantity_options__advanced-options--visible')
				});
			});
		</script>
		<?php
	}
	
	public function render( $productId, $loop = null, $role = null, $expandedByDefault = false, $showToggle = true ) {
		
		$maximumFieldName = Form::getFieldName( AdvancedQuantityOptionsAddon::MAXIMUM_QUANTITY_BASE_META_KEY, $role,
			$loop );
		$groupOfFieldName = Form::getFieldName( AdvancedQuantityOptionsAddon::GROUP_OF_QUANTITY_BASE_META_KEY, $role,
			$loop );
		?>

		<div class="tiered_pricing_advanced_quantity_options">
			<?php
				
				$maximum = DataProvider::getMaximumQuantity( $productId, $role, 'edit' );
				$groupOf = DataProvider::getGroupOfQuantity( $productId, $role, 'edit' );
				
				$isVisible = $maximum || $groupOf || $expandedByDefault;
				
				$maximumFieldAttributes = array(
					'min'  => 2,
					'step' => 1,
				);
				
				$groupOfFieldAttributes = array(
					'min'  => 2,
					'step' => 1,
				);
				
				if ( ! tpt_fs()->can_use_premium_code() ) {
					$maximumFieldAttributes['disabled'] = true;
					$groupOfFieldAttributes['disabled'] = true;
				}
				?>
			
			<?php if ( $showToggle ) : ?>
				<div
						class="tiered_pricing_advanced_quantity_options__toggle-advanced-options <?php echo esc_attr( $isVisible ? 'tiered_pricing_advanced_quantity_options__toggle-advanced-options--open' : '' ); ?>">
					<a href="#" role="button">
						<?php
							esc_html_e( 'Additional quantity options', 'tier-pricing-table' );
						?>
						<span class="dashicons dashicons-arrow-down-alt2"></span>
					</a>
				</div>
			<?php endif; ?>

			<div
					class="tiered_pricing_advanced_quantity_options__advanced-options
				<?php echo esc_attr( $isVisible ? 'tiered_pricing_advanced_quantity_options__advanced-options--visible' : '' ); ?>
				<?php echo esc_attr( ! is_null( $loop ) ? 'form-row' : '' ); ?>
			">
				<?php
					woocommerce_wp_text_input( array(
						'id'                => $maximumFieldName,
						'name'              => $maximumFieldName,
						'type'              => 'number',
						'custom_attributes' => $maximumFieldAttributes,
						'style'             => tpt_fs()->can_use_premium_code() ? '' : 'cursor: not-allowed;',
						'value'             => $maximum,
						'placeholder'       => __( 'Leave blank for no restrictions',
							'tier-pricing-table' ),
						'label'             => __( 'Maximum order quantity', 'tier-pricing-table' ),
						'description'       => __( 'The maximum quantity of the product a customer can order in a single transaction.',
							'tier-pricing-table' ),
						'desc_tip'          => true,
					) );
					
					woocommerce_wp_text_input( array(
						'id'                => $groupOfFieldName,
						'name'              => $groupOfFieldName,
						'type'              => 'number',
						'custom_attributes' => $groupOfFieldAttributes,
						'style'             => tpt_fs()->can_use_premium_code() ? '' : 'cursor: not-allowed;',
						'value'             => $groupOf,
						'label'             => __( 'Quantity step', 'tier-pricing-table' ),
						'placeholder'       => __( 'Leave blank for no restrictions',
							'tier-pricing-table' ),
						'description'       => __( 'Requires the product to be purchased in multiples of X.',
							'tier-pricing-table' ),
						'desc_tip'          => true,
					) );
				?>
			</div>

		</div>
		<?php
	}
}