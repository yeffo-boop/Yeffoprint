<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the Label Designer + Options/Pricing meta boxes to the WooCommerce
 * product edit screen, and saves the resulting template + pricing data.
 */
class PLC_Admin {

	/** Fixed set of customizable text zones every template can define. */
	public static function zone_fields() {
		return array(
			'compound_name'   => 'Compound Name',
			'strength'        => 'Strength / Dosage',
			'batch_number'    => 'Batch / Lot Number',
			'expiration_date' => 'Expiration Date',
		);
	}

	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_template_data' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_options_pricing' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	public function register_meta_boxes() {
		add_meta_box(
			'plc_label_designer',
			'Label Designer (Live Preview Template)',
			array( $this, 'render_designer_meta_box' ),
			'product',
			'normal',
			'high'
		);

		add_meta_box(
			'plc_options_pricing',
			'Label Options & Pricing (Size / Media / Color)',
			array( $this, 'render_options_meta_box' ),
			'product',
			'normal',
			'default'
		);
	}

	public function enqueue_admin_assets( $hook ) {
		global $post;
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		if ( ! $post || 'product' !== $post->post_type ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_script( 'jquery-ui-draggable' );
		wp_enqueue_style( 'plc-admin-css', PLC_URL . 'assets/css/admin-designer.css', array(), PLC_VERSION );
		wp_enqueue_script( 'plc-admin-js', PLC_URL . 'assets/js/admin-designer.js', array( 'jquery', 'jquery-ui-draggable' ), PLC_VERSION, true );

		$existing = get_post_meta( $post->ID, '_plc_template_data', true );
		wp_localize_script( 'plc-admin-js', 'PLC_ADMIN', array(
			'zoneFields' => self::zone_fields(),
			'existing'   => $existing ? $existing : null,
		) );
	}

	/**
	 * Renders the Label Designer meta box: upload a base image, then place
	 * and configure up to four text zones directly on top of it.
	 */
	public function render_designer_meta_box( $post ) {
		wp_nonce_field( 'plc_save_template', 'plc_template_nonce' );

		$data = get_post_meta( $post->ID, '_plc_template_data', true );
		$data = is_array( $data ) ? $data : array();

		$enabled    = ! empty( $data['enabled'] );
		$image_id   = ! empty( $data['image_id'] ) ? intval( $data['image_id'] ) : 0;
		$image_url  = $image_id ? wp_get_attachment_image_url( $image_id, 'full' ) : '';
		$zones      = ! empty( $data['zones'] ) ? $data['zones'] : array();
		?>
		<p>
			<label>
				<input type="checkbox" name="plc_enabled" id="plc_enabled" value="1" <?php checked( $enabled ); ?> />
				<strong>This product is a live-preview label template</strong>
				(uncheck for non-template products like custom design requests)
			</label>
		</p>

		<div id="plc-designer-wrap" style="<?php echo $enabled ? '' : 'display:none;'; ?>">

			<p>
				<button type="button" class="button" id="plc-upload-image">Choose Template Image</button>
				<button type="button" class="button" id="plc-remove-image" style="<?php echo $image_id ? '' : 'display:none;'; ?>">Remove Image</button>
			</p>
			<input type="hidden" id="plc-image-id" name="plc_image_id" value="<?php echo esc_attr( $image_id ); ?>" />

			<div id="plc-canvas-area" style="<?php echo $image_id ? '' : 'display:none;'; ?>">
				<p class="description">Drag each label onto the spot it belongs on the label. Positions are stored as a percentage of the image, so they stay correct at any size.</p>
				<div id="plc-image-stage">
					<img id="plc-template-image" src="<?php echo esc_url( $image_url ); ?>" alt="" />
					<?php foreach ( self::zone_fields() as $key => $label ) : ?>
						<div class="plc-zone-marker" data-zone="<?php echo esc_attr( $key ); ?>" style="display:none;">
							<span><?php echo esc_html( $label ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

			<table class="widefat plc-zone-table" id="plc-zone-table" style="<?php echo $image_id ? '' : 'display:none;'; ?>">
				<thead>
					<tr>
						<th>Zone</th>
						<th>Enabled</th>
						<th>Font Size (px)</th>
						<th>Font Color</th>
						<th>Weight</th>
						<th>Align</th>
						<th>Max Chars</th>
						<th>Default Value</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( self::zone_fields() as $key => $label ) :
					$z = isset( $zones[ $key ] ) ? $zones[ $key ] : array();
					$z_enabled = ! empty( $z['enabled'] );
					$x         = isset( $z['x'] ) ? floatval( $z['x'] ) : 10;
					$y         = isset( $z['y'] ) ? floatval( $z['y'] ) : 10;
					$font_size = isset( $z['font_size'] ) ? intval( $z['font_size'] ) : 32;
					$color     = isset( $z['color'] ) ? $z['color'] : '#ffffff';
					$weight    = isset( $z['weight'] ) ? $z['weight'] : 'bold';
					$align     = isset( $z['align'] ) ? $z['align'] : 'left';
					$max_len   = isset( $z['max_length'] ) ? intval( $z['max_length'] ) : 24;
					$default   = isset( $z['default_value'] ) ? $z['default_value'] : '';
					?>
					<tr data-zone-row="<?php echo esc_attr( $key ); ?>">
						<td><strong><?php echo esc_html( $label ); ?></strong></td>
						<td><input type="checkbox" class="plc-zone-enabled" name="plc_zones[<?php echo esc_attr( $key ); ?>][enabled]" value="1" <?php checked( $z_enabled ); ?> /></td>
						<td><input type="number" min="6" max="200" class="small-text" name="plc_zones[<?php echo esc_attr( $key ); ?>][font_size]" value="<?php echo esc_attr( $font_size ); ?>" /></td>
						<td><input type="color" name="plc_zones[<?php echo esc_attr( $key ); ?>][color]" value="<?php echo esc_attr( $color ); ?>" /></td>
						<td>
							<select name="plc_zones[<?php echo esc_attr( $key ); ?>][weight]">
								<option value="normal" <?php selected( $weight, 'normal' ); ?>>Normal</option>
								<option value="bold" <?php selected( $weight, 'bold' ); ?>>Bold</option>
							</select>
						</td>
						<td>
							<select name="plc_zones[<?php echo esc_attr( $key ); ?>][align]">
								<option value="left" <?php selected( $align, 'left' ); ?>>Left</option>
								<option value="center" <?php selected( $align, 'center' ); ?>>Center</option>
								<option value="right" <?php selected( $align, 'right' ); ?>>Right</option>
							</select>
						</td>
						<td><input type="number" min="1" max="200" class="small-text" name="plc_zones[<?php echo esc_attr( $key ); ?>][max_length]" value="<?php echo esc_attr( $max_len ); ?>" /></td>
						<td><input type="text" class="regular-text" placeholder="Used if customer leaves this blank" name="plc_zones[<?php echo esc_attr( $key ); ?>][default_value]" value="<?php echo esc_attr( $default ); ?>" /></td>
						<input type="hidden" class="plc-zone-x" name="plc_zones[<?php echo esc_attr( $key ); ?>][x]" value="<?php echo esc_attr( $x ); ?>" />
						<input type="hidden" class="plc-zone-y" name="plc_zones[<?php echo esc_attr( $key ); ?>][y]" value="<?php echo esc_attr( $y ); ?>" />
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">Only "Enabled" zones appear on the front end. Batch Number and Expiration Date are optional for customers — if they leave them blank, the Default Value above is used (or the zone is left off the label if no default is set).</p>
		</div>
		<?php
	}

	public function save_template_data( $post_id ) {
		if ( ! isset( $_POST['plc_template_nonce'] ) || ! wp_verify_nonce( $_POST['plc_template_nonce'], 'plc_save_template' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}

		$enabled  = ! empty( $_POST['plc_enabled'] );
		$image_id = isset( $_POST['plc_image_id'] ) ? intval( $_POST['plc_image_id'] ) : 0;

		$zones_in  = isset( $_POST['plc_zones'] ) && is_array( $_POST['plc_zones'] ) ? $_POST['plc_zones'] : array();
		$zones_out = array();

		foreach ( self::zone_fields() as $key => $label ) {
			$z = isset( $zones_in[ $key ] ) ? $zones_in[ $key ] : array();
			$zones_out[ $key ] = array(
				'enabled'       => ! empty( $z['enabled'] ),
				'x'             => isset( $z['x'] ) ? round( floatval( $z['x'] ), 2 ) : 10,
				'y'             => isset( $z['y'] ) ? round( floatval( $z['y'] ), 2 ) : 10,
				'font_size'     => isset( $z['font_size'] ) ? intval( $z['font_size'] ) : 32,
				'color'         => isset( $z['color'] ) ? sanitize_hex_color( $z['color'] ) : '#ffffff',
				'weight'        => isset( $z['weight'] ) && 'normal' === $z['weight'] ? 'normal' : 'bold',
				'align'         => isset( $z['align'] ) && in_array( $z['align'], array( 'left', 'center', 'right' ), true ) ? $z['align'] : 'left',
				'max_length'    => isset( $z['max_length'] ) ? intval( $z['max_length'] ) : 24,
				'default_value' => isset( $z['default_value'] ) ? sanitize_text_field( $z['default_value'] ) : '',
			);
		}

		$data = array(
			'enabled'  => $enabled,
			'image_id' => $image_id,
			'zones'    => $zones_out,
		);

		update_post_meta( $post_id, '_plc_template_data', $data );
	}

	/**
	 * Renders the Options & Pricing meta box: size, media, and color choices,
	 * each with an optional per-unit price add-on — mirroring the current
	 * WCPA-based form (size / media / color upcharges).
	 */
	public function render_options_meta_box( $post ) {
		wp_nonce_field( 'plc_save_options', 'plc_options_nonce' );

		$opts = get_post_meta( $post->ID, '_plc_options_data', true );
		$opts = is_array( $opts ) ? $opts : array();

		$sizes  = ! empty( $opts['sizes'] ) ? $opts['sizes'] : $this->default_sizes();
		$medias = ! empty( $opts['medias'] ) ? $opts['medias'] : $this->default_medias();
		$color_enabled = ! empty( $opts['color_enabled'] );
		?>
		<h4>Label Size</h4>
		<table class="widefat plc-option-table" data-group="sizes">
			<thead><tr><th>Label</th><th>Price Add-on ($)</th><th>Remove</th></tr></thead>
			<tbody>
			<?php foreach ( $sizes as $i => $row ) : ?>
				<tr>
					<td><input type="text" class="regular-text" name="plc_sizes[<?php echo $i; ?>][label]" value="<?php echo esc_attr( $row['label'] ); ?>" /></td>
					<td><input type="number" step="0.01" min="0" name="plc_sizes[<?php echo $i; ?>][price]" value="<?php echo esc_attr( $row['price'] ); ?>" /></td>
					<td><button type="button" class="button plc-remove-row">&times;</button></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p><button type="button" class="button plc-add-row" data-group="sizes">+ Add Size</button></p>

		<h4>Media / Vinyl Type</h4>
		<table class="widefat plc-option-table" data-group="medias">
			<thead><tr><th>Label</th><th>Price Add-on ($)</th><th>Remove</th></tr></thead>
			<tbody>
			<?php foreach ( $medias as $i => $row ) : ?>
				<tr>
					<td><input type="text" class="regular-text" name="plc_medias[<?php echo $i; ?>][label]" value="<?php echo esc_attr( $row['label'] ); ?>" /></td>
					<td><input type="number" step="0.01" min="0" name="plc_medias[<?php echo $i; ?>][price]" value="<?php echo esc_attr( $row['price'] ); ?>" /></td>
					<td><button type="button" class="button plc-remove-row">&times;</button></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p><button type="button" class="button plc-add-row" data-group="medias">+ Add Media Type</button></p>

		<h4>Color Choice</h4>
		<p>
			<label>
				<input type="checkbox" name="plc_color_enabled" value="1" <?php checked( $color_enabled ); ?> />
				Let customers pick an accent color (defaults to black if not selected)
			</label>
		</p>
		<?php
	}

	private function default_sizes() {
		return array(
			array( 'label' => '3mL - Standard size for smaller vials (peps, etc)', 'price' => 0 ),
			array( 'label' => '10mL - Standard size for oils', 'price' => 0.03 ),
			array( 'label' => '20mL - Standard size for Aminos', 'price' => 0.04 ),
			array( 'label' => '30mL - Standard size for BAC', 'price' => 0.05 ),
			array( 'label' => 'Custom Size', 'price' => 0.06 ),
		);
	}

	private function default_medias() {
		return array(
			array( 'label' => 'Glossy White', 'price' => 0 ),
			array( 'label' => 'Matte White', 'price' => 0 ),
			array( 'label' => 'Holographic', 'price' => 0.03 ),
			array( 'label' => 'Clear Vinyl', 'price' => 0.03 ),
			array( 'label' => 'Silver Metallic', 'price' => 0.03 ),
		);
	}

	public function save_options_pricing( $post_id ) {
		if ( ! isset( $_POST['plc_options_nonce'] ) || ! wp_verify_nonce( $_POST['plc_options_nonce'], 'plc_save_options' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}

		$sizes  = $this->sanitize_option_rows( isset( $_POST['plc_sizes'] ) ? $_POST['plc_sizes'] : array() );
		$medias = $this->sanitize_option_rows( isset( $_POST['plc_medias'] ) ? $_POST['plc_medias'] : array() );

		update_post_meta( $post_id, '_plc_options_data', array(
			'sizes'         => $sizes,
			'medias'        => $medias,
			'color_enabled' => ! empty( $_POST['plc_color_enabled'] ),
		) );
	}

	private function sanitize_option_rows( $rows ) {
		$out = array();
		if ( ! is_array( $rows ) ) {
			return $out;
		}
		foreach ( $rows as $row ) {
			if ( empty( $row['label'] ) ) {
				continue;
			}
			$out[] = array(
				'label' => sanitize_text_field( $row['label'] ),
				'price' => isset( $row['price'] ) ? floatval( $row['price'] ) : 0,
			);
		}
		return $out;
	}
}
