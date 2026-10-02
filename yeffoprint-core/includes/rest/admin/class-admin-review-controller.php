<?php
/**
 * Admin REST endpoints for the admin app's Reviews screen
 * (views/reviews.js): list reviews with their photos, publish /
 * unpublish / delete one, and the two review settings. The review
 * logic itself lives in class-order-reviews.php.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_Review_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/admin/reviews', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'list_reviews' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/admin/reviews/settings', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'save_settings' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/admin/reviews/(?P<id>\d+)/(?P<action>publish|unpublish|delete)', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'act' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );
	}

	public function list_reviews( \WP_REST_Request $request ): \WP_REST_Response {
		$status = (string) $request->get_param( 'status' );
		$args   = [
			'type'    => 'review',
			'number'  => 200,
			'orderby' => 'comment_date_gmt',
			'order'   => 'DESC',
			'status'  => 'waiting' === $status ? 'hold' : ( 'published' === $status ? 'approve' : 'all' ),
		];

		$rows = [];
		foreach ( get_comments( $args ) as $comment ) {
			$row   = YeffoPrint_Order_Reviews::format( $comment );
			$order = $row['order_id'] ? wc_get_order( $row['order_id'] ) : null;

			$row['email']        = (string) $comment->comment_author_email;
			$row['order_number'] = $order instanceof \WC_Order ? $order->get_order_number() : '';
			$row['items']        = $order instanceof \WC_Order ? array_column( YeffoPrint_Order_Reviews::order_lines( $order ), 'name' ) : [ get_the_title( (int) $comment->comment_post_ID ) ];
			$rows[]              = $row;
		}

		return rest_ensure_response( [
			'reviews'  => $rows,
			'counts'   => [
				'waiting'   => $this->count( 'hold' ),
				'published' => $this->count( 'approve' ),
			],
			'summary'  => YeffoPrint_Order_Reviews::summary(),
			'settings' => YeffoPrint_Order_Reviews::settings(),
		] );
	}

	private function count( string $status ): int {
		return (int) get_comments( [
			'type'   => 'review',
			'status' => $status,
			'count'  => true,
		] );
	}

	public function save_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_json_params() ?: [];
		return rest_ensure_response( [ 'settings' => YeffoPrint_Order_Reviews::save_settings( is_array( $params ) ? $params : [] ) ] );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function act( \WP_REST_Request $request ) {
		$comment = get_comment( (int) $request['id'] );
		if ( ! $comment instanceof \WP_Comment || 'review' !== $comment->comment_type ) {
			return new \WP_Error( 'yeffoprint_review_missing', __( 'That review no longer exists.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}

		$product_id = (int) $comment->comment_post_ID;

		switch ( $request['action'] ) {
			case 'publish':
				wp_set_comment_status( $comment, 'approve' );
				break;
			case 'unpublish':
				wp_set_comment_status( $comment, 'hold' );
				break;
			case 'delete':
				$order_id = (int) get_comment_meta( $comment->comment_ID, YeffoPrint_Order_Reviews::META_ORDER, true );
				wp_delete_comment( $comment, true );
				$order = $order_id ? wc_get_order( $order_id ) : null;
				if ( $order instanceof \WC_Order ) {
					$order->delete_meta_data( YeffoPrint_Order_Reviews::ORDER_META );
					$order->save();
				}
				break;
		}

		if ( class_exists( 'WC_Comments' ) ) {
			\WC_Comments::clear_transients( $product_id );
		}

		return rest_ensure_response( [ 'ok' => true ] );
	}
}
