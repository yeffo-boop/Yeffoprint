<?php

	use TierPricingTable\CalculationLogic;
	use TierPricingTable\PriceManager;
	use TierPricingTable\PricingRule;

	if ( ! defined( 'WPINC' ) ) {
		die;
	}
	/**
	 * Available variables
	 *
	 * @var array $price_rules
	 * @var PricingRule $pricing_rule
	 * @var string $real_price
	 * @var string $product_name
	 * @var string $pricing_type
	 * @var WC_Product $product
	 * @var string $id
	 * @var int $product_id
	 * @var int $minimum
	 * @var array $settings
	 */

	$sale_price = $product->get_sale_price();

	if ( $sale_price ) {
		$sale_price = wc_get_price_to_display( $product, array(
				'price' => $sale_price,
		) );
	}

	$regular_price = wc_get_price_to_display( $product, array(
			'price' => $product->get_regular_price(),
	) );

	$price = wc_get_price_to_display( $product, array(
			'price' => $product->get_price(),
	) );

	if ( ! function_exists( 'tptParsePlainText' ) ) {
		function tptParsePlainText( $text, $quantity, $discount = null, $price = null, $base_unit_name = null ) {
			return strtr( $text, array(
					'{tp_quantity}'         => $quantity,
					'{tp_discount}'         => $discount,
					'{tp_rounded_discount}' => ! is_null( $discount ) ? round( $discount ) : 0,
					'{tp_price}'            => $price ? wc_price( $price ) : '',
					'{tp_base_unit_name}'   => $base_unit_name,
			) );
		}
	}
?>
<?php if ( ! empty( $price_rules ) ) : ?>

	<div class="tiered-pricing-wrapper">
		<?php if ( ! empty( $settings['title'] ) ) : ?>
			<h3 style="clear:both; margin: 20px 0;"><?php echo esc_attr( $settings['title'] ); ?></h3>
		<?php endif; ?>

		<ul class="tiered-pricing-plain-texts"
		    id="<?php echo esc_attr( $id ); ?>"
		    data-product-id="<?php echo esc_attr( $product_id ); ?>"
		    data-price-rules="<?php echo esc_attr( htmlspecialchars( json_encode( $price_rules ) ) ); ?>"
		    data-minimum="<?php echo esc_attr( $minimum ); ?>"
		    data-product-name="<?php echo esc_attr( $product_name ); ?>"
		    data-regular-price="<?php echo esc_attr( $regular_price ); ?>"
		    data-sale-price="<?php echo esc_attr( $sale_price ); ?>"
		    data-price="<?php echo esc_attr( $price ); ?>"
		    data-product-price-suffix="<?php echo esc_attr( $product->get_price_suffix() ); ?>"
		>
			<li class="tiered-pricing-plain-text tiered-pricing--active tiered-pricing-plain-text--default"
			    data-tiered-quantity="<?php echo esc_attr( $minimum ); ?>"
			    data-tiered-price="<?php echo esc_attr( $price ); ?>"
			    data-tiered-price-exclude-taxes="
				<?php
					echo esc_attr( wc_get_price_excluding_tax( wc_get_product( $product_id ), array(
							'price' => $real_price,
					) ) );
				?>
				 "
			    data-tiered-price-include-taxes="
				<?php
					echo esc_attr( wc_get_price_including_tax( wc_get_product( $product_id ), array(
							'price' => $real_price,
					) ) );
				?>
				 "
			>
				<?php
					$discountAmount = 0;
					if ( CalculationLogic::calculateDiscountBasedOnRegularPrice() && $product->is_on_sale() ) {
						$discountAmount = PriceManager::calculateDiscount( $product->get_regular_price(),
								$product->get_sale_price() );
					}
				?>

				<?php if ( 1 >= array_keys( $price_rules )[0] - $minimum || 'static' === $settings['quantity_type'] ) : ?>
					<?php
					$quantity     = esc_attr( number_format_i18n( $minimum ) . ' ' );
					$baseUnitName = $settings['quantity_measurement_singular'];
					?>
				<?php else : ?>
					<?php
					$quantity     = esc_attr( number_format_i18n( $minimum ) . ' - ' . number_format_i18n( array_keys( $price_rules )[0] - 1 ) . ' ' );
					$baseUnitName = $settings['quantity_measurement_plural'];
					?>
				<?php endif; ?>

				<?php if ( $discountAmount > 0 ) : ?>
					<?php
					echo wp_kses_post( tptParsePlainText( $settings['plain_text_option_text'], $quantity,
							$discountAmount, $price, $baseUnitName ) );
					?>
				<?php else : ?>
					<?php
					echo wp_kses_post( tptParsePlainText( $settings['plain_text_default_option_text'], $quantity, null,
							$price, $baseUnitName ) );
					?>
				<?php endif; ?>

				<?php
					do_action( 'tiered_pricing_table/plain-text/label', $pricing_rule, $minimum, array(
							'id'    => $id,
							'style' => 'default',
					) );
				?>
			</li>

			<?php $iterator = new ArrayIterator( $price_rules ); ?>

			<?php while ( $iterator->valid() ) : ?>
				<?php
				$currentPrice    = $iterator->current();
				$currentQuantity = $iterator->key();

				if ( 'percentage' === $pricing_type ) {
					$discountAmount = $currentPrice;
				} else {
					$discountAmount = PriceManager::calculateDiscount( CalculationLogic::calculateDiscountBasedOnRegularPrice() ? $product->get_regular_price() : $product->get_price(),
							$pricing_rule->getTierPrice( $currentQuantity, false ) );
				}

				$iterator->next();

				if ( $iterator->valid() ) {
					$quantity = $currentQuantity;

					if ( intval( $iterator->key() - 1 != $currentQuantity ) ) {

						$quantity = number_format_i18n( $quantity );

						if ( 'range' === $settings['quantity_type'] ) {
							$quantity .= ' - ' . number_format_i18n( intval( $iterator->key() - 1 ) );
						}
					}
				} else {
					$quantity = number_format_i18n( $currentQuantity );

					$quantity .= apply_filters( 'tiered_pricing_table/tiered_pricing/last_tier_postfix', '+',
							$currentQuantity, $pricing_rule, 'blocks' );
				}

				$currentProductPrice = PriceManager::getPriceByRules( $currentQuantity, $product_id );

				$currentProductPriceExcludeTaxes = wc_get_price_excluding_tax( wc_get_product( $product_id ), array(
						'price' => PriceManager::getPriceByRules( $currentQuantity, $product_id, null, null, false ),
				) );

				$currentProductPriceIncludeTaxes = wc_get_price_including_tax( wc_get_product( $product_id ), array(
						'price' => PriceManager::getPriceByRules( $currentQuantity, $product_id, null, null, false ),
				) );

				?>

				<li class="tiered-pricing-plain-text"
				    data-tiered-quantity="<?php echo esc_attr( $currentQuantity ); ?>"
				    data-tiered-price="<?php echo esc_attr( $currentProductPrice ); ?>"
				    data-tiered-price-exclude-taxes="<?php echo esc_attr( $currentProductPriceExcludeTaxes ); ?>"
				    data-tiered-price-include-taxes="<?php echo esc_attr( $currentProductPriceIncludeTaxes ); ?>">

					<?php

						echo wp_kses_post( tptParsePlainText( $settings['plain_text_option_text'], $quantity,
								round( $discountAmount, 2 ), $currentProductPrice,
								$settings['quantity_measurement_plural'] ) );
					?>

					<?php
						do_action( 'tiered_pricing_table/plain-text/label', $pricing_rule, $currentQuantity, array(
								'id'    => $id,
								'style' => 'default',
						) );
					?>
				</li>
			<?php endwhile; ?>

			<?php do_action( 'tiered_pricing_table/plain-text/line', $pricing_rule, $settings ); ?>
		</ul>

		<?php do_action( 'tiered_pricing_table/plain-text/after_lines', $pricing_rule, $settings ); ?>
	</div>

	<style>
		<?php
		if ( $settings['clickable_rows'] && tpt_fs()->can_use_premium_code() ) {
				echo esc_html("#{$id} .tiered-pricing-plain-text {cursor: pointer; }");
		}

		if ( ! $settings['plain_text_show_default_option'] ) {
			echo esc_html("#{$id} .tiered-pricing-plain-text--default { display: none }");
		}
		
		echo esc_html( "#{$id} .tiered-pricing--active {
			color: {$settings['active_tier_color']};
		}");
		
		?>

	</style>
<?php endif; ?>