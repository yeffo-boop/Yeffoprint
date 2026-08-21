<?php namespace TierPricingTable\Addons\RequestAQuote\Integration;

use TierPricingTable\Addons\GlobalTieredPricing\CPT\GlobalTieredPricingCPT;
use TierPricingTable\Addons\RequestAQuote\Models\RequestQuoteForm;
use TierPricingTable\Forms\Form;
use TierPricingTable\PricingRule;

class PricingRulesIntegration {

	public function __construct() {
		// Unified Render for Quote Form Selector
		add_action( 'tiered_pricing_table/admin/tiered_pricing_rules_form/form_end', array( $this, 'renderFormSelect' ),
				10, 4 );

		// Product Level Integration (Saving)
		add_action( 'woocommerce_process_product_meta', array( $this, 'saveProductFormSelect' ) );

		// Variation Level Integration (Saving)
		add_action( 'woocommerce_save_product_variation', array( $this, 'saveVariationFormSelect' ), 10, 2 );

		// Global Rules Integration (Saving)
		add_action( 'tiered_pricing_table/global_pricing/before_updating', array( $this, 'saveGlobalFormSelect' ), 10,
				2 );

		// Role-based Integration (Saving)
		add_action( 'tiered_pricing_table/role_based_rules/save_role_based_rules', array( $this, 'saveRoleBasedMeta' ),
				10, 4 );

		// Inject to PricingRule
		add_filter( 'tiered_pricing_table/price/pricing_rule', array( $this, 'addRequestAQuoteToPricingRule' ), 99, 2 );
	}

	public function addRequestAQuoteToPricingRule( PricingRule $pricingRule, $productId ): PricingRule {

		if ( $pricingRule->provider === 'role-based' && ! empty( $pricingRule->providerData['role'] ) ) {
			$role   = $pricingRule->providerData['role'];
			$formId = get_post_meta( $productId, "_{$role}__tier_pricing_table_quote_form_id", true );

			if ( ! $formId ) {
				$product = wc_get_product( $productId );
				if ( $product && $product->is_type( 'variation' ) ) {
					$parentId = $product->get_parent_id();
					$formId   = get_post_meta( $parentId, "_{$role}__tier_pricing_table_quote_form_id", true );
					if ( $formId ) {
						$pricingRule->data['tier_pricing_table_quote_form_id']               = $formId;
						$pricingRule->data['tier_pricing_table_quote_auto_open_quantity']    = get_post_meta( $parentId,
								"_{$role}__tier_pricing_table_quote_auto_open_quantity", true );
						$pricingRule->data['tier_pricing_table_quote_integrated_label_text'] = get_post_meta( $parentId,
								"_{$role}__tier_pricing_table_quote_integrated_label_text", true );

						return $pricingRule;
					}
				}
			} else {
				$pricingRule->data['tier_pricing_table_quote_form_id']               = $formId;
				$pricingRule->data['tier_pricing_table_quote_auto_open_quantity']    = get_post_meta( $productId,
						"_{$role}__tier_pricing_table_quote_auto_open_quantity", true );
				$pricingRule->data['tier_pricing_table_quote_integrated_label_text'] = get_post_meta( $productId,
						"_{$role}__tier_pricing_table_quote_integrated_label_text", true );

				return $pricingRule;
			}
		}

		if ( $pricingRule->provider === 'global-rules' && ! empty( $pricingRule->providerData['rule_id'] ) ) {
			$ruleId = $pricingRule->providerData['rule_id'];
			$formId = get_post_meta( $ruleId, '_tier_pricing_table_quote_form_id', true );
			if ( $formId ) {
				$pricingRule->data['tier_pricing_table_quote_form_id']               = $formId;
				$pricingRule->data['tier_pricing_table_quote_auto_open_quantity']    = get_post_meta( $ruleId,
						'_tier_pricing_table_quote_auto_open_quantity', true );
				$pricingRule->data['tier_pricing_table_quote_integrated_label_text'] = get_post_meta( $ruleId,
						'_tier_pricing_table_quote_integrated_label_text', true );

				return $pricingRule;
			}
		}

		$formId = get_post_meta( $productId, '_tier_pricing_table_quote_form_id', true );
		if ( $formId ) {
			$pricingRule->data['tier_pricing_table_quote_form_id']               = $formId;
			$pricingRule->data['tier_pricing_table_quote_auto_open_quantity']    = get_post_meta( $productId,
					'_tier_pricing_table_quote_auto_open_quantity', true );
			$pricingRule->data['tier_pricing_table_quote_integrated_label_text'] = get_post_meta( $productId,
					'_tier_pricing_table_quote_integrated_label_text', true );

			return $pricingRule;
		}

		$product = wc_get_product( $productId );
		if ( $product && $product->is_type( 'variation' ) ) {
			$parentId = $product->get_parent_id();
			$formId   = get_post_meta( $parentId, '_tier_pricing_table_quote_form_id', true );
			if ( $formId ) {
				$pricingRule->data['tier_pricing_table_quote_form_id']               = $formId;
				$pricingRule->data['tier_pricing_table_quote_auto_open_quantity']    = get_post_meta( $parentId,
						'_tier_pricing_table_quote_auto_open_quantity', true );
				$pricingRule->data['tier_pricing_table_quote_integrated_label_text'] = get_post_meta( $parentId,
						'_tier_pricing_table_quote_integrated_label_text', true );
			}
		}

		return $pricingRule;
	}

	public function renderFormSelect( $entityId, $role = null, $loop = null, $prefix = '' ) {

		$place = $this->classifyPlace( $entityId, $role, $loop );

		if ( 'user' === $place ) {
			return;
		}

		$currentFormId = $role ? get_post_meta( $entityId, "_{$role}__tier_pricing_table_quote_form_id",
				true ) : get_post_meta( $entityId, '_tier_pricing_table_quote_form_id', true );

		if ( 'global' === $place ) {
			?>
			<style>

				select[name^="tiered_pricing_quote_"] {
					width: 75% !important;
				}
			</style>
			<div class="tpt-global-pricing-title">
				<?php esc_html_e( 'Request a Quote', 'tiered-pricing-table' ); ?>
			</div>
			<?php
			$this->renderSelectField( $currentFormId, $entityId, $role, $loop, $prefix );
		} else {
			?>
			<hr style="border:none; border-top: 1px solid #ededed;">
			<?php
			$this->renderSelectField( $currentFormId, $entityId, $role, $loop, $prefix );
		}

	}

	public function saveProductFormSelect( $productId ) {
		$this->saveMeta( $productId );
	}

	public function saveVariationFormSelect( $variationId, $loop ) {
		$formId = Form::getFieldValue( 'quote_form_id', null, $loop, '' );
		if ( ! is_null( $formId ) ) {
			if ( $formId !== '' ) {
				update_post_meta( $variationId, '_tier_pricing_table_quote_form_id', sanitize_text_field( $formId ) );
			} else {
				delete_post_meta( $variationId, '_tier_pricing_table_quote_form_id' );
			}
		}

		$autoOpen = Form::getFieldValue( 'quote_auto_open_quantity', null, $loop, '' );
		if ( ! is_null( $autoOpen ) ) {
			if ( $autoOpen !== '' ) {
				update_post_meta( $variationId, '_tier_pricing_table_quote_auto_open_quantity',
						sanitize_text_field( $autoOpen ) );
			} else {
				delete_post_meta( $variationId, '_tier_pricing_table_quote_auto_open_quantity' );
			}
		}

		$label = Form::getFieldValue( 'quote_integrated_label_text', null, $loop, '' );
		if ( ! is_null( $label ) ) {
			if ( $label !== '' ) {
				update_post_meta( $variationId, '_tier_pricing_table_quote_integrated_label_text',
						sanitize_text_field( $label ) );
			} else {
				delete_post_meta( $variationId, '_tier_pricing_table_quote_integrated_label_text' );
			}
		}
	}

	public function saveGlobalFormSelect( $pricingRule, $ruleId ) {
		$this->saveMeta( $ruleId );
	}

	public function saveRoleBasedMeta( $productId, $data, $role, $loop = null ) {

		$formId = Form::getFieldValue( 'quote_form_id', $role, $loop, '', $data );
		if ( ! is_null( $formId ) ) {
			update_post_meta( $productId, "_{$role}__tier_pricing_table_quote_form_id",
					sanitize_text_field( $formId ) );
		} else {
			delete_post_meta( $productId, "_{$role}__tier_pricing_table_quote_form_id" );
		}

		$autoOpen = Form::getFieldValue( 'quote_auto_open_quantity', $role, $loop, '', $data );
		if ( ! is_null( $autoOpen ) ) {
			update_post_meta( $productId, "_{$role}__tier_pricing_table_quote_auto_open_quantity",
					sanitize_text_field( $autoOpen ) );
		} else {
			delete_post_meta( $productId, "_{$role}__tier_pricing_table_quote_auto_open_quantity" );
		}

		$label = Form::getFieldValue( 'quote_integrated_label_text', $role, $loop, '', $data );
		if ( ! is_null( $label ) ) {
			update_post_meta( $productId, "_{$role}__tier_pricing_table_quote_integrated_label_text",
					sanitize_text_field( $label ) );
		} else {
			delete_post_meta( $productId, "_{$role}__tier_pricing_table_quote_integrated_label_text" );
		}
	}

	private function renderSelectField( $currentFormId, $entityId, $role = null, $loop = null, $prefix = '' ) {
		$forms = RequestQuoteForm::getAll();

		if ( empty( $forms ) ) {
			return; // No forms created yet
		}

		$options = array(
				'' => __( 'None', 'tier-pricing-table' ),
		);

		$formsData = array();

		foreach ( $forms as $form ) {
			$options[ $form->getId() ]   = $form->getName();
			$formsData[ $form->getId() ] = array(
					'display_position' => $form->getDisplayPosition(),
			);
		}

		$idSuffix = $role ? '_' . $role : '';
		$idSuffix .= ! is_null( $loop ) ? '_' . $loop : '';

		$formIdFieldId = '_tiered_pricing_quote_form_id' . $idSuffix;

		$nameFormId   = Form::getFieldName( 'quote_form_id', $role, $loop, $prefix );
		$nameLabel    = Form::getFieldName( 'quote_integrated_label_text', $role, $loop, $prefix );
		$nameAutoOpen = Form::getFieldName( 'quote_auto_open_quantity', $role, $loop, $prefix );

		$labelVal    = $role ? get_post_meta( $entityId, "_{$role}__tier_pricing_table_quote_integrated_label_text",
				true ) : get_post_meta( $entityId, '_tier_pricing_table_quote_integrated_label_text', true );
		$autoOpenVal = $role ? get_post_meta( $entityId, "_{$role}__tier_pricing_table_quote_auto_open_quantity",
				true ) : get_post_meta( $entityId, '_tier_pricing_table_quote_auto_open_quantity', true );

		woocommerce_wp_select( array(
				'id'      => $formIdFieldId,
				'name'    => $nameFormId,
				'value'   => $currentFormId,
				'options' => $options,
				'label'   => __( 'Request a Quote Form', 'tier-pricing-table' ),
				'class'   => 'select short tpt-quote-form-select',
		) );

		$wrapperStyle = ( ! empty( $currentFormId ) ) ? '' : 'display:none;';
		echo '<div id="tpt-quote-entity-settings-wrapper' . esc_attr( $idSuffix ) . '" class="tpt-quote-settings-wrapper" style="' . $wrapperStyle . '">';

		$labelStyle = 'display:none;';
		if ( ! empty( $currentFormId ) && isset( $formsData[ $currentFormId ] ) && $formsData[ $currentFormId ]['display_position'] === 'integrated' ) {
			$labelStyle = '';
		}
		echo '<div id="tpt-quote-integrated-label-wrapper' . esc_attr( $idSuffix ) . '" class="tpt-quote-label-wrapper" style="' . $labelStyle . '">';
		woocommerce_wp_text_input( array(
				'id'          => '_tier_pricing_table_quote_integrated_label_text' . $idSuffix,
				'name'        => $nameLabel,
				'value'       => $labelVal,
				'placeholder' => __( 'Leave blank to use the form default', 'tier-pricing-table' ),
				'label'       => __( 'Quantity Label', 'tier-pricing-table' ),
				'description' => __( 'Override the quantity label.', 'tier-pricing-table' ),
				'desc_tip'    => false,
		) );
		echo '</div>';

		woocommerce_wp_text_input( array(
				'id'                => '_tier_pricing_table_quote_auto_open_quantity' . $idSuffix,
				'name'              => $nameAutoOpen,
				'value'             => $autoOpenVal,
				'type'              => 'number',
				'placeholder'       => __( 'Leave blank to use the form default', 'tier-pricing-table' ),
				'custom_attributes' => array( 'min' => '1' ),
				'label'             => __( 'Quantity Trigger', 'tier-pricing-table' ),
				'description'       => __( 'Override the global auto-open trigger. Automatically open the quote form if a customer selects this quantity or higher.',
						'tier-pricing-table' ),
				'desc_tip'          => false,
		) );
		echo '</div>';

		?>
		<script>
			jQuery(document).ready(function ($) {
				if (typeof window.tptQuoteFormsData === 'undefined') {
					window.tptQuoteFormsData = <?php echo json_encode( $formsData ); ?>;

					$(document).on('change', '.tpt-quote-form-select', function () {
						var val = $(this).val();
						var $selectP = $(this).closest('p.form-field');
						var $wrapper = $selectP.nextAll('.tpt-quote-settings-wrapper').first();
						var $labelWrapper = $wrapper.find('.tpt-quote-label-wrapper');

						if (val && window.tptQuoteFormsData[val]) {
							$wrapper.show();
							if (window.tptQuoteFormsData[val].display_position === 'integrated') {
								$labelWrapper.show();
							} else {
								$labelWrapper.hide();
							}
						} else {
							$wrapper.hide();
						}
					});
				}

				// Trigger change on init for the current element specifically
				$('#<?php echo esc_js( $formIdFieldId ); ?>').trigger('change');
			});
		</script>
		<?php
	}

	private function saveMeta( $postId ) {
		$prefix = '';
		if ( isset( $_POST['product-type'] ) && 'variable' === $_POST['product-type'] ) {
			$prefix = '_variable';
		}

		$formId = Form::getFieldValue( 'quote_form_id', null, null, $prefix );
		if ( ! is_null( $formId ) ) {
			if ( $formId !== '' ) {
				update_post_meta( $postId, '_tier_pricing_table_quote_form_id', sanitize_text_field( $formId ) );

				// Save overrides
				$autoOpen = Form::getFieldValue( 'quote_auto_open_quantity', null, null, $prefix );
				if ( ! is_null( $autoOpen ) ) {
					update_post_meta( $postId, '_tier_pricing_table_quote_auto_open_quantity',
							sanitize_text_field( $autoOpen ) );
				}

				$label = Form::getFieldValue( 'quote_integrated_label_text', null, null, $prefix );
				if ( ! is_null( $label ) ) {
					update_post_meta( $postId, '_tier_pricing_table_quote_integrated_label_text',
							sanitize_text_field( $label ) );
				}
			} else {
				delete_post_meta( $postId, '_tier_pricing_table_quote_form_id' );
				delete_post_meta( $postId, '_tier_pricing_table_quote_auto_open_quantity' );
				delete_post_meta( $postId, '_tier_pricing_table_quote_integrated_label_text' );
			}
		}
	}

	protected function classifyPlace( $entityId, $role, $loop ) {
		if ( $role ) {
			if ( strpos( $role, 'user_' ) === 0 ) {
				return 'user';
			}

			return 'role';
		} elseif ( get_post_type( $entityId ) == GlobalTieredPricingCPT::SLUG ) {
			return 'global';
		} elseif ( $loop ) {
			return 'loop';
		} else {
			return 'product';
		}
	}
}
