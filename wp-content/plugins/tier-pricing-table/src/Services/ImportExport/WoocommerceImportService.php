<?php namespace TierPricingTable\Services\ImportExport;

use TierPricingTable\TierPricingTablePlugin;
use WC_Product;

class WoocommerceImportService {
	
	/**
	 * Import constructor.
	 */
	public function __construct() {
		add_filter( 'woocommerce_csv_product_import_mapping_options', array( $this, 'addColumnsToImporter' ) );
		add_filter( 'woocommerce_csv_product_import_mapping_default_columns',
			array( $this, 'addColumnToMappingScreen' ) );
		add_filter( 'woocommerce_product_import_pre_insert_product_object', array( $this, 'processImport' ), 10, 2 );
	}
	
	/**
	 * Register the 'Tiered pricing' column in the importer.
	 */
	public function addColumnsToImporter( array $columns ): array {
		
		$columns['tiered_pricing_table'] = array(
			'name'    => __( 'Tiered Pricing', 'tier-pricing-table' ),
			'options' => array(
				'tiered_price_type'       => __( 'Tiered pricing type', 'tier-pricing-table' ),
				'tiered_price_fixed'      => __( 'Fixed pricing rules', 'tier-pricing-table' ),
				'tiered_price_percentage' => __( 'Percentage pricing rules', 'tier-pricing-table' ),
				'tiered_price_minimum'    => __( 'Minimum order quantity', 'tier-pricing-table' ),
			),
		);
		
		return apply_filters( 'tiered_pricing_table/import/woocommerce/import_columns', $columns );
	}
	
	/**
	 * Add automatic mapping support for 'Tiered pricing'.
	 */
	public function addColumnToMappingScreen( array $columns ): array {
		
		$columns['Tiered Pricing — Fixed pricing rules']      = 'tiered_price_fixed';
		$columns['Tiered Pricing — Percentage pricing rules'] = 'tiered_price_percentage';
		$columns['Tiered Pricing — Tiered pricing type']      = 'tiered_price_type';
		$columns['Tiered Pricing — Minimum order quantity']   = 'tiered_price_minimum';
		
		return apply_filters( 'tiered_pricing_table/import/woocommerce/mapping_screen_columns', $columns );
	}
	
	/**
	 * Process the data read from the CSV file.
	 */
	public function processImport( WC_Product $product, array $data ): WC_Product {
		
		if ( isset( $data['tiered_price_fixed'] ) ) {
			
			$fixed = self::decodeExport( $data['tiered_price_fixed'] );
			
			$product->update_meta_data( '_fixed_price_rules', $fixed );
		}
		
		if ( isset( $data['tiered_price_percentage'] ) ) {
			
			$percentage = self::decodeExport( $data['tiered_price_percentage'] );
			
			$product->update_meta_data( '_percentage_price_rules', $percentage );
		}
		
		if ( isset( $data['tiered_price_type'] ) ) {
			
			if ( in_array( $data['tiered_price_type'], array( 'fixed', 'percentage' ) ) ) {
				$product->update_meta_data( '_tiered_price_rules_type', $data['tiered_price_type'] );
			}
		}
		
		if ( isset( $data['tiered_price_minimum'] ) ) {
			
			$minimum = (int) $data['tiered_price_minimum'];
			
			$product->update_meta_data( '_tiered_price_minimum_qty', $minimum );
		}
		
		return $product;
	}
	
	/**
	 * Decode export string format to array of pricing rules
	 */
	public static function decodeExport( string $data ): array {
		$rules = explode( TierPricingTablePlugin::getRulesSeparator(), $data );
		
		$data = array();
		
		if ( $rules ) {
			foreach ( $rules as $rule ) {
				$rule = explode( ':', $rule );
				
				if ( isset( $rule[0] ) && isset( $rule[1] ) ) {
					$data[ intval( $rule[0] ) ] = $rule[1];
				}
			}
		}
		
		$data = array_filter( $data );
		
		$data = array_filter( $data, function ( $itemKey ) {
			return is_numeric( $itemKey ) && $itemKey > 1;
		}, ARRAY_FILTER_USE_KEY );
		
		return ! empty( $data ) ? $data : array();
	}
}
