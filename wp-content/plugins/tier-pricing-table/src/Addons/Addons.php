<?php

namespace TierPricingTable\Addons;

use TierPricingTable\Addons\AdvancedQuantityOptions\AdvancedQuantityOptionsAddon;
use TierPricingTable\Addons\CartUpsells\CartUpsellsAddon;
use TierPricingTable\Addons\CatalogPrices\CatalogPricesAddon;
use TierPricingTable\Addons\CategoryTiers\CategoryTierAddon;
use TierPricingTable\Addons\Coupons\CouponsAddon;
use TierPricingTable\Addons\CustomColumns\CustomColumnsAddon;
use TierPricingTable\Addons\GlobalTieredPricing\GlobalTieredPricingAddon;
use TierPricingTable\Addons\ManualOrders\ManualOrdersAddon;
use TierPricingTable\Addons\MinQuantity\MinQuantity;
use TierPricingTable\Addons\NonLoggedInUsers\NonLoggedInUsersAddon;
use TierPricingTable\Addons\PluginsRecommendations\PluginsRecommendationsAddon;
use TierPricingTable\Addons\PricingSummary\PricingSummaryAddon;
use TierPricingTable\Addons\ProductCatalogLoop\ProductCatalogLoop;
use TierPricingTable\Addons\ReactProductEditorAddon\ReactProductEditorAddon;
use TierPricingTable\Addons\RoleBasedPricing\RoleBasedPricingAddon;
use TierPricingTable\Addons\UserBasedPricing\UserBasedPricingAddon;
use TierPricingTable\Addons\TaxSettings\TaxSettingsAddon;
use TierPricingTable\Addons\TieredPricingCart\TieredPricingCartAddon;
use TierPricingTable\Addons\TierLabels\TierLabelsAddon;
use TierPricingTable\Addons\Tools\ToolsAddon;
use TierPricingTable\Addons\YouSave\YouSaveAddon;
use TierPricingTable\Core\ServiceContainerTrait;
class Addons {
    use ServiceContainerTrait;
    /**
     * Addons constructor.
     */
    public function __construct() {
        $this->init();
    }

    public function init() {
        $addons = array(
            ManualOrdersAddon::class                                         => new ManualOrdersAddon(),
            GlobalTieredPricingAddon::class                                  => new GlobalTieredPricingAddon(),
            CouponsAddon::class                                              => new CouponsAddon(),
            RoleBasedPricingAddon::class                                     => new RoleBasedPricingAddon(),
            UserBasedPricingAddon::class                                     => new UserBasedPricingAddon(),
            CategoryTierAddon::class                                         => new CategoryTierAddon(),
            AdvancedQuantityOptionsAddon::class                              => new AdvancedQuantityOptionsAddon(),
            PluginsRecommendationsAddon::class                               => new PluginsRecommendationsAddon(),
            CustomColumnsAddon::class                                        => new CustomColumnsAddon(),
            ProductCatalogLoop::class                                        => new ProductCatalogLoop(),
            ReactProductEditorAddon::class                                   => new ReactProductEditorAddon(),
            TaxSettingsAddon::class                                          => new TaxSettingsAddon(),
            TierLabelsAddon::class                                           => new TierLabelsAddon(),
            ToolsAddon::class                                                => new ToolsAddon(),
            CatalogPricesAddon::class                                        => new CatalogPricesAddon(),
            TieredPricingCartAddon::class                                    => new TieredPricingCartAddon(),
            YouSaveAddon::class                                              => new YouSaveAddon(),
            CartUpsellsAddon::class                                          => new CartUpsellsAddon(),
            PricingSummaryAddon::class                                       => new PricingSummaryAddon(),
            NonLoggedInUsersAddon::class                                     => new NonLoggedInUsersAddon(),
            \TierPricingTable\Addons\ImportExport\ImportExportAddon::class   => new \TierPricingTable\Addons\ImportExport\ImportExportAddon(),
            \TierPricingTable\Addons\RequestAQuote\RequestAQuoteAddon::class => new \TierPricingTable\Addons\RequestAQuote\RequestAQuoteAddon(),
        );
        $addons = apply_filters( 'tiered_pricing_table/addons/list', $addons );
        foreach ( $addons as $addon ) {
            if ( $addon->isEnabled() ) {
                $addon->run();
            }
        }
    }

}
