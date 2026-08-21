<?php
/**
 * Request a Quote Table Integrated Template.
 *
 * This template can be overridden by copying it to yourtheme/tiered-pricing-table/integrated/table.php.
 *
 * @var \TierPricingTable\Addons\RequestAQuote\Models\RequestQuoteForm $form
 * @var int $productId
 * @var bool $isDivTable
 * @var bool $hasQty
 * @var bool $hasPrice
 * @var bool $hasDiscount
 * @var string $buttonHtml
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( $isDivTable ) : ?>
	<div class="tiered-pricing-table-row tpt-request-quote-integrated-row"
		 style="cursor: pointer; grid-template-columns: 1fr 2fr;"
		 onclick="document.getElementById('tpt-raq-table-<?php echo esc_attr( $productId ); ?>').click();">
		<?php if ( $hasQty ) : ?>
			<div class="tiered-pricing-table__quantity">
				<span class="tiered-pricing-table-row-qty">
					<?php echo esc_html( $form->getIntegratedLabelText() ); ?>
				</span>
			</div>
		<?php endif; ?>

		<?php if ( $hasPrice ) : ?>
			<div class="tiered-pricing-table__price" style="margin-left:auto">
				<span>
					<?php echo wp_kses_post($buttonHtml); ?>
				</span>
			</div>
		<?php endif; ?>
	</div>
<?php else : ?>
	<tr class="tiered-pricing-table-row tpt-request-quote-integrated-row" style="cursor: pointer;"
		onclick="document.getElementById('tpt-raq-table-<?php echo esc_attr( $productId ); ?>').click();">
		<?php if ( $hasQty ) : ?>
			<td class="tiered-pricing-table__quantity" style="vertical-align: middle">
				<span>
					<?php echo esc_html( $form->getIntegratedLabelText() ); ?>
				</span>
			</td>
		<?php endif; ?>
		<?php if ( $hasPrice ) : ?>
			<td class="tiered-pricing-table__price" <?php echo esc_attr($hasDiscount) ? 'colspan="2"' : ''; ?> style="text-align: right">
				<span>
					<?php echo wp_kses_post($buttonHtml); ?>
				</span>
			</td>
		<?php endif; ?>
	</tr>
<?php endif; ?>
