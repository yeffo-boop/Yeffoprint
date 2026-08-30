<?php
/**
 * Title: Material Info Modal
 * Slug: yeffoprint/material-info-modal
 * Categories: yeffoprint
 * Inserter: no
 *
 * Direct request: an info button next to the label configurator's
 * Material section (templates/single-yp_template.html) that opens "the
 * material information we created earlier" — the same Material Guide
 * copy already built out on the How It Works page
 * (patterns/material-guide.php), surfaced here as one drawer instead
 * of that page's own per-material zoom-photo drawers, since this is a
 * quick reference while choosing a material, not a dedicated page.
 * Shares its data with material-guide.php via
 * yeffoprint_material_guide_entries() (functions.php) rather than a
 * second hardcoded copy, so the two can't drift apart.
 *
 * Deliberately shows every material YeffoPrint offers, not just the
 * ones compatible with whichever design happens to be open — this is
 * general "how do I choose" reference content, the same list every
 * visitor to the How It Works page already sees. `Inserter: no` since
 * this is only ever meant to be referenced from that one template, not
 * inserted standalone by an editor.
 */

defined( 'ABSPATH' ) || exit;

$materials = yeffoprint_material_guide_entries();
?>
<div id="yp-material-info-modal" class="yp-drawer yp-drawer--center" aria-hidden="true">
	<div class="yp-drawer__backdrop"></div>
	<div class="yp-drawer__panel" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Choosing a Material', 'yeffoprint' ); ?>">
		<div class="yp-drawer__header">
			<span><?php esc_html_e( 'Choosing a Material', 'yeffoprint' ); ?></span>
			<button type="button" class="yp-icon-button" data-yp-drawer-close aria-label="<?php esc_attr_e( 'Close', 'yeffoprint' ); ?>">
				<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
					<line x1="2" y1="2" x2="14" y2="14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
					<line x1="14" y1="2" x2="2" y2="14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
				</svg>
			</button>
		</div>
		<div class="yp-drawer__body">
			<div class="yp-material-guide yp-material-guide--modal">
				<?php foreach ( $materials as $material ) : ?>
					<div class="yp-material-guide__item">
						<?php if ( $material['photo_url'] ) : ?>
							<img class="yp-material-guide__photo" src="<?php echo esc_url( $material['photo_url'] ); ?>" alt="" width="64" height="64" />
						<?php else : ?>
							<div class="yp-material-guide__photo yp-material-guide__photo--<?php echo esc_attr( $material['slug'] ); ?>" aria-hidden="true"></div>
						<?php endif; ?>
						<div class="yp-material-guide__body">
							<div class="yp-material-guide__header">
								<h3><?php echo esc_html( $material['name'] ); ?></h3>
								<?php if ( $material['spec'] ) : ?>
									<span class="yp-material-guide__spec"><?php echo esc_html( $material['spec'] ); ?></span>
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
				<?php endforeach; ?>
			</div>
		</div>
	</div>
</div>
