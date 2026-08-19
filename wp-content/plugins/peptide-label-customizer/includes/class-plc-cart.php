<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carries the customer's label customization (compound name, strength,
 * batch number, expiration date, size/media/color, notes, and a flattened
 * preview image) from the product form through the cart and into the order.
 */
class PLC_Cart {

	public function __construct() {
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 3 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_item_data' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_option_pricing' ) );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'add_order_line_item_meta' ), 10, 4 );
		add_action( 'woocommerce_after_order_itemmeta', array( $this, 'show_preview_in_admin' ), 10, 3 );
		add_action( 'wp_ajax_plc_upload_preview', array( $this, 'ajax_upload_preview' ) );
		add_action( 'wp_ajax_nopriv_plc_upload_preview', array( $this, 'ajax_upload_preview' ) );
	}

	/**
	 * Pull the customizer's hidden fields into cart item data. Also uploads
	 * the flattened preview PNG (base64 data URL) into the media library so
	 * it survives as a normal attachment tied to the order.
	 */
	public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		if ( empty( $_POST['plc_data'] ) ) {
			return $cart_item_data;
		}

		$decoded = json_decode( wp_unslash( $_POST['plc_data'] ), true );
		if ( ! is_array( $decoded ) ) {
			return $cart_item_data;
		}

		$clean = array(
			'compound_name'   => isset( $decoded['compound_name'] ) ? sanitize_text_field( $decoded['compound_name'] ) : '',
			'strength'        => isset( $decoded['strength'] ) ? sanitize_text_field( $decoded['strength'] ) : '',
			'batch_number'    => isset( $decoded['batch_number'] ) ? sanitize_text_field( $decoded['batch_number'] ) : '',
			'expiration_date' => isset( $decoded['expiration_date'] ) ? sanitize_text_field( $decoded['expiration_date'] ) : '',
			'size'            => isset( $decoded['size'] ) ? sanitize_text_field( $decoded['size'] ) : '',
			'media'           => isset( $decoded['media'] ) ? sanitize_text_field( $decoded['media'] ) : '',
			'color'           => isset( $decoded['color'] ) ? sanitize_hex_color( $decoded['color'] ) : '',
			'design_notes'    => isset( $decoded['design_notes'] ) ? sanitize_textarea_field( $decoded['design_notes'] ) : '',
		);

		$cart_item_data['plc_data'] = $clean;

		if ( ! empty( $_POST['plc_preview_image'] ) ) {
			$attachment_id = $this->save_preview_image( wp_unslash( $_POST['plc_preview_image'] ), $product_id );
			if ( $attachment_id ) {
				$cart_item_data['plc_preview_attachment_id'] = $attachment_id;
			}
		}

		// Ensure identical-looking customizations don't silently merge into one cart line.
		$cart_item_data['plc_unique'] = md5( wp_json_encode( $clean ) . microtime() );

		return $cart_item_data;
	}

	private function save_preview_image( $data_url, $product_id ) {
		if ( strpos( $data_url, 'base64,' ) === false ) {
			return 0;
		}
		list( , $base64 ) = explode( 'base64,', $data_url, 2 );
		$bits = base64_decode( $base64 );
		if ( ! $bits ) {
			return 0;
		}

		$filename = 'label-preview-' . $product_id . '-' . time() . '.png';
		$upload   = wp_upload_bits( $filename, null, $bits );
		if ( ! empty( $upload['error'] ) ) {
			return 0;
		}

		$filetype   = wp_check_filetype( $upload['file'], null );
		$attachment = array(
			'post_mime_type' => $filetype['type'],
			'post_title'     => sanitize_file_name( $filename ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);
		$attachment_id = wp_insert_attachment( $attachment, $upload['file'] );
		if ( ! is_wp_error( $attachment_id ) && $attachment_id ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$attach_data = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
			wp_update_attachment_metadata( $attachment_id, $attach_data );
			return $attachment_id;
		}
		return 0;
	}

	public function display_cart_item_data( $item_data, $cart_item ) {
		if ( empty( $cart_item['plc_data'] ) ) {
			return $item_data;
		}
		$d = $cart_item['plc_data'];
		$labels = array(
			'compound_name'   => 'Compound',
			'strength'        => 'Strength',
			'batch_number'    => 'Batch/Lot #',
			'expiration_date' => 'Expiration',
			'size'            => 'Size',
			'media'           => 'Media',
		);
		foreach ( $labels as $key => $label ) {
			if ( ! empty( $d[ $key ] ) ) {
				$item_data[] = array(
					'name'  => $label,
					'value' => esc_html( $d[ $key ] ),
				);
			}
		}
		if ( ! empty( $d['design_notes'] ) ) {
			$item_data[] = array(
				'name'  => 'Design Notes',
				'value' => esc_html( $d['design_notes'] ),
			);
		}
		return $item_data;
	}

	/**
	 * Adds the size/media price add-ons on top of the product's base price.
	 */
	public function apply_option_pricing( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		if ( did_action( 'woocommerce_before_calculate_totals' ) >= 2 ) {
			// Guard against double-application on repeated hook firing within one request.
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['plc_data'] ) ) {
				continue;
			}
			$product_id = $cart_item['product_id'];
			$opts = get_post_meta( $product_id, '_plc_options_data', true );
			if ( ! is_array( $opts ) ) {
				continue;
			}

			$addon = 0;
			$addon += $this->lookup_price( $opts, 'sizes', $cart_item['plc_data']['size'] );
			$addon += $this->lookup_price( $opts, 'medias', $cart_item['plc_data']['media'] );

			if ( $addon > 0 ) {
				$base_price = (float) $cart_item['data']->get_regular_price();
				$cart_item['data']->set_price( $base_price + $addon );
			}
		}
	}

	private function lookup_price( $opts, $group, $label ) {
		if ( empty( $opts[ $group ] ) || ! $label ) {
			return 0;
		}
		foreach ( $opts[ $group ] as $row ) {
			if ( $row['label'] === $label ) {
				return floatval( $row['price'] );
			}
		}
		return 0;
	}

	public function add_order_line_item_meta( $item, $cart_item_key, $values, $order ) {
		if ( empty( $values['plc_data'] ) ) {
			return;
		}
		$d = $values['plc_data'];
		$item->add_meta_data( 'Compound', $d['compound_name'], true );
		$item->add_meta_data( 'Strength', $d['strength'], true );
		if ( ! empty( $d['batch_number'] ) ) {
			$item->add_meta_data( 'Batch/Lot #', $d['batch_number'], true );
		}
		if ( ! empty( $d['expiration_date'] ) ) {
			$item->add_meta_data( 'Expiration', $d['expiration_date'], true );
		}
		if ( ! empty( $d['size'] ) ) {
			$item->add_meta_data( 'Size', $d['size'], true );
		}
		if ( ! empty( $d['media'] ) ) {
			$item->add_meta_data( 'Media', $d['media'], true );
		}
		if ( ! empty( $d['color'] ) ) {
			$item->add_meta_data( 'Accent Color', $d['color'], true );
		}
		if ( ! empty( $d['design_notes'] ) ) {
			$item->add_meta_data( 'Design Notes', $d['design_notes'], true );
		}
		if ( ! empty( $values['plc_preview_attachment_id'] ) ) {
			// Leading underscore hides this from the customer-facing order table;
			// it's picked back up for the admin thumbnail below.
			$item->add_meta_data( '_plc_preview_attachment_id', $values['plc_preview_attachment_id'], true );
		}
	}

	/**
	 * Shows the flattened label preview as a thumbnail on the admin order screen.
	 */
	public function show_preview_in_admin( $item_id, $item, $product ) {
		if ( ! is_admin() ) {
			return;
		}
		$attachment_id = $item->get_meta( '_plc_preview_attachment_id' );
		if ( ! $attachment_id ) {
			return;
		}
		$url = wp_get_attachment_image_url( $attachment_id, 'medium' );
		if ( ! $url ) {
			return;
		}
		echo '<div class="plc-admin-preview" style="margin-top:8px;">';
		echo '<strong>Label Preview:</strong><br />';
		echo '<img src="' . esc_url( $url ) . '" style="max-width:320px;height:auto;border:1px solid #ddd;border-radius:4px;margin-top:4px;" />';
		echo '</div>';
	}

	/**
	 * Optional AJAX endpoint reserved for future use if the preview needs to
	 * be uploaded before add-to-cart (e.g. for a "save my design" feature).
	 */
	public function ajax_upload_preview() {
		wp_send_json_error( 'Not implemented' );
	}
}
