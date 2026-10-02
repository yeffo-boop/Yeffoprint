<?php
/**
 * Admin REST endpoints for the admin app's Tracker Feedback screen
 * (views/tracker-feedback.js): list notes sent from the Dose Tracker's
 * Me > Help & feedback, mark one done / reopen / delete it, and show its
 * screenshots. The feedback logic itself lives in
 * includes/tracker/class-tracker-feedback.php.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_Tracker_Feedback_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/admin/tracker-feedback', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'list_notes' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/admin/tracker-feedback/(?P<id>\d+)/(?P<action>done|reopen|delete)', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'act' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		// An <img> can't send the X-WP-Nonce header, so the admin app puts
		// the nonce in the query string (_wpnonce), which WordPress's own
		// cookie auth checks the same way (no valid nonce: no user, so the
		// capability check below fails).
		register_rest_route( self::NAMESPACE, '/admin/tracker-feedback/(?P<id>\d+)/screenshot/(?P<n>\d)', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'screenshot' ],
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
		] );
	}

	public function list_notes( \WP_REST_Request $request ): \WP_REST_Response {
		$filter = (string) $request->get_param( 'filter' );
		$response = rest_ensure_response( [
			'notes' => YeffoPrint_Tracker_Feedback::list( '' !== $filter ? $filter : 'new' ),
			'stats' => YeffoPrint_Tracker_Feedback::stats(),
		] );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function act( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! YeffoPrint_Tracker_Feedback::get_row( $id ) ) {
			return new \WP_Error( 'yeffoprint_feedback_missing', __( 'That note no longer exists.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}

		switch ( $request['action'] ) {
			case 'done':
				YeffoPrint_Tracker_Feedback::mark_done( $id );
				break;
			case 'reopen':
				YeffoPrint_Tracker_Feedback::reopen( $id );
				break;
			case 'delete':
				YeffoPrint_Tracker_Feedback::delete( $id );
				break;
		}

		return rest_ensure_response( [ 'ok' => true ] );
	}

	/** Streams one screenshot to a signed-in admin. @return \WP_Error|void */
	public function screenshot( \WP_REST_Request $request ) {
		$row   = YeffoPrint_Tracker_Feedback::get_row( (int) $request['id'] );
		$shots = $row ? array_values( array_filter( explode( ',', (string) $row['screenshots'] ) ) ) : [];
		$name  = $shots[ (int) $request['n'] ] ?? '';
		$path  = '' !== $name ? YeffoPrint_Tracker_Feedback::screenshot_path( $name ) : '';

		if ( '' === $path ) {
			return new \WP_Error( 'yeffoprint_feedback_missing', __( 'That screenshot no longer exists.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}

		nocache_headers();
		header( 'Content-Type: ' . ( wp_get_image_mime( $path ) ?: 'image/jpeg' ) );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}
}
