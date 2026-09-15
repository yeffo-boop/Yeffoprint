<?php
/**
 * Admin REST endpoints for WooCommerce coupons — direct request:
 * "coupon management would be great." `shop_coupon` is a WooCommerce
 * core post type registered with no `show_in_rest` support at all (a
 * deliberate WooCommerce choice — coupons never got the same REST
 * treatment as products/orders), so there's no `/wp/v2/shop_coupon`
 * route the way Materials/Sizes ride on their own REST-enabled CPTs.
 * This wraps `WC_Coupon` directly instead, the same object every other
 * coupon UI (classic wp-admin, WooCommerce's own `/wc/v3/coupons` REST
 * API) already builds on.
 *
 * Deliberate scope limit: only the fields staff actually asked for —
 * code, discount type/amount, expiry, usage limits, min/max spend,
 * free shipping, individual-use-only, and an email allowlist. Product/
 * category-restricted coupons (an entirely separate picker UI) aren't
 * supported here; staff can still build one of those in the classic
 * wp-admin Coupons screen (Marketing → Coupons), unaffected by this
 * screen existing alongside it.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_Coupon_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/admin/coupons', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'list_coupons' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/admin/coupon', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'create_coupon' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/admin/coupon/(?P<id>\d+)', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_coupon' ],
				'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
			],
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'update_coupon' ],
				'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
			],
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_coupon' ],
				'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
			],
		] );
	}

	public function list_coupons( \WP_REST_Request $request ): \WP_REST_Response {
		$search   = trim( (string) $request->get_param( 'search' ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) );

		$query = new \WP_Query( [
			'post_type'      => 'shop_coupon',
			'post_status'    => [ 'publish', 'draft' ],
			'posts_per_page' => $per_page,
			'paged'          => $page,
			's'              => $search,
			'orderby'        => 'date',
			'order'          => 'DESC',
		] );

		$coupons = array_map( function ( \WP_Post $post ): array {
			return $this->summary_payload( new \WC_Coupon( $post->ID ) );
		}, $query->posts );

		return rest_ensure_response( [
			'coupons'       => $coupons,
			'total'         => (int) $query->found_posts,
			'max_num_pages' => (int) $query->max_num_pages,
			'page'          => $page,
		] );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function get_coupon( \WP_REST_Request $request ) {
		$coupon = $this->validate_coupon( (int) $request['id'] );
		if ( is_wp_error( $coupon ) ) {
			return $coupon;
		}
		return rest_ensure_response( $this->detail_payload( $coupon ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function create_coupon( \WP_REST_Request $request ) {
		return $this->save_coupon( new \WC_Coupon(), $request );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function update_coupon( \WP_REST_Request $request ) {
		$coupon = $this->validate_coupon( (int) $request['id'] );
		if ( is_wp_error( $coupon ) ) {
			return $coupon;
		}
		return $this->save_coupon( $coupon, $request );
	}

	/** @return \WP_REST_Response|\WP_Error */
	private function save_coupon( \WC_Coupon $coupon, \WP_REST_Request $request ) {
		$params = $request->get_json_params() ?: [];
		$code   = sanitize_text_field( (string) ( $params['code'] ?? '' ) );

		if ( '' === trim( $code ) ) {
			return new \WP_Error( 'yeffoprint_coupon_missing_code', __( 'Enter a coupon code.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$existing_id = wc_get_coupon_id_by_code( $code, $coupon->get_id() );
		if ( $existing_id ) {
			return new \WP_Error( 'yeffoprint_coupon_duplicate_code', __( 'A coupon with that code already exists.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$emails = array_values( array_filter( array_map(
			'trim',
			explode( ',', (string) ( $params['email_restrictions'] ?? '' ) )
		) ) );

		try {
			$coupon->set_code( $code );
			$coupon->set_discount_type( sanitize_key( (string) ( $params['discount_type'] ?? 'fixed_cart' ) ) );
			$coupon->set_amount( (float) ( $params['amount'] ?? 0 ) );
			$coupon->set_description( sanitize_textarea_field( (string) ( $params['description'] ?? '' ) ) );
			$coupon->set_status( ! empty( $params['active'] ) ? 'publish' : 'draft' );
			$coupon->set_date_expires( ! empty( $params['expiry_date'] ) ? sanitize_text_field( (string) $params['expiry_date'] ) : null );
			$coupon->set_usage_limit( ! empty( $params['usage_limit'] ) ? (int) $params['usage_limit'] : null );
			$coupon->set_usage_limit_per_user( ! empty( $params['usage_limit_per_user'] ) ? (int) $params['usage_limit_per_user'] : null );
			$coupon->set_minimum_amount( '' !== ( $params['minimum_amount'] ?? '' ) ? (float) $params['minimum_amount'] : '' );
			$coupon->set_maximum_amount( '' !== ( $params['maximum_amount'] ?? '' ) ? (float) $params['maximum_amount'] : '' );
			$coupon->set_individual_use( ! empty( $params['individual_use'] ) );
			$coupon->set_free_shipping( ! empty( $params['free_shipping'] ) );
			$coupon->set_email_restrictions( $emails );
			$coupon->save();
		} catch ( \WC_Data_Exception $exception ) {
			return new \WP_Error( 'yeffoprint_coupon_invalid', $exception->getMessage(), [ 'status' => 400 ] );
		}

		return rest_ensure_response( $this->detail_payload( $coupon ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function delete_coupon( \WP_REST_Request $request ) {
		$coupon = $this->validate_coupon( (int) $request['id'] );
		if ( is_wp_error( $coupon ) ) {
			return $coupon;
		}

		// Trashed, not force-deleted — reversible from the classic
		// wp-admin Coupons screen's own Trash tab if voided by mistake,
		// same "prefer reversible" default this app's own Void-label
		// action already established for Shippo labels.
		wp_trash_post( $coupon->get_id() );

		return rest_ensure_response( [ 'deleted' => true ] );
	}

	private function summary_payload( \WC_Coupon $coupon ): array {
		return [
			'id'            => $coupon->get_id(),
			'code'          => $coupon->get_code(),
			'discount_type' => $coupon->get_discount_type(),
			'amount'        => (float) $coupon->get_amount(),
			'usage_count'   => $coupon->get_usage_count(),
			'usage_limit'   => $coupon->get_usage_limit() ?: null,
			'expiry_date'   => $coupon->get_date_expires() ? $coupon->get_date_expires()->date( 'Y-m-d' ) : null,
			'active'        => 'publish' === $coupon->get_status(),
		];
	}

	private function detail_payload( \WC_Coupon $coupon ): array {
		return array_merge( $this->summary_payload( $coupon ), [
			'description'           => $coupon->get_description(),
			'usage_limit_per_user'  => $coupon->get_usage_limit_per_user() ?: null,
			'minimum_amount'        => '' !== $coupon->get_minimum_amount() ? (float) $coupon->get_minimum_amount() : null,
			'maximum_amount'        => '' !== $coupon->get_maximum_amount() ? (float) $coupon->get_maximum_amount() : null,
			'individual_use'        => $coupon->get_individual_use(),
			'free_shipping'         => $coupon->get_free_shipping(),
			'email_restrictions'    => implode( ', ', $coupon->get_email_restrictions() ),
		] );
	}

	/** @return \WC_Coupon|\WP_Error */
	private function validate_coupon( int $id ) {
		if ( ! $id || 'shop_coupon' !== get_post_type( $id ) ) {
			return new \WP_Error( 'yeffoprint_coupon_not_found', __( 'That coupon could not be found.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}
		return new \WC_Coupon( $id );
	}
}
