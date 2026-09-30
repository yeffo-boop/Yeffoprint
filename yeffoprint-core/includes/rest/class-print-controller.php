<?php
/**
 * 3D Prints' add-to-cart endpoint (direct request: a 3D Prints section
 * where the customer picks a color for each part of the print).
 * Validates every pick server-side against the item's live color
 * choices and stock (YeffoPrint_Print_Meta::resolve_picks()) before
 * anything reaches the cart, then answers with the same drawer payload
 * the label configurator's add-to-cart returns, so the product page can
 * open the cart drawer the same way.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Print_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/prints/(?P<id>\d+)', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'item' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( self::NAMESPACE, '/prints/cart', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'add' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'guest_or_nonced_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/admin/filament-image', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'import_filament_image' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );
	}

	/**
	 * Filament Colors' "Paste an image link": copies a photo from a
	 * brand's product page into the Media Library, so adding a filament
	 * never needs a download-then-upload round trip. The type comes from
	 * the file itself, not the link — brand CDNs often serve images from
	 * links without an extension (Bambu's end in ".media").
	 */
	public function import_filament_image( \WP_REST_Request $request ) {
		$url = esc_url_raw( trim( (string) $request->get_param( 'url' ) ) );
		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			return new \WP_Error( 'yeffoprint_filament_image_url', __( 'Paste a full image link starting with https://', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			/* translators: %s: the download error */
			return new \WP_Error( 'yeffoprint_filament_image_download', sprintf( __( 'Couldn’t download that image: %s', 'yeffoprint-core' ), $tmp->get_error_message() ), [ 'status' => 400 ] );
		}

		$types = [ 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif' ];
		$mime  = wp_get_image_mime( $tmp );
		if ( ! $mime || ! isset( $types[ $mime ] ) ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'yeffoprint_filament_image_type', __( 'That link isn’t a JPG, PNG, WebP or GIF image. Right-click the photo on the brand’s page and copy the image address.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$name  = sanitize_file_name( (string) $request->get_param( 'name' ) );
		$file  = [
			'name'     => ( '' !== $name ? $name : 'filament' ) . '.' . $types[ $mime ],
			'tmp_name' => $tmp,
		];
		$image_id = media_handle_sideload( $file, 0, (string) $request->get_param( 'name' ) );

		if ( is_wp_error( $image_id ) ) {
			wp_delete_file( $tmp );
			return $image_id;
		}

		return rest_ensure_response( [
			'id'  => $image_id,
			'url' => (string) wp_get_attachment_image_url( $image_id, 'medium_large' ),
		] );
	}

	public function item( \WP_REST_Request $request ) {
		$print_id = (int) $request->get_param( 'id' );
		$payload  = 'publish' === get_post_status( $print_id ) ? YeffoPrint_Print_Meta::get_item_payload( $print_id ) : null;

		if ( ! $payload ) {
			return new \WP_Error( 'yeffoprint_print_not_found', __( 'That item was not found.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}

		return rest_ensure_response( $payload );
	}

	public function add( \WP_REST_Request $request ) {
		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return new \WP_Error( 'yeffoprint_cart_unavailable', __( 'The cart is unavailable right now.', 'yeffoprint-core' ), [ 'status' => 503 ] );
		}

		// Load the saved cart before adding, or the add is lost (see
		// YeffoPrint_Cart_Controller::ensure_cart_loaded()).
		WC()->cart->get_cart();

		$print_id = (int) $request->get_param( 'print_id' );
		$quantity = max( 1, min( 100, (int) $request->get_param( 'quantity' ) ) );

		if ( 'yp_print' !== get_post_type( $print_id ) || 'publish' !== get_post_status( $print_id ) ) {
			return new \WP_Error( 'yeffoprint_print_not_found', __( 'That item was not found.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}

		$size = YeffoPrint_Print_Meta::resolve_size( $print_id, $request->get_param( 'size' ) );
		if ( is_wp_error( $size ) ) {
			return $size;
		}

		$picks = YeffoPrint_Print_Meta::resolve_picks( $print_id, (array) $request->get_param( 'colors' ) );
		if ( is_wp_error( $picks ) ) {
			return $picks;
		}

		$addons = YeffoPrint_Print_Meta::resolve_addons(
			$print_id,
			$request->get_param( 'text' ),
			$request->get_param( 'image_id' ),
			$request->get_param( 'text_color' ),
			$request->get_param( 'image_color' )
		);
		if ( is_wp_error( $addons ) ) {
			return $addons;
		}

		$product_id = YeffoPrint_Print_Product::get_linked_product_id( $print_id );
		$product    = $product_id && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $product || 'publish' !== $product->get_status() ) {
			return new \WP_Error( 'yeffoprint_print_unavailable', __( "This item isn't available to order yet.", 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		YeffoPrint_Cart_Pricing::allow_next_add( true );
		$cart_item_key = WC()->cart->add_to_cart( $product_id, $quantity, 0, [], [
			YeffoPrint_Cart_Item_Keys::PRINT_ID     => $print_id,
			YeffoPrint_Cart_Item_Keys::PRINT_SIZE   => $size,
			YeffoPrint_Cart_Item_Keys::PRINT_COLORS => $picks,
			YeffoPrint_Cart_Item_Keys::PRINT_TEXT   => $addons['text'],
			YeffoPrint_Cart_Item_Keys::PRINT_IMAGE  => $addons['image_id'],
			YeffoPrint_Cart_Item_Keys::PRINT_TEXT_COLOR  => $addons['text_color'],
			YeffoPrint_Cart_Item_Keys::PRINT_IMAGE_COLOR => $addons['image_color'],
		] );
		YeffoPrint_Cart_Pricing::allow_next_add( false );

		if ( ! $cart_item_key ) {
			$notices = wc_get_notices( 'error' );
			wc_clear_notices();
			$message = ! empty( $notices ) ? wp_strip_all_tags( $notices[0]['notice'] ) : __( "Couldn't add this to your cart.", 'yeffoprint-core' );
			return new \WP_Error( 'yeffoprint_add_to_cart_failed', $message, [ 'status' => 400 ] );
		}

		WC()->cart->calculate_totals();

		return rest_ensure_response( [
			'success'     => true,
			'cart_count'  => WC()->cart->get_cart_contents_count(),
			'drawer_html' => YeffoPrint_Cart_Controller::drawer_html(),
		] );
	}
}
