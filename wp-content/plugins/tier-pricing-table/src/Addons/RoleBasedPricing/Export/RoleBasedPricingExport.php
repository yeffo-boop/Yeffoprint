<?php namespace TierPricingTable\Addons\RoleBasedPricing\Export;

use TierPricingTable\Addons\RoleBasedPricing\RoleBasedPriceManager;
use TierPricingTable\TierPricingTablePlugin;
use WC_Product;

class RoleBasedPricingExport {
	
	/**
	 * Export constructor.
	 */
	public function __construct() {
		add_filter( 'woocommerce_product_export_column_names', array( $this, 'addExportColumn' ), 999 );
		add_filter( 'woocommerce_product_export_product_default_columns', array( $this, 'addExportColumn' ), 999 );
		
		add_action( 'init', function () {
			
			if ( ! is_admin() ) {
				return;
			}
			
			$columns = array();
			$disabled_roles = apply_filters( 'tiered_pricing_table/role_based_rules/import_export_disabled_roles', array( 'editor', 'author', 'contributor', 'shop_manager' ) );
			
			foreach ( wp_roles()->roles as $WPRole => $role_data ) {
				if ( in_array( $WPRole, $disabled_roles, true ) ) {
					continue;
				}
				
				$columns[] = array(
					'column_title'    => $WPRole . '_tiered_price_pricing_type',
					'export_callback' => function ( $value, WC_Product $product ) use ( $WPRole ) {
						return RoleBasedPriceManager::getProductPricingType( $product->get_id(), $WPRole, 'edit' );
					},
				);
				
				$columns[] = array(
					'column_title'    => $WPRole . '_tiered_price_regular_price',
					'export_callback' => function ( $value, WC_Product $product ) use ( $WPRole ) {
						return RoleBasedPriceManager::getProductRegularRolePrice( $product->get_id(), $WPRole, 'edit' );
					},
				);
				
				$columns[] = array(
					'column_title'    => $WPRole . '_tiered_price_sale_price',
					'export_callback' => function ( $value, WC_Product $product ) use ( $WPRole ) {
						return RoleBasedPriceManager::getProductSaleRolePrice( $product->get_id(), $WPRole, 'edit' );
					},
				);
				
				$columns[] = array(
					'column_title'    => $WPRole . '_tiered_price_discount',
					'export_callback' => function ( $value, WC_Product $product ) use ( $WPRole ) {
						return RoleBasedPriceManager::getProductDiscount( $product->get_id(), $WPRole, 'edit' );
					},
				);
				
				$columns[] = array(
					'column_title'    => $WPRole . '_tiered_price_fixed',
					'export_callback' => function ( $value, WC_Product $product ) use ( $WPRole ) {
						$fixedRules = RoleBasedPriceManager::getPriceRules( $product->get_id(), $WPRole, 'fixed' );
						
						$str       = '';
						$separator = TierPricingTablePlugin::getRulesSeparator();
						
						foreach ( $fixedRules as $quantity => $price ) {
							$str .= $quantity . ':' . $price . $separator;
						}
						
						return mb_strlen( $str ) > 0 ? trim( $str, $separator ) : null;
					},
				);
				
				$columns[] = array(
					'column_title'    => $WPRole . '_tiered_price_percentage',
					'export_callback' => function ( $value, WC_Product $product ) use ( $WPRole ) {
						$fixedRules = RoleBasedPriceManager::getPriceRules( $product->get_id(), $WPRole, 'percentage' );
						
						$str       = '';
						$separator = TierPricingTablePlugin::getRulesSeparator();
						
						foreach ( $fixedRules as $quantity => $discount ) {
							$str .= $quantity . ':' . $discount . $separator;
						}
						
						return mb_strlen( $str ) > 0 ? trim( $str, $separator ) : null;
					},
				);
				
				$columns[] = array(
					'column_title'    => $WPRole . '_tiered_price_type',
					'export_callback' => function ( $value, WC_Product $product ) use ( $WPRole ) {
						return RoleBasedPriceManager::getPricingType( $product->get_id(), $WPRole, 'edit' );
					},
				);
				
				$columns[] = array(
					'column_title'    => $WPRole . '_tiered_price_minimum',
					'export_callback' => function ( $value, WC_Product $product ) use ( $WPRole ) {
						return RoleBasedPriceManager::getProductQtyMin( $product->get_id(), $WPRole, 'edit' );
					},
				);
			}
			
			foreach ( $columns as $column ) {
				add_filter( 'woocommerce_product_export_product_column_' . $column['column_title'],
					$column['export_callback'], 20, 2 );
			}
			
		} );
	}
	
	/**
	 * Register the 'Fixed tiered price' column in the exporter.
	 */
	public function addExportColumn( array $columns ): array {
		
		global $wp_roles;
		$disabled_roles = apply_filters( 'tiered_pricing_table/role_based_rules/import_export_disabled_roles', array( 'editor', 'author', 'contributor', 'shop_manager' ) );
		
		foreach ( wp_roles()->roles as $WPRole => $role_data ) {
			if ( in_array( $WPRole, $disabled_roles, true ) ) {
				continue;
			}
			
			$roleName = isset( $wp_roles->role_names[ $WPRole ] ) ? translate_user_role( $wp_roles->role_names[ $WPRole ] ) : $WPRole;
			
			$columns[ $WPRole . '_tiered_price_pricing_type' ]  = 'Tiered Pricing —  ' . ' [' . $roleName . '] ' . __( 'Regular pricing type',
					'tier-pricing-table' );
			$columns[ $WPRole . '_tiered_price_regular_price' ] = 'Tiered Pricing — ' . ' [' . $roleName . '] ' . __( 'Regular price',
					'tier-pricing-table' );
			$columns[ $WPRole . '_tiered_price_sale_price' ]    = 'Tiered Pricing — ' . ' [' . $roleName . '] ' . __( 'Sale price',
					'tier-pricing-table' );
			$columns[ $WPRole . '_tiered_price_discount' ]      = 'Tiered Pricing — ' . ' [' . $roleName . '] ' . __( 'Percentage discount',
					'tier-pricing-table' );
			$columns[ $WPRole . '_tiered_price_fixed' ]         = 'Tiered Pricing — ' . ' [' . $roleName . '] ' . __( 'Fixed pricing rules',
					'tier-pricing-table' );
			$columns[ $WPRole . '_tiered_price_percentage' ]    = 'Tiered Pricing — ' . ' [' . $roleName . '] ' . __( 'Percentage pricing rules',
					'tier-pricing-table' );
			$columns[ $WPRole . '_tiered_price_type' ]          = 'Tiered Pricing — ' . ' [' . $roleName . '] ' . __( 'Tiered pricing type',
					'tier-pricing-table' );
			$columns[ $WPRole . '_tiered_price_minimum' ]       = 'Tiered Pricing — ' . ' [' . $roleName . '] ' . __( 'Minimum order quantity',
					'tier-pricing-table' );
		}
		
		return $columns;
	}
}

