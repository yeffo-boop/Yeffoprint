<?php namespace TierPricingTable\Addons\TierLabels\Frontend;

use TierPricingTable\Addons\TierLabels\TierLabel;
use TierPricingTable\Addons\TierLabels\TierLabelsManager;
use TierPricingTable\PricingRule;

class DisplayManager {

	public function __construct() {
		$this->hooks();
	}

	private function hooks() {

		$hooks = array(
				'options'          => 'tiered_pricing_table/options/label',
				'dropdown'         => 'tiered_pricing_table/dropdown/label',
				'horizontal-table' => 'tiered_pricing_table/horizontal-table/label',
				'plain-text'       => 'tiered_pricing_table/plain-text/label',
				'blocks'           => 'tiered_pricing_table/blocks/label',
				'table'            => 'tiered_pricing_table/table/label',
		);

		foreach ( $hooks as $layout => $hook ) {
			add_action( $hook, function ( PricingRule $rule, $quantity, $args ) use ( $layout ) {
				$this->render( $rule, $quantity, $args, $layout );
			}, 10, 3 );
		}
	}

	public function getLabelForQuantity( PricingRule $rule, $quantity ): ?TierLabel {

		$labelsData = $rule->data['tier_labels'][ $rule->getType() ] ?? array();

		if ( empty( $labelsData ) ) {
			return null;
		}

		$amountKey = null;

		if ( (int) $quantity === $rule->getMinimum( true ) ) {
			$amountKey = 'first_row';
		} else {
			foreach ( $rule->getRules() as $tierQty => $price ) {

				if ( $tierQty === $quantity ) {
					$amountKey = $tierQty;
					break;
				}
			}
		}

		if ( ! $amountKey || empty( $labelsData[ $amountKey ] ) ) {
			return null;
		}

		return TierLabelsManager::getInstance()->getLabel( $labelsData[ $amountKey ] );
	}

	public function render( PricingRule $rule, $quantity, $args = array(), $layout = '' ) {

		$label = $this->getLabelForQuantity( $rule, $quantity );

		if ( ! $label ) {
			return;
		}

		$args = wp_parse_args( $args, array(
				'id'    => '',
				'style' => 'default',
		) );

		$CSS = '';

		// CSS Fixes
		if ( 'blocks' === $layout ) {
			if ( in_array( $args['style'], array( 'default', '1', '2', '4' ) ) ) {
				$CSS .= "#{$args['id']} { gap: 20px 10px }";
				$CSS .= "#{$args['id']} .tiered-pricing-block { position: relative; }";
				$CSS .= "#{$args['id']} .tiered-pricing-tier-label {margin: 0 !important;
					position: absolute;
					left: 50%;
					top: 0;
					transform: translate(-50%, -50%);
					text-transform: uppercase;
					z-index: 20;}";
			}

			if ( in_array( $args['style'], array( 'default', '2', '4' ) ) ) {
				$CSS .= "#{$args['id']} .tiered-pricing-block:has(#" . $label->getId() . ") { padding-top: 12px; }";
			}

			if ( $args['style'] === '1' ) {
				$CSS .= "#{$args['id']} .tiered-pricing-block:has(#" . $label->getId() . ") {overflow: unset !important;}";
				$CSS .= "#{$args['id']} .tiered-pricing-block:has(#" . $label->getId() . ") .tiered-pricing-block__quantity {padding-top: 12px !important;}";
			}
		} elseif ( 'table' === $layout ) {
			$CSS .= "#{$args['id']} tbody td:has(#" . $label->getId() . ") { display: flex; align-items: center; gap: 5px; }";
		} elseif ( 'plain-text' === $layout ) {
			$CSS .= ".tiered-pricing-plain-text:has(#" . $label->getId() . ") {display:flex; align-items: center; gap: 5px; }";
		}

		if ( $CSS ) {
			?>
			<style>
				<?php echo wp_kses_post( $CSS ); ?>
			</style>
			<?php
		}

		// Output is escaped internally in TierLabel::render()
		echo $label->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}