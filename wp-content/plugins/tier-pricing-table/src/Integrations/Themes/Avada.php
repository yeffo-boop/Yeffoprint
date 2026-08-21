<?php namespace TierPricingTable\Integrations\Themes;

class Avada {
	
	public function __construct() {
		add_action( 'wp_head', function () {
			?>
			<!--Compatibility with Avada theme-->
			<style>
				.tiered-pricing-table tbody tr {
					height: inherit !important;
				}

				.tiered-pricing-table tbody td {
					padding: 15px 0 15px 10px !important;
				}

				.tiered-pricing-table th {
					padding-left: 10px !important;
				}
			</style>
			<?php
		} );
	}
}
