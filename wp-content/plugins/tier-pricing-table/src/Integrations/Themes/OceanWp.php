<?php namespace TierPricingTable\Integrations\Themes;

class OceanWp {

	public function __construct() {
		add_action('wp_head', function () {
			?>
			<style>
				.tiered-pricing--active .amount {
					color: #fff;
				}
				.tiered-pricing-table  tr {
					background: #fff;
				}
				.tiered-pricing-tooltip-icon {
					vertical-align: text-top;
				}
			</style>
			<?php
		});
	}
}
