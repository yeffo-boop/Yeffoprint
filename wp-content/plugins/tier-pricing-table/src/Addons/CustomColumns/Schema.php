<?php namespace TierPricingTable\Addons\CustomColumns;

use TierPricingTable\Addons\CustomColumns\Columns\CustomDataColumn;
use TierPricingTable\Addons\CustomColumns\Columns\PriceExcludingTaxesColumn;
use TierPricingTable\Addons\CustomColumns\Columns\PriceIncludingTaxesColumn;
use TierPricingTable\Addons\CustomColumns\Columns\RowTotalPriceColumn;
use TierPricingTable\Addons\CustomColumns\Columns\SavingPerItemColumn;
use TierPricingTable\Addons\CustomColumns\Columns\SkuColumn;

class Schema {
	public static function getAvailableCustomColumnsTypes() {
		return apply_filters( 'tiered_pricing_table/custom_columns/available_columns_types', array(
			'custom'           => __( 'Custom column', 'tier-pricing-table' ),
			'price_excl_taxes' => __( 'Price excluding taxes', 'tier-pricing-table' ),
			'price_incl_taxes' => __( 'Price including taxes', 'tier-pricing-table' ),
			'total_price'      => __( 'Total row price', 'tier-pricing-table' ),
			'saving_per_item'  => __( 'Saving per item', 'tier-pricing-table' ),
		) );
	}
	
	public static function getAvailableDataTypes() {
		return apply_filters( 'tiered_pricing_table/custom_columns/available_data_types', array(
			'price'  => __( 'Price', 'tier-pricing-table' ),
			'number' => __( 'Number', 'tier-pricing-table' ),
			'text'   => __( 'Text', 'tier-pricing-table' ),
		) );
	}
	
	public static function isValidColumnType( $type ): bool {
		return in_array( $type, array_keys( self::getAvailableCustomColumnsTypes() ) );
	}
	
	public static function isValidDataType( $type ): bool {
		return in_array( $type, array_keys( self::getAvailableDataTypes() ) );
	}
	
	public static function getClassForType( $type ) {
		$classes = apply_filters( 'tiered_pricing_table/custom_columns/columns_handlers', array(
			'custom'           => CustomDataColumn::class,
			'price_excl_taxes' => PriceExcludingTaxesColumn::class,
			'price_incl_taxes' => PriceIncludingTaxesColumn::class,
			'total_price'      => RowTotalPriceColumn::class,
			'saving_per_item'  => SavingPerItemColumn::class,
		) );
		
		return $classes[ $type ] ?? null;
	}
}
