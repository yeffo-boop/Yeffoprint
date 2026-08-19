<?php

namespace TierPricingTable\Integrations\Plugins\Yoast;

use TierPricingTable\Integrations\Plugins\SeoIntegrationAbstract;
use WC_Product;
class Yoast extends SeoIntegrationAbstract {
    public function getTitle() : string {
        return 'Yoast SEO';
    }

    public function getDescription() : string {
        return __( 'Add tiered pricing data to the Yoast product schema and introduce <b>%%lowest_price%%</b> and <b>%%price_range%%</b> variables for SEO.', 'tier-pricing-table' );
    }

    public function getSlug() : string {
        return 'yoast-seo';
    }

    protected function getSettingsPrefix() : string {
        return 'yoast';
    }

    protected function getSchemaFilterSlug() : string {
        return 'yoast_seo';
    }

    public function run() {
        add_action( 'plugins_loaded', function () {
            if ( !class_exists( 'WPSEO_Options' ) ) {
                return;
            }
            add_filter( 'tiered_pricing_table/settings/sections', function ( $sections ) {
                $sections[] = new Settings();
                return $sections;
            } );
        } );
        return;
        add_filter(
            'wpseo_replacements',
            function ( $replacements ) {
                if ( !$this->isVariablesEnabled() ) {
                    return $replacements;
                }
                if ( !$this->get_product() ) {
                    return $replacements;
                }
                $replacements['%%lowest_price%%'] = $this->getFormattedProductPrice( 'lowest_price' );
                $replacements['%%price_range%%'] = $this->getFormattedProductPrice( 'range' );
                return $replacements;
            },
            10,
            2
        );
        add_filter( 'wpseo_schema_product', function ( $data ) {
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

    public function getIconURL() : ?string {
        return $this->getContainer()->getFileManager()->locateAsset( 'admin/integrations/yoast-icon.gif' );
    }

}
