<?php

namespace TierPricingTable\Addons\GlobalTieredPricing;

use TierPricingTable\PricingRule;
use TierPricingTable\Addons\TieredPricingCart\CartOptionsSubsection;
class PricingService {
    public function __construct() {
        /**
         * Main function to filter the tiered pricing rules
         *
         * @priority 30
         */
        add_filter(
            'tiered_pricing_table/price/pricing_rule',
            function ( PricingRule $pricingRule, $productId ) {
                $product = wc_get_product( $productId );
                if ( !$product ) {
                    return $pricingRule;
                }
                $globalPricingRule = GlobalPricingRulesRepository::getInstance()->getMatchedPricingRule( $product );
                if ( !$globalPricingRule ) {
                    return $pricingRule;
                }
                $priority = $globalPricingRule->getSettings()->getPriorityType();
                // If priority is set to default, use the global settings
                if ( 'default' === $priority ) {
                    $priority = ( CartOptionsSubsection::globalRulesOverrideProductLevelRules() ? 'override' : 'prefer-product' );
                }
                $pricingRule->logPricingModification( '[global rule]: Matched global pricing rule #' . $globalPricingRule->getId() . '  with priority: ' . $priority );
                $pricingRule = apply_filters(
                    'tiered_pricing_table/global_pricing/before_adjusting_pricing_rule',
                    $pricingRule,
                    $globalPricingRule,
                    $productId,
                    $priority
                );
                if ( 'flexible' === $priority ) {
                    $pricingRule = $this->addFlexibleGlobalPricing( $pricingRule, $globalPricingRule );
                } elseif ( 'prefer-product' === $priority ) {
                    // Do not modify if there are pricing rules set (in product or role-based or category-based)
                    if ( !empty( $pricingRule->getRules() ) || 'role-based' === $pricingRule->provider ) {
                        $pricingRule->logPricingModification( '[global rule]: There is pricing at product-level - do not modify the rule' );
                        return $pricingRule;
                    } else {
                        $pricingRule = $this->addPricing( $pricingRule, $globalPricingRule );
                        $pricingRule->logPricingModification( '[global rule]: Pricing rule was fully overridden by global pricing rule data.' );
                    }
                } else {
                    $pricingRule->logPricingModification( '[global rule]: Pricing rule was fully overridden by global pricing rule data.' );
                    $pricingRule = $this->addPricing( $pricingRule, $globalPricingRule );
                }
                return apply_filters(
                    'tiered_pricing_table/global_pricing/after_adjusting_pricing_rule',
                    $pricingRule,
                    $globalPricingRule,
                    $productId,
                    $priority
                );
            },
            30,
            2
        );
    }

    public function addFlexibleGlobalPricing( PricingRule $pricingRule, GlobalPricingRule $globalPricingRule ) : PricingRule {
        $updateRegularPricing = false;
        $updateTieredPricing = false;
        /**
         * Regular prices
         */
        if ( $globalPricingRule->getSettings()->getRegularPricingPriority() === 'prefer-role-based-product' ) {
            if ( $pricingRule->provider !== 'role-based' ) {
                $updateRegularPricing = true;
            }
        } else {
            $updateRegularPricing = true;
        }
        /**
         * Quantity Limits
         */
        $updateMinimum = false;
        $updateMixAndMatch = false;
        if ( $globalPricingRule->getSettings()->getQuantityLimitsPriority() === 'prefer-role-based-product' ) {
            if ( $pricingRule->provider !== 'role-based' ) {
                $updateMinimum = true;
                $updateMixAndMatch = true;
            }
        } elseif ( $globalPricingRule->getSettings()->getQuantityLimitsPriority() === 'prefer-product' ) {
            if ( empty( $pricingRule->getMinimum() ) ) {
                $updateMinimum = true;
            }
            if ( get_post_meta( $pricingRule->getProductId(), '_tiered_price_mix_and_match_minimum', true ) === '' ) {
                $updateMixAndMatch = true;
            }
        } else {
            $updateMinimum = true;
            $updateMixAndMatch = true;
        }
        /**
         * Tiered Pricing
         */
        if ( $globalPricingRule->getSettings()->getTieredPricingPriority() === 'prefer-role-based-product' ) {
            if ( $pricingRule->provider !== 'role-based' ) {
                $updateTieredPricing = true;
            }
        } elseif ( $globalPricingRule->getSettings()->getTieredPricingPriority() === 'prefer-product' ) {
            if ( empty( $pricingRule->getRules() ) ) {
                $updateTieredPricing = true;
            }
        } else {
            $updateTieredPricing = true;
        }
        if ( $updateRegularPricing ) {
        }
        if ( $updateMinimum || $updateMixAndMatch ) {
        }
        if ( $updateTieredPricing ) {
            $pricingRule->setRules( $globalPricingRule->getTieredPricingRules() );
            $pricingRule->setType( $globalPricingRule->getTieredPricingType() );
            $pricingRule->logPricingModification( '[global rule]: Tiered Pricing was updated by global rule' );
        }
        $pricingRule->provider = 'global-rules';
        $pricingRule->providerData['rule_id'] = $globalPricingRule->getId();
        // If tiered pricing is used from global rule or if mix and match is allowed for product-level tiered pricing
        if ( $updateTieredPricing || $globalPricingRule->getSettings()->isAllowTieredPricingMixAndMatch() ) {
            $pricingRule->providerData['applying_type'] = $globalPricingRule->getApplyingType();
        } else {
            $pricingRule->providerData['applying_type'] = 'individual';
        }
        return $pricingRule;
    }

    /**
     * Main function to filter pricing rules with global pricing rule data
     *
     * @param  PricingRule  $pricingRule
     * @param  GlobalPricingRule  $globalPricingRule
     *
     * @return PricingRule
     */
    public function addPricing( PricingRule $pricingRule, GlobalPricingRule $globalPricingRule ) : PricingRule {
        $pricingRule->setRules( $globalPricingRule->getTieredPricingRules() );
        $pricingRule->setType( $globalPricingRule->getTieredPricingType() );
        $pricingRule->provider = 'global-rules';
        $pricingRule->providerData['rule_id'] = $globalPricingRule->getId();
        $pricingRule->providerData['applying_type'] = $globalPricingRule->getApplyingType();
        return $pricingRule;
    }

}
