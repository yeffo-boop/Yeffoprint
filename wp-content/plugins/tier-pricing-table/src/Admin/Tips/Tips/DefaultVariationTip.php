<?php namespace TierPricingTable\Admin\Tips\Tips;

use TierPricingTable\Admin\Tips\Tip;

/**
 * Class VariationsPricingCalculationTip
 *
 * @package TierPricingTable\Admin\Tips\Tips
 */
class DefaultVariationTip extends Tip {
	
	public function getSlug(): string {
		return 'default_variation_tip';
	}
	
	public function __construct() {
		parent::__construct();
		
		add_action( 'tiered_pricing_table/admin/before_advance_product_options', array( $this, 'render' ), 999, 1 );
	}
	
	public function render() {
		
		if ( $this->isSeen() ) {
			return;
		}
		
		?>
		<div class="hidden show_if_variable show_if_variable-subscription">
			<div class="tiered-pricing-tip"
				 style="margin: 12px; padding: 10px; background: #fafafa; border: 1px solid #eeeeee; display: flex; gap: 10px; justify-content: space-between">
				<div style="display:flex; gap: 10px; ">
					<div style="color: #2272b1; margin: 0 5px;">
						<span class="dashicons dashicons-admin-post"></span>
					</div>
					<div>
						<strong>
							<?php esc_html_e( 'Tip', 'tier-pricing-table' ); ?>:
						</strong>
						
						<?php 
						esc_html_e( 'You can show tiered pricing even if a variation is not selected.',
							'tier-pricing-table' ); 
						?>

						<div style="margin-top: 10px;">
							<?php 
							echo wp_kses_post( __( 'Tiered pricing is related to each variation, so a variation must be selected to show the pricing table. However, you can show the tiered pricing before users choose a variation by enabling the <strong>default variation</strong> option in the <strong>Additional Options</strong> section below.',
								'tier-pricing-table' ) ); 
							?>
						</div>
						
					</div>
				</div>

				<div style="white-space: nowrap; ">
					<a role="button" href="<?php echo esc_attr( $this->getMarkAsSeenURL() ); ?>"
					   class="tiered-pricing-tip-close-button">
						&times; <?php esc_html_e( 'Hide this tip', 'tier-pricing-table' ); ?>
					</a>
				</div>
			</div>
		</div>
		<?php
		
	}
}
