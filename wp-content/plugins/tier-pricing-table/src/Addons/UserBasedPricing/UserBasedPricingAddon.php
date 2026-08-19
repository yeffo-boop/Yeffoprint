<?php

namespace TierPricingTable\Addons\UserBasedPricing;

use TierPricingTable\Addons\AbstractAddon;
class UserBasedPricingAddon extends AbstractAddon {
    const SETTING_ENABLE_KEY = 'enable_user_based_pricing_addon';

    public function getName() : string {
        return __( 'User-based pricing rules on individual products', 'tier-pricing-table' );
    }

    public function isActive() : bool {
        return $this->getContainer()->getSettings()->get( self::SETTING_ENABLE_KEY, 'yes' ) === 'yes';
    }

    public function getDescription() : string {
        return __( 'Enable customer-based pricing rules on individual products.', 'tier-pricing-table' );
    }

    public function getIcon() : string {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>';
    }

    public function getSlug() : string {
        return 'user-based-rules';
    }

    public function run() {
        // Enable pricing service
        add_filter( 'tiered_pricing_table/services/pricing_service_enabled', '__return_true' );
        new ProductManager();
    }

}
