<?php
	
	use TierPricingTable\CalculationLogic;
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
	
if ( ! function_exists( 'tptParseOptionText' ) ) {
	function tptParseOptionText( $text, $quantity, $discount = null, $base_unit_name = null ) {
		return strtr( $text, array(
			'{tp_quantity}'         => $quantity,
			'{tp_discount}'         => $discount,
			'{tp_rounded_discount}' => ! is_null( $discount ) ? round( $discount ) : 0,
			'{tp_base_unit_name}'   => $base_unit_name,
		) );
	}
}
	
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
	
	$price_incl_taxes = wc_get_price_including_tax( wc_get_product( $product_id ), array(
		'price' => $real_price,
	) );
	
	$price_excl_taxes = wc_get_price_excluding_tax( wc_get_product( $product_id ), array(
		'price' => $real_price,
	) );


	?>
<?php if ( ! empty( $price_rules ) ) : ?>

	<div class="tiered-pricing-wrapper">
		<?php if ( ! empty( $settings['title'] ) ) : ?>
			<h3 style="clear:both; margin: 20px 0;"><?php echo esc_attr( $settings['title'] ); ?></h3>
		<?php endif; ?>

		<div class="tiered-pricing-dropdown <?php echo ( isset( $settings['compact_layout'] ) && $settings['compact_layout'] === 'yes' ) ? 'tiered-pricing-dropdown--slim' : ''; ?>"
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
			<?php
				$discountAmount = 0;
			if ( CalculationLogic::calculateDiscountBasedOnRegularPrice() && $product->is_on_sale() ) {
				$discountAmount = PriceManager::calculateDiscount( $product->get_regular_price(),
					$product->get_sale_price() );
			}
			?>

			<div class="tiered-pricing-dropdown__select-box" tabindex="0" role="combobox" aria-haspopup="listbox" aria-expanded="false" aria-controls="tiered-pricing-dropdown-list-<?php echo esc_attr( $id ); ?>">
				<div class="tiered-pricing-dropdown-option">
					<div class="tiered-pricing-dropdown-option__quantity">
						
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
							echo wp_kses_post( tptParseOptionText( $settings['options_option_text'], $quantity,
								$discountAmount, $baseUnitName ) );
							?>
						<?php else : ?>
							<?php
							echo wp_kses_post( tptParseOptionText( $settings['options_default_option_text'], $quantity,
								null, $baseUnitName ) );
							?>
						<?php endif; ?>
					</div>

					<?php
						do_action( 'tiered_pricing_table/dropdown/label', $pricing_rule, $minimum, array(
								'id'    => $id,
								'style' => 'default',
						) );
					?>

					<div class="tiered-pricing-dropdown-option__pricing">
						<div class="tiered-pricing-option-price">
							
							<?php if ( $discountAmount > 0 ) : ?>
								<div class="tiered-pricing-dropdown-option-price__original">
									<del>
										<?php
											echo wp_kses_post( wc_price( wc_get_price_to_display( $product, array(
												'price' => $regular_price,
											) ) ) );
										?>
									</del>
								</div>
							<?php endif; ?>

							<div class="tiered-pricing-dropdown-option-price__discounted">
								<?php
									echo wp_kses_post( wc_price( wc_get_price_to_display( wc_get_product( $product_id ),
										array(
											'price' => $real_price,
										) ) ) );
								?>
							</div>
						</div>
					</div>
				</div>
				<div class="tiered-pricing-dropdown__select-box-arrow">
					<svg height="24" viewBox="0 0 48 48" width="24" xmlns="http://www.w3.org/2000/svg">
						<path d="M14.83 16.42l9.17 9.17 9.17-9.17 2.83 2.83-12 12-12-12z"/>
						<path d="M0-.75h48v48h-48z" fill="none"/>
					</svg>
				</div>
			</div>

			<div class="tiered-pricing-dropdown__list">
				<ul role="listbox" id="tiered-pricing-dropdown-list-<?php echo esc_attr( $id ); ?>">
					<li class="tiered-pricing-dropdown-option tiered-pricing-dropdown-option--active tiered-pricing-dropdown-option--default"
						role="option"
						aria-selected="true"
						data-tiered-quantity="<?php echo esc_attr( $minimum ); ?>"
						data-tiered-price="<?php echo esc_attr( $price ); ?>"
						data-tiered-price-exclude-taxes="<?php echo esc_attr( $price_excl_taxes ); ?>"
						data-tiered-price-include-taxes="<?php echo esc_attr( $price_incl_taxes ); ?>">

						<div class="tiered-pricing-dropdown-option__quantity">
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
								echo wp_kses_post( tptParseOptionText( $settings['options_option_text'], $quantity,
									$discountAmount, $baseUnitName ) );
								?>
							<?php else : ?>
								<?php
								echo wp_kses_post( tptParseOptionText( $settings['options_default_option_text'],
									$quantity, null, $baseUnitName ) );
								?>
							<?php endif; ?>
						</div>

						<?php
							do_action( 'tiered_pricing_table/dropdown/label', $pricing_rule, $minimum, array(
									'id'    => $id,
									'style' => 'default',
							) );
						?>

						<div class="tiered-pricing-dropdown-option__pricing">
							<div class="tiered-pricing-option-price">
								
								<?php if ( $discountAmount > 0 ) : ?>
									<div class="tiered-pricing-dropdown-option-price__original">
										<del>
											<?php
												echo wp_kses_post( wc_price( wc_get_price_to_display( $product, array(
													'price' => $regular_price,
												) ) ) );
											?>
										</del>
									</div>
								<?php endif; ?>

								<div class="tiered-pricing-dropdown-option-price__discounted">
									<?php
										echo wp_kses_post( wc_price( wc_get_price_to_display( wc_get_product( $product_id ),
											array(
												'price' => $real_price,
											) ) ) );
									?>
								</div>
							</div>
						</div>
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
						
						$currentProductPriceExcludeTaxes = wc_get_price_excluding_tax( wc_get_product( $product_id ),
							array(
								'price' => PriceManager::getPriceByRules( $currentQuantity, $product_id, null, null,
									false ),
							) );
						
						$currentProductPriceIncludeTaxes = wc_get_price_including_tax( wc_get_product( $product_id ),
							array(
								'price' => PriceManager::getPriceByRules( $currentQuantity, $product_id, null, null,
									false ),
							) );
						?>

						<li class="tiered-pricing-dropdown-option"
							role="option"
							aria-selected="false"
							data-tiered-quantity="<?php echo esc_attr( $currentQuantity ); ?>"
							data-tiered-price="<?php echo esc_attr( $currentProductPrice ); ?>"
							data-tiered-price-exclude-taxes="<?php echo esc_attr( $currentProductPriceExcludeTaxes ); ?>"
							data-tiered-price-include-taxes="<?php echo esc_attr( $currentProductPriceIncludeTaxes ); ?>">

							<div class="tiered-pricing-dropdown-option__quantity">
								<?php
									echo wp_kses_post( tptParseOptionText( $settings['options_option_text'], $quantity,
										round( $discountAmount, 2 ), $settings['quantity_measurement_plural'] ) );
								?>
							</div>

							<?php
								do_action( 'tiered_pricing_table/dropdown/label', $pricing_rule, $currentQuantity, array(
										'id'    => $id,
										'style' => 'default',
								) );
							?>

							<div class="tiered-pricing-dropdown-option__pricing">
								<div class="tiered-pricing-option-price">
									<div class="tiered-pricing-dropdown-option-price__original">
										<del>
											<?php
												echo wp_kses_post( wc_price( wc_get_price_to_display( $product, array(
													'price' => CalculationLogic::calculateDiscountBasedOnRegularPrice() ? $regular_price : $real_price,
												) ) ) );
											?>
										</del>
									</div>
									<div class="tiered-pricing-dropdown-option-price__discounted">
										<?php
											echo wp_kses_post( wc_price( PriceManager::getPriceByRules( $currentQuantity,
												$product_id ) ) );
										?>
									</div>
								</div>
							</div>
						</li>
					<?php endwhile; ?>
					<?php do_action( 'tiered_pricing_table/dropdown/options', $pricing_rule ); ?>
				</ul>
				
				<?php do_action( 'tiered_pricing_table/dropdown/after_options', $pricing_rule ); ?>
			</div>
		</div>
	</div>

	<style>
		<?php
		$backgroundColor = Settings::hex2rgba($settings['active_tier_color'], 0.05);

		$clickableCSS = <<<EOD
			.tiered-pricing-dropdown__list .tiered-pricing-dropdown-option:hover,
			.tiered-pricing-dropdown__list .tiered-pricing-dropdown-option:focus {
		        background: $backgroundColor;
		         cursor: pointer;
		    }
EOD;
		$CSS = <<<EOD
		#$id .tiered-pricing-dropdown__list, #$id .tiered-pricing-dropdown__select-box {
			border-color:  {$settings['active_tier_color']};
		}
		#$id .tiered-pricing-dropdown__select-box-arrow svg {
			fill: {$settings['active_tier_color']};
		}
		#$id .tiered-pricing--active .tiered-pricing-option-checkbox::after {
			background: {$settings['active_tier_color']};
		}
		#{$id} .tiered-pricing-dropdown__list .tiered-pricing--active {
			background: $backgroundColor;
		}
EOD;

		$hideOriginalPriceCSS = <<<EOD
		#$id .tiered-pricing-dropdown-option-price__original {
			display: none
		}
EOD;

		echo esc_html($CSS);

		if (tpt_fs()->is__premium_only() && $settings['clickable_rows']) {
			echo esc_html($clickableCSS);
		}

		if (! $settings['options_show_original_product_price']) {
			echo esc_html($hideOriginalPriceCSS);
		}
		?>
	</style>
<?php endif; ?>

