<?php

namespace TierPricingTable\Integrations\Plugins\RankMath;

use RankMath\Helpers\Param;
use TierPricingTable\Integrations\Plugins\SeoIntegrationAbstract;
use WC_Product;
class RankMath extends SeoIntegrationAbstract {
    public function getTitle() : string {
        return 'Rank Math SEO';
    }

    public function getDescription() : string {
        return __( 'Add tiered pricing data to the product schema and introduce <b>%lowest_price%</b> and <b>%price_range%</b> variables for SEO.', 'tier-pricing-table' );
    }

    public function getSlug() : string {
        return 'rank-math';
    }

    protected function getSettingsPrefix() : string {
        return 'rank_math';
    }

    protected function getSchemaFilterSlug() : string {
        return 'rank_math';
    }

    public function run() {
        add_action( 'plugins_loaded', function () {
            if ( !class_exists( 'RankMath\\Helpers\\Param' ) || !function_exists( 'rank_math_register_var_replacement' ) ) {
                return;
            }
            add_filter( 'tiered_pricing_table/settings/sections', function ( $sections ) {
                $sections[] = new Settings();
                return $sections;
            } );
        } );
        return;
        add_action( 'rank_math/vars/register_extra_replacements', function () {
            if ( !function_exists( 'rank_math_register_var_replacement' ) ) {
                return;
            }
            if ( !$this->isVariablesEnabled() ) {
                return;
            }
            rank_math_register_var_replacement( 'lowest_price', [
                'name'        => esc_html__( 'Lowest Price', 'rank-math' ),
                'description' => esc_html__( 'Tiered Pricing: The lowest price of a product.', 'rank-math' ),
                'variable'    => 'lowest_price',
                'example'     => $this->getLowestPrice(),
            ], array($this, 'getLowestPrice') );
            rank_math_register_var_replacement( 'price_range', [
                'name'        => esc_html__( 'Price range', 'rank-math' ),
                'description' => esc_html__( 'Tiered Pricing: A price range from the lowest to the highest.', 'rank-math' ),
                'variable'    => 'price_range',
                'example'     => $this->getPriceRange(),
            ], array($this, 'getPriceRange') );
        } );
        add_filter( 'rank_math/snippet/rich_snippet_product_entity', function ( $data ) {
            global $product;
            if ( !$this->isEnhancedSchemaEnabled() || !$product instanceof WC_Product || !is_array( $data ) ) {
                return $data;
            }
            return $this->enhanceProductSchema( $data, $product );
        }, 10 );
    }

    protected function resolveProductId() {
        if ( !class_exists( 'RankMath\\Helpers\\Param' ) ) {
            return get_queried_object_id();
        }
        return Param::get( 'post', get_queried_object_id(), FILTER_VALIDATE_INT );
    }

    public function getIconURL() : ?string {
        return $this->getContainer()->getFileManager()->locateAsset( 'admin/integrations/rank-math-icon.svg' );
    }

    public function getPriceRange() : ?string {
        return $this->getFormattedProductPrice( 'range' );
    }

    public function getLowestPrice() : ?string {
        return $this->getFormattedProductPrice( 'lowest_price' );
    }

}
