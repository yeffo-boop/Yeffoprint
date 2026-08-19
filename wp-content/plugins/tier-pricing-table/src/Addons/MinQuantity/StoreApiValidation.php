<?php namespace TierPricingTable\Addons\MinQuantity;

use TierPricingTable\PriceManager;
use WC_Product;

class StoreApiValidation {
	
	public function run() {
		add_action( 'woocommerce_store_api_cart_errors', array( $this, 'validateCart' ) );
	}

	public function validateCart( $errorContext ) {
		$cart = wc()->cart;
		if ( ! $cart ) {
			return;
		}

		$parentQuantities = array();

		foreach ( $cart->get_cart_contents() as $cartItem ) {
			if ( $cartItem['data'] instanceof WC_Product ) {
				$itemPricingRule = PriceManager::getPricingRule( $cartItem['data']->get_id() );
				$itemMin = $itemPricingRule->getMinimum();
				if ( $itemMin && $cartItem['quantity'] < $itemMin ) {
					$errorContext->add(
						'tier_pricing_table_min_qty',
						sprintf( __( 'Minimum quantity for %1$s is %2$s.', 'tier-pricing-table' ),
							$cartItem['data']->get_name(), $itemMin )
					);
				}

				$parentId = $cartItem['data']->get_parent_id();
				if ( $parentId ) {
					if ( ! isset( $parentQuantities[ $parentId ] ) ) {
						$parentQuantities[ $parentId ] = 0;
					}
					$parentQuantities[ $parentId ] += $cartItem['quantity'];
				}
			}
		}

		foreach ( $parentQuantities as $parentId => $qty ) {
			$pricingRule = PriceManager::getPricingRule( $parentId );
			if ( $pricingRule->isMixAndMatchMinQuantity() && $pricingRule->getMinimum() ) {
				if ( $qty < $pricingRule->getMinimum() ) {
					$product = wc_get_product( $parentId );
					$errorContext->add(
						'tier_pricing_table_mix_match_min_qty',
						sprintf( __( 'You need to order at least %1$s items of %2$s to meet the minimum order quantity.', 'tier-pricing-table' ),
							$pricingRule->getMinimum(), $product ? $product->get_name() : '' )
					);
				}
			}
		}
	}
}
