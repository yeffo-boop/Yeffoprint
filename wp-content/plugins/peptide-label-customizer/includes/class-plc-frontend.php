<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the live-preview label customizer on the single product page
 * for any product marked as a label template.
 */
class PLC_Frontend {

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_customizer' ), 5 );
	}

	private function get_template_data( $product_id ) {
		$data = get_post_meta( $product_id, '_plc_template_data', true );
		return is_array( $data ) ? $data : array();
	}

	private function get_options_data( $product_id ) {
		$opts = get_post_meta( $product_id, '_plc_options_data', true );
		return is_array( $opts ) ? $opts : array();
	}

	private function is_active_template_product( $product_id ) {
		$data = $this->get_template_data( $product_id );
		return ! empty( $data['enabled'] ) && ! empty( $data['image_id'] );
	}

	public function maybe_enqueue_assets() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		global $product;
		if ( ! $product || ! $this->is_active_template_product( $product->get_id() ) ) {
			return;
		}

		wp_enqueue_style( 'plc-frontend-css', PLC_URL . 'assets/css/frontend.css', array(), PLC_VERSION );
		wp_enqueue_script( 'plc-frontend-js', PLC_URL . 'assets/js/live-preview.js', array( 'jquery' ), PLC_VERSION, true );

		$template = $this->get_template_data( $product->get_id() );
		$options  = $this->get_options_data( $product->get_id() );
		$image_url = wp_get_attachment_image_url( $template['image_id'], 'full' );
		$meta      = wp_get_attachment_metadata( $template['image_id'] );

		wp_localize_script( 'plc-frontend-js', 'PLC_DATA', array(
			'imageUrl'        => $image_url,
			'imageWidth'      => ! empty( $meta['width'] ) ? intval( $meta['width'] ) : 1200,
			'imageHeight'     => ! empty( $meta['height'] ) ? intval( $meta['height'] ) : 600,
			'zones'           => $template['zones'],
			'currencySymbol'  => get_woocommerce_currency_symbol(),
			'basePrice'       => wc_get_product( $product->get_id() )->get_price(),
		) );
	}

	public function render_customizer() {
		global $product;
		if ( ! $product || ! $this->is_active_template_product( $product->get_id() ) ) {
			return;
		}

		$template = $this->get_template_data( $product->get_id() );
		$options  = $this->get_options_data( $product->get_id() );
		$zones    = $template['zones'];
		?>
		<div id="plc-customizer" class="plc-customizer">

			<div class="plc-preview-col">
				<canvas id="plc-canvas" width="<?php echo esc_attr( 900 ); ?>" height="<?php echo esc_attr( 450 ); ?>"></canvas>
				<p class="plc-preview-note">Live preview — final print quality may vary slightly.</p>
			</div>

			<div class="plc-fields-col">

				<?php if ( ! empty( $zones['compound_name']['enabled'] ) ) : ?>
					<p class="form-row">
						<label for="plc_compound_name">Compound Name <span class="required">*</span></label>
						<input type="text" id="plc_compound_name" maxlength="<?php echo esc_attr( $zones['compound_name']['max_length'] ); ?>" required />
					</p>
				<?php endif; ?>

				<?php if ( ! empty( $zones['strength']['enabled'] ) ) : ?>
					<p class="form-row">
						<label for="plc_strength">Strength / Dosage <span class="required">*</span></label>
						<input type="text" id="plc_strength" placeholder="e.g. 500mg or 10mg/mL" maxlength="<?php echo esc_attr( $zones['strength']['max_length'] ); ?>" required />
					</p>
				<?php endif; ?>

				<?php if ( ! empty( $zones['batch_number']['enabled'] ) ) : ?>
					<p class="form-row">
						<label for="plc_batch_number">Batch / Lot Number <span class="optional">(optional — leave blank to use default)</span></label>
						<input type="text" id="plc_batch_number" maxlength="<?php echo esc_attr( $zones['batch_number']['max_length'] ); ?>" />
					</p>
				<?php endif; ?>

				<?php if ( ! empty( $zones['expiration_date']['enabled'] ) ) : ?>
					<p class="form-row">
						<label for="plc_expiration_date">Expiration Date <span class="optional">(optional — leave blank to use default)</span></label>
						<input type="text" id="plc_expiration_date" placeholder="MM/YYYY" maxlength="<?php echo esc_attr( $zones['expiration_date']['max_length'] ); ?>" />
					</p>
				<?php endif; ?>

				<?php if ( ! empty( $options['sizes'] ) ) : ?>
					<p class="form-row">
						<label for="plc_size">Label Size <span class="required">*</span></label>
						<select id="plc_size" required>
							<option value="">Select a size&hellip;</option>
							<?php foreach ( $options['sizes'] as $row ) : ?>
								<option value="<?php echo esc_attr( $row['label'] ); ?>" data-price="<?php echo esc_attr( $row['price'] ); ?>">
									<?php echo esc_html( $row['label'] ); ?><?php if ( $row['price'] > 0 ) : ?> (+<?php echo wc_price( $row['price'] ); ?>)<?php endif; ?>
								</option>
							<?php endforeach; ?>
						</select>
					</p>
				<?php endif; ?>

				<?php if ( ! empty( $options['medias'] ) ) : ?>
					<p class="form-row">
						<label for="plc_media">Media / Vinyl Type <span class="required">*</span></label>
						<select id="plc_media" required>
							<option value="">Select a material&hellip;</option>
							<?php foreach ( $options['medias'] as $row ) : ?>
								<option value="<?php echo esc_attr( $row['label'] ); ?>" data-price="<?php echo esc_attr( $row['price'] ); ?>">
									<?php echo esc_html( $row['label'] ); ?><?php if ( $row['price'] > 0 ) : ?> (+<?php echo wc_price( $row['price'] ); ?>)<?php endif; ?>
								</option>
							<?php endforeach; ?>
						</select>
					</p>
				<?php endif; ?>

				<?php if ( ! empty( $options['color_enabled'] ) ) : ?>
					<p class="form-row">
						<label for="plc_color">Accent Color <span class="optional">(defaults to black)</span></label>
						<input type="color" id="plc_color" value="#000000" />
					</p>
				<?php endif; ?>

				<p class="form-row">
					<label for="plc_design_notes">Design Notes</label>
					<textarea id="plc_design_notes" rows="3" placeholder="Anything else we should know?"></textarea>
				</p>

				<p class="form-row plc-confirm-row">
					<label>
						<input type="checkbox" id="plc_confirm" required />
						I've checked my label for accuracy. Changes requested after placing the order may not be possible. <span class="required">*</span>
					</label>
				</p>

				<p class="plc-error-message" id="plc-error-message" style="display:none;"></p>

				<!-- Hidden fields submitted with the add-to-cart form -->
				<input type="hidden" name="plc_data" id="plc_data_hidden" value="" />
				<input type="hidden" name="plc_preview_image" id="plc_preview_image_hidden" value="" />
			</div>
		</div>
		<?php
	}
}
