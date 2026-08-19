<?php
/**
 * The public proof-approval flow (V2): a CustomOrder's own customer —
 * logged in or not — views their latest proof and approves it or
 * requests changes. Guest access (PROJECT_SPEC: "not everyone has an
 * account that orders") is via a long random token generated at
 * submission (class-custom-order-controller.php) and never rotated;
 * anyone holding the exact link can act on that one request, the same
 * trust model as any unguessable share link. A logged-in customer who
 * owns the request, or staff, can use it too — that path is nonce-
 * protected the same as every other authenticated write endpoint here,
 * since unlike a guest they *do* have a session worth protecting.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Proof_Approval_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/custom-orders/(?P<id>\d+)/proof', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_proof' ],
			'permission_callback' => [ $this, 'check_access' ],
		] );

		register_rest_route( self::NAMESPACE, '/custom-orders/(?P<id>\d+)/proof/approve', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'approve' ],
			'permission_callback' => [ $this, 'check_access' ],
		] );

		register_rest_route( self::NAMESPACE, '/custom-orders/(?P<id>\d+)/proof/request-changes', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'request_changes' ],
			'permission_callback' => [ $this, 'check_access' ],
		] );
	}

	/**
	 * @return true|\WP_Error
	 */
	public function check_access( \WP_REST_Request $request ) {
		$custom_order_id = absint( $request->get_param( 'id' ) );
		$post            = get_post( $custom_order_id );

		if ( ! $post || 'yp_custom_order' !== $post->post_type ) {
			return new \WP_Error( 'yeffoprint_custom_order_not_found', __( 'That request was not found.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}

		$token        = (string) $request->get_param( 'token' );
		$stored_token = (string) get_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::ACCESS_TOKEN, true );

		if ( '' !== $token && '' !== $stored_token && hash_equals( $stored_token, $token ) ) {
			return true;
		}

		if ( is_user_logged_in() ) {
			$customer_id = (int) get_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::CUSTOMER_ID, true );
			$owns_it     = $customer_id && $customer_id === get_current_user_id();

			if ( $owns_it || current_user_can( 'manage_woocommerce' ) ) {
				$nonce = $request->get_header( 'X-WP-Nonce' );

				return ( $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) )
					? true
					: new \WP_Error( 'yeffoprint_invalid_nonce', __( 'Your session has expired. Please refresh the page and try again.', 'yeffoprint-core' ), [ 'status' => 403 ] );
			}
		}

		return new \WP_Error( 'yeffoprint_forbidden', __( "You don't have access to this proof.", 'yeffoprint-core' ), [ 'status' => 403 ] );
	}

	public function get_proof( \WP_REST_Request $request ) {
		$custom_order_id = absint( $request->get_param( 'id' ) );
		$status          = (string) get_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::STATUS, true );

		$proofs = [];
		foreach ( YeffoPrint_Proof_Meta::get_for_custom_order( $custom_order_id ) as $proof_id ) {
			$file_id = (int) get_post_meta( $proof_id, YeffoPrint_Proof_Meta::FILE_ID, true );
			if ( ! $file_id ) {
				continue;
			}

			$proofs[] = [
				'id'       => $proof_id,
				'url'      => wp_get_attachment_url( $file_id ),
				'is_image' => (bool) wp_attachment_is_image( $file_id ),
				'date'     => get_the_date( '', $proof_id ),
			];
		}

		return rest_ensure_response( [
			'brand_name'   => (string) get_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::BRAND_NAME, true ),
			'status'       => $status,
			'status_label' => YeffoPrint_Custom_Order_Meta::get_status_label( $status ),
			'can_respond'  => 'awaiting_approval' === $status,
			'proofs'       => $proofs,
		] );
	}

	public function approve( \WP_REST_Request $request ) {
		$custom_order_id = absint( $request->get_param( 'id' ) );
		$status          = (string) get_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::STATUS, true );

		if ( 'awaiting_approval' !== $status ) {
			return new \WP_Error( 'yeffoprint_not_awaiting_approval', __( "This proof isn't waiting on your approval — it may have already been responded to. Refresh the page to see its current status.", 'yeffoprint-core' ), [ 'status' => 409 ] );
		}

		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::STATUS, 'approved' );

		return rest_ensure_response( [
			'success'      => true,
			'status'       => 'approved',
			'status_label' => YeffoPrint_Custom_Order_Meta::get_status_label( 'approved' ),
		] );
	}

	public function request_changes( \WP_REST_Request $request ) {
		$custom_order_id = absint( $request->get_param( 'id' ) );
		$status          = (string) get_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::STATUS, true );

		if ( 'awaiting_approval' !== $status ) {
			return new \WP_Error( 'yeffoprint_not_awaiting_approval', __( "This proof isn't waiting on your approval — it may have already been responded to. Refresh the page to see its current status.", 'yeffoprint-core' ), [ 'status' => 409 ] );
		}

		$notes = sanitize_textarea_field( (string) $request->get_param( 'notes' ) );

		// Back to "Design in progress" — a requested change means staff
		// have design work to do again before another proof goes out,
		// same pipeline step a fresh request starts from (PROJECT_SPEC
		// §13's six states, no separate "changes requested" state).
		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::STATUS, 'design_in_progress' );
		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::CHANGE_REQUEST_NOTES, $notes );

		return rest_ensure_response( [
			'success'      => true,
			'status'       => 'design_in_progress',
			'status_label' => YeffoPrint_Custom_Order_Meta::get_status_label( 'design_in_progress' ),
		] );
	}
}
