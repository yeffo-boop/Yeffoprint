<?php namespace TierPricingTable\Addons\RoleBasedPricing\Import;

use TierPricingTable\Addons\RoleBasedPricing\RoleBasedPricingRule;
use TierPricingTable\Addons\ImportExport\WoocommerceImportService;
use WC_Product;

class RoleBasedPricingImport {
	
	public function __construct() {
		add_filter( 'tiered_pricing_table/import/woocommerce/mapping_screen_columns',
			array( $this, 'addColumnsToMapper' ) );
		add_filter( 'tiered_pricing_table/import/woocommerce/import_columns', array( $this, 'addColumnsToImporter' ) );
		add_filter( 'woocommerce_product_import_pre_insert_product_object', array( $this, 'processImport' ), 10, 2 );
	}
	
	public function processImport( WC_Product $product, array $data ): WC_Product {
		
		$disabled_roles = apply_filters( 'tiered_pricing_table/role_based_rules/import_export_disabled_roles', array( 'editor', 'author', 'contributor', 'shop_manager' ) );
		
		foreach ( wp_roles()->roles as $WPRole => $role_data ) {
			
			if ( in_array( $WPRole, $disabled_roles, true ) ) {
				continue;
			}
			
			$roleBasedRule = new RoleBasedPricingRule( $product->get_id(), $WPRole );
			$needUpdate    = false;
			
			if ( isset( $data[ $WPRole . '_tiered_price_pricing_type' ] ) ) {
				$roleBasedRule->setPricingType( $data[ $WPRole . '_tiered_price_pricing_type' ] );
				$needUpdate = true;
			}
			
			if ( isset( $data[ $WPRole . '_tiered_price_regular_price' ] ) ) {
				$regularPrice = floatval( $data[ $WPRole . '_tiered_price_regular_price' ] );
				
				$roleBasedRule->setRegularPrice( $regularPrice );
				$needUpdate = true;
			}
			
			if ( isset( $data[ $WPRole . '_tiered_price_sale_price' ] ) ) {
				
				$salePrice = floatval( $data[ $WPRole . '_tiered_price_sale_price' ] );
				
				$roleBasedRule->setSalePrice( $salePrice );
				$needUpdate = true;
			}
			
			if ( isset( $data[ $WPRole . '_tiered_price_discount' ] ) ) {
				
				$discount = floatval( $data[ $WPRole . '_tiered_price_discount' ] );
				
				if ( $discount ) {
					$roleBasedRule->setDiscount( min( 100, $discount ) );
				}
				
				$needUpdate = true;
			}
			
			if ( isset( $data[ $WPRole . '_tiered_price_fixed' ] ) ) {
				
				$fixedPricingRules = WoocommerceImportService::decodeExport( $data[ $WPRole . '_tiered_price_fixed' ] );
				$roleBasedRule->setFixedTieredPricingRules( $fixedPricingRules );
				$needUpdate = true;
			}
			
			if ( isset( $data[ $WPRole . '_tiered_price_percentage' ] ) ) {
				
				$percentagePricingRules = WoocommerceImportService::decodeExport( $data[ $WPRole . '_tiered_price_percentage' ] );
				
				$roleBasedRule->setPercentageTieredPricingRules( $percentagePricingRules );
				$needUpdate = true;
			}
			
			if ( isset( $data[ $WPRole . '_tiered_price_type' ] ) ) {
				
				$tieredPricingType = $data[ $WPRole . '_tiered_price_type' ];
				
				if ( in_array( $tieredPricingType, array( 'fixed', 'percentage' ) ) ) {
					$roleBasedRule->setTieredPricingType( $tieredPricingType );
				}
			}
			
			if ( isset( $data[ $WPRole . '_tiered_price_minimum' ] ) ) {
				
				$minimum = (int) $data[ $WPRole . '_tiered_price_minimum' ];
				
				$roleBasedRule->setMinimumOrderQuantity( $minimum );
				$needUpdate = true;
			}
			
			if ( $needUpdate ) {
				try {
					$roleBasedRule->save();
				} catch ( \Exception $e ) {
					wc_get_logger()->error( 'Error saving role-based pricing rule for product ID ' . $product->get_id() . ' and role ' . $WPRole . ': ' . $e->getMessage(),
						array( 'source' => 'tiered-pricing-table' ) );
				}
			}
		}
		
		return $product;
	}
	
	public function addColumnsToImporter( $columns ): array {
		
		global $wp_roles;
		$disabled_roles = apply_filters( 'tiered_pricing_table/role_based_rules/import_export_disabled_roles', array( 'editor', 'author', 'contributor', 'shop_manager' ) );
		
		foreach ( wp_roles()->roles as $WPRole => $role_data ) {
			if ( in_array( $WPRole, $disabled_roles, true ) ) {
				continue;
			}
			
			$roleName = isset( $wp_roles->role_names[ $WPRole ] ) ? translate_user_role( $wp_roles->role_names[ $WPRole ] ) : $WPRole;
			
			$columns[ 'tpt_' . $WPRole ] = array(
				'name'    => __( 'Tiered Pricing', 'tier-pricing-table' ) . ': ' . $roleName,
				'options' => array(
					$WPRole . '_tiered_price_pricing_type'  => $roleName . ': ' . __( 'Regular pricing type',
							'tier-pricing-table' ),
					$WPRole . '_tiered_price_regular_price' => $roleName . ': ' . __( 'Regular price',
							'tier-pricing-table' ),
					$WPRole . '_tiered_price_sale_price'    => $roleName . ': ' . __( 'Sale price',
							'tier-pricing-table' ),
					$WPRole . '_tiered_price_discount'      => $roleName . ': ' . __( 'Percentage discount',
							'tier-pricing-table' ),
					$WPRole . '_tiered_price_type'          => $roleName . ': ' . __( 'Tiered pricing type',
							'tier-pricing-table' ),
					$WPRole . '_tiered_price_minimum'       => $roleName . ': ' . __( 'Minimum order quantity',
							'tier-pricing-table' ),
					$WPRole . '_tiered_price_fixed'         => $roleName . ': ' . __( 'Fixed pricing rules',
							'tier-pricing-table' ),
					$WPRole . '_tiered_price_percentage'    => $roleName . ': ' . __( 'Percentage pricing rules',
							'tier-pricing-table' ),
				),
			);
		}
		
		return $columns;
	}
	
	public function addColumnsToMapper( array $columns ): array {
		
		global $wp_roles;
		$disabled_roles = apply_filters( 'tiered_pricing_table/role_based_rules/import_export_disabled_roles', array( 'editor', 'author', 'contributor', 'shop_manager' ) );
		
		foreach ( wp_roles()->roles as $WPRole => $role_data ) {
			if ( in_array( $WPRole, $disabled_roles, true ) ) {
				continue;
			}
			
			$roleName = isset( $wp_roles->role_names[ $WPRole ] ) ? translate_user_role( $wp_roles->role_names[ $WPRole ] ) : $WPRole;
			
			$columns[ 'Tiered Pricing — [' . $roleName . '] Regular pricing type' ] = $WPRole . '_tiered_price_pricing_type';
			
			$columns[ 'Tiered Pricing — [' . $roleName . '] Regular price' ] = $WPRole . '_tiered_price_regular_price';
			
			$columns[ 'Tiered Pricing — [' . $roleName . '] Sale price' ] = $WPRole . '_tiered_price_sale_price';
			
			$columns[ 'Tiered Pricing — [' . $roleName . '] Percentage discount' ] = $WPRole . '_tiered_price_discount';
			
			$columns[ 'Tiered Pricing — [' . $roleName . '] Fixed pricing rules' ] = $WPRole . '_tiered_price_fixed';
			
			$columns[ 'Tiered Pricing — [' . $roleName . '] Percentage pricing rules' ] = $WPRole . '_tiered_price_percentage';
			
			$columns[ 'Tiered Pricing — [' . $roleName . '] Tiered pricing type' ] = $WPRole . '_tiered_price_type';
			
			$columns[ 'Tiered Pricing — [' . $roleName . '] Minimum order quantity' ] = $WPRole . '_tiered_price_minimum';
		}
		
		return $columns;
	}
}
