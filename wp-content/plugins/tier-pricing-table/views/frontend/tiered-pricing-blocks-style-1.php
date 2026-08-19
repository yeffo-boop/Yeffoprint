<?php use TierPricingTable\CalculationLogic;
	use TierPricingTable\PriceManager;
	use TierPricingTable\PricingRule;
	use TierPricingTable\Settings\Settings;

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

	$backgroundColor = Settings::hex2rgba( $settings['active_tier_color'], 0.8 );

?>
<?php if ( ! empty( $price_rules ) ) : ?>

	<div class="tiered-pricing-wrapper">
		<?php if ( ! empty( $settings['title'] ) ) : ?>
			<h3 style="clear:both; margin: 20px 0;"><?php echo esc_attr( $settings['title'] ); ?></h3>
		<?php endif; ?>

		<div class="tiered-pricing-blocks tiered-pricing-blocks--styled tiered-pricing-blocks--style-1 <?php echo ( isset( $settings['compact_layout'] ) && $settings['compact_layout'] === 'yes' ) ? 'tiered-pricing-blocks--slim' : ''; ?>"
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

			<div class="tiered-pricing-block tiered-pricing--active"
			     data-tiered-quantity="<?php echo esc_attr( $minimum ); ?>"
			     data-tiered-price="
				<?php
					 echo esc_attr( wc_get_price_to_display( wc_get_product( $product_id ), array(
							 'price' => $real_price,
					 ) ) );
				 ?>
				"
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
				 ">

				<?php
					do_action( 'tiered_pricing_table/blocks/label', $pricing_rule, $minimum, array(
							'id'    => $id,
							'style' => '1',
					) );
				?>

				<div class="tiered-pricing-block__quantity">
					<?php if ( 1 >= array_keys( $price_rules )[0] - $minimum || 'static' === $settings['quantity_type'] ) : ?>
						<span><?php echo esc_attr( number_format_i18n( $minimum ) ); ?></span>
						<?php if ( $minimum > 1 ) : ?>
							<?php echo esc_html( $settings['quantity_measurement_plural'] ); ?>
						<?php else : ?>
							<?php echo esc_html( $settings['quantity_measurement_singular'] ); ?>
						<?php endif; ?>
					<?php else : ?>
						<span><?php echo esc_attr( number_format_i18n( $minimum ) ); ?> - <?php echo esc_attr( number_format_i18n( array_keys( $price_rules )[0] - 1 ) ); ?></span>
						<?php echo esc_html( $settings['quantity_measurement_plural'] ); ?>
					<?php endif; ?>
				</div>

				<div class="tiered-pricing-block__price">
					<?php
						echo wp_kses_post( wc_price( wc_get_price_to_display( wc_get_product( $product_id ), array(
								'price' => $real_price,
						) ) ) );
					?>

					<?php if ( $settings['show_discount_column'] ) : ?>
						<?php
						$discountAmount = 0;
						if ( CalculationLogic::calculateDiscountBasedOnRegularPrice() && $product->is_on_sale() ) {
							$discountAmount = PriceManager::calculateDiscount( $product->get_regular_price(),
									$product->get_sale_price() );
						}
						?>
						<?php if ( $discountAmount > 0 ) : ?>
							<span class="tiered-pricing-block__price-discount">
						<?php
							// translators: %d: discount amount
							echo esc_html( sprintf( __( '(%d%% off)', 'tier-pricing-table' ),
									round( $discountAmount, 2 ) ) );
						?>
						</span>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			</div>

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

				$quantity = $quantity . ' ' . $settings['quantity_measurement_plural'];

				$currentProductPrice = PriceManager::getPriceByRules( $currentQuantity, $product_id );

				$currentProductPriceExcludeTaxes = wc_get_price_excluding_tax( wc_get_product( $product_id ), array(
						'price' => PriceManager::getPriceByRules( $currentQuantity, $product_id, null, null, false ),
				) );

				$currentProductPriceIncludeTaxes = wc_get_price_including_tax( wc_get_product( $product_id ), array(
						'price' => PriceManager::getPriceByRules( $currentQuantity, $product_id, null, null, false ),
				) );

				?>

				<div class="tiered-pricing-block"
				     data-tiered-quantity="<?php echo esc_attr( $currentQuantity ); ?>"
				     data-tiered-price="<?php echo esc_attr( $currentProductPrice ); ?>"
				     data-tiered-price-exclude-taxes="<?php echo esc_attr( $currentProductPriceExcludeTaxes ); ?>"
				     data-tiered-price-include-taxes="<?php echo esc_attr( $currentProductPriceIncludeTaxes ); ?>">

					<?php
						do_action( 'tiered_pricing_table/blocks/label', $pricing_rule, $currentQuantity, array(
								'id'    => $id,
								'style' => '1',
						) ); ?>

					<div class="tiered-pricing-block__quantity"><?php echo esc_html( $quantity ); ?></div>
					<div class="tiered-pricing-block__price">
						<span>
							<?php
								echo wp_kses_post( wc_price( PriceManager::getPriceByRules( $currentQuantity,
										$product_id ) ) );
							?>
						</span>

						<?php if ( $settings['show_discount_column'] ) : ?>
							<span class="tiered-pricing-block__price-discount">
								<?php
									// translators: %d: discount amount
									echo esc_html( sprintf( __( '(%d%% off)', 'tier-pricing-table' ),
											round( $discountAmount, 2 ) ) );
								?>
							</span>
						<?php endif; ?>
					</div>
				</div>
			<?php endwhile; ?>

			<?php do_action( 'tiered_pricing_table/blocks/blocks', $pricing_rule, $settings ); ?>
		</div>

		<?php do_action( 'tiered_pricing_table/blocks/after_blocks', $pricing_rule ); ?>
	</div>

	<style>
		<?php echo esc_attr('#' . $id); ?>
		.tiered-pricing-block {
			border-color: <?php echo esc_html($backgroundColor); ?>;
		}

		<?php echo esc_attr('#' . $id); ?>
		.tiered-pricing-block .tiered-pricing-block__quantity {
			background: <?php echo esc_html($backgroundColor); ?>;
		}

		<?php
		if ( $settings['clickable_rows'] && tpt_fs()->can_use_premium_code()) {
			echo esc_attr('#' . $id) . ' .tiered-pricing-block {cursor: pointer; }';
		}
		?>

		<?php echo esc_attr('#' . $id); ?>
		.tiered-pricing--active {
			border-color: <?php echo esc_attr($settings['active_tier_color']); ?> !important;
		}

		<?php echo esc_attr('#' . $id); ?>
		.tiered-pricing--active .tiered-pricing-block__quantity {
			background: <?php echo esc_html($settings['active_tier_color']); ?> !important;
		}
	</style>
<?php endif; ?>