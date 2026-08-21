<?php namespace TierPricingTable\Addons\GlobalTieredPricing\CPT\Columns;

use ArrayIterator;
use Exception;
use TierPricingTable\Addons\GlobalTieredPricing\GlobalPricingRule;
use TierPricingTable\Core\ServiceContainerTrait;

class Pricing {
	
	use ServiceContainerTrait;
	
	public function getName(): string {
		return __( 'Pricing', 'tier-pricing-table' );
	}
	
	public function render( GlobalPricingRule $rule ) {
		try {
			$rule->validatePricing();
			
			$pricingType = $rule->getTieredPricingType();
			$rules       = 'percentage' === $pricingType ? $rule->getPercentageTieredPricingRules() : $rule->getFixedTieredPricingRules();
			$minimum     = $rule->getMinimum() ? intval( $rule->getMinimum() ) : 1;
			
			$regularProductPriceString = __( 'Regular price', 'tier-pricing-table' );
			
			if ( $rule->getPricingType() === 'flat' ) {
				if ( $rule->getSalePrice() ) {
					$regularProductPriceString = wc_price( $rule->getSalePrice() );
				} elseif ( $rule->getRegularPrice() ) {
					$regularProductPriceString = wc_price( $rule->getRegularPrice() );
				}
			} elseif ( $rule->getDiscount() ) {
				$regularProductPriceString = $rule->getDiscount() . '% off';
			}
			
			?>
			
			<?php
			$applyingType = $rule->getApplyingType() === 'individual' ? __( '(Individual)', 'tier-pricing-table' ) : __( '(Mix and match)', 'tier-pricing-table' );
			?>
			<div style="margin-bottom: 12px;">
				<?php
				$hasBasePricing = ( $rule->getPricingType() === 'flat' && ( $rule->getRegularPrice() || $rule->getSalePrice() ) ) || ( $rule->getPricingType() !== 'flat' && $rule->getDiscount() );
				if ( $hasBasePricing ) : ?>
					<div style="margin-bottom: 8px;">
						<strong style="display: block; margin-bottom: 4px;"><?php esc_html_e( 'Base Pricing:', 'tier-pricing-table' ); ?></strong>
						<div style="display: flex; flex-wrap: wrap; gap: 4px;">
							<?php if ( $rule->getPricingType() === 'flat' ) : ?>
								<?php if ( $rule->getRegularPrice() ) : ?>
									<span style="display: inline-block; padding: 2px 8px; background: #f0f0f1; border-radius: 3px; font-size: 12px; color: #3c434a; border: 1px solid #dcdcdc;">
										<?php echo wp_kses_post( __( 'Regular:', 'tier-pricing-table' ) . ' <b>' . wc_price( $rule->getRegularPrice() ) . '</b>' ); ?>
									</span>
								<?php endif; ?>
								<?php if ( $rule->getSalePrice() ) : ?>
									<span style="display: inline-block; padding: 2px 8px; background: #f0f0f1; border-radius: 3px; font-size: 12px; color: #3c434a; border: 1px solid #dcdcdc;">
										<?php echo wp_kses_post( __( 'Sale:', 'tier-pricing-table' ) . ' <b>' . wc_price( $rule->getSalePrice() ) . '</b>' ); ?>
									</span>
								<?php endif; ?>
							<?php else : ?>
								<?php if ( $rule->getDiscount() ) : ?>
									<span style="display: inline-block; padding: 2px 8px; background: #f0f0f1; border-radius: 3px; font-size: 12px; color: #3c434a; border: 1px solid #dcdcdc;">
										<?php esc_html_e( 'Discount:', 'tier-pricing-table' ); ?> <b><?php echo esc_html( $rule->getDiscount() ); ?>%</b>
										(<?php echo $rule->getDiscountType() === 'sale_price' ? esc_html__( 'Sale price', 'tier-pricing-table' ) : esc_html__( 'Regular price', 'tier-pricing-table' ); ?>)
									</span>
								<?php endif; ?>
							<?php endif; ?>
						</div>
					</div>
				<?php endif; ?>
				
				<?php if ( ! empty( $rules ) ) : ?>
					<div>
						<div style="display: flex; align-items: center; margin-bottom: 8px;">
							<strong style="display: block; color: #1d2327; margin-right: 6px;"><?php esc_html_e( 'Tiered Rules:', 'tier-pricing-table' ); ?></strong>
							<span style="color: #50575e; font-size: 13px;">
								<?php echo esc_html( $applyingType ); ?>
							</span>
						</div>
						<table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left; margin-bottom: 8px; border: 1px solid #c3c4c7; border-radius: 4px; overflow: hidden; background: #fff;">
							<thead style="background: #f0f0f1; border-bottom: 2px solid #c3c4c7;">
								<tr>
									<th style="padding: 8px 10px; font-weight: 600; color: #1d2327;"><?php esc_html_e( 'Quantity', 'tier-pricing-table' ); ?></th>
									<th style="padding: 8px 10px; font-weight: 600; color: #1d2327;"><?php echo 'percentage' === $pricingType ? esc_html__( 'Discount', 'tier-pricing-table' ) : esc_html__( 'Price', 'tier-pricing-table' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php
								// Base quantity row
								$baseQtyStr = '';
								if ( 1 >= array_keys( $rules )[0] - $minimum ) {
									$baseQtyStr = number_format_i18n( $minimum );
								} else {
									$baseQtyStr = number_format_i18n( $minimum ) . ' - ' . number_format_i18n( array_keys( $rules )[0] - 1 );
								}
								?>
								<tr style="border-bottom: 1px solid #c3c4c7;">
									<td style="padding: 6px 10px; color: #1d2327; border-right: 1px solid #e2e4e7;"><?php echo esc_html( $baseQtyStr ); ?></td>
									<td style="padding: 6px 10px; color: #1d2327;"><?php echo wp_kses_post( $regularProductPriceString ); ?></td>
								</tr>
								
								<?php
								$iterator = new ArrayIterator( $rules );
								while ( $iterator->valid() ) {
									$currentPrice    = $iterator->current();
									$currentQuantity = $iterator->key();
									$iterator->next();
									
									if ( $iterator->valid() ) {
										$quantity = $currentQuantity;
										if ( intval( $iterator->key() - 1 != $currentQuantity ) ) {
											$quantity = number_format_i18n( $quantity );
											if ( $this->getContainer()->getSettings()->get( 'quantity_type', 'range' ) === 'range' ) {
												$quantity .= ' - ' . number_format_i18n( intval( $iterator->key() - 1 ) );
											}
										}
									} else {
										$quantity = number_format_i18n( $currentQuantity ) . '+';
									}
									
									$priceStr = 'percentage' === $pricingType ? esc_html( $currentPrice . '%' ) : wp_kses_post( wc_price( $currentPrice ) );
									?>
									<tr style="border-bottom: 1px solid #c3c4c7;">
										<td style="padding: 6px 10px; color: #1d2327; font-weight: 600; border-right: 1px solid #e2e4e7;"><?php echo esc_html( $quantity ); ?></td>
										<td style="padding: 6px 10px; color: #0070bc; font-weight: 600;"><?php echo $priceStr; ?></td>
									</tr>
									<?php
								}
								?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>
			<?php
			
		} catch ( Exception $e ) {
			echo wp_kses_post( '<div class="help_tip tpt-rule-status tpt-rule-status--invalid" data-tip="' . $e->getMessage() . '">!</div>' );
		}
	}
}
