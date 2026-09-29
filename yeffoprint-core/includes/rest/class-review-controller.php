<?php
/**
 * Public endpoints behind the /leave-a-review/ page (theme
 * assets/js/leave-review.js). Same access rule as the /track-order/
 * page (class-order-tracking-controller.php): the order's own
 * order_key in the URL for a guest, or a logged-in owner/staff with a
 * REST nonce. All the review logic lives in class-order-reviews.php.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Review_Controller {

	private const NAMESPACE       = 'yeffoprint-core/v1';
	private const RATE_LIMIT_MAX  = 10;
	private const RATE_LIMIT_SECS = HOUR_IN_SECONDS;

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/reviews/order/(?P<id>\d+)', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_order' ],
				'permission_callback' => [ $this, 'check_access' ],
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'submit' ],
				'permission_callback' => [ $this, 'check_access' ],
			],
		] );
	}

	/**
	 * @return true|\WP_Error
	 */
	public function check_access( \WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request->get_param( 'id' ) ) );

		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error( 'yeffoprint_order_not_found', __( 'That order was not found.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}

		$key = (string) $request->get_param( 'key' );
		if ( '' !== $key && hash_equals( $order->get_order_key(), $key ) ) {
			return true;
		}

		if ( is_user_logged_in() ) {
			$owns_it = $order->get_customer_id() && $order->get_customer_id() === get_current_user_id();

			if ( $owns_it || current_user_can( 'manage_woocommerce' ) ) {
				$nonce = $request->get_header( 'X-WP-Nonce' );

				return ( $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) )
					? true
					: new \WP_Error( 'yeffoprint_invalid_nonce', __( 'Your session has expired. Please refresh the page and try again.', 'yeffoprint-core' ), [ 'status' => 403 ] );
			}
		}

		return new \WP_Error( 'yeffoprint_forbidden', __( "This review link isn't valid. Please use the link from your delivery email.", 'yeffoprint-core' ), [ 'status' => 403 ] );
	}

	public function get_order( \WP_REST_Request $request ): \WP_REST_Response {
		$order  = wc_get_order( absint( $request->get_param( 'id' ) ) );
		$review = YeffoPrint_Order_Reviews::get_review( $order );

		return rest_ensure_response( [
			'order_number'   => $order->get_order_number(),
			'first_name'     => $order->get_billing_first_name(),
			'can_review'     => YeffoPrint_Order_Reviews::is_reviewable( $order ) && ! $review,
			'delivered'      => YeffoPrint_Order_Reviews::is_reviewable( $order ),
			'items'          => YeffoPrint_Order_Reviews::order_lines( $order ),
			'suggested_name' => YeffoPrint_Order_Reviews::suggested_name( $order ),
			'review'         => $review ? YeffoPrint_Order_Reviews::format( $review ) : null,
			'max_photos'     => YeffoPrint_Order_Reviews::MAX_PHOTOS,
			'max_text'       => YeffoPrint_Order_Reviews::MAX_TEXT,
		] );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function submit( \WP_REST_Request $request ) {
		$limited = $this->check_rate_limit();
		if ( $limited ) {
			return $limited;
		}

		$order  = wc_get_order( absint( $request->get_param( 'id' ) ) );
		$result = YeffoPrint_Order_Reviews::submit(
			$order,
			(int) $request->get_param( 'rating' ),
			(string) $request->get_param( 'text' ),
			(string) $request->get_param( 'name' ),
			self::photo_files( $request->get_file_params() )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( [ 'review' => YeffoPrint_Order_Reviews::format( $result ) ] );
	}

	/**
	 * Flattens PHP's `photos[]` multi-file shape
	 * (name => [..], tmp_name => [..]) into one entry per file.
	 *
	 * @return array<int, array>
	 */
	private static function photo_files( array $files ): array {
		$field = $files['photos'] ?? null;
		if ( ! is_array( $field ) || ! isset( $field['name'] ) ) {
			return [];
		}
		if ( ! is_array( $field['name'] ) ) {
			return UPLOAD_ERR_NO_FILE === (int) ( $field['error'] ?? 0 ) ? [] : [ $field ];
		}

		$out = [];
		foreach ( array_keys( $field['name'] ) as $i ) {
			if ( UPLOAD_ERR_NO_FILE === (int) ( $field['error'][ $i ] ?? 0 ) ) {
				continue;
			}
			$out[] = [
				'name'     => $field['name'][ $i ],
				'type'     => $field['type'][ $i ] ?? '',
				'tmp_name' => $field['tmp_name'][ $i ] ?? '',
				'error'    => $field['error'][ $i ] ?? 0,
				'size'     => $field['size'][ $i ] ?? 0,
			];
		}
		return $out;
	}

	private function check_rate_limit(): ?\WP_Error {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' === $ip ) {
			return null;
		}

		$key   = 'yp_review_rl_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT_MAX ) {
			return new \WP_Error( 'yeffoprint_rate_limited', __( 'Too many tries. Please wait a few minutes and try again.', 'yeffoprint-core' ), [ 'status' => 429 ] );
		}
		set_transient( $key, $count + 1, self::RATE_LIMIT_SECS );
		return null;
	}
}
