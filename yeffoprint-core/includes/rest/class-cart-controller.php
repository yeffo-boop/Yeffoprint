<?php
/**
 * Cart endpoints: add a batch, fetch the drawer's contents, and fetch
 * one cart item's batch data back out for "Edit customization"
 * (PROJECT_SPEC §14).
 *
 * Every field submitted here is re-validated against the Template's
 * actual field_schema/compatible sizes & materials — the same rule
 * that governs pricing (PROJECT_SPEC §12) applies to the customization
 * data itself: the client's copy is never trusted as-is.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Cart_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/cart/add', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'add' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'guest_or_nonced_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/cart/drawer', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'drawer' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( self::NAMESPACE, '/cart/item/(?P<key>[a-zA-Z0-9]+)', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_item' ],
			'permission_callback' => '__return_true',
		] );
	}

	/**
	 * REST requests don't run WooCommerce's normal frontend bootstrap,
	 * so the session/cart need to be initialized explicitly. Standard
	 * WooCommerce technique for cart manipulation outside a full page
	 * load (wc_load_cart() has been available since WC 3.6).
	 */
	private function ensure_cart_loaded(): void {
		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
	}

	/**
	 * Explicit rather than relying on exactly when WC internally
	 * recalculates — cheap and idempotent, and it's what actually runs
	 * our price-override hook (class-cart-pricing.php), so calling it
	 * right before reading any price/total guarantees they're current.
	 */
	private function recalculate(): void {
		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->calculate_totals();
		}
	}

	public function add( \WP_REST_Request $request ) {
		$this->ensure_cart_loaded();

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return new \WP_Error( 'yeffoprint_cart_unavailable', __( 'The cart is not available right now.', 'yeffoprint-core' ), [ 'status' => 503 ] );
		}

		$template_id = absint( $request->get_param( 'template_id' ) );
		$template    = get_post( $template_id );

		if ( ! $template || 'yp_template' !== $template->post_type || 'publish' !== $template->post_status ) {
			return new \WP_Error( 'yeffoprint_invalid_template', __( 'This design is not available.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$product_id = YeffoPrint_Linked_Product::get_linked_product_id( $template_id );
		if ( ! $product_id ) {
			return new \WP_Error( 'yeffoprint_no_product', __( 'This design is not orderable yet.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$compatible_sizes     = array_map( 'absint', (array) get_post_meta( $template_id, YeffoPrint_Template_Meta::COMPATIBLE_SIZES, true ) );
		$compatible_materials = array_map( 'absint', (array) get_post_meta( $template_id, YeffoPrint_Template_Meta::COMPATIBLE_MATERIALS, true ) );

		$size_id = absint( $request->get_param( 'size_id' ) );
		if ( $compatible_sizes && ! in_array( $size_id, $compatible_sizes, true ) ) {
			return new \WP_Error( 'yeffoprint_invalid_size', __( 'Please choose a valid size.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$material_id = absint( $request->get_param( 'material_id' ) );
		if ( $compatible_materials && ! in_array( $material_id, $compatible_materials, true ) ) {
			return new \WP_Error( 'yeffoprint_invalid_material', __( 'Please choose a valid material.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		if ( $material_id && ! (bool) get_post_meta( $material_id, YeffoPrint_Commerce_Record_Meta::IN_STOCK, true ) ) {
			return new \WP_Error( 'yeffoprint_material_out_of_stock', __( 'That material is currently out of stock. Please choose a different one.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$variants = YeffoPrint_Field_Schema::sanitize_variants( $request->get_param( 'variants' ), YeffoPrint_Field_Schema::get( $template_id ) );
		if ( is_wp_error( $variants ) ) {
			return $variants;
		}

		$total_quantity = array_sum( array_column( $variants, 'quantity' ) );

		$edit_key = (string) $request->get_param( 'edit_key' );
		if ( $edit_key && WC()->cart->get_cart_item( $edit_key ) ) {
			WC()->cart->remove_cart_item( $edit_key );
		}

		YeffoPrint_Cart_Pricing::allow_next_add( true );
		$cart_item_key = WC()->cart->add_to_cart( $product_id, $total_quantity, 0, [], [
			YeffoPrint_Cart_Item_Keys::TEMPLATE_ID => $template_id,
			YeffoPrint_Cart_Item_Keys::SIZE_ID     => $size_id,
			YeffoPrint_Cart_Item_Keys::MATERIAL_ID => $material_id,
			YeffoPrint_Cart_Item_Keys::VARIANTS    => $variants,
			YeffoPrint_Cart_Item_Keys::TOTAL_QTY   => $total_quantity,
		] );
		YeffoPrint_Cart_Pricing::allow_next_add( false );

		if ( ! $cart_item_key ) {
			$notices = wc_get_notices( 'error' );
			wc_clear_notices();
			$message = ! empty( $notices ) ? wp_strip_all_tags( $notices[0]['notice'] ) : __( 'Could not add this design to your cart.', 'yeffoprint-core' );
			return new \WP_Error( 'yeffoprint_add_to_cart_failed', $message, [ 'status' => 400 ] );
		}

		$this->recalculate();

		return rest_ensure_response( [
			'success'       => true,
			'cart_item_key' => $cart_item_key,
			'cart_count'    => WC()->cart->get_cart_contents_count(),
			'cart_total'    => wp_strip_all_tags( WC()->cart->get_cart_total() ),
			'drawer_html'   => $this->render_drawer(),
		] );
	}

	public function drawer() {
		$this->ensure_cart_loaded();
		$this->recalculate();

		return rest_ensure_response( [
			'cart_count'  => function_exists( 'WC' ) && WC()->cart ? WC()->cart->get_cart_contents_count() : 0,
			'drawer_html' => $this->render_drawer(),
		] );
	}

	public function get_item( \WP_REST_Request $request ) {
		$this->ensure_cart_loaded();

		$key  = (string) $request->get_param( 'key' );
		$item = function_exists( 'WC' ) && WC()->cart ? WC()->cart->get_cart_item( $key ) : null;

		if ( ! $item || empty( $item[ YeffoPrint_Cart_Item_Keys::TOTAL_QTY ] ) ) {
			return new \WP_Error( 'yeffoprint_cart_item_not_found', __( 'That cart item was not found.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}

		return rest_ensure_response( [
			'template_id' => $item[ YeffoPrint_Cart_Item_Keys::TEMPLATE_ID ],
			'size_id'     => $item[ YeffoPrint_Cart_Item_Keys::SIZE_ID ],
			'material_id' => $item[ YeffoPrint_Cart_Item_Keys::MATERIAL_ID ],
			'variants'    => $item[ YeffoPrint_Cart_Item_Keys::VARIANTS ],
		] );
	}

	private function render_drawer(): string {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return '<p>' . esc_html__( 'Your cart is empty.', 'yeffoprint-core' ) . '</p>';
		}

		ob_start();
		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( empty( $cart_item[ YeffoPrint_Cart_Item_Keys::TOTAL_QTY ] ) ) {
				continue;
			}

			$this->render_drawer_item( $cart_item_key, $cart_item );
		}
		?>
		<p class="yp-cart-drawer__total">
			<?php esc_html_e( 'Subtotal', 'yeffoprint-core' ); ?>
			<strong><?php echo wp_kses_post( WC()->cart->get_cart_subtotal() ); ?></strong>
		</p>
		<div class="yp-cart-drawer__actions">
			<a class="wp-block-button__link" href="<?php echo esc_url( wc_get_checkout_url() ); ?>"><?php esc_html_e( 'Checkout', 'yeffoprint-core' ); ?></a>
			<a class="wp-block-button__link is-style-outline" href="<?php echo esc_url( wc_get_cart_url() ); ?>"><?php esc_html_e( 'View Cart', 'yeffoprint-core' ); ?></a>
		</div>
		<?php
		return ob_get_clean();
	}

	private function render_drawer_item( string $cart_item_key, array $cart_item ): void {
		$template_id = (int) $cart_item[ YeffoPrint_Cart_Item_Keys::TEMPLATE_ID ];
		$size        = get_post( $cart_item[ YeffoPrint_Cart_Item_Keys::SIZE_ID ] ?? 0 );
		$material    = get_post( $cart_item[ YeffoPrint_Cart_Item_Keys::MATERIAL_ID ] ?? 0 );
		$variants    = (array) $cart_item[ YeffoPrint_Cart_Item_Keys::VARIANTS ];
		$line_total  = $cart_item['data']->get_price() * (int) $cart_item[ YeffoPrint_Cart_Item_Keys::TOTAL_QTY ];
		$thumbnail   = $template_id ? get_the_post_thumbnail_url( $template_id, 'thumbnail' ) : '';
		$edit_url    = $template_id ? add_query_arg( 'edit', $cart_item_key, get_permalink( $template_id ) ) : '';
		?>
		<div class="yp-cart-drawer__item">
			<?php if ( $thumbnail ) : ?>
				<img class="yp-cart-drawer__thumb" src="<?php echo esc_url( $thumbnail ); ?>" alt="" />
			<?php endif; ?>
			<div class="yp-cart-drawer__details">
				<strong><?php echo esc_html( $template_id ? get_the_title( $template_id ) : '' ); ?></strong>
				<span>
					<?php
					echo esc_html( implode( ' · ', array_filter( [
						$size ? $size->post_title : '',
						$material ? $material->post_title : '',
						sprintf(
							/* translators: %d: quantity */
							_n( '%d label', '%d labels', (int) $cart_item[ YeffoPrint_Cart_Item_Keys::TOTAL_QTY ], 'yeffoprint-core' ),
							(int) $cart_item[ YeffoPrint_Cart_Item_Keys::TOTAL_QTY ]
						),
					] ) ) );
					?>
				</span>
				<?php if ( count( $variants ) > 1 ) : ?>
					<span><?php printf( esc_html__( '%d variants in this batch', 'yeffoprint-core' ), count( $variants ) ); ?></span>
				<?php endif; ?>
				<span class="yp-cart-drawer__price"><?php echo wp_kses_post( wc_price( $line_total ) ); ?></span>
				<?php if ( $edit_url ) : ?>
					<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit customization', 'yeffoprint-core' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
