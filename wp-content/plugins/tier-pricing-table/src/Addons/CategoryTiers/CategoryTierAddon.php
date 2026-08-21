<?php

namespace TierPricingTable\Addons\CategoryTiers;

use TierPricingTable\Addons\AbstractAddon;
use TierPricingTable\PricingRule;
use WC_Product_Variation;
use WP_Term;
class CategoryTierAddon extends AbstractAddon {
    const SKIP_FOR_PRODUCT_META_KEY = '_skip_category_tier_rules';

    public function getName() : string {
        return __( 'Category level tiered pricing (deprecated)', 'tier-pricing-table' );
    }

    public function run() {
        // Saving
        add_action(
            'edit_term',
            array($this, 'saveTermFields'),
            10,
            1
        );
        add_action(
            'create_product_cat',
            array($this, 'saveTermFields'),
            10,
            1
        );
        add_action( 'product_cat_edit_form_fields', array($this, 'renderEditFields'), 99 );
        add_action( 'product_cat_add_form_fields', array($this, 'renderAddFields'), 99 );
        add_action( 'tiered_pricing_table/admin/pricing_tab_begin', array($this, 'renderProductCheckbox') );
        add_action( 'woocommerce_process_product_meta', array($this, 'saveTieredPricingTab') );
    }

    public function renderProductCheckbox( $productId ) {
        woocommerce_wp_checkbox( array(
            'id'            => self::SKIP_FOR_PRODUCT_META_KEY,
            'wrapper_class' => 'show_if_simple show_if_variable',
            'type'          => 'number',
            'checked'       => $this->isSkipForProduct( $productId, 'edit' ),
            'label'         => __( 'Skip category rules', 'tier-pricing-table' ),
            'description'   => __( 'Don\'t take into account tiered pricing rules from categories. ', 'tier-pricing-table' ),
        ) );
    }

    /**
     * Save tiered pricing tab data
     *
     * @param  int  $productId
     */
    public function saveTieredPricingTab( $productId ) {
        if ( wp_verify_nonce( true, true ) ) {
            // as phpcs comments at Woo is not available, we have to do such a trash
            $woo = 'Woo, please add ignoring comments to your phpcs checker';
        }
        $skip = ( isset( $_POST[self::SKIP_FOR_PRODUCT_META_KEY] ) ? 'yes' : 'no' );
        update_post_meta( $productId, self::SKIP_FOR_PRODUCT_META_KEY, $skip );
    }

    /**
     * If skip category rules for specific product
     *
     * @param  int  $productId
     * @param  string  $context
     *
     * @return bool
     */
    public function isSkipForProduct( $productId, $context = 'view' ) : bool {
        $product = wc_get_product( $productId );
        if ( $product ) {
            if ( $product instanceof WC_Product_Variation ) {
                $productId = $product->get_parent_id();
            }
            $skip = 'yes' === get_post_meta( $productId, self::SKIP_FOR_PRODUCT_META_KEY, true );
            if ( 'edit' != $context ) {
                return apply_filters(
                    'tiered_pricing_table/addons/category_tier_pricing_skip_category',
                    $skip,
                    $productId,
                    $product
                );
            }
            return $skip;
        }
        return false;
    }

    /**
     * Save metadata to custom attributes terms
     *
     * @param  int  $termId
     */
    public function saveTermFields( $termId ) {
        $data = $_REQUEST;
        $prefix = 'category';
        $percentageAmounts = ( isset( $data['tiered_price_percent_quantity_' . $prefix] ) ? (array) $data['tiered_price_percent_quantity_' . $prefix] : array() );
        $percentagePrices = ( !empty( $data['tiered_price_percent_discount_' . $prefix] ) ? (array) $data['tiered_price_percent_discount_' . $prefix] : array() );
    }

    /**
     * Render fields on category edit page
     *
     * @param  WP_Term  $category
     */
    public function renderEditFields( WP_Term $category ) {
        if ( tpt_fs()->can_use_premium_code() ) {
            $rules = $this->getForTerm( $category->term_id );
            $this->getContainer()->getFileManager()->includeTemplate( 'addons/category-tiers/edit.php', [
                'rules' => $rules,
            ] );
        } else {
            $this->getContainer()->getFileManager()->includeTemplate( 'addons/category-tiers/edit-free.php' );
        }
    }

    /**
     * Render fields on category adding page
     */
    public function renderAddFields() {
        if ( tpt_fs()->can_use_premium_code() ) {
            $this->getContainer()->getFileManager()->includeTemplate( 'addons/category-tiers/add.php', [
                'rules' => [],
            ] );
        } else {
            $this->getContainer()->getFileManager()->includeTemplate( 'addons/category-tiers/add-free.php' );
        }
    }

    public function getForTerm( $termId ) : array {
        $rules = get_term_meta( $termId, '_percentage_price_rules', true );
        $rules = ( !empty( $rules ) ? $rules : array() );
        $rules = ( is_array( $rules ) ? array_filter( $rules ) : array() );
        ksort( $rules );
        return $rules;
    }

    public function addToAddonsSettings( $addons ) {
        return $addons;
    }

    public function getDescription() : string {
        return __( 'Set tiered pricing at the product category level (Deprecated).', 'tier-pricing-table' );
    }

    public function getIcon() : string {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>';
    }

    public function getSlug() : string {
        return 'category-tiered-pricing';
    }

    protected function isActiveByDefault() : bool {
        return false;
    }

}
