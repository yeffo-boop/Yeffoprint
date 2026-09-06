<?php
/**
 * Lives on the Custom Design page (templates/custom-design-form.html),
 * right after the mode radiogroup — direct request: "combine everything
 * into one flow and ask the customer at the beginning if they want to
 * use our designer or fill out the form." Renders nothing at all for a
 * non-admin (YeffoPrint_Feature_Gate — "I don't want to release all of
 * these new features until I'm sure they're ready"): the page looks and
 * behaves exactly as it does today for a real customer, with no trace
 * of the Designer option anywhere in the markup. For an admin, renders
 * the design-method choice (only meaningful under 'new_design' — a
 * customer who already has their own file or is reordering has nothing
 * to design) and the Designer's own canvas app shell, both starting
 * hidden — custom-order-form.js's updateDesignMethodUi() drives their
 * visibility alongside the existing mode radiogroup.
 *
 * The canvas app shell also carries a label-size preset picker (direct
 * request: "some preset size options and also a custom option: Peptide
 * Vials 45mmx21mm, Oils Labels: 60x30, Custom") — width/height stay in
 * inches (what the pricing formula and canvas sizing already use), the
 * two named presets are just that conversion pre-filled and the fields
 * locked; "Custom" unlocks them back to today's manual entry. See
 * label-designer.js's own comment on the preset radios for why the
 * width/height `step` moved from 0.1 to 0.01 (needed for the presets'
 * mm-to-inch conversions to land on an exact step multiple).
 *
 * Fidelity/experience round (direct follow-up: "what other features can
 * we add... to get the final product as close to it as possible" ->
 * "Let's do them all!"): a starter-layout picker (data-yp-ld-layouts,
 * shown only while the canvas is empty), a safe-zone guide overlay
 * (data-yp-ld-safe-zone — a plain DOM element positioned over the canvas
 * by label-designer.js, deliberately *not* a Fabric object, so it never
 * has to be re-added after every clear()/undo/redo/draft-restore the
 * way an in-canvas object would), a layers panel (data-yp-ld-layers),
 * zoom controls, a "Preview on Product" flat-overlay toggle (data-yp-ld-
 * product-preview — one inline SVG silhouette per size preset, matching
 * this theme's existing flat-illustration style; deliberately not a
 * photorealistic cylindrical wrap simulation), and a one-line screen-
 * vs-print color disclaimer. See label-designer.js for all the logic.
 *
 * Muscle-memory round (direct follow-up): a Duplicate button/Ctrl+D
 * (data-yp-ld-duplicate, works on a single object or a shift-click/
 * marquee multi-select), arrow-key nudging, a fix for Delete silently
 * no-oping on a multi-select (Fabric's canvas.remove() never matched an
 * active ActiveSelection against canvas._objects), and submit-time
 * safety checks for a font still swapping in or an image upload still
 * in flight. See label-designer.js for all the logic.
 */

defined( 'ABSPATH' ) || exit;

if ( ! YeffoPrint_Feature_Gate::is_admin_viewer() ) {
	return;
}
?>
<div class="yp-field yp-custom-order__design-method yp-choice-cards yp-choice-cards--pair" role="radiogroup" aria-label="How would you like to design it?" hidden data-yp-co-design-method-group>
	<label class="yp-choice-card">
		<input type="radio" name="design_method" value="form" checked />
		<span class="yp-choice-card__check" aria-hidden="true">&#10003;</span>
		<span class="yp-choice-card__row">
			<span>
				<span class="yp-choice-card__title">Describe it for our designer</span>
				<span class="yp-choice-card__desc">Tell us your brand, style, and any files to work from.</span>
			</span>
		</span>
	</label>
	<label class="yp-choice-card">
		<input type="radio" name="design_method" value="designer" />
		<span class="yp-choice-card__check" aria-hidden="true">&#10003;</span>
		<span class="yp-choice-card__row">
			<span>
				<span class="yp-choice-card__title">Use our online Designer</span>
				<span class="yp-choice-card__desc">Build it yourself on a live canvas — same $25 fee.</span>
			</span>
		</span>
	</label>
</div>

<div id="yp-label-designer" class="yp-label-designer" hidden data-yp-ld-container>

	<p><button type="button" class="button-link" data-yp-ld-choice-back>&larr; Choose a different way to create your label</button></p>

	<div class="yp-configurator__status" role="status" aria-live="polite">Loading&hellip;</div>

	<form id="yp-label-designer-form" hidden>

		<div class="yp-field">
			<label>Label size</label>
			<div class="yp-size-presets" role="radiogroup" aria-label="Label size" data-yp-ld-size-presets>
				<label class="yp-size-preset">
					<input type="radio" name="ld_size_preset" value="peptide-vials" data-width-in="1.77" data-height-in="0.83" checked />
					<span class="yp-size-preset__name">Peptide Vials</span>
					<span class="yp-size-preset__dims">45 &times; 21 mm</span>
				</label>
				<label class="yp-size-preset">
					<input type="radio" name="ld_size_preset" value="oil-labels" data-width-in="2.36" data-height-in="1.18" />
					<span class="yp-size-preset__name">Oil Labels</span>
					<span class="yp-size-preset__dims">60 &times; 30 mm</span>
				</label>
				<label class="yp-size-preset">
					<input type="radio" name="ld_size_preset" value="custom" />
					<span class="yp-size-preset__name">Custom</span>
					<span class="yp-size-preset__dims">Enter your own</span>
				</label>
			</div>
			<p class="yp-ld__size-preset-hint" data-yp-ld-size-preset-hint>Locked to Peptide Vials — choose Custom to enter your own width/height.</p>
		</div>

		<div class="yp-ld__setup" data-yp-ld-setup>
			<div class="yp-field yp-ld__dimension">
				<label for="yp-ld-width">Width <span class="description">(inches)</span></label>
				<input type="number" id="yp-ld-width" min="0.2" max="19.6" step="0.01" value="1.77" required disabled />
			</div>
			<div class="yp-field yp-ld__dimension">
				<label for="yp-ld-height">Height <span class="description">(inches)</span></label>
				<input type="number" id="yp-ld-height" min="0.2" max="19.6" step="0.01" value="0.83" required disabled />
			</div>
			<div class="yp-field">
				<label for="yp-ld-material">Material</label>
				<select id="yp-ld-material" required></select>
			</div>
			<div class="yp-field">
				<label for="yp-ld-quantity">Quantity</label>
				<input type="number" id="yp-ld-quantity" min="1" step="1" value="50" required />
				<div class="yp-quantity-presets" data-yp-ld-quantity-presets></div>
			</div>
		</div>

		<div class="yp-ld__layouts" data-yp-ld-layouts hidden>
			<p class="yp-ld__layouts-label">Start from a layout, or start blank:</p>
			<div class="yp-ld__layouts-row">
				<button type="button" class="yp-ld__layout-option" data-yp-ld-layout="centered">
					<span class="yp-ld__layout-preview" data-layout-preview="centered" aria-hidden="true"></span>
					<span>Centered</span>
				</button>
				<button type="button" class="yp-ld__layout-option" data-yp-ld-layout="banner">
					<span class="yp-ld__layout-preview" data-layout-preview="banner" aria-hidden="true"></span>
					<span>Banner</span>
				</button>
				<button type="button" class="yp-ld__layout-option" data-yp-ld-layout="corner">
					<span class="yp-ld__layout-preview" data-layout-preview="corner" aria-hidden="true"></span>
					<span>Logo Corner</span>
				</button>
				<button type="button" class="button-link" data-yp-ld-layout-dismiss>Start blank</button>
			</div>
		</div>

		<div class="yp-ld__workspace" data-yp-ld-workspace>

			<div class="yp-ld__toolbar" role="toolbar" aria-label="Design tools">
				<button type="button" class="button-link" data-yp-ld-add="text">+ Text</button>
				<button type="button" class="button-link" data-yp-ld-add="rect">+ Rectangle</button>
				<button type="button" class="button-link" data-yp-ld-add="ellipse">+ Ellipse</button>
				<button type="button" class="button-link" data-yp-ld-add="line">+ Line</button>
				<button type="button" class="button-link" data-yp-ld-add="triangle">+ Triangle</button>
				<button type="button" class="button-link" data-yp-ld-icon-toggle>+ Icon</button>
				<button type="button" class="button-link" data-yp-ld-image-toggle>+ Image</button>
				<input type="file" accept="image/png,image/jpeg,image/svg+xml" data-yp-ld-image-input hidden />
				<span class="yp-ld__toolbar-sep" aria-hidden="true"></span>
				<button type="button" class="button-link" data-yp-ld-undo disabled>Undo</button>
				<button type="button" class="button-link" data-yp-ld-redo disabled>Redo</button>
				<button type="button" class="button-link" data-yp-ld-duplicate disabled>Duplicate</button>
				<button type="button" class="button-link" data-yp-ld-delete disabled>Delete</button>
				<span class="yp-ld__toolbar-sep" aria-hidden="true"></span>
				<button type="button" class="button-link" data-yp-ld-front disabled>Bring to front</button>
				<button type="button" class="button-link" data-yp-ld-back disabled>Send to back</button>
				<span class="yp-ld__toolbar-sep" aria-hidden="true"></span>
				<button type="button" class="button-link" data-yp-ld-zoom-out>&minus;</button>
				<span class="yp-ld__zoom-label" data-yp-ld-zoom-label>100%</span>
				<button type="button" class="button-link" data-yp-ld-zoom-in>+</button>
				<button type="button" class="button-link" data-yp-ld-zoom-reset>Reset zoom</button>
				<span class="yp-ld__toolbar-sep" aria-hidden="true"></span>
				<button type="button" class="button-link" data-yp-ld-preview-toggle>Preview on Product</button>
				<span class="yp-ld__toolbar-sep" aria-hidden="true"></span>
				<button type="button" class="button-link" data-yp-ld-clear>Start over</button>
			</div>

			<div class="yp-ld__icon-panel" data-yp-ld-icon-panel hidden></div>

			<div class="yp-ld__editor-row">

				<div class="yp-ld__canvas-wrap">
					<div class="yp-ld__canvas-stage" data-yp-ld-canvas-stage>
						<canvas id="yp-ld-canvas"></canvas>
						<div class="yp-ld__safe-zone-guide" data-yp-ld-safe-zone aria-hidden="true"></div>
					</div>
				</div>

				<div class="yp-ld__layers" data-yp-ld-layers>
					<p class="yp-ld__layers-label">Layers</p>
					<ul class="yp-ld__layers-list" data-yp-ld-layers-list></ul>
				</div>

			</div>

			<div class="yp-ld__properties" data-yp-ld-properties hidden>
				<div class="yp-ld__prop" data-yp-ld-prop="font-family">
					<label>Font</label>
					<select data-yp-ld-font-family></select>
				</div>
				<div class="yp-ld__prop" data-yp-ld-prop="font-size">
					<label>Size</label>
					<input type="number" min="6" max="400" step="1" data-yp-ld-font-size />
				</div>
				<div class="yp-ld__prop" data-yp-ld-prop="text-style">
					<label>Style</label>
					<button type="button" class="yp-ld__toggle" data-yp-ld-bold><strong>B</strong></button>
					<button type="button" class="yp-ld__toggle" data-yp-ld-italic><em>I</em></button>
				</div>
				<div class="yp-ld__prop" data-yp-ld-prop="align">
					<label>Align</label>
					<select data-yp-ld-text-align>
						<option value="left">Left</option>
						<option value="center">Center</option>
						<option value="right">Right</option>
					</select>
				</div>
				<div class="yp-ld__prop" data-yp-ld-prop="fill">
					<label>Color</label>
					<input type="color" data-yp-ld-fill />
				</div>
				<div class="yp-ld__prop" data-yp-ld-prop="stroke">
					<label>Outline</label>
					<input type="color" data-yp-ld-stroke />
				</div>
				<div class="yp-ld__prop" data-yp-ld-prop="stroke-width">
					<label>Outline width</label>
					<input type="number" min="0" max="40" step="1" data-yp-ld-stroke-width />
				</div>
				<p class="description yp-ld__color-note">Printed colors may look slightly different than on screen.</p>
			</div>

			<div class="yp-field yp-ld__background">
				<label for="yp-ld-bg-color">Label background</label>
				<input type="color" id="yp-ld-bg-color" value="#ffffff" />
			</div>

			<div class="yp-ld__product-preview" data-yp-ld-product-preview hidden>
				<p class="yp-ld__product-preview-note">A flat preview of your label on the product shape — not an exact print simulation.</p>
				<div class="yp-ld__product-preview-stage">
					<svg data-preview-silhouette="peptide-vials" viewBox="0 0 200 340" aria-hidden="true">
						<rect x="70" y="10" width="60" height="24" rx="4" fill="#B8C4CC" />
						<rect x="78" y="2" width="44" height="12" rx="3" fill="#8FA0AA" />
						<path d="M60 34 h80 a8 8 0 0 1 8 8 v250 a20 20 0 0 1 -20 20 h-56 a20 20 0 0 1 -20 -20 v-250 a8 8 0 0 1 8 -8 z" fill="#E7F4FA" stroke="#B8C4CC" stroke-width="2" />
						<rect class="yp-ld__product-preview-label-area" data-preview-label-area x="46" y="140" width="108" height="60" rx="3" fill="#ffffff" stroke="#B8C4CC" />
					</svg>
					<svg data-preview-silhouette="oil-labels" viewBox="0 0 200 340" aria-hidden="true" hidden>
						<rect x="82" y="8" width="36" height="20" rx="3" fill="#8FA0AA" />
						<path d="M70 28 h60 v30 l14 14 a10 10 0 0 1 4 8 v220 a20 20 0 0 1 -20 20 h-56 a20 20 0 0 1 -20 -20 v-220 a10 10 0 0 1 4 -8 l14 -14 z" fill="#F2EFE8" stroke="#C9C2B0" stroke-width="2" />
						<rect class="yp-ld__product-preview-label-area" data-preview-label-area x="42" y="150" width="116" height="80" rx="3" fill="#ffffff" stroke="#C9C2B0" />
					</svg>
					<svg data-preview-silhouette="custom" viewBox="0 0 200 340" aria-hidden="true" hidden>
						<rect x="76" y="10" width="48" height="22" rx="4" fill="#C7B8CC" />
						<path d="M64 32 h72 a10 10 0 0 1 10 10 v260 a24 24 0 0 1 -24 24 h-44 a24 24 0 0 1 -24 -24 v-260 a10 10 0 0 1 10 -10 z" fill="#F5EEF7" stroke="#C7B8CC" stroke-width="2" />
						<rect class="yp-ld__product-preview-label-area" data-preview-label-area x="44" y="130" width="112" height="90" rx="3" fill="#ffffff" stroke="#C7B8CC" />
					</svg>
					<img class="yp-ld__product-preview-snapshot" data-preview-snapshot alt="" />
				</div>
			</div>

		</div>

		<div class="yp-field">
			<label for="yp-ld-brand">Brand name</label>
			<input type="text" id="yp-ld-brand" maxlength="80" required class="widefat" />
		</div>

		<div class="yp-field">
			<label for="yp-ld-notes">Anything else we should know? <span class="description">(optional)</span></label>
			<textarea id="yp-ld-notes" rows="3" maxlength="500" class="widefat"></textarea>
		</div>

		<div class="yp-custom-order__pricing">
			<div class="yp-custom-order__fee">
				<span>Design fee</span>
				<strong data-yp-ld-fee>&mdash;</strong>
			</div>
			<div class="yp-custom-order__fee">
				<span>Price per label</span>
				<strong data-yp-ld-unit-price>&mdash;</strong>
			</div>
			<div class="yp-custom-order__fee yp-custom-order__fee--total">
				<span>Total due today</span>
				<strong data-yp-ld-total>&mdash;</strong>
			</div>
		</div>
		<p class="description">A designer builds your final print-ready label using this as a template and sends a proof before anything prints.</p>

		<p>
			<button type="submit" class="wp-block-button__link is-style-accent" data-yp-ld-submit>Continue to Payment</button>
		</p>

	</form>

</div>
