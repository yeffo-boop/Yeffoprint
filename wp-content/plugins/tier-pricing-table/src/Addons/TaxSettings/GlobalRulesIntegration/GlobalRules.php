<?php

namespace TierPricingTable\Addons\TaxSettings\GlobalRulesIntegration;

class GlobalRules {
    public function __construct() {
        add_filter(
            'tiered_pricing_table/global_pricing/form_tabs',
            function ( $tabs, $form ) {
                $settingsTab = array_pop( $tabs );
                $tabs[] = new TaxSettingsTab($form);
                $tabs[] = $settingsTab;
                return $tabs;
            },
            10,
            2
        );
    }

}
