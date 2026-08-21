<?php

namespace TierPricingTable\Addons\AdvancedQuantityOptions;

class RoleBasedOptions {
    /**
     * Form
     *
     * @var AdvancedQuantityOptionsForm
     */
    protected $form;

    public function __construct( AdvancedQuantityOptionsForm $form ) {
        $this->form = $form;
        add_action(
            'tiered_pricing_table/admin/role_based_rules/after_minimum_order_quantity_field',
            function ( $productId, $role, $loop = null ) {
                $this->form->render( $productId, $loop, $role );
            },
            10,
            3
        );
    }

}
