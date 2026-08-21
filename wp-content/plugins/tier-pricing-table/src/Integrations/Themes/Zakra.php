<?php namespace TierPricingTable\Integrations\Themes;

class Zakra {
	
	public function __construct() {
		
		add_action( 'wp_head', function () {
			?>
			<style>
				.zak-primary form.cart {
					flex-wrap: wrap;
				}
			</style>
			<?php
		} );
	}
}
