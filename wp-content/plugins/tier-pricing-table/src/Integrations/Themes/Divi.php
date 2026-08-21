<?php namespace TierPricingTable\Integrations\Themes;

class Divi {

	public function __construct() {
  
		add_action( 'wp_head', function () {
			?>
			<style>
				.tier-pricing-summary-table {
					margin-bottom: 20px;
				}
			</style>
			<?php
		} );
	}
}
