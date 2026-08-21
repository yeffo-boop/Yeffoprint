<?php

namespace TierPricingTable\Services\API\ProductFields;

use TierPricingTable\PriceManager;
use WC_Product;
class PercentageTieredPricing extends ProductField {
    public function getFieldSlug() : string {
        return 'tiered_pricing_percentage_rules';
    }

    public function sanitizeValue( $value ) : array {
        $rules = array();
        $value = ( is_array( $value ) ? $value : array() );
        foreach ( $value as $key => $val ) {
            $rules[(int) $key] = (float) $val;
        }
        return $rules;
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
        return 'Tiered pricing percentage rules. Key is a quantity and value is a percentage discount. Minimum quantity is 2';
    }

}
