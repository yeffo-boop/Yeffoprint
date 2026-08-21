<?php namespace TierPricingTable\Integrations\Plugins;

class WooCommerceDeposits extends PluginIntegrationAbstract {
	
	public function getTitle(): string {
		return 'WooCommerce Deposits (by WooCommerce)';
	}
	
	public function getDescription(): string {
		return __( 'Calculate deposit amounts and remaining balances correctly based on tiered pricing rules.', 'tier-pricing-table' );
	}
	
	public function getSlug(): string {
		return 'woocommerce-deposits';
	}
	
	public function getAuthorURL(): string {
		return 'https://woocommerce.com/products/woocommerce-deposits/';
	}
	
	public function getIconURL(): string {
		return $this->getContainer()->getFileManager()->locateAsset( 'admin/integrations/woocommerce-develop.jpeg' );
	}
	
	public function run() {
		add_filter( 'tiered_pricing_table/cart/product_cart_price', function ( $new_price, $cart_item, $key ) {

			if ( ! $new_price ) {
				return $new_price;
			}

			// Only WooCommerce Deposits populates these cart-item keys, so their presence gates this to deposit products.
			$cart = wc()->cart;

			if ( ! isset( $cart->cart_contents[ $key ]['full_amount'], $cart->cart_contents[ $key ]['deposit_amount'] ) ) {
				return $new_price;
			}

			$fullAmount    = (float) $cart->cart_contents[ $key ]['full_amount'];
			$depositAmount = (float) $cart->cart_contents[ $key ]['deposit_amount'];

			// Keep the original deposit-to-full ratio and re-apply it to the new tiered price. Guard the
			// zero full amount (e.g. 100%-later plans, where the deposit is 0 too) to avoid dividing by zero.
			$depositPercentage = $fullAmount > 0 ? ( $depositAmount / $fullAmount ) : 0;

			$cart->cart_contents[ $key ]['full_amount']    = $new_price;
			$cart->cart_contents[ $key ]['deposit_amount'] = $new_price * $depositPercentage;

			return $new_price;

		}, 10, 3 );
	}
	
	public function getIntegrationCategory(): string {
		return 'custom_product_types';
	}
}
