<?php

namespace TierPricingTable\Addons\RoleBasedPricing;

use TierPricingTable\Addons\AbstractAddon;
use TierPricingTable\Addons\RoleBasedPricing\Export\RoleBasedPricingExport;
use TierPricingTable\Addons\RoleBasedPricing\Import\RoleBasedPricingImport;
class RoleBasedPricingAddon extends AbstractAddon {
    const SETTING_ENABLE_KEY = 'enable_role_based_pricing_addon';

    public function getName() : string {
        return __( 'Role-based pricing rules on individual products', 'tier-pricing-table' );
    }

    public function isActive() : bool {
        return $this->getContainer()->getSettings()->get( self::SETTING_ENABLE_KEY, 'yes' ) === 'yes';
    }

    public function getDescription() : string {
        return __( 'Enable role-based pricing rules on individual products.', 'tier-pricing-table' );
    }

    public function getIcon() : string {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>';
    }

    public function getSlug() : string {
        return 'role-based-rules';
    }

    public function run() {
        // Enable pricing service
        add_filter( 'tiered_pricing_table/services/pricing_service_enabled', '__return_true' );
        new ProductManager();
    }

}
