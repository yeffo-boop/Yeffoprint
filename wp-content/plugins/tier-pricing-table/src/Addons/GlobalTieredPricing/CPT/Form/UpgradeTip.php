<?php

namespace TierPricingTable\Addons\GlobalTieredPricing\CPT\Form;

use TierPricingTable\Addons\GlobalTieredPricing\CPT\GlobalTieredPricingCPT;
use TierPricingTable\Admin\Tips\Tip;
use TierPricingTable\TierPricingTablePlugin;
use WP_Post;
class UpgradeTip extends Tip {
    public function __construct() {
        parent::__construct();
        add_filter(
            'tiered_pricing_table/admin/tips/get_tip_by_slug',
            function ( $tip, $slug ) {
                if ( $slug === $this->getSlug() ) {
                    return $this;
                }
                return $tip;
            },
            10,
            2
        );
        add_action( 'submitpost_box', function ( WP_Post $post ) {
            if ( GlobalTieredPricingCPT::SLUG !== $post->post_type ) {
                return;
            }
            $this->render();
        } );
    }

    protected function isValid() : bool {
        if ( $this->isSeen() ) {
            return false;
        }
        $activationDate = TierPricingTablePlugin::getPluginActivationDate();
        if ( !$activationDate ) {
            return false;
        }
        // If activation date is more than 2 months ago
        return time() - $activationDate > MONTH_IN_SECONDS * 2;
    }

    public function render() {
        if ( !$this->isValid() ) {
            return;
        }
        ?>
			
		<div>
			<div class="tiered-pricing-tip tiered-pricing-upgrade-tip"
				 style="animation: pulse-animation 2s infinite;border-radius: 5px; margin-bottom: 20px; position: relative; padding: 20px 10px;  background: #fff; border: 1px solid #c3c4c7;">

				<div style="text-align: center" >

					<div style="white-space: nowrap;position: absolute;right: 10px;top: 5px;">
						<a role="button" title="Close the tip"
						   href="<?php 
        echo esc_attr( $this->getMarkAsSeenURL() );
        ?>"
						   style="text-decoration: none;color: #000;font-size: 1.2em;"
						   class="tiered-pricing-tip-close-button">
							&times;
						</a>
					</div>

					<h3 style="margin-top: 5px;">
						🎉 <?php 
        esc_html_e( 'You have got a discount!', 'tier-pricing-table' );
        ?>
					</h3>

					<div style="margin-top: 10px;">
						<?php 
        esc_html_e( 'You\'ve been using the free version for a while now, and we\'d like to offer you an exclusive discount on the premium version!', 'tier-pricing-table' );
        ?>
					</div>

					<div style="margin-top: 10px;">
						<?php 
        echo wp_kses_post( __( 'Use the coupon code below for a <b>20%</b> discount on upgrading your plan.', 'tier-pricing-table' ) );
        ?>
					</div>

					<div style="margin-top: 25px; text-align: center; ">
						<code style="font-size: 1.5em;">GRD20OFF</code>
					</div>

					<div style="margin-top: 20px;text-align:center">
						<a target="_blank"
						   href="<?php 
        echo esc_attr( tpt_fs_activation_url() );
        ?>">
							<?php 
        esc_html_e( 'Upgrade your plan', 'tier-pricing-table' );
        ?>
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
		</div>
		<?php 
    }

    public function getSlug() : string {
        return 'global-rule-2-months-discount';
    }

}
