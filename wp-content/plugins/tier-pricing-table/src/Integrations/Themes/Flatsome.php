<?php namespace TierPricingTable\Integrations\Themes;

class Flatsome {

	public function __construct() {
		add_action('wp_head', function () {
			?>
			<style>
				.tiered-pricing-table tbody td {
					padding: 10px;
				}

				.tiered-pricing-table th {
					padding-left: 10px;
				}
			</style>
			<?php
		});
	}
}
