<?php namespace TierPricingTable\Addons\PluginsRecommendations;

use TierPricingTable\Admin\Tips\Tip;

class CancellationSurveysPlugin extends Tip {
	
	public function __construct() {
		parent::__construct();
		
		add_action( 'woocommerce_subscriptions_product_options_pricing', array( $this, 'render' ), 10, 1 );
		
		add_filter( 'tiered_pricing_table/admin/tips/get_tip_by_slug', function ( $tip, $slug ) {
			if ( $slug === $this->getSlug() ) {
				return $this;
			}
			
			return $tip;
		}, 10, 2 );
	}
	
	public function render() {
		
		if ( $this->isSeen() ) {
			return;
		}
		
		?>
		<div class="">
			<div class="tiered-pricing-tip"
				 style="margin: 12px; padding: 10px; background: #fafafa; border: 1px solid #eeeeee; display: flex; gap: 10px; justify-content: space-between">
				<div style="display:flex; gap: 10px; ">
					<div style="color: #2272b1; margin: 0 5px;">
						<span class="dashicons dashicons-admin-post"></span>
					</div>
					<div>

						<div>
							<strong>
								<?php esc_html_e( 'Tip', 'tier-pricing-table' ); ?>:
							</strong>
							You can <b>increase retention for subscriptions</b> by offering discounts and
							collecting feedback with surveys when customers consider canceling.
						</div>

						<div style="margin-top: 10px;">
							Offer a discount to customers who are considering canceling their subscription. You can also
							collect feedback from customers who have canceled their subscription to understand why they
							left.
						</div>

						<div style="margin-top: 10px;">
							<a target="_blank"
							   href="https://woocommerce.com/products/cancellation-survey-and-offers-for-woocommerce-subscriptions/">
								<?php esc_html_e( 'Learn more', 'tier-pricing-table' ); ?>
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

				<div style="white-space: nowrap;">
					<a role="button" href="<?php echo esc_attr( $this->getMarkAsSeenURL() ); ?>"
					   class="tiered-pricing-tip-close-button">
						&times; <?php esc_html_e( 'Hide this tip', 'tier-pricing-table' ); ?>
					</a>
				</div>
			</div>
		</div>
		<?php
	}
	
	public function getSlug(): string {
		return 'cancellation-surveys-recommendation';
	}
}
