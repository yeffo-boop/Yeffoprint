<?php

namespace TierPricingTable\Addons\GlobalTieredPricing;

use TierPricingTable\Addons\GlobalTieredPricing\PricingRule\RuleSettings;
use TierPricingTable\Forms\Form;
use Exception;
use WC_Product;
use WP_User;
class GlobalPricingRule {
    /**
     * Rule ID
     *
     * @var int
     */
    public $id;

    /**
     * Is suspended
     *
     * @var bool
     */
    public $isSuspended = false;

    /**
     * Regular pricing type
     *
     * @var string
     */
    public $pricingType;

    /**
     * Regular price
     *
     * @var ?float
     */
    public $regularPrice;

    /**
     * Sale price
     *
     * @var ?float
     */
    public $salePrice;

    /**
     * Percentage Discount
     *
     * @var ?float
     */
    public $discount;

    /**
     * Percentage Discount
     *
     * @var string
     */
    public $discountType = 'sale_price';

    /**
     * Applying type
     *
     * @var string
     */
    public $applyingType;

    /**
     * Tax status
     *
     * @var string
     */
    public $taxStatus = '';

    /**
     * Tax class
     *
     * @var string
     */
    public $taxClass = '';

    /**
     * Tiered Pricing type
     *
     * @var string
     */
    public $tieredPricingType;

    /**
     * Percentage Tiered Pricing Rules
     *
     * @var array
     */
    public $percentageTieredPricingRules = array();

    /**
     * Fixed Tiered Pricing Rules
     *
     * @var array
     */
    public $fixedTieredPricingRules = array();

    /**
     * Included categories
     *
     * @var array
     */
    public $includedProductCategories = array();

    /**
     * Excluded categories
     *
     * @var array
     */
    public $excludedProductCategories = array();

    /**
     * Included tags
     *
     * @var array
     */
    public $includedProductTags = array();

    /**
     * Excluded tags
     *
     * @var array
     */
    public $excludedProductTags = array();

    /**
     * Included brands
     *
     * @var array
     */
    public $includedProductBrands = array();

    /**
     * Excluded brands
     *
     * @var array
     */
    public $excludedProductBrands = array();

    /**
     * Included products
     *
     * @var array
     */
    public $includedProducts = array();

    /**
     * Excluded products
     *
     * @var array
     */
    public $excludedProducts = array();

    /**
     * Included product roles
     *
     * @var array
     */
    public $includedUsersRole = array();

    /**
     * Excluded product roles
     *
     * @var array
     */
    public $excludedUsersRole = array();

    /**
     * Included users
     *
     * @var array
     */
    public $includedUsers = array();

    /**
     * Excluded users
     *
     * @var array
     */
    public $excludedUsers = array();

    /**
     * Product minimum purchase quantity
     *
     * @var int|null
     */
    public $minimum;

    /**
     * Mix and match minimum quantity
     *
     * @var bool|null
     */
    public $mixAndMatchMinQuantity = null;

    public $priorityOptions;

    /**
     * Array with custom data from 3rd-party addons
     *
     * @var array
     */
    public $data = array();

    public function getId() : int {
        return $this->id;
    }

    public function setId( int $id ) {
        $this->id = $id;
    }

    public function getDiscount() : ?float {
        return $this->discount;
    }

    public function setDiscount( ?float $discount ) {
        $this->discount = $discount;
    }

    public function getDiscountType() : string {
        return $this->discountType;
    }

    public function setDiscountType( string $discountType ) {
        $this->discountType = ( in_array( $discountType, array('sale_price', 'regular_price') ) ? $discountType : 'sale_price' );
    }

    public function getTieredPricingType() : string {
        return 'fixed';
    }

    public function setTieredPricingType( string $tieredPricingType ) {
        $this->tieredPricingType = ( in_array( $tieredPricingType, array('percentage', 'fixed') ) ? $tieredPricingType : 'fixed' );
    }

    public function getPercentageTieredPricingRules() : array {
        return $this->percentageTieredPricingRules;
    }

    public function setPercentageTieredPricingRules( array $percentageTieredPricingRules ) {
        $this->percentageTieredPricingRules = $percentageTieredPricingRules;
    }

    public function getFixedTieredPricingRules() : array {
        return $this->fixedTieredPricingRules;
    }

    public function setFixedTieredPricingRules( array $fixedTieredPricingRules ) {
        $this->fixedTieredPricingRules = $fixedTieredPricingRules;
    }

    public function getApplyingType() : string {
        return $this->applyingType;
    }

    public function setApplyingType( string $applyingType ) {
        $this->applyingType = ( in_array( $applyingType, array('individual', 'cross') ) ? $applyingType : 'cross' );
    }

    public function getPricingType() : string {
        return $this->pricingType;
    }

    public function setPricingType( string $priceType ) {
        $this->pricingType = ( in_array( $priceType, array('percentage', 'flat') ) ? $priceType : 'flat' );
    }

    public function getTieredPricingRules() : array {
        return $this->getFixedTieredPricingRules();
    }

    public function getTaxStatus() : string {
        return $this->taxStatus;
    }

    public function setTaxStatus( string $taxStatus ) {
        $this->taxStatus = $taxStatus;
    }

    public function getTaxClass() : string {
        return $this->taxClass;
    }

    public function setTaxClass( string $taxClass ) {
        $this->taxClass = $taxClass;
    }

    public function getRegularPrice() : ?float {
        return $this->regularPrice;
    }

    public function setRegularPrice( ?float $regularPrice ) {
        $this->regularPrice = ( !Form::isEmpty( $regularPrice ) ? floatval( $regularPrice ) : null );
    }

    public function getSalePrice() : ?float {
        return $this->salePrice;
    }

    public function setSalePrice( ?float $salePrice ) {
        $this->salePrice = $salePrice;
    }

    /**
     * Create instance from array
     *
     * @param  array  $data
     *
     * @return self
     */
    public static function fromArray( array $data ) : self {
        $applyingType = $data['applying_type'] ?? 'individual';
        $applyingType = ( in_array( $applyingType, array('individual', 'cross') ) ? $applyingType : 'cross' );
        $tieredPricingType = $data['tiered_pricing_type'] ?? 'fixed';
        $tieredPricingType = ( in_array( $tieredPricingType, array('flat', 'percentage') ) ? $tieredPricingType : 'fixed' );
        $percentageRules = ( isset( $data['percentage_rules'] ) ? (array) $data['percentage_rules'] : array() );
        $fixedRules = ( isset( $data['fixed_rules'] ) ? (array) $data['fixed_rules'] : array() );
        $pricingType = $data['pricing_type'] ?? 'flat';
        $pricingType = ( in_array( $pricingType, array('flat', 'percentage') ) ? $pricingType : 'flat' );
        $regularPrice = $data['regular_price'] ?? null;
        $salePrice = $data['sale_price'] ?? null;
        $discount = $data['discount'] ?? null;
        $discountType = $data['discount_type'] ?? 'sale_price';
        $minimum = $data['minimum'] ?? null;
        $mixAndMatchValue = $data['mix_and_match_minimum'] ?? '';
        $mixAndMatch = ( $mixAndMatchValue === '' ? null : $mixAndMatchValue === 'yes' );
        $taxStatus = $data['tax_status'] ?? '';
        $taxClass = $data['tax_class'] ?? '';
        $self = new self();
        $self->setTaxStatus( $taxStatus );
        $self->setTaxClass( $taxClass );
        $self->setPricingType( (string) $pricingType );
        $self->setRegularPrice( ( Form::isEmpty( $regularPrice ) ? null : (float) $regularPrice ) );
        $self->setSalePrice( ( Form::isEmpty( $salePrice ) ? null : (float) $salePrice ) );
        $self->setDiscount( ( Form::isEmpty( $discount ) ? null : (float) $discount ) );
        $self->setDiscountType( (string) $discountType );
        $self->setApplyingType( $applyingType );
        $self->setTieredPricingType( $tieredPricingType );
        $self->setPercentageTieredPricingRules( $percentageRules );
        $self->setFixedTieredPricingRules( $fixedRules );
        $self->setMinimum( ( Form::isEmpty( $minimum ) ? null : (int) $minimum ) );
        $self->setMixAndMatchMinQuantity( $mixAndMatch );
        return $self;
    }

    /**
     * Validate
     *
     * @throws Exception
     */
    public function validatePricing() {
        $valid = !Form::isEmpty( $this->getRegularPrice() );
        $valid = $valid || !Form::isEmpty( $this->getSalePrice() );
        $valid = $valid || !empty( $this->getTieredPricingRules() );
        $valid = $valid || !Form::isEmpty( $this->getMinimum() );
        $valid = $valid || !Form::isEmpty( $this->getDiscount() );
        $valid = $valid || $this->getSettings()->getPriorityType() === 'flexible';
        $valid = apply_filters( 'tiered_pricing_table/global_pricing/validation', $valid, $this );
        if ( !$valid ) {
            throw new Exception(esc_html__( 'The pricing rule does not affect either prices or product quantity. The rule will be skipped.', 'tier-pricing-table' ));
        }
    }

    public function isValidPricing() : bool {
        try {
            $this->validatePricing();
        } catch ( Exception $e ) {
            return false;
        }
        return true;
    }

    public function getMinimum() : ?int {
        return $this->minimum;
    }

    public function setMinimum( ?int $minimum ) {
        $this->minimum = ( intval( $minimum ) > 1 ? $minimum : null );
    }

    public function getMixAndMatchMinQuantity() : ?bool {
        return $this->mixAndMatchMinQuantity;
    }

    public function setMixAndMatchMinQuantity( ?bool $mixAndMatch ) {
        $this->mixAndMatchMinQuantity = $mixAndMatch;
    }

    public function getIncludedProductCategories() : array {
        return $this->includedProductCategories;
    }

    public function getExcludedProductCategories() : array {
        return $this->excludedProductCategories;
    }

    public function setIncludedProductCategories( array $includedProductCategories ) {
        $this->includedProductCategories = $includedProductCategories;
    }

    public function setExcludedProductCategories( array $excludedProductCategories ) {
        $this->excludedProductCategories = $excludedProductCategories;
    }

    public function getIncludedProductTags() : array {
        return $this->includedProductTags;
    }

    public function getExcludedProductTags() : array {
        return $this->excludedProductTags;
    }

    public function setIncludedProductTags( array $includedProductTags ) {
        $this->includedProductTags = $includedProductTags;
    }

    public function setExcludedProductTags( array $excludedProductTags ) {
        $this->excludedProductTags = $excludedProductTags;
    }

    public function getIncludedProductBrands() : array {
        return $this->includedProductBrands;
    }

    public function getExcludedProductBrands() : array {
        return $this->excludedProductBrands;
    }

    public function setIncludedProductBrands( array $includedProductBrands ) {
        $this->includedProductBrands = $includedProductBrands;
    }

    public function setExcludedProductBrands( array $excludedProductBrands ) {
        $this->excludedProductBrands = $excludedProductBrands;
    }

    public function getIncludedProducts() : array {
        return $this->includedProducts;
    }

    public function getExcludedProducts() : array {
        return $this->excludedProducts;
    }

    public function setIncludedProducts( array $includedProducts ) {
        $this->includedProducts = $includedProducts;
    }

    public function setExcludedProducts( array $excludedProducts ) {
        $this->excludedProducts = $excludedProducts;
    }

    public function getIncludedUserRoles() : array {
        return $this->includedUsersRole;
    }

    public function getExcludedUserRoles() : array {
        return $this->excludedUsersRole;
    }

    public function setIncludedUsersRole( array $includedUsersRole ) {
        $this->includedUsersRole = $includedUsersRole;
    }

    public function setExcludedUsersRole( array $excludedUsersRole ) {
        $this->excludedUsersRole = $excludedUsersRole;
    }

    public function getIncludedUsers() : array {
        return $this->includedUsers;
    }

    public function getExcludedUsers() : array {
        return $this->excludedUsers;
    }

    public function setIncludedUsers( array $includedUsers ) {
        $this->includedUsers = $includedUsers;
    }

    public function setExcludedUsers( array $excludedUsers ) {
        $this->excludedUsers = $excludedUsers;
    }

    public function getSettings() : RuleSettings {
        if ( !$this->priorityOptions ) {
            $this->priorityOptions = new RuleSettings($this);
        }
        return $this->priorityOptions;
    }

    public function asArray() : array {
        return array(
            'pricing_type'          => $this->getPricingType(),
            'regular_price'         => $this->getRegularPrice(),
            'sale_price'            => $this->getSalePrice(),
            'discount'              => $this->getDiscount(),
            'discount_type'         => $this->getDiscountType(),
            'applying_type'         => $this->getApplyingType(),
            'tiered_pricing_type'   => $this->getTieredPricingType(),
            'percentage_rules'      => $this->getPercentageTieredPricingRules(),
            'fixed_rules'           => $this->getFixedTieredPricingRules(),
            'minimum'               => $this->getMinimum(),
            'mix_and_match_minimum' => $this->getMixAndMatchMinQuantity(),
            'tax_status'            => $this->getTaxStatus(),
            'tax_class'             => $this->getTaxClass(),
            'included_categories'   => $this->getIncludedProductCategories(),
            'included_tags'         => $this->getIncludedProductTags(),
            'included_brands'       => $this->getIncludedProductBrands(),
            'included_products'     => $this->getIncludedProducts(),
            'included_users'        => $this->getIncludedUsers(),
            'included_users_role'   => $this->getIncludedUserRoles(),
            'excluded_categories'   => $this->getExcludedProductCategories(),
            'excluded_tags'         => $this->getExcludedProductTags(),
            'excluded_brands'       => $this->getExcludedProductBrands(),
            'excluded_products'     => $this->getExcludedProducts(),
            'excluded_users'        => $this->getExcludedUsers(),
            'excluded_users_role'   => $this->getExcludedUserRoles(),
            'rule_id'               => $this->getId(),
            'is_suspended'          => $this->isSuspended(),
        );
    }

    public function save() {
        $dataToUpdate = array(
            '_tpt_pricing_type'          => $this->getPricingType(),
            '_tpt_regular_price'         => $this->getRegularPrice(),
            '_tpt_sale_price'            => $this->getSalePrice(),
            '_tpt_discount'              => $this->getDiscount(),
            '_tpt_discount_type'         => $this->getDiscountType(),
            '_tpt_applying_type'         => $this->getApplyingType(),
            '_tpt_tiered_pricing_type'   => $this->getTieredPricingType(),
            '_tpt_percentage_rules'      => $this->getPercentageTieredPricingRules(),
            '_tpt_fixed_rules'           => $this->getFixedTieredPricingRules(),
            '_tpt_minimum'               => $this->getMinimum(),
            '_tpt_mix_and_match_minimum' => ( is_null( $this->getMixAndMatchMinQuantity() ) ? '' : wc_bool_to_string( $this->getMixAndMatchMinQuantity() ) ),
            '_tpt_tax_status'            => $this->getTaxStatus(),
            '_tpt_tax_class'             => $this->getTaxClass(),
            '_tpt_included_categories'   => $this->getIncludedProductCategories(),
            '_tpt_included_tags'         => $this->getIncludedProductTags(),
            '_tpt_included_brands'       => $this->getIncludedProductBrands(),
            '_tpt_included_products'     => $this->getIncludedProducts(),
            '_tpt_included_users'        => $this->getIncludedUsers(),
            '_tpt_included_user_roles'   => $this->getIncludedUserRoles(),
            '_tpt_excluded_categories'   => $this->getExcludedProductCategories(),
            '_tpt_excluded_tags'         => $this->getExcludedProductTags(),
            '_tpt_excluded_brands'       => $this->getExcludedProductBrands(),
            '_tpt_excluded_products'     => $this->getExcludedProducts(),
            '_tpt_excluded_users'        => $this->getExcludedUsers(),
            '_tpt_excluded_user_roles'   => $this->getExcludedUserRoles(),
            '_tpt_is_suspended'          => wc_bool_to_string( $this->isSuspended() ),
        );
        foreach ( $dataToUpdate as $key => $value ) {
            update_post_meta( $this->getId(), $key, $value );
        }
    }

    public static function build( $ruleId ) : self {
        // Simple data to read
        $dataToRead = array(
            '_tpt_pricing_type'          => 'pricing_type',
            '_tpt_sale_price'            => 'sale_price',
            '_tpt_regular_price'         => 'regular_price',
            '_tpt_discount'              => 'discount',
            '_tpt_discount_type'         => 'discount_type',
            '_tpt_applying_type'         => 'applying_type',
            '_tpt_tiered_pricing_type'   => 'tiered_pricing_type',
            '_tpt_minimum'               => 'minimum',
            '_tpt_mix_and_match_minimum' => 'mix_and_match_minimum',
            '_tpt_tax_status'            => 'tax_status',
            '_tpt_tax_class'             => 'tax_class',
            '_tpt_is_suspended'          => 'is_suspended',
        );
        $data = array();
        foreach ( $dataToRead as $key => $name ) {
            $data[$name] = get_post_meta( $ruleId, $key, true );
        }
        $priceRule = self::fromArray( $data );
        $existingRoles = wp_roles()->roles;
        $includedCategoriesIds = array_filter( array_map( 'intval', (array) get_post_meta( $ruleId, '_tpt_included_categories', true ) ) );
        $includedTagsIds = array_filter( array_map( 'intval', (array) get_post_meta( $ruleId, '_tpt_included_tags', true ) ) );
        $includedBrandsIds = array_filter( array_map( 'intval', (array) get_post_meta( $ruleId, '_tpt_included_brands', true ) ) );
        $includedProductsIds = array_filter( array_map( 'intval', (array) get_post_meta( $ruleId, '_tpt_included_products', true ) ) );
        $includedUsersRole = array_filter( (array) get_post_meta( $ruleId, '_tpt_included_user_roles', true ), function ( $role ) use($existingRoles) {
            return array_key_exists( $role, $existingRoles );
        } );
        $includedUsers = array_filter( array_map( 'intval', (array) get_post_meta( $ruleId, '_tpt_included_users', true ) ) );
        $excludedCategoriesIds = array_filter( array_map( 'intval', (array) get_post_meta( $ruleId, '_tpt_excluded_categories', true ) ) );
        $excludedTagsIds = array_filter( array_map( 'intval', (array) get_post_meta( $ruleId, '_tpt_excluded_tags', true ) ) );
        $excludedBrandsIds = array_filter( array_map( 'intval', (array) get_post_meta( $ruleId, '_tpt_excluded_brands', true ) ) );
        $excludedProductsIds = array_filter( array_map( 'intval', (array) get_post_meta( $ruleId, '_tpt_excluded_products', true ) ) );
        $excludedUsersRole = array_filter( (array) get_post_meta( $ruleId, '_tpt_excluded_user_roles', true ), function ( $role ) use($existingRoles) {
            return array_key_exists( $role, $existingRoles );
        } );
        $excludedUsers = array_filter( array_map( 'intval', (array) get_post_meta( $ruleId, '_tpt_excluded_users', true ) ) );
        $isSuspended = get_post_meta( $ruleId, '_tpt_is_suspended', true ) === 'yes';
        $priceRule->setPercentageTieredPricingRules( self::readPricingRules( 'percentage', $ruleId ) );
        $priceRule->setFixedTieredPricingRules( self::readPricingRules( 'fixed', $ruleId ) );
        $priceRule->setIncludedProductCategories( $includedCategoriesIds );
        $priceRule->setIncludedProductTags( $includedTagsIds );
        $priceRule->setIncludedProductBrands( $includedBrandsIds );
        $priceRule->setIncludedUsers( $includedUsers );
        $priceRule->setIncludedUsersRole( $includedUsersRole );
        $priceRule->setIncludedProducts( $includedProductsIds );
        $priceRule->setExcludedProductCategories( $excludedCategoriesIds );
        $priceRule->setExcludedProductTags( $excludedTagsIds );
        $priceRule->setExcludedProductBrands( $excludedBrandsIds );
        $priceRule->setExcludedUsers( $excludedUsers );
        $priceRule->setExcludedUsersRole( $excludedUsersRole );
        $priceRule->setExcludedProducts( $excludedProductsIds );
        $priceRule->setIsSuspended( $isSuspended );
        $priceRule->setId( $ruleId );
        return apply_filters( 'tiered_pricing_table/global_pricing/after_built_rule', $priceRule );
    }

    protected static function readPricingRules( $type, $id ) : array {
        $type = ( in_array( $type, array('percentage', 'fixed') ) ? $type : 'fixed' );
        $rules = get_post_meta( $id, "_tpt_{$type}_rules", true );
        $rules = ( !empty( $rules ) ? $rules : array() );
        $rules = ( is_array( $rules ) ? array_filter( $rules ) : array() );
        ksort( $rules );
        return $rules;
    }

    public function setIsSuspended( bool $isSuspended ) {
        $this->isSuspended = $isSuspended;
    }

    public function suspend() {
        $this->setIsSuspended( true );
    }

    public function reactivate() {
        $this->setIsSuspended( false );
    }

    public function isSuspended() : bool {
        return $this->isSuspended;
    }

    /**
     * Wrapper for the main "match" function to provide the hook for 3rd party devs
     *
     * @param  WP_User  $user
     * @param  WC_Product  $product
     *
     * @return bool
     */
    public function matchRequirements( WP_User $user, WC_Product $product ) : bool {
        $matched = $this->_matchRequirements( $user, $product );
        return apply_filters(
            'tiered_pricing_table/global_pricing/match_requirements',
            $matched,
            $this,
            $user,
            $product
        );
    }

    protected function _matchRequirements( WP_User $user, WC_Product $product ) : bool {
        $parentProduct = ( $product->is_type( array('variation', 'subscription-variation') ) ? wc_get_product( $product->get_parent_id() ) : $product );
        /**
         * 1. Check for product exclusion
         *
         * If the product in exclusion - pricing rule does not match immediately
         */
        $excludedProducts = $this->translateIds( $this->getExcludedProducts(), 'product' );
        if ( !empty( $excludedProducts ) ) {
            if ( in_array( $product->get_id(), $excludedProducts ) || in_array( $parentProduct->get_id(), $excludedProducts ) ) {
                return false;
            }
        }
        $excludedProductCategories = $this->translateIds( $this->getExcludedProductCategories(), 'product_cat' );
        if ( !empty( $excludedProductCategories ) ) {
            if ( !empty( array_intersect( $parentProduct->get_category_ids(), $excludedProductCategories ) ) ) {
                return false;
            }
        }
        $excludedProductTags = $this->translateIds( $this->getExcludedProductTags(), 'product_tag' );
        if ( !empty( $excludedProductTags ) ) {
            if ( !empty( array_intersect( $parentProduct->get_tag_ids(), $excludedProductTags ) ) ) {
                return false;
            }
        }
        $excludedProductBrands = $this->translateIds( $this->getExcludedProductBrands(), 'product_brand' );
        if ( !empty( $excludedProductBrands ) ) {
            $productBrands = wp_get_post_terms( $parentProduct->get_id(), 'product_brand', array(
                'fields' => 'ids',
            ) );
            if ( is_wp_error( $productBrands ) ) {
                $productBrands = [];
            }
            if ( !empty( array_intersect( $productBrands, $excludedProductBrands ) ) ) {
                return false;
            }
        }
        /**
         * 2. Check for user exclusion
         *
         * If users in exclusion - pricing rule does not match immediately
         */
        if ( in_array( $user->ID, $this->getExcludedUsers() ) ) {
            return false;
        }
        foreach ( $this->getExcludedUserRoles() as $role ) {
            if ( in_array( $role, $user->roles ) ) {
                return false;
            }
        }
        /**
         * 3. Check for rule limitation for specific products
         *
         * If yes - match rule only for selected product/product categories
         */
        $productMatched = false;
        $productLimitations = false;
        $includedProducts = $this->translateIds( $this->getIncludedProducts(), 'product' );
        if ( !empty( $includedProducts ) ) {
            $productLimitations = true;
            if ( in_array( $product->get_id(), $includedProducts ) || in_array( $parentProduct->get_id(), $includedProducts ) ) {
                $productMatched = true;
            }
        }
        $includedProductCategories = $this->translateIds( $this->getIncludedProductCategories(), 'product_cat' );
        if ( !empty( $includedProductCategories ) ) {
            $productLimitations = true;
            if ( !empty( array_intersect( $parentProduct->get_category_ids(), $includedProductCategories ) ) ) {
                $productMatched = true;
            }
        }
        $includedProductTags = $this->translateIds( $this->getIncludedProductTags(), 'product_tag' );
        if ( !empty( $includedProductTags ) ) {
            $productLimitations = true;
            if ( !empty( array_intersect( $parentProduct->get_tag_ids(), $includedProductTags ) ) ) {
                $productMatched = true;
            }
        }
        $includedProductBrands = $this->translateIds( $this->getIncludedProductBrands(), 'product_brand' );
        if ( !empty( $includedProductBrands ) ) {
            $productLimitations = true;
            $productBrands = wp_get_post_terms( $parentProduct->get_id(), 'product_brand', array(
                'fields' => 'ids',
            ) );
            if ( is_wp_error( $productBrands ) ) {
                $productBrands = [];
            }
            if ( !empty( array_intersect( $productBrands, $includedProductBrands ) ) ) {
                $productMatched = true;
            }
        }
        // There is product limitation and the product/category does not match the rule
        if ( $productLimitations && !$productMatched ) {
            return false;
        }
        /**
         * 4. If there are no user limits - match the rule immediately
         */
        if ( empty( $this->getIncludedUserRoles() ) && empty( $this->getIncludedUsers() ) ) {
            return true;
        }
        /**
         * 5. If there are user limits - check for user ID and user role.
         */
        if ( in_array( $user->ID, $this->getIncludedUsers() ) ) {
            return true;
        }
        foreach ( $this->getIncludedUserRoles() as $role ) {
            if ( in_array( $role, $user->roles ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Map a rule's stored object IDs to the language currently being viewed.
     *
     * A rule is created in a single language and stores the product/term IDs of that language. The
     * product being priced (and the term IDs returned by WooCommerce for it) are in the current
     * language, so under WPML/Polylang a raw ID comparison would never match on a translation. This
     * translates the stored IDs into the current language via the shared `wpml_object_id` filter
     * (implemented by both WPML and Polylang) so the comparison works across every translation.
     *
     * When no multilingual plugin is active the filter has no listeners, so the IDs are returned
     * unchanged with no overhead.
     *
     * @param  int[]   $ids   Object IDs as stored on the rule.
     * @param  string  $type  Element type: a post type ('product') or taxonomy ('product_cat',
     *                        'product_tag', 'product_brand').
     *
     * @return int[]
     */
    protected function translateIds( array $ids, string $type ) : array {
        if ( empty( $ids ) || !has_filter( 'wpml_object_id' ) ) {
            return $ids;
        }
        $translated = array();
        foreach ( $ids as $id ) {
            // The `true` argument keeps the original ID when the object has no translation in the
            // current language, preserving the previous behaviour for untranslated objects.
            $translated[] = (int) apply_filters(
                'wpml_object_id',
                $id,
                $type,
                true
            );
        }
        return $translated;
    }

}
