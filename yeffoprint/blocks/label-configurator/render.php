<?php
/**
 * The live label configurator on a Template's single page — direct
 * report: "ChatGPT and the like cannot index my site." This page used
 * to be a single static `wp:html` block (a `.html` template can't run
 * PHP or template tags): an empty `<h1 data-yp-title>` and no other
 * page copy at all until assets/js/configurator.js fetched the
 * template's data from the /templates/{id}/configurator REST endpoint
 * and filled it in — a crawler that doesn't execute JavaScript, which
 * is most AI-answer-engine bots, saw nothing but a "please enable
 * JavaScript" notice.
 *
 * Converting this into a dynamic block adds a real, server-rendered
 * title, description and price/size/material summary (yeffoprint_core_get_template_seo_data(),
 * the server-side counterpart of that same REST endpoint) ahead of the
 * interactive tool, with zero behavior change to the tool itself: every
 * class, id, and data-yp-* attribute below is unchanged from the old
 * static markup. The one structural change is `<h1 data-yp-title>` —
 * previously empty and nested inside `.yp-configurator__controls`,
 * inside the `hidden` `.yp-configurator__layout` until JS revealed it —
 * now pre-filled and moved up to a new, un-hidden `.yp-configurator__intro`
 * block. It has to stay a direct child of #yp-configurator rather than
 * move outside it entirely: configurator.js queries every element it
 * touches via `root.querySelector(...)` scoped to
 * `document.getElementById('yp-configurator')` (`root` in that file),
 * and still does `titleEl.textContent = schema.title` once its own
 * fetch resolves — a no-op here since the two values already match,
 * but a null `titleEl` from moving this outside `root` would throw and
 * break the rest of that same callback (sizes, materials, pricing,
 * everything downstream of it in one promise chain).
 */

defined( 'ABSPATH' ) || exit;

$post_id = get_the_ID();
$seo     = ( $post_id && function_exists( 'yeffoprint_core_get_template_seo_data' ) )
	? yeffoprint_core_get_template_seo_data( $post_id )
	: null;

$title = $seo['title'] ?? get_the_title( $post_id );

/*
 * Starting price plus size and material counts, shown as small bubbles
 * under the label preview. Each count bubble's title lists the names.
 */
$spec_chips = [];
if ( $seo ) {
	$spec_chips[] = [
		'text'  => $seo['starting_price'],
		'title' => '',
		'class' => 'is-price',
	];

	$size_count = count( $seo['size_names'] );
	if ( $size_count ) {
		$spec_chips[] = [
			/* translators: %d: number of available sizes */
			'text'  => sprintf( _n( '%d size', '%d sizes', $size_count, 'yeffoprint' ), $size_count ),
			'title' => implode( ', ', $seo['size_names'] ),
			'class' => '',
		];
	}

	$material_count = count( $seo['material_names'] );
	if ( $material_count ) {
		$spec_chips[] = [
			/* translators: %d: number of available materials */
			'text'  => sprintf( _n( '%d material', '%d materials', $material_count, 'yeffoprint' ), $material_count ),
			'title' => implode( ', ', $seo['material_names'] ),
			'class' => '',
		];
	}
}
?>
<div id="yp-configurator" class="yp-configurator" data-loading="true">
	<noscript>
		<p class="yp-configurator__noscript">
			<?php
			printf(
				/* translators: %s: link to browse the full design gallery */
				esc_html__( "This design's live configurator needs JavaScript enabled. You can still %s or contact us to order.", 'yeffoprint' ),
				'<a href="' . esc_url( home_url( '/shop-labels/' ) ) . '">' . esc_html__( 'browse the full gallery', 'yeffoprint' ) . '</a>'
			);
			?>
		</p>
	</noscript>
	<div class="yp-configurator__intro">
		<h1 class="yp-configurator__title" data-yp-title><?php echo esc_html( $title ); ?></h1>
	</div>
	<div class="yp-configurator__status" role="status" aria-live="polite"><?php esc_html_e( 'Loading design…', 'yeffoprint' ); ?></div>
	<div class="yp-configurator__skeleton" data-yp-skeleton aria-hidden="true">
		<div class="yp-skel yp-skel--stage"></div>
		<div class="yp-configurator__skeleton-controls">
			<div class="yp-skel yp-skel--line yp-skel--w60"></div>
			<div class="yp-skel yp-skel--line yp-skel--w80"></div>
			<div class="yp-skel yp-skel--pill"></div>
			<div class="yp-skel yp-skel--pill"></div>
			<div class="yp-skel yp-skel--pill"></div>
			<div class="yp-skel yp-skel--line yp-skel--cta"></div>
		</div>
	</div>
	<div class="yp-configurator__layout" hidden>
		<div class="yp-configurator__preview">
			<div class="yp-configurator__view-toggle" role="tablist" aria-label="<?php esc_attr_e( 'Preview mode', 'yeffoprint' ); ?>">
				<button type="button" id="yp-view-tab-label" class="yp-view-tab is-active" role="tab" aria-selected="true" aria-controls="yp-configurator-stage" tabindex="0" data-yp-view="label"><?php esc_html_e( 'Label View', 'yeffoprint' ); ?></button>
				<button type="button" id="yp-view-tab-vial" class="yp-view-tab" role="tab" aria-selected="false" aria-controls="yp-configurator-stage" tabindex="-1" data-yp-view="vial"><?php esc_html_e( 'Vial View', 'yeffoprint' ); ?></button>
			</div>
			<div class="yp-configurator__stage" id="yp-configurator-stage" role="tabpanel" aria-labelledby="yp-view-tab-label" tabindex="0" data-yp-stage></div>
			<?php if ( $spec_chips ) : ?>
				<ul class="yp-configurator__spec-chips">
					<?php foreach ( $spec_chips as $chip ) : ?>
						<li class="yp-spec-chip <?php echo esc_attr( $chip['class'] ); ?>"<?php echo $chip['title'] ? ' title="' . esc_attr( $chip['title'] ) . '"' : ''; ?>><?php echo esc_html( $chip['text'] ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<p class="yp-configurator__description" data-yp-description<?php echo empty( $seo['description'] ) ? ' hidden' : ''; ?>><?php echo esc_html( $seo['description'] ?? '' ); ?></p>
			<p class="yp-configurator__live-preview-note" data-yp-live-preview-note hidden><?php esc_html_e( 'Live preview is temporarily off while we fine-tune this design — your label will still print exactly as you enter it below.', 'yeffoprint' ); ?></p>
			<div class="yp-configurator__overflow-warning" data-yp-overflow-warning hidden><?php esc_html_e( 'Text is too long for this design.', 'yeffoprint' ); ?></div>
		</div>
		<div class="yp-configurator__controls">
			<div class="yp-configurator__section" data-yp-section="size">
				<div class="yp-section-heading-row">
					<h2 id="yp-size-heading"><?php esc_html_e( 'Size', 'yeffoprint' ); ?></h2>
					<button type="button" class="yp-field__tooltip-trigger" data-yp-drawer-trigger="yp-size-info-modal" aria-haspopup="dialog" aria-label="<?php esc_attr_e( 'See label size dimensions', 'yeffoprint' ); ?>">?</button>
				</div>
				<div class="yp-option-group" role="radiogroup" aria-labelledby="yp-size-heading" data-yp-size-options></div>
			</div>
			<div class="yp-configurator__section" data-yp-section="material">
				<div class="yp-section-heading-row">
					<h2 id="yp-material-heading"><?php esc_html_e( 'Material', 'yeffoprint' ); ?></h2>
					<button type="button" class="yp-field__tooltip-trigger" data-yp-drawer-trigger="yp-material-info-modal" aria-haspopup="dialog" aria-label="<?php esc_attr_e( 'Learn about each material', 'yeffoprint' ); ?>">?</button>
				</div>
				<div class="yp-option-group" role="radiogroup" aria-labelledby="yp-material-heading" data-yp-material-options></div>
			</div>
			<div class="yp-configurator__section" data-yp-section="fields">
				<h2><?php esc_html_e( 'Customize', 'yeffoprint' ); ?></h2>
				<div data-yp-dose-tip hidden></div>
				<div class="yp-field-inputs" data-yp-field-inputs></div>
			</div>
			<div class="yp-configurator__section" data-yp-section="quantity">
				<h2><?php esc_html_e( 'Quantity', 'yeffoprint' ); ?></h2>
				<div class="yp-quantity-control" data-yp-quantity></div>
			</div>
			<div class="yp-configurator__section" data-yp-section="bulk-pricing" data-yp-bulk-pricing-section hidden>
				<h2><?php esc_html_e( 'Bulk Pricing', 'yeffoprint' ); ?></h2>
				<p class="yp-bulk-pricing__intro"><?php esc_html_e( "The more you order, the less each label costs. Discounts apply to your whole order across every design you add, not just this one.", 'yeffoprint' ); ?></p>
				<div data-yp-bulk-pricing-table></div>
			</div>
			<div class="yp-configurator__section" data-yp-section="variants">
				<div class="yp-variants-header">
					<h2><?php esc_html_e( 'Batch', 'yeffoprint' ); ?></h2>
					<button type="button" class="yp-add-variant-btn" data-yp-add-variant>+ <?php esc_html_e( 'Add another label', 'yeffoprint' ); ?></button>
				</div>
				<p class="yp-variants-blurb"><?php esc_html_e( "Add another label to split this batch's quantity across different text — same design, size, and material, one combined order that still qualifies for bulk pricing.", 'yeffoprint' ); ?></p>
				<div class="yp-variant-cards" data-yp-variant-cards></div>
			</div>
			<div class="yp-configurator__summary" aria-live="polite" aria-atomic="true" data-yp-summary></div>
			<p class="yp-configurator__desktop-cta">
				<button type="button" class="wp-block-button__link is-style-accent" data-yp-add-to-cart><?php esc_html_e( 'Add to Cart', 'yeffoprint' ); ?></button>
				<button type="button" class="wp-block-button__link is-style-outline" data-yp-save-design><?php esc_html_e( 'Save this design', 'yeffoprint' ); ?></button>
			</p>
		</div>
	</div>
</div>
