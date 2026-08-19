<?php namespace TierPricingTable\Addons\MinQuantity;

use TierPricingTable\Addons\AbstractAddon;
use TierPricingTable\PriceManager;
use TierPricingTable\TierPricingTablePlugin;
use WC_Cart;
use WC_Product;

class MinQuantity extends AbstractAddon {

	public function getName(): string {
		return __( 'Minimum order quantity validation', 'tier-pricing-table' );
	}

	public function run() {

		( new StoreApiValidation() )->run();

		add_action( 'woocommerce_before_calculate_totals', function ( WC_Cart $cart ) {
			foreach ( $cart->get_cart_contents() as $cartItemKey => $cartItem ) {
				if ( $cartItem['data'] instanceof WC_Product ) {

					$productId = ! empty( $cartItem['variation_id'] ) ? $cartItem['variation_id'] : $cartItem['product_id'];

					$pricingRule = PriceManager::getPricingRule( $productId );
					$min         = $pricingRule->getMinimum();

					if ( ! $min ) {
						continue;
					}

					$cartQty = ! empty( $cartItem['variation_id'] )
						? $this->getProductCartQuantity( $cartItem['variation_id'], 'variation', $cart )
						: $this->getProductCartQuantity( $cartItem['product_id'], 'product', $cart );

					if ( $cartQty < $min ) {
						$cart->cart_contents[ $cartItemKey ]['quantity'] = $min;

						// translators: %1$s: item name, %2$s: minimum quantity
						wc_add_notice( sprintf( __( 'Minimum quantity for the %1$s is %2$d', 'tier-pricing-table' ),
								$cartItem['data']->get_name(), $min ), 'error' );
					}
				}
			}
		} );

		add_filter( 'woocommerce_quantity_input_args', function ( $args, $product = null ) {

			if ( $product instanceof WC_Product && TierPricingTablePlugin::isSimpleProductSupported( $product ) ) {
				
				if ( is_cart() && ! apply_filters( 'tiered_pricing_table/minimum_quantity/control_cart_quantity_field', true, $product ) ) {
					return $args;
				}

				$pricingRule = PriceManager::getPricingRule( $product->get_id() );
				$min         = $pricingRule->getMinimum();

				if ( ! $min ) {
					return $args;
				}

				if ( is_cart() ) {
					$args['min_value'] = $min;
				} else {
					$min               = max( 1, $min - $this->getProductCartQuantity( $product->get_id() ) );
					$args['min_value'] = $min;
				}
			}

			return $args;
		}, 9999, 2 );

		add_filter( 'woocommerce_add_to_cart_validation', function ( $passed, $productId, $quantity, $variationId = 0 ) {
			$productId = intval( $productId );
			$quantity  = intval( $quantity );

			if ( $variationId ) {
				$variationRule = PriceManager::getPricingRule( $variationId );
				$varMin        = $variationRule->getMinimum();
				if ( $varMin ) {
					$remainingMin = max( 1, $varMin - $this->getProductCartQuantity( $variationId, 'variation' ) );
					if ( $quantity < $remainingMin ) {
						wc_add_notice( sprintf( __( 'Minimum quantity for the product is %s', 'tier-pricing-table' ), $varMin ), 'error' );
						return false;
					}
				}
				
				$pricingRule = PriceManager::getPricingRule( $productId );
				if ( $pricingRule->isMixAndMatchMinQuantity() ) {
					return $passed;
				}
			} else {
				$pricingRule = PriceManager::getPricingRule( $productId );
				$min         = $pricingRule->getMinimum();
				
				if ( $min ) {
					$cartQty = $this->getProductCartQuantity( $productId, 'product' );
					$remainingMin = max( 1, $min - $cartQty );

					if ( $quantity < $remainingMin ) {
						// translators: %s: minimum quantity
						wc_add_notice( sprintf( __( 'Minimum quantity for the product is %s', 'tier-pricing-table' ), $min ), 'error' );
						return false;
					}
				}
			}

			return $passed;

		}, 10, 4 );

		add_filter( 'woocommerce_update_cart_validation', function ( $passed, $cart_item_key, $values, $quantity ) {

			$product = $values['data'] ?? null;

			if ( ! ( $product instanceof WC_Product ) ) {
				return $passed;
			}

			$pricingRule = PriceManager::getPricingRule( $product->get_id() );

			if ( ! $pricingRule->getMinimum() ) {
				return $passed;
			}

			if ( $quantity && $quantity < $pricingRule->getMinimum() ) {

				// translators: %s: minimum quantity
				wc_add_notice( sprintf( __( 'Minimum quantity for the product is %s', 'tier-pricing-table' ),
						$pricingRule->getMinimum() ), 'error' );

				return false;
			}

			return $passed;

		}, 10, 4 );

		add_filter( 'woocommerce_available_variation', function ( $variation ) {
			$pricingRule = PriceManager::getPricingRule( (int) $variation['variation_id'] );

			$min = $pricingRule->getMinimum();

			if ( ! $min ) {
				return $variation;
			}

			$min = max( 1, $min - $this->getProductCartQuantity( (int) $variation['variation_id'], 'variation' ) );

			$variation['min_qty']   = $min;
			$variation['qty_value'] = $min;

			return $variation;
		} );

		add_action( 'woocommerce_check_cart_items', function () {
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
						// translators: 1: Product name, 2: Minimum quantity.
						wc_add_notice( sprintf( __( 'Minimum quantity for %1$s is %2$s.', 'tier-pricing-table' ),
							$cartItem['data']->get_name(), $itemMin ), 'error' );
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
						// translators: 1: Minimum quantity, 2: Product name.
						wc_add_notice( sprintf( __( 'You need to order at least %1$s items of %2$s to meet the minimum order quantity.',
								'tier-pricing-table' ), $pricingRule->getMinimum(),
								$product ? $product->get_name() : '' ), 'error' );
					}
				}
			}
		} );

		add_action( 'woocommerce_add_to_cart',
				function ( $cart_item_key, $productId, $quantity, $variationId, $variation, $cartItemData ) {
					if ( $variationId ) {
						$parentPricingRule = PriceManager::getPricingRule( $productId );
						if ( $parentPricingRule->isMixAndMatchMinQuantity() && $parentPricingRule->getMinimum() ) {
							$cartQty = $this->getProductCartQuantity( $productId, 'product' );
							$min     = $parentPricingRule->getMinimum();
							if ( $cartQty < $min ) {
								$missing = $min - $cartQty;
								$product = wc_get_product( $productId );
								// translators: 1: Missing quantity, 2: Product name, 3: Minimum quantity.
								wc_add_notice( sprintf( __( 'You need %1$s more items of %2$s to meet the minimum order quantity of %3$s.',
										'tier-pricing-table' ), $missing, $product ? $product->get_name() : '', $min ),
										'notice' );
							}
						}
					}
				}, 10, 6 );

		add_action( 'wp_head', function () {

			if ( ! is_product() ) {
				return;
			}

			?>
			<script>
				// Handle Minimum Quantities by Tiered Pricing Table
				(function ($) {

					$(document).on('found_variation', function (event, variation) {
						if (typeof variation.qty_value !== "undefined") {
							// update quantity field with a new minimum
							$('form.cart').find('[name=quantity]').val(variation.qty_value)
						}

						if (typeof variation.min_qty !== "undefined") {
							// update quantity field with a new minimum
							$('form.cart').find('[name=quantity]').attr('min', variation.min_qty);
						}
					});

				})(jQuery);
			</script>
			<?php
		} );
	}

	protected function getProductCartQuantity( $productId, $type = 'product', $cart = null ) {
		$qty = 0;

		$cart = $cart ? $cart : wc()->cart;

		if ( $cart && is_array( $cart->cart_contents ) ) {
			foreach ( $cart->cart_contents as $cartItem ) {

				if ( 'variation' === $type ) {
					$compare = ! empty( $cartItem['variation_id'] ) ? $cartItem['variation_id'] : 0;
				} else {
					$compare = $cartItem['product_id'];
				}

				if ( $compare == $productId ) {
					$qty += $cartItem['quantity'];
				}
			}
		}

		return apply_filters( 'tiered_pricing_table/minimum_quantity/item_quantity', $qty, $productId );
	}

	public function getDescription(): string {
		return __( 'Enforce minimum order quantities for products based on tiered pricing rules.',
				'tier-pricing-table' );
	}

	public function getIcon(): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1zm16 16c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z"/></svg>';
	}

	public function getSlug(): string {
		return 'minimum-quantity';
	}
}
