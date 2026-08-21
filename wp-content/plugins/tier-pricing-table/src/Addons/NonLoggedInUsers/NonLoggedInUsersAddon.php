<?php

namespace TierPricingTable\Addons\NonLoggedInUsers;

use TierPricingTable\Addons\AbstractAddon;
class NonLoggedInUsersAddon extends AbstractAddon {
    public function getName() {
        return __( 'Non-Logged-In Users', 'tier-pricing-table' );
    }

    public function getDescription() {
        return __( 'Hide prices or prevent purchasing for users who are not logged in.', 'tier-pricing-table' );
    }

    public function getIcon() : string {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>';
    }

    public function getSlug() {
        return 'non-logged-in-users';
    }

    public function run() {
        add_filter( 'tiered_pricing_table/settings/general_subsections', array($this, 'addSettingsSubsection') );
    }

    public function addSettingsSubsection( $subsections ) {
        $subsections[] = NonLoggedInUsersSubsection::class;
        return $subsections;
    }

}
