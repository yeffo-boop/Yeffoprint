<?php

namespace TierPricingTable\Addons\CatalogPrices;

use TierPricingTable\Core\ServiceContainerTrait;
use TierPricingTable\Managers\FormatPriceManager;
use TierPricingTable\Settings\Sections\GeneralSection\Subsections\ProductPagePriceSubsection;
use TierPricingTable\TierPricingTablePlugin;
use WC_Product;
use WC_Product_Variable;
/**
 * Class CatalogPricesService
 *
 * @package TierPricingTable\Addons\CatalogPrices
 */
class CatalogPricesService {
    use ServiceContainerTrait;
    /**
     * CatalogPriceManager constructor.
     */
    public function __construct() {
        if ( !$this->isEnabled() ) {
            return;
        }
        if ( is_admin() && !defined( 'DOING_AJAX' ) ) {
            return;
        }
    }

    public function formatPrice( ?string $defaultPriceHTML, ?WC_Product $product ) : ?string {
        if ( !$product ) {
            return $defaultPriceHTML;
        }
        // Some themes use ->get_price_html() to show cart item price. Do not modify product price if we're in the cart
        if ( is_cart() ) {
            return $defaultPriceHTML;
        }
        // Do not modify price in quick edit and inline edit in admin
        if ( isset( $_POST['action'] ) && in_array( $_POST['action'], ['inline-save'], true ) ) {
            return $defaultPriceHTML;
        }
        if ( isset( $GLOBALS['TPT_DISABLE_CATALOG_PRICE_FORMAT'] ) ) {
            return $defaultPriceHTML;
        }
        $currentProductPageProductId = get_queried_object_id();
        $parentProductId = ( $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id() );
        // Handle product page pricing
        if ( $currentProductPageProductId === $parentProductId ) {
            // Do not modify prices for variations on product page
            if ( TierPricingTablePlugin::isVariationProductSupported( $product ) && !apply_filters(
                'tiered_pricing_table/catalog_pricing/format_variation_price',
                false,
                $defaultPriceHTML,
                $product
            ) ) {
                return $defaultPriceHTML;
            }
            $newPriceHTML = null;
            if ( 'same_as_catalog' === ProductPagePriceSubsection::getFormatPriceType() ) {
                // Format only if this is not a variable product or if it is enabled for variable products
                if ( !TierPricingTablePlugin::isVariableProductSupported( $product ) || $this->useForVariable() ) {
                    $newPriceHTML = FormatPriceManager::getFormattedPrice( $product, array(
                        'html'               => true,
                        'use_cache'          => true,
                        'for_display'        => true,
                        'with_suffix'        => true,
                        'with_lowest_prefix' => true,
                        'with_default_price' => false,
                    ) );
                }
            }
        } else {
            // Formation can be disabled for variable products
            if ( TierPricingTablePlugin::isVariableProductSupported( $product ) && !$this->useForVariable() ) {
                $newPriceHTML = null;
            } else {
                $newPriceHTML = FormatPriceManager::getFormattedPrice( $product, array(
                    'html'               => true,
                    'use_cache'          => true,
                    'for_display'        => true,
                    'with_suffix'        => true,
                    'with_lowest_prefix' => true,
                    'with_default_price' => false,
                ) );
            }
        }
        $newPriceHtml = ( is_null( $newPriceHTML ) ? $defaultPriceHTML : $newPriceHTML );
        return apply_filters(
            'tiered_pricing_table/catalog_pricing/price_html',
            $newPriceHtml,
            $defaultPriceHTML,
            $product
        );
    }

    public function isEnabled() : bool {
        return 'yes' === $this->getContainer()->getSettings()->get( 'tiered_price_at_catalog', 'yes' );
    }

    public function useForVariable() : bool {
        return $this->getContainer()->getSettings()->get( 'tiered_price_at_catalog_for_variable', 'yes' ) === 'yes';
    }

    public function getDisplayType() : string {
        return $this->getContainer()->getSettings()->get( 'tiered_price_at_catalog_type', 'range' );
    }

}
