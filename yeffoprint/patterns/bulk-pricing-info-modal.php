<?php
/**
 * Title: Bulk Pricing Info Modal
 * Slug: yeffoprint/bulk-pricing-info-modal
 * Categories: yeffoprint
 * Inserter: no
 *
 * Direct request: "a bulk pricing table on the template order page so
 * customers can see the savings if they order more... dynamic and show
 * whatever tiered pricing is assigned to that template." Same info-
 * button-next-to-a-section-heading pattern as the Size/Material modals
 * already on this page (templates/single-yp_template.html) — this one
 * next to Quantity instead, since that's the control the bulk-discount
 * threshold is actually measured against.
 *
 * Unlike size-info-modal.php/material-info-modal.php, this pattern is
 * a pure static shell with no PHP-rendered content of its own — the
 * table body (`data-yp-bulk-pricing-table`) is filled in and kept live
 * by configurator.js, since it has to reflect whichever size/material
 * the customer currently has selected (their adjustments stack on top
 * of the tier discount) and recompute every time either changes. There
 * is no per-Template tier data to fetch server-side here in the first
 * place — one active PricingRule's tier set applies site-wide, same
 * "one active rule" model as the base unit price itself (see
 * YeffoPrint_Pricing_Rule's own docblock) — configurator.js already
 * loads that list from the same `/templates/{id}/configurator` payload
 * it gets everything else on this page from.
 */

defined( 'ABSPATH' ) || exit;
?>
<div id="yp-bulk-pricing-info-modal" class="yp-drawer yp-drawer--center" aria-hidden="true">
	<div class="yp-drawer__backdrop"></div>
	<div class="yp-drawer__panel" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Bulk Pricing', 'yeffoprint' ); ?>">
		<div class="yp-drawer__header">
			<span><?php esc_html_e( 'Bulk Pricing', 'yeffoprint' ); ?></span>
			<button type="button" class="yp-icon-button" data-yp-drawer-close aria-label="<?php esc_attr_e( 'Close', 'yeffoprint' ); ?>">
				<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
					<line x1="2" y1="2" x2="14" y2="14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
					<line x1="14" y1="2" x2="2" y2="14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
				</svg>
			</button>
		</div>
		<div class="yp-drawer__body">
			<p class="yp-bulk-pricing__intro"><?php esc_html_e( 'The more you order, the less each label costs. Discounts apply to your whole order across every design you add, not just this one.', 'yeffoprint' ); ?></p>
			<div data-yp-bulk-pricing-table></div>
		</div>
	</div>
</div>
