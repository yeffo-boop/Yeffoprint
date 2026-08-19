<?php

namespace TierPricingTable\Addons\CustomColumns\Columns;

use TierPricingTable\Addons\CustomColumns\Schema;
use TierPricingTable\Addons\GlobalTieredPricing\GlobalPricingRule;
use TierPricingTable\Addons\RoleBasedPricing\RoleBasedPricingRule;
use TierPricingTable\PricingRule;
use TierPricingTable\Forms\Form;
use TierPricingTable\TierPricingTablePlugin;
class CustomDataColumn extends AbstractCustomColumn {
    const TYPE = 'custom';

    public function getType() : string {
        return self::TYPE;
    }

    protected function hooks() {
        parent::hooks();
        $this->adminHooks();
    }

    public function isValid() : bool {
        $valid = parent::isValid();
        if ( !isset( $this->data['data_type'] ) ) {
            $valid = false;
        }
        if ( !in_array( $this->data['data_type'], array_keys( Schema::getAvailableDataTypes() ) ) ) {
            $valid = false;
        }
        return $valid;
    }

    public function adminHooks() {
        add_action(
            'tiered_pricing_table/role_based_rules/delete_role_rule',
            function ( $productId, $role ) {
                delete_post_meta( $productId, $this->getMetaKey( $role ) );
            },
            10,
            2
        );
        add_action(
            'tiered_pricing_table/admin/tiered_pricing_rules_form/after_pricing_type',
            array($this, 'renderFirstRowField'),
            10,
            7
        );
        add_action(
            'tiered_pricing_table/admin/tiered_pricing_rules_form/inputs',
            array($this, 'renderInputField'),
            10,
            7
        );
        // Each custom data field adds 25% of width for container
        add_filter( 'tiered_pricing_table/admin/tiered_pricing_rules_form/inputs_width', function ( $defaultWidth ) {
            $defaultWidth += 25;
            return min( 100, $defaultWidth );
        } );
    }

    public function savingHooks() {
        add_action(
            'tiered_pricing_table/admin/components/tiered_pricing_rules_form/get_from_request',
            array($this, 'saveField'),
            10,
            6
        );
    }

    public function pricingRuleAdjustmentHooks() {
        add_filter(
            'tiered_pricing_table/price/pricing_rule',
            array($this, 'addCustomColumnDataToPricingRule'),
            1,
            2
        );
        add_filter(
            'tiered_pricing_table/role_based_pricing/after_adjusting_pricing_rule',
            function ( PricingRule $pricingRule, RoleBasedPricingRule $roleBasedPricingRule ) {
                return $this->addCustomColumnDataToPricingRule( $pricingRule, $pricingRule->getProductId(), $roleBasedPricingRule->getRole() );
            },
            2,
            3
        );
        add_filter(
            'tiered_pricing_table/global_pricing/after_adjusting_pricing_rule',
            function ( PricingRule $pricingRule, GlobalPricingRule $globalPricingRule ) {
                return $this->addCustomColumnDataToPricingRule(
                    $pricingRule,
                    $globalPricingRule->getId(),
                    null,
                    false
                );
            },
            3,
            3
        );
    }

    public function addCustomColumnDataToPricingRule(
        PricingRule $pricingRule,
        $entityId,
        $role = null,
        $isProductRule = true
    ) : PricingRule {
        $percentageValues = $this->getValue( $entityId, 'percentage', $role );
        $fixedValues = $this->getValue( $entityId, 'fixed', $role );
        // Try to get variable product level value if values are empty.
        if ( empty( $percentageValues ) || empty( $fixedValues ) ) {
            if ( $isProductRule ) {
                $product = wc_get_product( $entityId );
                if ( !$product ) {
                    return $pricingRule;
                }
                if ( $product->get_parent_id() ) {
                    $percentageValues = ( $percentageValues ? $percentageValues : $this->getValue( $product->get_parent_id(), 'percentage', $role ) );
                    $fixedValues = ( $fixedValues ? $fixedValues : $this->getValue( $product->get_parent_id(), 'fixed', $role ) );
                }
            }
        }
        $pricingRule->customColumnsData[$this->getSlug()]['percentage'] = $percentageValues;
        $pricingRule->customColumnsData[$this->getSlug()]['fixed'] = $fixedValues;
        return $pricingRule;
    }

    public function renderInputField(
        $entityId,
        $amount,
        $role,
        $loop,
        $custom_prefix,
        $type
    ) {
        $value = $this->getValueForAmount(
            $amount,
            $entityId,
            $role,
            $loop,
            $custom_prefix,
            $type
        );
        $name = Form::getFieldName(
            $this->getSlug( $type ),
            $role,
            $loop,
            $custom_prefix
        );
        $class = '';
        if ( $this->getDataType() === 'price' ) {
            $class = 'wc_input_price';
            $value = wc_format_localized_price( $value );
        }
        ?>
		<input
			<?php 
        echo esc_attr( ( tpt_fs()->can_use_premium_code() ? '' : 'disabled' ) );
        ?>
				type="<?php 
        echo ( esc_attr( $this->getDataType() === 'number' ) ? 'number' : 'text' );
        ?>"
				class="<?php 
        echo esc_attr( $class );
        ?>"
				value="<?php 
        echo esc_attr( $value );
        ?>"
				placeholder="<?php 
        echo esc_attr( $this->getName() );
        ?>"
				name="<?php 
        echo esc_attr( $name );
        ?>[]"
		>
		<?php 
    }

    public function renderFirstRowField(
        $entityId,
        $role,
        $loop,
        $custom_prefix
    ) {
        $class = '';
        $value = $this->getFirstRowValue( $entityId, 'fixed', $role );
        if ( $this->getDataType() === 'price' ) {
            $class = 'wc_input_price';
            $value = wc_format_localized_price( $value );
        }
        woocommerce_wp_text_input( array(
            'custom_attributes' => ( tpt_fs()->can_use_premium_code() ? array() : array(
                'disabled' => 'disabled',
            ) ),
            'id'                => Form::getFieldName(
                $this->getSlug( 'first_row' ),
                $role,
                $loop,
                $custom_prefix
            ),
            'label'             => $this->getName() . ' ' . __( '(first row)', 'tier-pricing-table' ),
            'value'             => $value,
            'class'             => $class,
            'placeholder'       => $this->getName(),
            'wrapper_class'     => ( is_null( $loop ) ? 'tiered-pricing-form-block' : 'tiered-pricing-form-variation-block' ),
        ) );
        if ( !tpt_fs()->can_use_premium_code() ) {
            ?>
			<div style="padding: 0 20px 0 162px!important; color:red;">
				<?php 
            esc_html_e( 'Custom columns are available only in the premium version.', 'tier-pricing-table' );
            ?>
				<a target="_blank" href="<?php 
            echo esc_attr( tpt_fs()->get_upgrade_url() );
            ?>">
					<?php 
            esc_html_e( 'Upgrade you plan', 'tier-pricing-table' );
            ?>
				</a>
			</div>
			<?php 
        }
    }

    public function getValue( $entityId, $type, $role = null ) : array {
        $value = (array) get_post_meta( $entityId, $this->getMetaKey( $role ), true );
        return ( !empty( $value[$type] ) ? (array) $value[$type] : array() );
    }

    public function getValueForAmount(
        $amount,
        $entityId,
        $role,
        $loop,
        $custom_prefix,
        $type
    ) {
        if ( !$amount ) {
            return null;
        }
        $amount = intval( $amount );
        if ( $amount < 2 ) {
            return null;
        }
        $value = $this->getValue( $entityId, $type, $role );
        if ( array_key_exists( $amount, $value ) ) {
            return $value[$amount];
        }
        return null;
    }

    public function getFirstRowValue( $entityId, $type, $role ) {
        $value = $this->getValue( $entityId, $type, $role );
        if ( array_key_exists( 'first_row', $value ) ) {
            return $value['first_row'];
        }
        return null;
    }

    public function saveField(
        $entityId,
        $role,
        $loop,
        $customPrefix,
        $data,
        $request
    ) {
        $_value = array(
            'fixed'      => array(),
            'percentage' => array(),
        );
        foreach ( array('fixed', 'percentage') as $pricingType ) {
            $value = Form::getFieldValue(
                $this->getSlug( $pricingType ),
                $role,
                $loop,
                $customPrefix,
                $request
            );
            $value = ( is_array( $value ) ? $value : array() );
            $amounts = ( 'fixed' === $pricingType ? $data['fixed_quantities'] : $data['percentage_quantities'] );
            foreach ( $value as $key => $itemValue ) {
                if ( !empty( $amounts[$key] ) ) {
                    $_value[$pricingType][$amounts[$key]] = $this->sanitizeValue( $itemValue );
                }
            }
            $firstRowValue = Form::getFieldValue(
                $this->getSlug( 'first_row' ),
                $role,
                $loop,
                $customPrefix,
                $request
            );
            if ( !Form::isEmpty( $firstRowValue ) ) {
                $_value[$pricingType]['first_row'] = $this->sanitizeValue( $firstRowValue );
            }
        }
        update_post_meta( $entityId, $this->getMetaKey( $role ), $_value );
    }

    protected function _getSingleRowValue( PricingRule $pricingRule, $currentTierQuantity = null ) {
        $key = ( $currentTierQuantity ? $currentTierQuantity : 'first_row' );
        $value = '';
        if ( !empty( $pricingRule->customColumnsData[$this->getSlug()][$pricingRule->getType()] ) ) {
            $data = $pricingRule->customColumnsData[$this->getSlug()][$pricingRule->getType()];
            if ( array_key_exists( $key, $data ) ) {
                $value = $data[$key];
            }
        }
        if ( !Form::isEmpty( $value ) ) {
            if ( $this->getDataType() === 'price' ) {
                $value = wc_price( wc_get_price_to_display( wc_get_product( $pricingRule->getProductId() ), array(
                    'qty'   => 1,
                    'price' => $value,
                ) ) );
            }
        }
        return apply_filters(
            'tiered_pricing_table/custom_columns/value',
            $value,
            $pricingRule,
            $currentTierQuantity,
            $this
        );
    }

    public function getDataType() : string {
        return $this->data['data_type'] ?? 'price';
    }

    protected function sanitizeValue( $value ) {
        $value = ( Form::isEmpty( $value ) ? null : $value );
        if ( 'text' === $this->getDataType() ) {
            return sanitize_text_field( $value );
        } elseif ( 'number' === $this->getDataType() ) {
            return floatval( $value );
        } elseif ( 'price' === $this->getDataType() ) {
            return wc_format_decimal( $value );
        }
        return false;
    }

    public function getMetaKey( $role = null ) : string {
        $rolePrefix = ( $role ? '_' . $role : '' );
        return $rolePrefix . '_tiered_price_custom_column_' . $this->getSlug();
    }

}
