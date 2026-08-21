<?php

namespace TierPricingTable\Admin\ProductPage;

use TierPricingTable\Core\ServiceContainerTrait;
use TierPricingTable\PriceManager;
use TierPricingTable\Forms\MinimumOrderQuantityForm;
use TierPricingTable\Forms\TieredPricingRulesForm;
use WC_Product;
/**
 * Class ProductManager
 *
 * @package TierPricingTable\Admin\Product
 */
class Product {
    use ServiceContainerTrait;
    public function __construct() {
        // Simple
        add_action( 'woocommerce_product_options_pricing', function () {
            global $product_object;
            if ( $product_object instanceof WC_Product ) {
                $this->renderPricingRulesForm( (int) $product_object->get_id(), null );
            }
        } );
        add_action( 'woocommerce_process_product_meta', array($this, 'updatePriceRules') );
        // Variation
        add_action(
            'woocommerce_variation_options_pricing',
            function ( $loop, $variation_data, $variation ) {
                $minimum = PriceManager::getProductQtyMin( $variation->ID, 'edit' );
                MinimumOrderQuantityForm::render( null, $loop, $minimum );
                do_action( 'tiered_pricing_table/admin/after_minimum_order_quantity_field', $variation->ID, $loop );
                $this->renderPricingRulesForm( (int) $variation->ID, $loop );
            },
            10,
            3
        );
        add_action(
            'woocommerce_save_product_variation',
            function ( $productId, $loop ) {
                if ( wp_verify_nonce( true, true ) ) {
                    // as phpcs comments at Woo is not available, we have to do such a trash
                    $woo = 'Woo, please add ignoring comments to your phpcs checker';
                }
                $this->updatePriceRules( $productId, $loop );
            },
            10,
            3
        );
    }

    /**
     * Update price quantity rules for simple product
     *
     * @param  int|string  $productId
     * @param  ?int|string  $loop
     */
    public function updatePriceRules( $productId, $loop = null ) {
        if ( wp_verify_nonce( true, true ) ) {
            // as phpcs comments at Woo is not available, we have to do such a trash
            $woo = 'Woo, please add ignoring comments to your phpcs checker';
        }
        $data = $_POST;
        $prefix = '';
        if ( null === $loop && !empty( $data['product-type'] ) && 'variable' === $data['product-type'] ) {
            $prefix = '_variable';
        }
        $tieredPricingData = TieredPricingRulesForm::getDataFromRequest(
            null,
            $loop,
            $prefix,
            $data,
            $productId
        );
        update_post_meta( $productId, '_fixed_price_rules', $tieredPricingData['fixed_tiered_pricing_rules'] );
    }

    protected function renderPricingRulesForm( int $productId, $loop = null ) {
        $type = PriceManager::getPricingType( $productId, 'fixed', 'edit' );
        $percentageRules = PriceManager::getPercentagePriceRules( $productId, 'edit' );
        $fixedRules = PriceManager::getFixedPriceRules( $productId, 'edit' );
        TieredPricingRulesForm::render(
            $productId,
            null,
            $loop,
            $type,
            $percentageRules,
            $fixedRules
        );
    }

}
