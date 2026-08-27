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

		if ( ! self::approve_custom_order( $custom_order_id ) ) {
			return new \WP_Error( 'yeffoprint_not_awaiting_approval', __( "This proof isn't waiting on your approval — it may have already been responded to. Refresh the page to see its current status.", 'yeffoprint-core' ), [ 'status' => 409 ] );
		}

		return rest_ensure_response( [
			'success'      => true,
			'status'       => 'approved',
			'status_label' => YeffoPrint_Custom_Order_Meta::get_status_label( 'approved' ),
		] );
	}

	public function request_changes( \WP_REST_Request $request ) {
		$custom_order_id = absint( $request->get_param( 'id' ) );
		$notes           = sanitize_textarea_field( (string) $request->get_param( 'notes' ) );

		if ( ! self::reject_custom_order( $custom_order_id, $notes ) ) {
			return new \WP_Error( 'yeffoprint_not_awaiting_approval', __( "This proof isn't waiting on your approval — it may have already been responded to. Refresh the page to see its current status.", 'yeffoprint-core' ), [ 'status' => 409 ] );
		}

		return rest_ensure_response( [
			'success'      => true,
			'status'       => 'design_in_progress',
			'status_label' => YeffoPrint_Custom_Order_Meta::get_status_label( 'design_in_progress' ),
		] );
	}

	/**
	 * The actual approve/reject domain actions, extracted from approve()/
	 * request_changes() above (which now just parse the REST request and
	 * call these) so class-telegram-callback-handler.php (direct
	 * request: approve or reject a proof right from the bot) can trigger
	 * the identical status transition + admin notification without going
	 * through a WP_REST_Request at all — there was no such indirection
	 * layer before this; the guard/meta-writes/notify were previously
	 * inline in the REST methods themselves. Both `static` and public
	 * for exactly that cross-class reuse. @return bool False if the
	 * order wasn't actually awaiting_approval — the caller's cue to
	 * report "already responded to" rather than treat it as a hard
	 * error.
	 */
	public static function approve_custom_order( int $custom_order_id ): bool {
		if ( 'awaiting_approval' !== (string) get_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::STATUS, true ) ) {
			return false;
		}

		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::STATUS, 'approved' );
		self::notify_admin_of_approval( $custom_order_id );

		return true;
	}

	public static function reject_custom_order( int $custom_order_id, string $notes ): bool {
		if ( 'awaiting_approval' !== (string) get_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::STATUS, true ) ) {
			return false;
		}

		// Back to "Design in progress" — a requested change means staff
		// have design work to do again before another proof goes out,
		// same pipeline step a fresh request starts from (PROJECT_SPEC
		// §13's six states, no separate "changes requested" state).
		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::STATUS, 'design_in_progress' );
		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::CHANGE_REQUEST_NOTES, $notes );
		self::notify_admin_of_change_request( $custom_order_id, $notes );

		return true;
	}

	/**
	 * Both admin notifications below are the one thing this controller
	 * was missing — approve()/request_changes() previously only ever
	 * updated post meta, so staff had no way to learn a customer had
	 * responded short of noticing the "Design in progress"/"Custom
	 * Orders" admin-menu bubble count (class-admin-menu.php) on their
	 * own next visit to wp-admin. `get_option( 'admin_email' )`, not a
	 * dedicated recipient setting like the contact form's — same
	 * reasoning as class-payment-webhook-controller.php's own
	 * `notify_admin()`: this is an internal "something needs your
	 * attention" alert, not customer-facing correspondence with its own
	 * configurable inbox.
	 */
	private static function notify_admin_of_approval( int $custom_order_id ): void {
		self::notify_admin(
			sprintf(
				/* translators: %s: the custom order's title (brand name + submission date, or "Custom Stickers — date") */
				__( 'Proof approved — %s', 'yeffoprint-core' ),
				get_the_title( $custom_order_id )
			),
			sprintf(
				/* translators: %s: link to the custom order's admin edit screen */
				__( "The customer approved their proof — ready to move to printing.\n\n%s", 'yeffoprint-core' ),
				admin_url( 'post.php?post=' . $custom_order_id . '&action=edit' )
			)
		);
	}

	private static function notify_admin_of_change_request( int $custom_order_id, string $notes ): void {
		self::notify_admin(
			sprintf(
				/* translators: %s: the custom order's title */
				__( 'Changes requested on proof — %s', 'yeffoprint-core' ),
				get_the_title( $custom_order_id )
			),
			sprintf(
				/* translators: 1: the customer's change-request notes, 2: link to the custom order's admin edit screen */
				__( "The customer requested changes to their proof:\n\n%1\$s\n\n%2\$s", 'yeffoprint-core' ),
				'' !== $notes ? $notes : __( '(No additional notes provided.)', 'yeffoprint-core' ),
				admin_url( 'post.php?post=' . $custom_order_id . '&action=edit' )
			)
		);
	}

	private static function notify_admin( string $subject, string $body ): void {
		wp_mail( get_option( 'admin_email' ), $subject, $body );
	}
}
