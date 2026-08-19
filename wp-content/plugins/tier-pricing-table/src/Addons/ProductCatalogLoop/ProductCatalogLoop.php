<?php

namespace TierPricingTable\Addons\ProductCatalogLoop;

use TierPricingTable\Addons\AbstractAddon;
use TierPricingTable\Addons\ProductCatalogLoop\Settings\ProductCatalogLoopSettingsSection;
use WC_Product;
class ProductCatalogLoop extends AbstractAddon {
    public function getName() : string {
        return __( 'Shop & Categories', 'tier-pricing-table' );
    }

    public function getDescription() : string {
        return __( 'Display tiered pricing tables on the shop and category pages.', 'tier-pricing-table' );
    }

    public function getIcon() : string {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M4 10h12v2H4zm0-4h16v2H4zm0 8h8v2H4zm10 0v6l5-3z"/></svg>';
    }

    public function getSlug() : string {
        return 'shop-loop-display';
    }

    public function getRenderAttributes() : array {
        if ( !ProductCatalogLoopSettingsSection::isCustomLayoutSettings() ) {
            return array(
                'display' => true,
            );
        }
        $args = array(
            'display_context'                     => 'shop-loop',
            'display'                             => true,
            'display_type'                        => ProductCatalogLoopSettingsSection::getLayoutType(),
            'title'                               => ProductCatalogLoopSettingsSection::getTitle(),
            'quantity_type'                       => ProductCatalogLoopSettingsSection::getQuantityType(),
            'getSelectedQuantityColor'            => ProductCatalogLoopSettingsSection::getSelectedQuantityColor(),
            'quantity_column_title'               => ProductCatalogLoopSettingsSection::getTableColumnsTitles()['head_quantity_text'],
            'price_column_title'                  => ProductCatalogLoopSettingsSection::getTableColumnsTitles()['head_price_text'],
            'discount_column_title'               => ProductCatalogLoopSettingsSection::getTableColumnsTitles()['head_discount_text'],
            'show_discount_column'                => ProductCatalogLoopSettingsSection::blocksShowDiscount(),
            'clickable_rows'                      => ProductCatalogLoopSettingsSection::isClickableTableRows(),
            'active_tier_color'                   => ProductCatalogLoopSettingsSection::getSelectedQuantityColor(),
            'options_show_original_product_price' => ProductCatalogLoopSettingsSection::isShowOriginalProductPriceInOptions(),
            'options_show_default_option'         => ProductCatalogLoopSettingsSection::isShowDefaultOption(),
            'options_option_text'                 => ProductCatalogLoopSettingsSection::getOptionText(),
            'options_default_option_text'         => ProductCatalogLoopSettingsSection::getDefaultOptionText(),
            'plain_text_show_default_option'      => ProductCatalogLoopSettingsSection::isShowFirstPlainTextTier(),
            'plain_text_option_text'              => ProductCatalogLoopSettingsSection::getPlainTextTemplate(),
            'plain_text_default_option_text'      => ProductCatalogLoopSettingsSection::getFirstTierPlainTextTemplate(),
        );
        $default_quantity_measurement = array(
            'singular' => '',
            'plural'   => '',
        );
        $quantity_measurement = $default_quantity_measurement;
        if ( in_array( $args['display_type'], array('table', 'horizontal-table', 'tooltip') ) ) {
            $quantity_measurement = ProductCatalogLoopSettingsSection::getTableQuantityMeasurement();
        }
        if ( 'blocks' === $args['display_type'] ) {
            $quantity_measurement = ProductCatalogLoopSettingsSection::getBlocksQuantityMeasurement();
        }
        $args['quantity_measurement_singular'] = $quantity_measurement['singular'];
        $args['quantity_measurement_plural'] = $quantity_measurement['plural'];
        return $args;
    }

    public function run() {
        add_filter( 'tiered_pricing_table/settings/sections', array($this, 'addSettingSection'), 5 );
        if ( !ProductCatalogLoopSettingsSection::isEnabled() ) {
            return;
        }
        add_filter(
            'tiered_pricing_table/frontend/variation_render_settings',
            function ( $settings, $productId, $context ) {
                if ( 'shop-loop' !== $context ) {
                    return $settings;
                }
                return $this->getRenderAttributes();
            },
            10,
            3
        );
        add_filter(
            'tiered_pricing_table/frontend/default_price_behaviour_type',
            function (
                $type,
                $product,
                $priceHTML,
                $priceDisplayContext
            ) {
                if ( 'shop-loop' === $priceDisplayContext ) {
                    return ( ProductCatalogLoopSettingsSection::isDynamicPrice() ? 'dynamic' : 'static' );
                }
                return $type;
            },
            10,
            4
        );
        add_action( 'init', function () {
            $position = ProductCatalogLoopSettingsSection::getPosition();
            $closeLink = false;
            if ( 'woocommerce_shop_loop_item_title' === $position['hook'] ) {
                $closeLink = true;
                remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_product_link_close', 5 );
            }
            add_action( $position['hook'], function () use($closeLink) {
                global $product, $post;
                if ( !$product instanceof WC_Product ) {
                    $productID = ( isset( $post ) ? $post->ID : null );
                    $product = wc_get_product( $productID );
                }
                if ( !$product ) {
                    return;
                }
                if ( !$product->is_purchasable() ) {
                    return;
                }
                if ( $closeLink ) {
                    echo '</a>';
                }
                $this->render( $product, ProductCatalogLoopSettingsSection::useReducedStyles() );
            }, $position['priority'] );
        } );
    }

    public function render( WC_product $product, $useReducedStyles = false ) {
        $classes = 'tiered-pricing-shop-loop';
        if ( $useReducedStyles ) {
            $classes .= ' tiered-pricing-shop-loop--reduced';
        }
        $this->getContainer()->getFileManager()->includeTemplate( 'frontend/shop-loop.php', array(
            'classes'  => $classes,
            'product'  => $product,
            'settings' => $this->getRenderAttributes(),
        ) );
    }

    public function addSettingSection( $sections ) : array {
        $_sections = array();
        foreach ( $sections as $section ) {
            $_sections[] = $section;
            if ( $section->getSlug() === 'general' ) {
                $_sections[] = new ProductCatalogLoopSettingsSection();
            }
        }
        return $_sections;
    }

}
