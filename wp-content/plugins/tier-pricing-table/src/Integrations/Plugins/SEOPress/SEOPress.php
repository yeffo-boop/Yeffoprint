<?php

namespace TierPricingTable\Integrations\Plugins\SEOPress;

use TierPricingTable\Integrations\Plugins\SeoIntegrationAbstract;
use WC_Product;
class SEOPress extends SeoIntegrationAbstract {
    public function getTitle() : string {
        return 'SEOPress';
    }

    public function getDescription() : string {
        return __( 'Add tiered pricing data to SEOPress and introduce <b>%%lowest_price%%</b> and <b>%%price_range%%</b> variables for SEO.', 'tier-pricing-table' );
    }

    public function getSlug() : string {
        return 'seopress';
    }

    protected function getSettingsPrefix() : string {
        return 'seopress';
    }

    protected function getSchemaFilterSlug() : string {
        return 'seopress';
    }

    public function run() {
        add_action( 'plugins_loaded', function () {
            if ( !function_exists( 'seopress_init' ) ) {
                return;
            }
            add_filter( 'tiered_pricing_table/settings/sections', function ( $sections ) {
                // Ensure you create a corresponding Settings class for SEOPress in this namespace
                $sections[] = new Settings();
                return $sections;
            } );
        } );
        return;
        add_filter( 'seopress_titles_template_replace_array', function ( $replace ) {
            if ( !$this->isVariablesEnabled() ) {
                return $replace;
            }
            $replace['%%lowest_price%%'] = $this->getPrice( 'lowest_price' );
            $replace['%%price_range%%'] = $this->getPrice( 'range' );
            return $replace;
        } );
        add_filter( 'seopress_titles_template_variables_array', function ( $variables ) {
            if ( !$this->isVariablesEnabled() ) {
                return $variables;
            }
            $variables[] = "%%lowest_price%%";
            $variables[] = "%%price_range%%";
            return $variables;
        } );
        add_filter( 'seopress_get_dynamic_variables', function ( $variables ) {
            $variables['%%lowest_price%%'] = 'Lowest Price';
            $variables['%%price_range%%'] = 'Price Range';
            return $variables;
        } );
        add_filter( 'seopress_tags_available', function ( $tags ) {
            // Register Lowest Price
            $tags['lowest_price'] = [
                'class'       => SEOPressLowestPriceTag::class,
                'name'        => __( 'Lowest Price', 'tier-pricig-table' ),
                'description' => SEOPressLowestPriceTag::getDescription(),
                'input'       => '%%lowest_price%%',
            ];
            // Register Price Range
            $tags['price_range'] = [
                'class'       => SEOPressPriceRangeTag::class,
                'name'        => __( 'Price Range', 'seopress' ),
                'description' => SEOPressPriceRangeTag::getDescription(),
                'input'       => '%%price_range%%',
            ];
            return $tags;
        } );
        // SEOPress Schema Integration
        add_filter( 'seopress_structured_data_product', function ( $data ) {
            if ( !is_product() || !is_array( $data ) ) {
                return $data;
            }
            global $product;
            if ( !$this->isEnhancedSchemaEnabled() || !$product instanceof WC_Product ) {
                return $data;
            }
            return $this->enhanceProductSchema( $data, $product );
        } );
    }

    public function getPrice( $type ) : ?string {
        return $this->getFormattedProductPrice( $type, false );
    }

    public function getIconURL() : ?string {
        // Make sure to add a seopress-icon.png/gif to your assets
        return $this->getContainer()->getFileManager()->locateAsset( 'admin/integrations/seopress-icon.gif' );
    }

}
