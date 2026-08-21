<?php namespace TierPricingTable\Services;

/*
 * Class PricingService
 *
 * Service adjusts regular and sale price based on data in Pricing Rule.
 * Built-in addons like role-based and global rules can modify regular and sale prices.
 *
 * @package TierPricingTable\Services
 */

use TierPricingTable\CalculationLogic;
use TierPricingTable\Forms\Form;
use TierPricingTable\PriceManager;
use TierPricingTable\TierPricingTablePlugin;
use WC_Product;

class RegularPricingService {
	
	protected $cachedPrices = array(
		'regular' => array(),
		'sale'    => array(),
		'price'   => array(),
	);
	
	public function __construct() {
		
		// The service should be enabled by addons. This is useful to do not run those services when all pricing addons are disabled
		if ( ! apply_filters( 'tiered_pricing_table/services/pricing_service_enabled', false ) ) {
			return;
		}
		
		add_filter( 'woocommerce_product_get_regular_price', array(
			$this,
			'adjustRegularPrice',
		), 99, 2 );
		add_filter( 'woocommerce_product_get_sale_price', array(
			$this,
			'adjustSalePrice',
		), 99, 2 );
		add_filter( 'woocommerce_product_get_price', array( $this, 'adjustPrice' ), 99, 2 );
		
		// Variations
		add_filter( 'woocommerce_product_variation_get_regular_price', array(
			$this,
			'adjustRegularPrice',
		), 99, 2 );
		add_filter( 'woocommerce_product_variation_get_sale_price', array(
			$this,
			'adjustSalePrice',
		), 99, 2 );
		add_filter( 'woocommerce_product_variation_get_price', array(
			$this,
			'adjustPrice',
		), 99, 2 );
		
		// Variable (price range)
		add_filter( 'woocommerce_variation_prices_price', array( $this, 'adjustPrice' ), 99, 3 );
		// Variation
		add_filter( 'woocommerce_variation_prices_regular_price', array(
			$this,
			'adjustRegularPrice',
		), 99, 3 );
		add_filter( 'woocommerce_variation_prices_sale_price', array(
			$this,
			'adjustSalePrice',
		), 99, 3 );
		
		// Price caching
		add_filter( 'woocommerce_get_variation_prices_hash', function ( $hash, $product ) {
			
			$hash[] = md5( serialize( TierPricingTablePlugin::getCurrentUserRoles() ) );
			
			return $hash;
		}, 99, 2 );
	}
	
	protected function getPrice( ?WC_Product $product, $specific = false, $originalPrice = false ) {
		
		$newPrice = $this->_getPrice( $product, $specific, $originalPrice );
		
		return apply_filters( 'tiered_pricing_table/services/regular_pricing/price', $newPrice, $product, $specific,
			$originalPrice );
	}
	
	protected function _getPrice( ?WC_Product $product, $specific = false, $originalPrice = false ) {
		
		if ( ! $product ) {
			return null;
		}
		
		$pricingRule = PriceManager::getPricingRule( $product->get_id() );
		
		$pricingType  = $pricingRule->pricingData['pricing_type'] ?? null;
		$discount     = $pricingRule->pricingData['discount'] ?? null;
		$discountType = $pricingRule->pricingData['discount_type'] ?? 'sale_price';
		$salePrice    = $pricingRule->pricingData['sale_price'] ?? null;
		$regularPrice = $pricingRule->pricingData['regular_price'] ?? null;
		
		if ( is_null( $discount ) && 'percentage' === $pricingType ) {
			return null;
		}
		
		if ( is_null( $salePrice ) && is_null( $regularPrice ) && 'flat' === $pricingType ) {
			return null;
		}
		
		if ( 'percentage' === $pricingType ) {
			
			// Do not modify the regular price if discount type is "sale_price". Adjust the sale price only.
			if ( 'sale_price' === $discountType && 'regular' === $specific ) {
				return null;
			}
			
			if ( CalculationLogic::calculateDiscountBasedOnRegularPrice() ) {
				$originalPrice = $product->get_regular_price( 'edit' );
			} else {
				$originalPrice = $originalPrice ? $originalPrice : $product->get_price( 'edit' );
			}
			
			// Calculate price based on percentage discount
			if ( $discount && $originalPrice > 0 ) {
				$discountedPrice = $originalPrice - ( ( $originalPrice / 100 ) * $discount );
				
				if ( CalculationLogic::roundPrice() ) {
					$discountedPrice = round( $discountedPrice, max( 2, wc_get_price_decimals() ) );
				}
				
				return $discountedPrice;
			}
		} else {
			if ( $specific ) {
				if ( 'sale' === $specific && ! Form::isEmpty( $salePrice ) ) {
					return $salePrice;
				} elseif ( 'regular' === $specific && ! Form::isEmpty( $regularPrice ) ) {
					return $regularPrice;
				}
			} else {
				if ( ! Form::isEmpty( $salePrice ) ) {
					return $salePrice;
				} elseif ( ! Form::isEmpty( $regularPrice ) ) {
					return $regularPrice;
				}
			}
		}
		
		return null;
	}
	
	public function adjustPrice( $originalPrice, ?WC_Product $product ) {

		if ( ! $product ) {
			return $originalPrice;
		}

		if ( ! TierPricingTablePlugin::isSimpleProductSupported( $product ) ) {
			return $originalPrice;
		}

		if ( $product->get_meta( 'tiered_pricing_cart_price_calculated' ) === 'yes' ) {
			return $originalPrice;
		}
		
		if ( ! $originalPrice && ! apply_filters( 'tiered_pricing_table/services/pricing/override_zero_prices',
				true ) ) {
			return $originalPrice;
		}
		
		$newPrice = $this->getPrice( $product, null, $originalPrice );
		
		return ! is_null( $newPrice ) ? $newPrice : $originalPrice;
	}
	
	public function adjustSalePrice( $price, ?WC_Product $product ) {
		
		if ( ! $product ) {
			return $price;
		}
		
		$newPrice = $this->getPrice( $product, 'sale' );
		
		return ! is_null( $newPrice ) ? (float) $newPrice : $price;
	}
	
	public function adjustRegularPrice( $price, ?WC_Product $product ) {
		
		if ( ! $product ) {
			return $price;
		}
		
		$newPrice = $this->getPrice( $product, 'regular' );
		
		return ! is_null( $newPrice ) ? (float) $newPrice : $price;
	}
}
