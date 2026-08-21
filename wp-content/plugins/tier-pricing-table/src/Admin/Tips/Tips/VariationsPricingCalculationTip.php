<?php namespace TierPricingTable\Admin\Tips\Tips;

use TierPricingTable\Admin\Tips\Tip;

/**
 * Class VariationsPricingCalculationTip
 *
 * @package TierPricingTable\Admin\Tips\Tips
 */
class VariationsPricingCalculationTip extends Tip {
	
	public function getSlug(): string {
		return 'variations_pricing_calculation';
	}
	
	public function __construct() {
		parent::__construct();
		
		add_action( 'tiered_pricing_table/admin/pricing_tab_begin', array( $this, 'render' ), 10, 1 );
	}
	
	public function render() {

		if ( $this->isSeen() ) {
			return;
		}

		$calculationSettingsURL = add_query_arg( array(
			'section' => 'calculation_logic',
		), $this->getContainer()->getSettings()->getLink() );
		
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
						esc_html_e( 'You can make tiered pricing calculations by considering all variations as one product.',
							'tier-pricing-table' ); 
						?>

						<div style="margin-top: 10px;">
							<?php 
							echo wp_kses_post( __( 'For example, if you have a variable product with <strong>3 variations</strong>, the price will be calculated for <strong>a combination of those variations</strong> in the cart, not individually.',
								'tier-pricing-table' ) ); 
							?>
						</div>

						<div style="margin-top: 10px;">
							<a target="_blank"
							   href="<?php echo esc_attr( $calculationSettingsURL ); ?>">
								<?php esc_html_e( 'Find more in the settings', 'tier-pricing-table' ); ?>
								<svg style="
							width: 0.8rem;
							height: 0.8rem;
							stroke: currentColor;
							fill: none;"
									 xmlns='http://www.w3.org/2000/svg'
									 stroke-width='10' stroke-dashoffset='0'
									 stroke-dasharray='0' stroke-linecap='round'
									 stroke-linejoin='round' viewBox='0 0 100 100'>
									<polyline fill="none" points="40 20 20 20 20 90 80 90 80 60"/>
									<polyline fill="none" points="60 10 90 10 90 40"/>
									<line fill="none" x1="89" y1="11" x2="50" y2="50"/>
								</svg>
							</a>
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
