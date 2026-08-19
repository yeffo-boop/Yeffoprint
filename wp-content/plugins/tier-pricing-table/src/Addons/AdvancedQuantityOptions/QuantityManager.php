<?php namespace TierPricingTable\Addons\AdvancedQuantityOptions;

use TierPricingTable\PriceManager;
use TierPricingTable\PricingRule;
use TierPricingTable\TierPricingTablePlugin;
use WC_Product;

class QuantityManager {
	
	public function __construct() {
		
		add_filter( 'tiered_pricing_table/tiered_pricing/last_tier_postfix',
			function ( $postfix, $quantity, PricingRule $pricingRule ) {
				
				if ( $pricingRule->data['maximum_quantity'] && $pricingRule->data['maximum_quantity'] <= $quantity ) {
					return '';
				}
				
				return $postfix;
			}, 10, 3 );
		
		add_filter( 'tiered_pricing_table/price/pricing_rule', function ( PricingRule $pricingRule, $productId ) {
			$pricingRule->data['maximum_quantity']  = DataProvider::getMaximumQuantity( $productId );
			$pricingRule->data['group_of_quantity'] = DataProvider::getGroupOfQuantity( $productId );
			
			return $pricingRule;
		}, 1, 2 );
		
		add_action( 'woocommerce_before_calculate_totals', function ( \WC_Cart $cart ) {
			
			foreach ( $cart->get_cart_contents() as $cartItemKey => $cartItem ) {
				
				if ( $cartItem['data'] instanceof WC_Product ) {
					
					$productId = ! empty( $cartItem['variation_id'] ) ? $cartItem['variation_id'] : $cartItem['product_id'];
					
					$pricingRule = PriceManager::getPricingRule( $productId );
					$max         = $pricingRule->data['maximum_quantity'] ?? null;
					$groupOf     = $pricingRule->data['group_of_quantity'] ?? null;
					
					$cartItemQuantity = $this->getProductCartQuantity( $productId );
					
					if ( $max ) {
						
						$max = max( $groupOf, $max );
						
						if ( $this->getProductCartQuantity( $productId ) > $max ) {
							$cart->cart_contents[ $cartItemKey ]['quantity'] = $max;
							// translators: %1$s: item name, %2$s: minimum quantity
							wc_add_notice( sprintf( __( 'Maximum quantity for the %1$s is %2$d', 'tier-pricing-table' ),
								$cartItem['data']->get_name(), $max ), 'error' );
						}
					}
					
					if ( $groupOf ) {
						
						if ( 0 !== $cartItemQuantity % $groupOf ) {
							// translators: %s: quantity step
							wc_add_notice( sprintf( __( 'Order quantity must be multiple of %s', 'tier-pricing-table' ),
								$groupOf ), 'error' );
							
							$cart->cart_contents[ $cartItemKey ]['quantity'] = ceil( $cartItemQuantity / $groupOf ) * $groupOf;
						}
					}
				}
			}
		}, 10, 1 );
		
		add_filter( 'woocommerce_quantity_input_args', function ( $args, $product ) {
			
			if ( $product instanceof WC_Product && TierPricingTablePlugin::isSimpleProductSupported( $product ) ) {
				
				$pricingRule = PriceManager::getPricingRule( $product->get_id() );
				
				$max     = $pricingRule->data['maximum_quantity'] ?? null;
				$groupOf = $pricingRule->data['group_of_quantity'] ?? null;
				
				if ( $max ) {
					
					if ( ! is_cart() && ! is_checkout() ) {
						$max = max( 1, $max - $this->getProductCartQuantity( $product->get_id() ) );
					}
					
					$max = max( $groupOf, $max );
					
					$args['max_value'] = $max;
				}
				
				if ( $groupOf ) {
					$args['step']      = $groupOf;
					$args['min_value'] = max( $args['min_value'] ?? 1, $groupOf );
					$args['value']     = max( $args['value'] ?? 1, $groupOf );
				}
			}
			
			return $args;
		}, 9999999, 2 );
		
		add_filter( 'woocommerce_add_to_cart_validation', function ( $passed, $_productId, $qty, $variationId = null ) {
			
			$productId = $variationId ? $variationId : $_productId;
			
			// Do not show additional notices if there are already notices
			if ( ! $passed ) {
				return false;
			}
			
			$pricingRule = PriceManager::getPricingRule( $productId );
			
			$max     = $pricingRule->data['maximum_quantity'] ?? null;
			$groupOf = $pricingRule->data['group_of_quantity'] ?? null;
			
			if ( $max ) {
				
				$max = max( $groupOf, $max );
				
				if ( $qty + $this->getProductCartQuantity( $_productId ) > $max ) {
					
					// translators: %s: maximum quantity
					wc_add_notice( sprintf( __( 'Maximum order quantity for the product is %s', 'tier-pricing-table' ),
						$max ), 'error' );
					
					return false;
				}
			}
			
			if ( $groupOf ) {
				
				if ( 0 !== $qty % $groupOf ) {
					// translators: %s: quantity step
					wc_add_notice( sprintf( __( 'Order quantity must be multiple of %s', 'tier-pricing-table' ),
						$groupOf ), 'error' );
					
					return false;
				}
			}
			
			return $passed;
			
		}, 10, 4 );
		
		add_action( 'wp_head', function () {
			if ( is_product() ) {
				?>
				<script>
					jQuery(document).ready(function () {

						let $quantity = jQuery('.single_variation_wrap').find('[name=quantity]');

						jQuery(document).on('found_variation', function (e, variation) {

							if (variation.step) {
								$quantity.attr('step', variation.step);
								$quantity.data('step', variation.step);
							} else {
								$quantity.attr('step', 1);
								$quantity.data('step', 1);
							}

							if (variation.max_qty) {
								$quantity.attr('max', variation.max_qty);
								$quantity.data('max', variation.max_qty);
							} else {
								$quantity.removeAttr('max');
							}
						});

						jQuery(document).on('reset_data', function () {
							// Do not remove step attr - it can be used for some themes for +\- buttons
							$quantity.attr('step', 1);
							$quantity.data('step', 1);

							$quantity.removeAttr('max');
						});
					});
				</script>
				<?php
			}
		} );
		
		add_filter( 'woocommerce_update_cart_validation', function ( $passed, $cart_item_key, $values, $quantity ) {
			$product = $values['data'] ?? null;
			
			if ( ! ( $product instanceof WC_Product ) ) {
				return $passed;
			}
			
			$pricingRule = PriceManager::getPricingRule( $product->get_id() );
			
			$max     = $pricingRule->data['maximum_quantity'] ? intval( $pricingRule->data['maximum_quantity'] ) : null;
			$groupOf = $pricingRule->data['group_of_quantity'] ? intval( $pricingRule->data['group_of_quantity'] ) : null;
			
			if ( $max ) {
				
				if ( $quantity > $max ) {
					
					// translators: %s: maximum quantity
					wc_add_notice( sprintf( __( 'Maximum order quantity for the product is %s', 'tier-pricing-table' ),
						$max ), 'error' );
					
					return false;
				}
			}
			
			if ( $groupOf ) {
				
				if ( 0 !== $quantity % $groupOf ) {
					
					// translators: %s: quantity step
					wc_add_notice( sprintf( __( 'Order quantity must be multiple of %s', 'tier-pricing-table' ),
						$groupOf ), 'error' );
					
					return false;
				}
			}
			
			return $passed;
			
		}, 10, 4 );
		
		add_filter( 'woocommerce_available_variation', function ( $variation ) {
			
			$pricingRule = PriceManager::getPricingRule( $variation['variation_id'] );
			
			$max     = $pricingRule->data['maximum_quantity'] ?? null;
			$groupOf = $pricingRule->data['group_of_quantity'] ?? null;
			
			if ( $max ) {
				$max = max( 1, $max - $this->getProductCartQuantity( $variation['variation_id'], true ) );
				
				if ( $groupOf ) {
					$max = max( $groupOf, $max );
				}
				
				$variation['max_qty'] = $max;
			}
			
			if ( $groupOf ) {
				$variation['step']      = $groupOf;
				$variation['min_qty']   = max( $variation['min_qty'], $groupOf );
				$variation['qty_value'] = max( $variation['min_qty'], $groupOf );
			}
			
			return $variation;
		}, 999 );
	}
	
	public function getProductCartQuantity( $productId, $isVariation = false ) {
		$qty = 0;
		
		if ( is_array( wc()->cart->cart_contents ) ) {
			foreach ( wc()->cart->cart_contents as $cart_content ) {
				
				$compare = $isVariation ? ( $cart_content['variation_id'] ?? false ) : $cart_content['product_id'];
				
				if ( $compare == $productId ) {
					$qty += $cart_content['quantity'];
				}
				
			}
		}
		
		return $qty;
	}
}