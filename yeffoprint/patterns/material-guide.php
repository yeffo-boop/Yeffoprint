<?php
/**
 * Title: Material Guide
 * Slug: yeffoprint/material-guide
 * Categories: yeffoprint
 *
 * Direct request: real customer-facing copy for each material type, a
 * deeper "which one should I pick" guide.
 *
 * Fully data-driven (V6, direct request: "make that dynamic so I can
 * add/remove materials from the dashboard and they add/remove from
 * that page"). Every entry below is a live, published `yp_material`
 * record — add one in the dashboard and it appears here, unpublish or
 * delete one and it's gone, no file edit required. `name` is the
 * record's title; `body` is its Description (post_content — the same
 * field the admin-app's Add/Edit Material form already labels "Shown
 * on the Material Guide"); `note` is the optional Guide note field
 * (YeffoPrint_Commerce_Record_Meta::GUIDE_NOTE) for a logistics caveat
 * like a shipping-delay warning, when one applies.
 *
 * Each circle is click-to-enlarge (direct follow-up request: "hover or
 * click on the image to see a bigger image of what the material looks
 * like on the vinyl") — reuses the site's existing accessible drawer
 * primitive (assets/js/site.js's openDrawer/closeDrawer, the same one
 * driving the header's search/cart panels and the splash screen) in
 * its centered-modal variant, one per material, wired entirely through
 * data-yp-drawer-trigger/-close — no new JS. The enlarged photo prefers
 * the record's "Hover / on-vial image" (Material editor field,
 * class-material-size-editor.php) — a real photo of the finish
 * actually applied to a vial — falling back to a larger export of the
 * same swatch photo if no on-vial photo has been uploaded yet. A
 * material with neither (gradient-fallback only) gets no zoom
 * affordance at all, since there's no larger image to show. A material
 * with no photo uploaded at all falls back to a per-slug gradient
 * "chip" look for a handful of known finishes (Glossy/Matte
 * White, Holographic, Prism, Metallic, Clear — see patterns.css), or a
 * plain neutral chip for anything else.
 *
 * The thickness figure in each entry's spec pill is live where the
 * record has one set (Material editor's Thickness (mil) field); a
 * material with none set shows no spec pill at all.
 *
 * The query and per-record shaping live in
 * yeffoprint_material_guide_entries() (functions.php), shared with the
 * label configurator's Material info modal
 * (patterns/material-info-modal.php) — this file's own rendering below
 * is unchanged, it just consumes the already-resolved entries. See
 * that function's own docblock for the fuller history of how this
 * page's data source got here (an exact-slug match against a hardcoded
 * six-material list, briefly a keyword-fallback match against that
 * same list, and now this).
 */

defined( 'ABSPATH' ) || exit;

$materials = yeffoprint_material_guide_entries();
?>
<!-- wp:group {"tagName":"section","className":"yp-section","layout":{"type":"constrained","contentSize":"760px"}} -->
<section class="wp-block-group yp-section">

	<!-- wp:paragraph {"align":"center","className":"yp-eyebrow"} -->
	<p class="has-text-align-center yp-eyebrow">Choosing a Material</p>
	<!-- /wp:paragraph -->

	<!-- wp:heading {"textAlign":"center","level":2} -->
	<h2 class="wp-block-heading has-text-align-center">What Material Should I Select?</h2>
	<!-- /wp:heading -->

	<!-- wp:paragraph {"align":"center"} -->
	<p class="has-text-align-center">This is a great question — and an important one for your label design. Here's what to know about each material we offer. Have questions about a specific one, or want a recommendation for the design you've chosen? <a href="/contact/">Contact us</a> — we're happy to help.</p>
	<!-- /wp:paragraph -->

	<!-- wp:html -->
	<div class="yp-material-guide">

		<?php foreach ( $materials as $material ) :
			$photo_url = $material['photo_url'];
			$zoom_url  = $material['zoom_url'];
			$spec      = $material['spec'];
			$zoom_id   = 'yp-material-zoom-' . $material['slug'];
			?>
			<div class="yp-material-guide__item">

				<?php if ( $zoom_url ) : ?>
					<button type="button" class="yp-material-guide__photo-btn" data-yp-drawer-trigger="<?php echo esc_attr( $zoom_id ); ?>" aria-haspopup="dialog" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: material name */ __( 'See a larger photo of %s', 'yeffoprint' ), $material['name'] ) ); ?>">
						<?php if ( $photo_url ) : ?>
							<img class="yp-material-guide__photo" src="<?php echo esc_url( $photo_url ); ?>" alt="" width="88" height="88" />
						<?php else : ?>
							<span class="yp-material-guide__photo yp-material-guide__photo--<?php echo esc_attr( $material['slug'] ); ?>" aria-hidden="true"></span>
						<?php endif; ?>
						<span class="yp-material-guide__zoom-badge" aria-hidden="true">
							<svg width="12" height="12" viewBox="0 0 16 16" fill="none" focusable="false">
								<circle cx="6.75" cy="6.75" r="4.75" stroke="currentColor" stroke-width="1.5" />
								<line x1="10.25" y1="10.25" x2="14" y2="14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
							</svg>
						</span>
					</button>
				<?php elseif ( $photo_url ) : ?>
					<img class="yp-material-guide__photo" src="<?php echo esc_url( $photo_url ); ?>" alt="" width="88" height="88" />
				<?php else : ?>
					<div class="yp-material-guide__photo yp-material-guide__photo--<?php echo esc_attr( $material['slug'] ); ?>" aria-hidden="true"></div>
				<?php endif; ?>

				<div class="yp-material-guide__body">
					<div class="yp-material-guide__header">
						<h3><?php echo esc_html( $material['name'] ); ?></h3>
						<?php if ( $spec ) : ?>
							<span class="yp-material-guide__spec"><?php echo esc_html( $spec ); ?></span>
						<?php endif; ?>
					</div>
					<?php if ( $material['body'] ) : ?>
						<p><?php echo esc_html( $material['body'] ); ?></p>
					<?php endif; ?>
					<?php if ( $material['note'] ) : ?>
						<p class="yp-material-guide__note"><?php echo esc_html( $material['note'] ); ?></p>
					<?php endif; ?>
				</div>

			</div>

			<?php if ( $zoom_url ) : ?>
				<div id="<?php echo esc_attr( $zoom_id ); ?>" class="yp-drawer yp-drawer--center" aria-hidden="true">
					<div class="yp-drawer__backdrop"></div>
					<div class="yp-drawer__panel" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr( $material['name'] ); ?>">
						<div class="yp-drawer__header">
							<span><?php echo esc_html( $material['name'] ); ?></span>
							<button type="button" class="yp-icon-button" data-yp-drawer-close aria-label="<?php esc_attr_e( 'Close', 'yeffoprint' ); ?>">
								<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
									<line x1="2" y1="2" x2="14" y2="14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
									<line x1="14" y1="2" x2="2" y2="14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
								</svg>
							</button>
						</div>
						<div class="yp-drawer__body yp-material-guide__zoom-body">
							<img src="<?php echo esc_url( $zoom_url ); ?>" alt="<?php echo esc_attr( $material['name'] ); ?>" />
						</div>
					</div>
				</div>
			<?php endif; ?>
		<?php endforeach; ?>

	</div>
	<!-- /wp:html -->

</section>
<!-- /wp:group -->
