<?php

namespace TierPricingTable\Services\API\ProductFields;

use TierPricingTable\Addons\RoleBasedPricing\RoleBasedPriceManager;
use TierPricingTable\Addons\RoleBasedPricing\RoleBasedPricingRule;
use WC_Product;
class RoleBasedPricingData extends ProductField {
    public function getFieldSlug() : string {
        return 'tiered_pricing_roles_data';
    }

    public function sanitizeValue( $value, $productId ) : array {
        $value = ( is_array( $value ) ? $value : array() );
        $sanitizedValue = array();
        wp_roles()->roles;
        foreach ( $value as $role => $roleData ) {
            if ( array_key_exists( $role, wp_roles()->roles ) ) {
                $roleBasedRule = RoleBasedPricingRule::build( $productId, $role );
                $sanitizedValue[$role] = wp_parse_args( $roleData, $roleBasedRule->asArray() );
            }
        }
        return $sanitizedValue;
    }

    public function getValue( array $product ) : array {
        return array();
    }

    public function updateValue( $value, WC_Product $product ) {
    }

    public function getType() : string {
        return 'object';
    }

    public function getDescription() : string {
        return 'Roles pricing data. See RoleBasedPricingRule class to get more information about the structure of the data.';
    }

}
