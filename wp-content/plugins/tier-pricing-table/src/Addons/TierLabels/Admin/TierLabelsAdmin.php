<?php namespace TierPricingTable\Addons\TierLabels\Admin;

use TierPricingTable\Addons\GlobalTieredPricing\GlobalPricingRule;
use TierPricingTable\Addons\RoleBasedPricing\RoleBasedPricingRule;
use TierPricingTable\Addons\TierLabels\TierLabelsManager;
use TierPricingTable\Forms\Form;
use TierPricingTable\PricingRule;

class TierLabelsAdmin {

	/**
	 * @var TierLabelsManager
	 */
	private $manager;

	public function __construct( TierLabelsManager $manager ) {
		$this->manager = $manager;

		add_action( 'tiered_pricing_table/admin/tiered_pricing_rules_form/inputs', [ $this, 'renderInputField' ], 999,
				7 );
		add_action( 'tiered_pricing_table/admin/tiered_pricing_rules_form/after_pricing_type',
				[ $this, 'renderFirstRowField' ], 10, 4 );
		add_action( 'tiered_pricing_table/admin/components/tiered_pricing_rules_form/get_from_request',
				[ $this, 'saveField' ], 10, 6 );

		add_filter( 'tiered_pricing_table/price/pricing_rule', array( $this, 'addLabelDataToPricingRule' ), 1, 2 );

		add_filter( 'tiered_pricing_table/role_based_pricing/after_adjusting_pricing_rule',
				function ( PricingRule $pricingRule, RoleBasedPricingRule $roleBasedPricingRule ) {
					return $this->addLabelDataToPricingRule( $pricingRule, $pricingRule->getProductId(),
							$roleBasedPricingRule->getRole() );
				}, 2, 3 );

		add_filter( 'tiered_pricing_table/global_pricing/after_adjusting_pricing_rule', function (
				PricingRule $pricingRule,
				GlobalPricingRule $globalPricingRule
		) {
			return $this->addLabelDataToPricingRule( $pricingRule, $globalPricingRule->getId(), null, false );
		}, 3, 3 );

		add_filter( 'tiered_pricing_table/admin/tiered_pricing_rules_form/inputs_width', function ( $defaultWidth ) {

			$labels = $this->manager->getLabels();

			if ( empty( $labels ) ) {
				return $defaultWidth;
			}

			return min( 100, $defaultWidth + 20 );
		} );
	}

	public function renderInputField(
			$entityId,
			$amount,
			$role,
			$loop,
			$custom_prefix,
			$type
	) {
		$labels = $this->manager->getLabels();

		if ( empty( $labels ) ) {
			return;
		}

		$value = $this->getValueForAmount( $amount, $entityId, $role, $type );
		$name  = Form::getFieldName( 'tier_label_' . $type, $role, $loop, $custom_prefix );

		?>
		<select name="<?php echo esc_attr( $name ); ?>[]" style="width: 35%;">
			<option value=""><?php esc_html_e( 'No label', 'tier-pricing-table' ); ?></option>
			<?php foreach ( $labels as $label ) : ?>
				<option value="<?php echo esc_attr( $label->getId() ); ?>" <?php selected( $value,
						$label->getId() ); ?>>
					<?php echo esc_html( $label->getTitle() ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	public function renderFirstRowField(
			$entityId,
			$role,
			$loop,
			$custom_prefix
	) {
		$labels = $this->manager->getLabels();

		if ( empty( $labels ) ) {
			return;
		}

		$value = $this->getFirstRowValue( $entityId, 'fixed', $role );

		$options = [ '' => __( 'No label', 'tier-pricing-table' ) ];
		foreach ( $labels as $label ) {
			$options[ $label->getId() ] = $label->getTitle();
		}

		woocommerce_wp_select( array(
				'id'            => Form::getFieldName( 'tier_label_first_row', $role, $loop, $custom_prefix ),
				'label'         => __( 'Tier label (regular price)', 'tier-pricing-table' ),
				'value'         => $value,
				'options'       => $options,
				'wrapper_class' => is_null( $loop ) ? 'tiered-pricing-form-block' : 'tiered-pricing-form-variation-block',
		) );
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

		foreach ( array( 'fixed', 'percentage' ) as $pricingType ) {

			$value = Form::getFieldValue( 'tier_label_' . $pricingType, $role, $loop, $customPrefix, $request );

			$value   = is_array( $value ) ? $value : array();
			$amounts = 'fixed' === $pricingType ? $data['fixed_quantities'] : $data['percentage_quantities'];

			foreach ( $value as $key => $itemValue ) {
				if ( ! empty( $amounts[ $key ] ) ) {
					$_value[ $pricingType ][ $amounts[ $key ] ] = sanitize_text_field( $itemValue );
				}
			}

			$firstRowValue = Form::getFieldValue( 'tier_label_first_row', $role, $loop, $customPrefix, $request );

			if ( ! Form::isEmpty( $firstRowValue ) ) {
				$_value[ $pricingType ]['first_row'] = sanitize_text_field( $firstRowValue );
			}
		}

		update_post_meta( $entityId, $this->getMetaKey( $role ), $_value );
	}

	public function getValue( $entityId, $type, $role = null ): array {
		$value = (array) get_post_meta( $entityId, $this->getMetaKey( $role ), true );

		return ! empty( $value[ $type ] ) ? (array) $value[ $type ] : array();
	}

	public function getValueForAmount( $amount, $entityId, $role, $type ) {
		if ( ! $amount ) {
			return '';
		}

		$amount = intval( $amount );

		if ( $amount < 2 ) {
			return '';
		}

		$value = $this->getValue( $entityId, $type, $role );

		if ( array_key_exists( $amount, $value ) ) {
			return $value[ $amount ];
		}

		return '';
	}

	public function getFirstRowValue( $entityId, $type, $role ) {
		$value = $this->getValue( $entityId, $type, $role );

		if ( array_key_exists( 'first_row', $value ) ) {
			return $value['first_row'];
		}

		return '';
	}

	public function addLabelDataToPricingRule(
			PricingRule $pricingRule,
			$entityId,
			$role = null,
			$isProductRule = true
	): PricingRule {

		$percentageValues = $this->getValue( $entityId, 'percentage', $role );
		$fixedValues      = $this->getValue( $entityId, 'fixed', $role );

		if ( empty( $percentageValues ) || empty( $fixedValues ) ) {
			if ( $isProductRule ) {
				$product = wc_get_product( $entityId );
				if ( $product && $product->get_parent_id() ) {
					$percentageValues = $percentageValues ?: $this->getValue( $product->get_parent_id(), 'percentage',
							$role );
					$fixedValues      = $fixedValues ?: $this->getValue( $product->get_parent_id(), 'fixed', $role );
				}
			}
		}

		$percentageValues = array_filter( $percentageValues, function ( $value ) {
			return ! is_null( $this->manager->getLabel( $value ) );
		} );

		$fixedValues = array_filter( $fixedValues, function ( $value ) {
			return ! is_null( $this->manager->getLabel( $value ) );
		} );

		$firstRowValue = $this->getFirstRowValue( $entityId, 'fixed', $role );

		$pricingRule->data['tier_labels']['percentage'] = $percentageValues;
		$pricingRule->data['tier_labels']['fixed']      = $fixedValues;

		return $pricingRule;
	}

	public function getMetaKey( $role = null ): string {
		$rolePrefix = $role ? '_' . $role : '';

		return $rolePrefix . '_tiered_price_tier_labels';
	}
}
