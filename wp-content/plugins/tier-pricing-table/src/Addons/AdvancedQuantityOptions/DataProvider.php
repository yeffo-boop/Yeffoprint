<?php namespace TierPricingTable\Addons\AdvancedQuantityOptions;

use TierPricingTable\Forms\Form;
use TierPricingTable\TierPricingTablePlugin;

class DataProvider {
	
	protected static $cache = array();
	
	protected static function sanitize( $type, $value ) {
		
		switch ( $type ) {
			case 'maximum':
				if ( Form::isEmpty( $value ) ) {
					return null;
				}
				
				return max( intval( $value ), 1 );
			case 'group_of':
				if ( Form::isEmpty( $value ) ) {
					return null;
				}
				
				return max( intval( $value ), 2 );
			default:
				return null;
		}
	}
	
	protected static function update( $productId, $type, $value, $role = false ) {
		$metaKey = self::getMetaKey( $type, $role );
		
		$value = self::sanitize( $type, $value );
		
		if ( $metaKey ) {
			update_post_meta( $productId, $metaKey, $value );
		}
	}
	
	public static function getMetaKey( $type, $role = false ) {
		$role = $role ? "_{$role}" : '';
		
		switch ( $type ) {
			case 'maximum':
				return "{$role}" . '_tiered_pricing_' . AdvancedQuantityOptionsAddon::MAXIMUM_QUANTITY_BASE_META_KEY;
			case 'group_of':
				return "{$role}" . '_tiered_pricing_' . AdvancedQuantityOptionsAddon::GROUP_OF_QUANTITY_BASE_META_KEY;
			default:
				return false;
		}
	}
	
	protected static function get( $productId, $type, $role = false, $context = 'view' ) {
		$cacheKey = $productId . $role;
		
		if ( isset( self::$cache[ $cacheKey ][ $type ] ) ) {
			$value = self::$cache[ $cacheKey ][ $type ];
		} else {
			$metaKey = self::getMetaKey( $type, $role );
			
			$value = get_post_meta( $productId, $metaKey, true );
			
			// If product is a variation - check for parent level value
			if ( Form::isEmpty( $value ) && 'edit' !== $context ) {
				$product = wc_get_product( $productId );
				
				if ( $product && TierPricingTablePlugin::isVariationProductSupported( $product ) ) {
					$value = self::get( $product->get_parent_id(), $type, $role, 'edit' );
				}
			}
			
			self::$cache[ $cacheKey ][ $type ] = $value;
		}
		
		$value = Form::isEmpty( $value ) ? null : $value;
		
		if ( 'edit' !== $context ) {
			return apply_filters( 'tiered_pricing_table/advanced_quantity/get_' . $type, $value, $productId, $role );
		}
		
		return $value;
	}
	
	public static function updateMaximumQuantity( $productId, $value, $role = false ) {
		self::update( $productId, 'maximum', $value, $role );
	}
	
	public static function updateGroupOfQuantity( $productId, $value, $role = false ) {
		self::update( $productId, 'group_of', $value, $role );
	}
	
	public static function getMaximumQuantity( $productId, $role = false, $context = 'view' ) {
		return self::get( $productId, 'maximum', $role, $context );
	}
	
	public static function getGroupOfQuantity( $productId, $role = false, $context = 'view' ) {
		return self::get( $productId, 'group_of', $role, $context );
	}
	
	public static function updateFromRequest(
		$fieldToUpdate,
		$entityId,
		$role = null,
		$loop = null,
		$customPrefix = null
	) {
		$base = array(
			'maximum'  => AdvancedQuantityOptionsAddon::MAXIMUM_QUANTITY_BASE_META_KEY,
			'group_of' => AdvancedQuantityOptionsAddon::GROUP_OF_QUANTITY_BASE_META_KEY,
		);
		
		if ( ! isset( $base[ $fieldToUpdate ] ) ) {
			return;
		}
		
		$value = Form::getFieldValue( $base[ $fieldToUpdate ], $role, $loop, $customPrefix );
		
		self::update( $entityId, $fieldToUpdate, $value, $role );
	}
	
}
