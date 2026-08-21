<?php namespace TierPricingTable\Addons\UserBasedPricing;

use TierPricingTable\Forms\Form;

class UserBasedPriceManager {
	
	public static function userHasRules( int $userId, int $productId, string $context = 'view' ): bool {
		
		$metadataToCheck = apply_filters( 'tiered_pricing_table/user_based_rules/rule_exists_meta', array(
			'_tiered_price_rules_type',
			'_tiered_price_pricing_type',
		), $userId );
		
		$productUserRulesExists = false;
		
		foreach ( $metadataToCheck as $metaKey ) {
			if ( metadata_exists( 'post', $productId, "_user_{$userId}{$metaKey}" ) ) {
				$productUserRulesExists = true;
				
				break;
			}
		}
		
		return $productUserRulesExists;
	}
	
	public static function deleteAllDataForUser( $productId, $userId ) {
		
		delete_post_meta( $productId, "_user_{$userId}_tiered_price_regular_price" );
		delete_post_meta( $productId, "_user_{$userId}_tiered_price_sale_price" );
		delete_post_meta( $productId, "_user_{$userId}_tiered_price_discount" );
		delete_post_meta( $productId, "_user_{$userId}_tiered_price_discount_type" );
		delete_post_meta( $productId, "_user_{$userId}_tiered_price_pricing_type" );
		
		delete_post_meta( $productId, "_user_{$userId}_percentage_price_rules" );
		delete_post_meta( $productId, "_user_{$userId}_fixed_price_rules" );
		delete_post_meta( $productId, "_user_{$userId}_tiered_price_rules_type" );
		
		delete_post_meta( $productId, "_user_{$userId}_tiered_price_minimum_qty" );
		
		delete_post_meta( $productId, "_user_{$userId}_tiered_price_tax_status" );
		delete_post_meta( $productId, "_user_{$userId}_tiered_price_tax_class" );
		
		do_action( 'tiered_pricing_table/user_based_rules/delete_user_rule', $productId, $userId );
	}
	
	/**
	 * Return empty array if rules do not exist.
	 */
	public static function getFixedPriceRules( int $productId, int $userId, string $context = 'view' ): array {
		return self::getPriceRules( $productId, $userId, 'fixed', $context );
	}
	
	/**
	 * Return an empty array if rules do not exist.
	 */
	public static function getPercentagePriceRules( int $productId, int $userId, string $context = 'view' ): array {
		return self::getPriceRules( $productId, $userId, 'percentage', $context );
	}
	
	public static function getPriceRules(
		int $productId,
		int $userId,
		?string $type = null,
		string $context = 'view'
	): array {
		
		$type = $type ? $type : self::getPricingType( $productId, $userId, 'fixed', $context );
		
		if ( 'fixed' === $type ) {
			$rules = (array) get_post_meta( $productId, "_user_{$userId}_fixed_price_rules", true );
		} else {
			$rules = (array) get_post_meta( $productId, "_user_{$userId}_percentage_price_rules", true );
		}
		
		$rules = ! empty( $rules ) ? array_filter( $rules ) : array();
		ksort( $rules );
		
		if ( 'edit' !== $context ) {
			
			$rules = apply_filters( 'tiered_pricing_table/user_based_rules/price/product_price_rules', $rules,
				$productId, $type );
		}
		
		return $rules;
	}
	
	public static function getPricingType(
		int $productId,
		int $userId,
		string $default = 'fixed',
		string $context = 'view'
	): string {
		
		$type = get_post_meta( $productId, "_user_{$userId}_tiered_price_rules_type", true );
		
		$type = in_array( $type, array( 'fixed', 'percentage' ) ) ? $type : $default;
		
		if ( 'edit' !== $context ) {
			return apply_filters( 'tiered_pricing_table/user_based_rules/price/type', $type, $userId, $productId );
		}
		
		return $type;
	}
	
	public static function getProductQtyMin( int $productId, int $userId, string $context = 'view' ): ?int {
		
		$minimum = get_post_meta( $productId, "_user_{$userId}_tiered_price_minimum_qty", true );
		$minimum = ! Form::isEmpty( $minimum ) ? intval( $minimum ) : null;
		
		if ( 'view' === $context ) {
			return apply_filters( 'tiered_pricing_table/user_based_rules/price/minimum', $minimum, $userId, $productId );
		}
		
		return $minimum;
	}
	
	public static function getProductRegularUserPrice(
		int $productId,
		int $userId,
		string $context = 'view'
	): ?float {
		
		$price = get_post_meta( $productId, "_user_{$userId}_tiered_price_regular_price", true );
		
		$price = ! Form::isEmpty( $price ) ? floatval( $price ) : null;
		
		if ( 'edit' !== $context ) {
			return apply_filters( 'tiered_pricing_table/user_based_rules/price/regular_price', $price, $userId,
				$productId );
		}
		
		return $price;
	}
	
	public static function getProductSaleUserPrice( int $productId, int $userId, string $context = 'view' ): ?float {
		
		$price = get_post_meta( $productId, "_user_{$userId}_tiered_price_sale_price", true );
		
		$price = ! Form::isEmpty( $price ) ? floatval( $price ) : null;
		
		if ( 'edit' !== $context ) {
			return apply_filters( 'tiered_pricing_table/user_based_rules/price/sale_price', $price, $userId, $productId );
		}
		
		return $price;
	}
	
	public static function getProductDiscount( int $productId, int $userId, string $context = 'view' ): ?float {
		$discount = get_post_meta( $productId, "_user_{$userId}_tiered_price_discount", true );
		
		$discount = ! Form::isEmpty( $discount ) ? floatval( $discount ) : null;
		
		if ( 'edit' !== $context ) {
			return apply_filters( 'tiered_pricing_table/user_based_rules/price/discount', $discount, $userId,
				$productId );
		}
		
		return $discount;
	}
	
	public static function getProductDiscountType( int $productId, int $userId, string $context = 'view' ): string {
		$discountType = get_post_meta( $productId, "_user_{$userId}_tiered_price_discount_type", true );
		
		if ( 'edit' !== $context ) {
			return apply_filters( 'tiered_pricing_table/user_based_rules/price/discount_type', $discountType, $userId,
				$productId );
		}
		
		return in_array( $discountType, array( 'sale_price', 'regular_price' ) ) ? $discountType : 'sale_price';
	}
	
	public static function getProductPricingType( int $productId, int $userId, string $context = 'view' ): string {
		$pricingType = get_post_meta( $productId, "_user_{$userId}_tiered_price_pricing_type", true );
		
		$pricingType = in_array( $pricingType, array( 'flat', 'percentage' ) ) ? $pricingType : 'flat';
		
		if ( 'edit' !== $context ) {
			return apply_filters( 'tiered_pricing_table/user_based_rules/price/pricing_type', $pricingType, $userId,
				$productId );
		}
		
		return $pricingType;
	}
	
	public static function getProductTaxStatus( int $productId, int $userId, string $context = 'view' ): string {
		$taxStatus = get_post_meta( $productId, "_user_{$userId}_tiered_price_tax_status", true );
		
		if ( 'edit' !== $context ) {
			return apply_filters( 'tiered_pricing_table/user_based_rules/price/tax_status', $taxStatus, $userId, $productId );
		}
		
		return $taxStatus;
	}
	
	public static function getProductTaxClass( int $productId, int $userId, string $context = 'view' ): string {
		$taxClass = get_post_meta( $productId, "_user_{$userId}_tiered_price_tax_class", true );
		
		if ( 'edit' !== $context ) {
			return apply_filters( 'tiered_pricing_table/user_based_rules/price/tax_class', $taxClass, $userId, $productId );
		}
		
		return $taxClass;
	}
}
