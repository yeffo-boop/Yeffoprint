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

		// A Size with no print dimensions (e.g. "Custom") means the
		// customer types their own label size (configurator.js). Kept in
		// inches on the same keys Custom Stickers use; for a Template
		// item they're display-only (pricing still uses the Size's flat
		// adjustment — the inch keys only feed sticker pricing).
		$custom_width_in  = 0.0;
		$custom_height_in = 0.0;
		if ( $size_id && ! YeffoPrint_Commerce_Record_Meta::size_has_dimensions( $size_id ) ) {
			$custom_width_in  = round( (float) $request->get_param( 'custom_width_in' ), 2 );
			$custom_height_in = round( (float) $request->get_param( 'custom_height_in' ), 2 );
			if ( $custom_width_in < 0.25 || $custom_width_in > 24 || $custom_height_in < 0.25 || $custom_height_in > 24 ) {
				return new \WP_Error( 'yeffoprint_custom_size_required', __( 'Enter your label’s width and height in inches (between 0.25 and 24).', 'yeffoprint-core' ), [ 'status' => 400 ] );
			}
		}

		$material_id = absint( $request->get_param( 'material_id' ) );
		if ( $compatible_materials && ! in_array( $material_id, $compatible_materials, true ) ) {
			return new \WP_Error( 'yeffoprint_invalid_material', __( 'Please choose a valid material.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		if ( $material_id && ! (bool) get_post_meta( $material_id, YeffoPrint_Commerce_Record_Meta::IN_STOCK, true ) ) {
			return new \WP_Error( 'yeffoprint_material_out_of_stock', __( 'That material is currently out of stock. Please choose a different one.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$raw_variants = $request->get_param( 'variants' );
		$variants     = YeffoPrint_Field_Schema::sanitize_variants( $raw_variants, YeffoPrint_Field_Schema::get( $template_id ) );
		if ( is_wp_error( $variants ) ) {
			return $variants;
		}

		$total_quantity = array_sum( array_column( $variants, 'quantity' ) );

		// Root cause found (diagnostic logging below confirmed the server
		// always received/summed every batch row correctly — the loss
		// happened after that): WC_Cart::generate_cart_id() hashes
		// product + size + material + variants to decide whether an add
		// is "the same line item" as something already in the cart. When
		// a customer adds a second batch for the same template/size/
		// material, WC_Cart::add_to_cart() (class-wc-cart.php) treats a
		// hash match as "already in cart" and *only* bumps its own
		// internal quantity counter — it never touches the VARIANTS/
		// TOTAL_QTY meta this site actually displays and prices from. The
		// result: every new batch after the first silently vanished into
		// an invisible counter nobody reads, while the cart kept showing
		// whatever the very first add's data was. Same mechanism, same
		// fix already in place for Custom Design's own batch rows —
		// CUSTOM_ORDER_ROW_INDEX's own docblock (class-cart-item-keys.php)
		// — this class's docblock claimed the Template flow didn't need
		// it since a batch is "one call, never split," which is true but
		// beside the point: two *separate* Add to Cart clicks for the
		// same template/size/material are still two separate calls that
		// can hash identically. A `uniqid()` per add guarantees every
		// fresh submission gets its own line item; editing an existing
		// batch still goes through the explicit edit_key remove-then-add
		// path below, untouched by this.
		$cart_item_data = [
			YeffoPrint_Cart_Item_Keys::TEMPLATE_ID => $template_id,
			YeffoPrint_Cart_Item_Keys::SIZE_ID     => $size_id,
			YeffoPrint_Cart_Item_Keys::MATERIAL_ID => $material_id,
			YeffoPrint_Cart_Item_Keys::VARIANTS    => $variants,
			YeffoPrint_Cart_Item_Keys::TOTAL_QTY   => $total_quantity,
			'yp_unique_add'                        => uniqid( '', true ),
		];

		if ( $custom_width_in > 0 ) {
			$cart_item_data[ YeffoPrint_Cart_Item_Keys::CUSTOM_WIDTH_IN ]  = $custom_width_in;
			$cart_item_data[ YeffoPrint_Cart_Item_Keys::CUSTOM_HEIGHT_IN ] = $custom_height_in;
		}

		// Direct report: submitting 3 batch rows (30 total) landed in the
		// cart as a single 10-label line item — every step of this
		// function reads correctly on paper (sanitize_variants() doesn't
		// truncate, array_sum() doesn't dedupe), so rather than guess
		// further blind, log exactly what this request received and
		// computed. WooCommerce -> Status -> Logs, source
		// "yeffoprint-cart-batches", is where the next occurrence shows
		// up with the actual counts.
		wc_get_logger()->info(
			sprintf(
				'cart/add for template #%d — raw variants: %d, sanitized variants: %d, total_quantity: %d',
				$template_id,
				is_array( $raw_variants ) ? count( $raw_variants ) : -1,
				count( $variants ),
				$total_quantity
			),
			[ 'source' => 'yeffoprint-cart-batches' ]
		);

		$edit_key = (string) $request->get_param( 'edit_key' );
		if ( $edit_key && WC()->cart->get_cart_item( $edit_key ) ) {
			WC()->cart->remove_cart_item( $edit_key );
		}

		YeffoPrint_Cart_Pricing::allow_next_add( true );
		$cart_item_key = WC()->cart->add_to_cart( $product_id, $total_quantity, 0, [], $cart_item_data );
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
			'drawer_html'   => self::drawer_html(),
		] );
	}

	public function drawer() {
		$this->ensure_cart_loaded();
		$this->recalculate();

		return rest_ensure_response( [
			'cart_count'  => function_exists( 'WC' ) && WC()->cart ? WC()->cart->get_cart_contents_count() : 0,
			'drawer_html' => self::drawer_html(),
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
			'custom_width_in'  => (float) ( $item[ YeffoPrint_Cart_Item_Keys::CUSTOM_WIDTH_IN ] ?? 0 ),
			'custom_height_in' => (float) ( $item[ YeffoPrint_Cart_Item_Keys::CUSTOM_HEIGHT_IN ] ?? 0 ),
		] );
	}

	/** Public so other add-to-cart endpoints (class-print-controller.php) can answer with the same drawer payload. */
	public static function drawer_html(): string {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return '<p>' . esc_html__( 'Your cart is empty.', 'yeffoprint-core' ) . '</p>';
		}

		ob_start();
		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( ! empty( $cart_item[ YeffoPrint_Cart_Item_Keys::PRINT_ID ] ) ) {
				self::render_print_drawer_item( $cart_item );
				continue;
			}

			if ( empty( $cart_item[ YeffoPrint_Cart_Item_Keys::TOTAL_QTY ] ) ) {
				continue;
			}

			self::render_drawer_item( $cart_item_key, $cart_item );
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

	private static function render_drawer_item( string $cart_item_key, array $cart_item ): void {
		$template_id = (int) $cart_item[ YeffoPrint_Cart_Item_Keys::TEMPLATE_ID ];
		$size        = get_post( $cart_item[ YeffoPrint_Cart_Item_Keys::SIZE_ID ] ?? 0 );
		$material    = get_post( $cart_item[ YeffoPrint_Cart_Item_Keys::MATERIAL_ID ] ?? 0 );
		$variants    = (array) $cart_item[ YeffoPrint_Cart_Item_Keys::VARIANTS ];
		$line_total  = $cart_item['data']->get_price() * (int) $cart_item[ YeffoPrint_Cart_Item_Keys::TOTAL_QTY ];
		$thumbnail = '';
		if ( $template_id && function_exists( 'yeffoprint_core_get_template_card_data' ) ) {
			$card      = yeffoprint_core_get_template_card_data( $template_id );
			$thumbnail = $card ? (string) ( $card['vial_mockup_url'] ?: $card['artwork_url'] ?: '' ) : '';
		}
		if ( ! $thumbnail && $template_id ) {
			$thumbnail = (string) get_the_post_thumbnail_url( $template_id, 'medium' );
		}
		$edit_url = $template_id ? add_query_arg( 'edit', $cart_item_key, get_permalink( $template_id ) ) : '';
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

	/** A 3D print line: photo, name, one "Part: Color" line per color choice, quantity and price. */
	private static function render_print_drawer_item( array $cart_item ): void {
		$print_id  = (int) $cart_item[ YeffoPrint_Cart_Item_Keys::PRINT_ID ];
		$picks     = (array) ( $cart_item[ YeffoPrint_Cart_Item_Keys::PRINT_COLORS ] ?? [] );
		$quantity  = (int) $cart_item['quantity'];
		$thumbnail = (string) get_the_post_thumbnail_url( $print_id, 'medium' );
		?>
		<div class="yp-cart-drawer__item">
			<?php if ( $thumbnail ) : ?>
				<img class="yp-cart-drawer__thumb" src="<?php echo esc_url( $thumbnail ); ?>" alt="" />
			<?php endif; ?>
			<div class="yp-cart-drawer__details">
				<strong><?php echo esc_html( get_the_title( $print_id ) ); ?></strong>
				<?php foreach ( $picks as $pick ) : ?>
					<span><?php echo esc_html( ( $pick['slot'] ?? '' ) . ': ' . ( $pick['name'] ?? '' ) ); ?></span>
				<?php endforeach; ?>
				<span>
					<?php
					/* translators: %d: quantity */
					echo esc_html( sprintf( __( 'Qty %d', 'yeffoprint-core' ), $quantity ) );
					?>
				</span>
				<span class="yp-cart-drawer__price"><?php echo wp_kses_post( wc_price( $cart_item['data']->get_price() * $quantity ) ); ?></span>
			</div>
		</div>
		<?php
	}
}
