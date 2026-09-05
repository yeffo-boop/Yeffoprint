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
 */

defined( 'ABSPATH' ) || exit;

if ( ! YeffoPrint_Feature_Gate::is_admin_viewer() ) {
	return;
}
?>
<div class="yp-field yp-custom-order__design-method" role="radiogroup" aria-label="How would you like to design it?" hidden data-yp-co-design-method-group>
	<label class="yp-radio-option">
		<input type="radio" name="design_method" value="form" checked />
		<span>Describe it for our designer <span class="description">— tell us what you want, we'll build it</span></span>
	</label>
	<label class="yp-radio-option">
		<input type="radio" name="design_method" value="designer" />
		<span>Use our online Designer <span class="description">— build it yourself with a live preview</span></span>
	</label>
</div>

<div id="yp-label-designer" class="yp-label-designer" hidden data-yp-ld-container>

	<p><button type="button" class="button-link" data-yp-ld-choice-back>&larr; Choose a different way to create your label</button></p>

	<div class="yp-configurator__status" role="status" aria-live="polite">Loading&hellip;</div>

	<form id="yp-label-designer-form" hidden>

		<div class="yp-ld__setup">
			<div class="yp-field yp-ld__dimension">
				<label for="yp-ld-width">Width <span class="description">(inches)</span></label>
				<input type="number" id="yp-ld-width" min="0.2" max="19.6" step="0.1" value="2" required />
			</div>
			<div class="yp-field yp-ld__dimension">
				<label for="yp-ld-height">Height <span class="description">(inches)</span></label>
				<input type="number" id="yp-ld-height" min="0.2" max="19.6" step="0.1" value="1" required />
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

		<div class="yp-ld__workspace">

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
				<button type="button" class="button-link" data-yp-ld-delete disabled>Delete</button>
				<span class="yp-ld__toolbar-sep" aria-hidden="true"></span>
				<button type="button" class="button-link" data-yp-ld-front disabled>Bring to front</button>
				<button type="button" class="button-link" data-yp-ld-back disabled>Send to back</button>
				<span class="yp-ld__toolbar-sep" aria-hidden="true"></span>
				<button type="button" class="button-link" data-yp-ld-clear>Start over</button>
			</div>

			<div class="yp-ld__icon-panel" data-yp-ld-icon-panel hidden></div>

			<div class="yp-ld__canvas-wrap">
				<canvas id="yp-ld-canvas"></canvas>
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
			</div>

			<div class="yp-field yp-ld__background">
				<label for="yp-ld-bg-color">Label background</label>
				<input type="color" id="yp-ld-bg-color" value="#ffffff" />
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
