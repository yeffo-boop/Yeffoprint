<?php namespace TierPricingTable\Integrations\Plugins;

use TierPricingTable\PricingRule;

class WooPaymentsMulticurrency extends PluginIntegrationAbstract {
	
	public function run() {
		add_filter( 'tiered_pricing_table/price/pricing_rule', function ( PricingRule $pricingRule ) {
			
			// Break if WooPayments MultiCurrency is not installed
			if ( ! class_exists( '\WCPay\MultiCurrency\MultiCurrency' ) ) {
				return $pricingRule;
			}
			
			if ( $pricingRule->isFixed() ) {
				$rules = [];
				
				foreach ( $pricingRule->getRules() as $quantity => $price ) {
					$rules[ $quantity ] = \WCPay\MultiCurrency\MultiCurrency::instance()->get_price( $price, 'product' );
				}
				
				$pricingRule->setRules( $rules );
			}
			
			return $pricingRule;
			
		}, 9999, 1 );
	}
	
	public function getTitle(): string {
		return 'WooPayments Multicurrency';
	}
	
	public function getAuthorURL(): string {
		return 'https://woocommerce.com/products/woocommerce-payments/';
	}
	
	public function getIconURL(): string {
		return $this->getContainer()->getFileManager()->locateAsset( 'admin/integrations/woocommerce-develop.jpeg' );
	}
	
	public function getDescription(): string {
		return __( 'Convert and display tiered pricing correctly when using WooPayments Multicurrency.', 'tier-pricing-table' );
	}
	
	public function getSlug(): string {
		return 'woopayments-multicurrency';
	}
	
	public function getIntegrationCategory(): string {
		return 'multicurrency';
	}
}
