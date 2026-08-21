<?php namespace TierPricingTable\Integrations\Themes;

class Shopkeeper {

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

				.tiered-pricing-table {
					border-collapse: collapse !important;
					padding-left: 10px;
				}
			</style>
			<?php
		} );
	}
}
