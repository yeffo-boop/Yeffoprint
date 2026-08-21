<?php

namespace TierPricingTable\Services\API\ProductFields;

use TierPricingTable\PriceManager;
use WC_Product;
class MinimumOrderQuantity extends ProductField {
    public function getFieldSlug() : string {
        return 'tiered_pricing_minimum_quantity';
    }

    public function getValue( array $product ) : ?int {
        return null;
    }

    public function updateValue( $value, WC_Product $product ) {
    }

    public function getType() : string {
        return 'string';
    }

    public function getDescription() : string {
        return 'Minimum order quantity.';
    }

}
