<?php

namespace TierPricingTable\Addons\TaxSettings;

use TierPricingTable\Addons\AbstractAddon;
use TierPricingTable\Addons\TaxSettings\API\TaxSettingsEndpoints;
use TierPricingTable\Addons\TaxSettings\Overrides\ProductsTaxService;
use TierPricingTable\Addons\TaxSettings\RoleBasedRulesIntegration\RoleBasedRulesIntegration;
use TierPricingTable\Addons\TaxSettings\Settings\Settings;
use TierPricingTable\Addons\TaxSettings\Overrides\RoleBasedTaxOptions;
class TaxSettingsAddon extends AbstractAddon {
    public function getName() : string {
        return __( 'Tax Options', 'tier-pricing-table' );
    }

    public function getDescription() : string {
        return __( 'Manage tax overrides and display options for user roles and global pricing rules.', 'tier-pricing-table' );
    }

    public function getIcon() : string {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>';
    }

    public function getSlug() : string {
        return 'tax-settings';
    }

    public function isEnabled() : bool {
        if ( get_option( 'woocommerce_calc_taxes' ) !== 'yes' ) {
            return false;
        }
        return parent::isEnabled();
    }

    public function run() {
        new GlobalRulesIntegration\GlobalRules();
        new RoleBasedRulesIntegration();
        new TaxSettingsEndpoints();
        new Settings();
    }

}
