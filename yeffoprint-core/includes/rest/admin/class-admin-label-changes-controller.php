<?php
/**
 * Admin app endpoints for label changes on an order that hasn't shipped
 * (class-order-label-changes.php): the Labels card's Edit form, and
 * Approve / Decline on a customer's change request.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_Label_Changes_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/admin/order/(?P<id>\d+)/label-details', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'save_label_details' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/admin/order/(?P<id>\d+)/change-request', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'answer_request' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function save_label_details( \WP_REST_Request $request ) {
		$order = $this->validate_order( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		if ( ! YeffoPrint_Order_Label_Changes::admin_can_edit( $order ) ) {
			return new \WP_Error( 'yeffoprint_order_locked', __( 'This order has shipped, so its label details can no longer be changed.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$user    = wp_get_current_user();
		$applied = YeffoPrint_Order_Label_Changes::apply( $order, $request->get_param( 'changes' ), $user->display_name ?: __( 'staff', 'yeffoprint-core' ) );
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		return rest_ensure_response( [ 'changed' => $applied ] );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function answer_request( \WP_REST_Request $request ) {
		$order = $this->validate_order( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$action = (string) $request->get_param( 'action' );
		if ( 'approve' === $action ) {
			$result = YeffoPrint_Order_Label_Changes::approve( $order );
		} elseif ( 'decline' === $action ) {
			$result = YeffoPrint_Order_Label_Changes::decline( $order, sanitize_textarea_field( (string) $request->get_param( 'message' ) ) );
		} else {
			return new \WP_Error( 'yeffoprint_bad_action', __( 'Choose approve or decline.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( [ 'ok' => true ] );
	}

	/** @return \WC_Order|\WP_Error */
	private function validate_order( int $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error( 'yeffoprint_order_not_found', __( 'That order could not be found.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}
		return $order;
	}
}
