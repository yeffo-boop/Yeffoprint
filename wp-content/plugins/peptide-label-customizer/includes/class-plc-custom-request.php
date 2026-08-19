<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles "custom, made-from-scratch" design request products: a details
 * form with reference-file upload instead of the live template preview.
 * The order lands with the description + uploaded files attached, for
 * manual design work.
 */
class PLC_Custom_Request {

	public function __construct() {
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'add_product_field' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_field' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_request_form' ), 5 );

		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 3 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'add_order_line_item_meta' ), 10, 4 );
		add_action( 'woocommerce_after_order_itemmeta', array( $this, 'show_files_in_admin' ), 10, 3 );
	}

	private function is_custom_request_product( $product_id ) {
		return 'yes' === get_post_meta( $product_id, '_plc_custom_request', true );
	}

	public function add_product_field() {
		global $post;
		echo '<div class="options_group">';
		woocommerce_wp_checkbox( array(
			'id'          => '_plc_custom_request',
			'label'       => 'Custom Design Request',
			'description' => 'Show a design-request form (details + reference file upload) instead of the label template customizer.',
		) );
		echo '</div>';
	}

	public function save_product_field( $post_id ) {
		$value = isset( $_POST['_plc_custom_request'] ) ? 'yes' : 'no';
		update_post_meta( $post_id, '_plc_custom_request', $value );
	}

	public function maybe_enqueue() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		global $product;
		if ( ! $product || ! $this->is_custom_request_product( $product->get_id() ) ) {
			return;
		}
		wp_enqueue_style( 'plc-frontend-css', PLC_URL . 'assets/css/frontend.css', array(), PLC_VERSION );
		wp_enqueue_script( 'plc-custom-request-js', PLC_URL . 'assets/js/custom-request.js', array( 'jquery' ), PLC_VERSION, true );
	}

	public function render_request_form() {
		global $product;
		if ( ! $product || ! $this->is_custom_request_product( $product->get_id() ) ) {
			return;
		}
		?>
		<div id="plc-custom-request" class="plc-customizer plc-custom-request">
			<div class="plc-fields-col" style="flex-basis:100%;">
				<p class="form-row">
					<label for="plc_cr_description">Describe what you'd like designed <span class="required">*</span></label>
					<textarea id="plc_cr_description" name="plc_cr_description" rows="5" required placeholder="Compound(s), label size, style, colors, anything you want featured..."></textarea>
				</p>
				<p class="form-row">
					<label for="plc_cr_files">Reference Files <span class="optional">(images, PDFs — optional)</span></label>
					<input type="file" id="plc_cr_files" name="plc_cr_files[]" multiple accept="image/*,.pdf" />
				</p>
				<p class="plc-error-message" id="plc-cr-error-message" style="display:none;"></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle the uploaded reference files + description at add-to-cart time.
	 */
	public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		if ( ! $this->is_custom_request_product( $product_id ) ) {
			return $cart_item_data;
		}
		if ( empty( $_POST['plc_cr_description'] ) ) {
			return $cart_item_data;
		}

		$cart_item_data['plc_cr_description'] = sanitize_textarea_field( wp_unslash( $_POST['plc_cr_description'] ) );

		if ( ! empty( $_FILES['plc_cr_files'] ) && ! empty( $_FILES['plc_cr_files']['name'][0] ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';

			$attachment_ids = array();
			$file_count = count( $_FILES['plc_cr_files']['name'] );
			for ( $i = 0; $i < $file_count; $i++ ) {
				if ( empty( $_FILES['plc_cr_files']['name'][ $i ] ) ) {
					continue;
				}
				$file = array(
					'name'     => $_FILES['plc_cr_files']['name'][ $i ],
					'type'     => $_FILES['plc_cr_files']['type'][ $i ],
					'tmp_name' => $_FILES['plc_cr_files']['tmp_name'][ $i ],
					'error'    => $_FILES['plc_cr_files']['error'][ $i ],
					'size'     => $_FILES['plc_cr_files']['size'][ $i ],
				);
				$_FILES['plc_single_file'] = $file;
				$attachment_id = media_handle_upload( 'plc_single_file', 0 );
				if ( ! is_wp_error( $attachment_id ) ) {
					$attachment_ids[] = $attachment_id;
				}
			}
			unset( $_FILES['plc_single_file'] );

			if ( ! empty( $attachment_ids ) ) {
				$cart_item_data['plc_cr_files'] = $attachment_ids;
			}
		}

		$cart_item_data['plc_cr_unique'] = md5( microtime() . wp_rand() );

		return $cart_item_data;
	}

	public function display_cart_item_data( $item_data, $cart_item ) {
		if ( ! empty( $cart_item['plc_cr_description'] ) ) {
			$item_data[] = array(
				'name'  => 'Design Request',
				'value' => esc_html( $cart_item['plc_cr_description'] ),
			);
		}
		if ( ! empty( $cart_item['plc_cr_files'] ) ) {
			$item_data[] = array(
				'name'  => 'Reference Files',
				'value' => count( $cart_item['plc_cr_files'] ) . ' file(s) attached',
			);
		}
		return $item_data;
	}

	public function add_order_line_item_meta( $item, $cart_item_key, $values, $order ) {
		if ( ! empty( $values['plc_cr_description'] ) ) {
			$item->add_meta_data( 'Design Request', $values['plc_cr_description'], true );
		}
		if ( ! empty( $values['plc_cr_files'] ) ) {
			$item->add_meta_data( '_plc_cr_file_ids', $values['plc_cr_files'], true );
		}
	}

	public function show_files_in_admin( $item_id, $item, $product ) {
		if ( ! is_admin() ) {
			return;
		}
		$file_ids = $item->get_meta( '_plc_cr_file_ids' );
		if ( empty( $file_ids ) || ! is_array( $file_ids ) ) {
			return;
		}
		echo '<div class="plc-admin-cr-files" style="margin-top:8px;">';
		echo '<strong>Reference Files:</strong><br />';
		foreach ( $file_ids as $fid ) {
			$url = wp_get_attachment_url( $fid );
			if ( $url ) {
				echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( basename( $url ) ) . '</a><br />';
			}
		}
		echo '</div>';
	}
}
