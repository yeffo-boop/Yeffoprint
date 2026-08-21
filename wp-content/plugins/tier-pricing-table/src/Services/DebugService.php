<?php namespace TierPricingTable\Services;

/*
 * Class DebugService
 *
 * @package TierPricingTable/Services
 */

use TierPricingTable\Core\ServiceContainer;
use TierPricingTable\PriceManager;
use TierPricingTable\PricingRule;

class DebugService {

	public function __construct() {

		if ( ServiceContainer::getInstance()->getSettings()->get( 'debug_enabled', 'no' ) !== 'yes' ) {
			return;
		}

		add_action( 'woocommerce_after_cart_item_name', function ( $cartItem ) {

			if ( ! ( $cartItem['data'] instanceof \WC_Product ) ) {
				return;
			}

			$pricingRule = PriceManager::getPricingRule( $cartItem['data']->get_id() );

			$this->formatPricingRule( $pricingRule, $cartItem );
		} );

		add_action( 'tiered_pricing_table/before_rendering_tiered_pricing/inner',
				function ( PricingRule $pricingRule ) {
					$this->formatPricingRule( $pricingRule );
				} );
	}

	protected function getPricingRuleData( PricingRule $pricingRule, ?array $cartItem = null ): array {

		switch ( $pricingRule->provider ) {
			case 'global-rules':
				$globalRuleId     = $pricingRule->providerData['rule_id'] ?? 'N\A';
				$data['provider'] = sprintf( '<a href="%s">%s</a>', get_edit_post_link( $globalRuleId ),
						"Global rule ($globalRuleId)" );
				break;
			case 'category-rules':
				$categoryId       = $pricingRule->providerData['category_id'] ?? 'N\A';
				$data['provider'] = sprintf( '<a href="%s">%s</a>', get_edit_term_link( $categoryId ),
						"DEPRECATED Category rule ($categoryId)" );
				break;
			case 'role-based':
				$role             = $pricingRule->providerData['role'] ?? 'N\A';
				$data['provider'] = "Role-based rule ($role)";
				break;
			case 'product':
				$data['provider'] = 'Product level rule';
				break;
			default:
				$provider         = $pricingRule->provider ?? 'N\A';
				$data['provider'] = "Undefined provider ($provider)";
		}

		$data['minimum']               = $pricingRule->getMinimum() ? $pricingRule->getMinimum() : 'N/A';
		$data['minimum_mix_and_match'] = $pricingRule->isMixAndMatchMinQuantity() ? 'Yes' : 'No';
		$data['maximum']               = ! empty( $pricingRule->data['maximum_quantity'] ) ? $pricingRule->data['maximum_quantity'] : 'N/A';
		$data['quantity_step']         = ! empty( $pricingRule->data['group_of_quantity'] ) ? $pricingRule->data['group_of_quantity'] : 'N/A';

		$data['pricing_type'] = $pricingRule->getType();
		$data['rules']        = '-';

		if ( ! empty( $pricingRule->getRules() ) ) {
			$data['rules'] = '';

			foreach ( $pricingRule->getRules() as $quantity => $price ) {
				$data['rules'] .= $quantity . ':' . $price . ',';
			}
		}

		if ( $cartItem ) {
			$data['total_in_cart'] = ! empty( $cartItem['tiered_pricing_data']['total_item_quantity'] ) ? $cartItem['tiered_pricing_data']['total_item_quantity'] : 'undefined';
		} else {
			$data['total_in_cart'] = 'N\A';
		}

		$data['custom_regular_price'] = ! empty( $pricingRule->pricingData['regular_price'] ) ? $pricingRule->pricingData['regular_price'] : 'N\A';
		$data['custom_sale_price']    = ! empty( $pricingRule->pricingData['sale_price'] ) ? $pricingRule->pricingData['sale_price'] : 'N\A';
		$data['discount']             = ! empty( $pricingRule->pricingData['discount'] ) ? $pricingRule->pricingData['discount'] : 'N\A';
		$data['custom_pricing_type']  = ! empty( $pricingRule->pricingData['pricing_type'] ) ? $pricingRule->pricingData['pricing_type'] : 'N\A';

		return $data;
	}

	protected function formatPricingRule( PricingRule $pricingRule, ?array $cartItem = null ) {

		$data = $this->getPricingRuleData( $pricingRule, $cartItem );
		?>

		<div>
			<table style="font-size: .7em">
				<thead>
				<tr>
					<th colspan="2" style="padding: 10px">Debug info</th>
				</tr>
				</thead>
				<tbody>
				<?php foreach ( $data as $key => $value ) : ?>
					<tr>
						<td style="padding: 0">
							<?php echo esc_html( $key . ':' ); ?>
						</td>
						<td style="padding: 0">
							<?php echo wp_kses_post( $value ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<?php if ( ! empty( $pricingRule->getPricingLog() ) ) : ?>
			<small>
				Pricing Log
				<ol>
					<?php foreach ( $pricingRule->getPricingLog() as $log ) : ?>
						<li><?php echo $log; ?></>
					<?php endforeach; ?>
				</ol>
			</small>
		<?php endif; ?>

		<?php
	}
}