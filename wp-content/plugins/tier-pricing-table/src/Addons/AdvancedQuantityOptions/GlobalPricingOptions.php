<?php

namespace TierPricingTable\Addons\AdvancedQuantityOptions;

use TierPricingTable\Addons\GlobalTieredPricing\GlobalPricingRule;
use TierPricingTable\CalculationLogic;
use TierPricingTable\PricingRule;
class GlobalPricingOptions {
    /**
     * Form
     *
     * @var AdvancedQuantityOptionsForm
     */
    protected $form;

    public function __construct( AdvancedQuantityOptionsForm $form ) {
        $this->form = $form;
        add_action( 'tiered_pricing_table/global_pricing/after_minimum_order_quantity_field', function ( $ruleId ) {
            $this->form->render(
                $ruleId,
                null,
                null,
                true,
                false
            );
        } );
    }

}
