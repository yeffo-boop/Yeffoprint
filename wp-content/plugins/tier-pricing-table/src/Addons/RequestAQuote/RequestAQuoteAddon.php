<?php

namespace TierPricingTable\Addons\RequestAQuote;

use TierPricingTable\Addons\AbstractAddon;
class RequestAQuoteAddon extends AbstractAddon {
    public function getName() : string {
        return __( 'Request a Quote', 'tier-pricing-table' );
    }

    public function getDescription() : string {
        return __( 'Allow customers to request a custom quote.', 'tier-pricing-table' );
    }

    public function getIcon() : string {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>';
    }

    public function getSlug() : string {
        return 'request-a-quote';
    }

    public function run() {
        // Always init settings and forms REST API so users can create forms
        new Settings\Settings();
        add_action( 'rest_api_init', function () {
            ( new API\FormsEndpoint() )->register_routes();
            ( new API\SettingsEndpoint() )->register_routes();
        } );
        // Conditionally init the rest if forms exist
        $forms = Models\RequestQuoteForm::getAll();
        if ( empty( $forms ) ) {
            return;
        }
        new Frontend\QuoteFormDisplay();
        new Frontend\API\SubmitQuoteEndpoint();
        new Integration\PricingRulesIntegration();
    }

}
