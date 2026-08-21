<?php namespace TierPricingTable\Integrations\Plugins;

use TierPricingTable\PricingRule;

class AeliaMulticurrency extends PluginIntegrationAbstract {
	
	public function run() {
		add_filter( 'tiered_pricing_table/price/pricing_rule', function ( PricingRule $pricingRule ) {
			
			// Break if Aelia multicurrency is not installed
			if ( ! has_action( 'wc_aelia_cs_convert' ) ) {
				return $pricingRule;
			}
			
			$from_currency = get_option( 'woocommerce_currency' );
			$to_currency   = get_woocommerce_currency();
			
			if ( $pricingRule->isFixed() ) {
				$_rules = [];
				
				foreach ( $pricingRule->getRules() as $quantity => $price ) {
					$_rules[ $quantity ] = apply_filters( 'wc_aelia_cs_convert', $price, $from_currency, $to_currency );
				}
				
				$pricingRule->setRules( $_rules );
			}
			
			return $pricingRule;
			
		}, 9999, 2 );
	}
	
	public function getTitle(): string {
		return 'Aelia Multicurrency';
	}
	
	public function getAuthorURL(): string {
		return 'https://aelia.co/shop/currency-switcher-woocommerce/';
	}
	
	public function getIconURL(): string {
		return $this->getContainer()->getFileManager()->locateAsset( 'admin/integrations/aelia-icon.svg' );
	}
	
	public function getDescription(): string {
		return __( 'Convert and display tiered pricing correctly when using the Aelia Currency Switcher.', 'tier-pricing-table' );
	}
	
	public function getSlug(): string {
		return 'aelia-multicurrency';
	}
	
	public function getIntegrationCategory(): string {
		return 'multicurrency';
	}
}
