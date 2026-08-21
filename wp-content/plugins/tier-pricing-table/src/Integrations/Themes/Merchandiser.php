<?php namespace TierPricingTable\Integrations\Themes;

class Merchandiser {

	public function __construct() {
		add_action( 'wp_head', function () {
			?>

			<style>
				.tiered-pricing-table tbody td {
					padding: 10px !important;
				}

				.tiered-pricing-table th {
					padding-left: 10px !important;
				}
			</style>
   
			<?php
		} );
	}
}
