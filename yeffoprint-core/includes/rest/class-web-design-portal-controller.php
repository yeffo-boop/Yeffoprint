<?php
/**
 * Public-facing Web Design workflow endpoints — the customer's own side
 * of class-web-design-project-meta.php's lifecycle: reviewing & signing
 * the agreement, approving or requesting changes on the staged site,
 * and submitting go-live server access. Same trust model as
 * class-proof-approval-controller.php: a long, never-rotated token
 * (YeffoPrint_Web_Design_Project_Meta::ACCESS_TOKEN) is the guest
 * credential — anyone holding the exact emailed link can act on that
 * one order, same as any unguessable share link — with a logged-in
 * owner or staff member able to use the same routes nonce-protected
 * instead, no token required.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Web_Design_Portal_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/web-design/(?P<id>\d+)/agreement', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_agreement' ],
			'permission_callback' => [ $this, 'check_access' ],
		] );

		register_rest_route( self::NAMESPACE, '/web-design/(?P<id>\d+)/agreement/sign', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'sign_agreement' ],
			'permission_callback' => [ $this, 'check_access' ],
		] );

		register_rest_route( self::NAMESPACE, '/web-design/(?P<id>\d+)/staging', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_staging' ],
			'permission_callback' => [ $this, 'check_access' ],
		] );

		register_rest_route( self::NAMESPACE, '/web-design/(?P<id>\d+)/staging/approve', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'approve_staging' ],
			'permission_callback' => [ $this, 'check_access' ],
		] );

		register_rest_route( self::NAMESPACE, '/web-design/(?P<id>\d+)/staging/request-changes', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'request_staging_changes' ],
			'permission_callback' => [ $this, 'check_access' ],
		] );

		register_rest_route( self::NAMESPACE, '/web-design/(?P<id>\d+)/golive', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_golive_status' ],
			'permission_callback' => [ $this, 'check_access' ],
		] );

		register_rest_route( self::NAMESPACE, '/web-design/(?P<id>\d+)/golive/submit', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'submit_golive' ],
			'permission_callback' => [ $this, 'check_access' ],
		] );

		register_rest_route( self::NAMESPACE, '/web-design/(?P<id>\d+)/updates', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_updates' ],
			'permission_callback' => [ $this, 'check_access' ],
		] );
	}

	/** @return true|\WP_Error */
	public function check_access( \WP_REST_Request $request ) {
		$order_id = absint( $request->get_param( 'id' ) );
		$order    = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;

		if ( ! $order instanceof \WC_Order || ! YeffoPrint_Web_Design_Project_Meta::is_web_design_order( $order ) ) {
			return new \WP_Error( 'yeffoprint_order_not_found', __( 'That project was not found.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}

		$token        = (string) $request->get_param( 'token' );
		$stored_token = (string) $order->get_meta( YeffoPrint_Web_Design_Project_Meta::ACCESS_TOKEN );

		if ( '' !== $token && '' !== $stored_token && hash_equals( $stored_token, $token ) ) {
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

		return new \WP_Error( 'yeffoprint_forbidden', __( "You don't have access to this project.", 'yeffoprint-core' ), [ 'status' => 403 ] );
	}

	public function get_agreement( \WP_REST_Request $request ) {
		$order = $this->order( $request );
		$agreement = YeffoPrint_Web_Design_Project_Meta::get_agreement( $order );

		return rest_ensure_response( [
			'package_name' => $this->package_name( $order ),
			'total_paid'   => (float) $order->get_total(),
			'kickoff_date' => $agreement['kickoff_date'],
			'staging_due'  => $agreement['staging_due'],
			'golive_due'   => $agreement['golive_due'],
			'milestones'   => $agreement['milestones'],
			'addons'       => $agreement['addons'],
			'scope_text'   => $agreement['scope_text'],
			'is_signed'    => YeffoPrint_Web_Design_Project_Meta::is_agreement_signed( $order ),
			'signed_name'  => $agreement['signed_name'],
			'signed_at'    => $agreement['signed_at'],
		] );
	}

	public function sign_agreement( \WP_REST_Request $request ) {
		$order = $this->order( $request );

		if ( YeffoPrint_Web_Design_Project_Meta::is_agreement_signed( $order ) ) {
			return new \WP_Error( 'yeffoprint_already_signed', __( 'This agreement has already been signed.', 'yeffoprint-core' ), [ 'status' => 409 ] );
		}

		$params = $request->get_json_params() ?: [];
		$name   = sanitize_text_field( (string) ( $params['name'] ?? '' ) );

		if ( '' === $name ) {
			return new \WP_Error( 'yeffoprint_missing_name', __( 'Please type your full name to sign.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		YeffoPrint_Web_Design_Project_Meta::sign_agreement( $order, $name, $this->client_ip() );
		$this->notify_admin(
			sprintf( /* translators: %s: order number */ __( 'Agreement signed — Order #%s', 'yeffoprint-core' ), $order->get_order_number() ),
			sprintf(
				/* translators: 1: signer's name, 2: link to the order */
				__( "%1\$s signed their web design agreement.\n\n%2\$s", 'yeffoprint-core' ),
				$name,
				$order->get_edit_order_url()
			)
		);

		return rest_ensure_response( [ 'success' => true, 'signed_at' => (string) $order->get_meta( YeffoPrint_Web_Design_Project_Meta::AGREEMENT_SIGNED_AT ) ] );
	}

	public function get_staging( \WP_REST_Request $request ) {
		$order   = $this->order( $request );
		$staging = YeffoPrint_Web_Design_Project_Meta::get_staging( $order );
		$response = (string) $order->get_meta( YeffoPrint_Web_Design_Project_Meta::CLIENT_RESPONSE );

		return rest_ensure_response( [
			'package_name' => $this->package_name( $order ),
			'staging_url'  => $staging['staging_url'],
			'can_respond'  => '' !== $staging['sent_at'] && '' === $response,
			'response'     => $response,
		] );
	}

	public function approve_staging( \WP_REST_Request $request ) {
		$order = $this->order( $request );

		if ( ! $this->staging_awaiting_response( $order ) ) {
			return new \WP_Error( 'yeffoprint_not_awaiting_response', __( 'This project already has a response on file — refresh the page to see its current status.', 'yeffoprint-core' ), [ 'status' => 409 ] );
		}

		YeffoPrint_Web_Design_Project_Meta::record_client_response( $order, 'approved', '' );
		$this->notify_admin(
			sprintf( /* translators: %s: order number */ __( 'Staging approved — Order #%s', 'yeffoprint-core' ), $order->get_order_number() ),
			sprintf( __( "The customer approved their staging site — ready for go-live access.\n\n%s", 'yeffoprint-core' ), $order->get_edit_order_url() )
		);

		return rest_ensure_response( [
			'success'    => true,
			'golive_url' => YeffoPrint_Web_Design_Project_Meta::get_golive_url( $order ),
		] );
	}

	public function request_staging_changes( \WP_REST_Request $request ) {
		$order = $this->order( $request );

		if ( ! $this->staging_awaiting_response( $order ) ) {
			return new \WP_Error( 'yeffoprint_not_awaiting_response', __( 'This project already has a response on file — refresh the page to see its current status.', 'yeffoprint-core' ), [ 'status' => 409 ] );
		}

		$params = $request->get_json_params() ?: [];
		$notes  = sanitize_textarea_field( (string) ( $params['notes'] ?? '' ) );

		if ( '' === $notes ) {
			return new \WP_Error( 'yeffoprint_missing_notes', __( "Please describe what you'd like changed.", 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		YeffoPrint_Web_Design_Project_Meta::record_client_response( $order, 'changes_requested', $notes );
		$this->notify_admin(
			sprintf( /* translators: %s: order number */ __( 'Changes requested — Order #%s', 'yeffoprint-core' ), $order->get_order_number() ),
			sprintf(
				/* translators: 1: the customer's notes, 2: link to the order */
				__( "The customer requested changes on their staging site:\n\n%1\$s\n\n%2\$s", 'yeffoprint-core' ),
				$notes,
				$order->get_edit_order_url()
			)
		);

		return rest_ensure_response( [ 'success' => true ] );
	}

	public function get_golive_status( \WP_REST_Request $request ) {
		$order  = $this->order( $request );
		$golive = YeffoPrint_Web_Design_Project_Meta::get_golive( $order );

		return rest_ensure_response( [
			'package_name' => $this->package_name( $order ),
			'approved'     => 'approved' === (string) $order->get_meta( YeffoPrint_Web_Design_Project_Meta::CLIENT_RESPONSE ),
			'is_live'      => YeffoPrint_Web_Design_Project_Meta::is_live( $order ),
			'submitted_at' => $golive['submitted_at'],
		] );
	}

	public function submit_golive( \WP_REST_Request $request ) {
		$order = $this->order( $request );

		if ( 'approved' !== (string) $order->get_meta( YeffoPrint_Web_Design_Project_Meta::CLIENT_RESPONSE ) ) {
			return new \WP_Error( 'yeffoprint_not_approved', __( 'Approve your staging site before submitting go-live access.', 'yeffoprint-core' ), [ 'status' => 409 ] );
		}

		$params   = $request->get_json_params() ?: [];
		$method   = sanitize_key( (string) ( $params['method'] ?? 'ftp' ) );
		$username = sanitize_text_field( (string) ( $params['username'] ?? '' ) );
		$password = (string) ( $params['password'] ?? '' );

		if ( '' === $username || '' === $password ) {
			return new \WP_Error( 'yeffoprint_missing_credentials', __( 'Please enter a username and password.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		if ( 'wp_admin' === $method && '' === trim( (string) ( $params['wp_url'] ?? '' ) ) ) {
			return new \WP_Error( 'yeffoprint_missing_url', __( 'Please enter your WordPress admin URL.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		if ( 'ftp' === $method && '' === trim( (string) ( $params['host'] ?? '' ) ) ) {
			return new \WP_Error( 'yeffoprint_missing_host', __( 'Please enter your server host.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		YeffoPrint_Web_Design_Project_Meta::submit_golive( $order, $params, $this->client_ip() );
		$this->notify_admin(
			sprintf( /* translators: %s: order number */ __( 'Go-live access received — Order #%s', 'yeffoprint-core' ), $order->get_order_number() ),
			sprintf( __( "The customer submitted their go-live server access.\n\n%s", 'yeffoprint-core' ), $order->get_edit_order_url() )
		);

		return rest_ensure_response( [ 'success' => true ] );
	}

	/**
	 * Progress Reports & Site Activity tab — direct request: "let them
	 * access all of the changes that have been made on the site."
	 * Read-only, same guest-token access as every other page here.
	 */
	public function get_updates( \WP_REST_Request $request ) {
		$order = $this->order( $request );

		return rest_ensure_response( [
			'package_name'     => $this->package_name( $order ),
			'progress_reports' => YeffoPrint_Web_Design_Project_Meta::get_progress_reports( $order ),
			'site_updates'     => YeffoPrint_Web_Design_Project_Meta::get_site_updates( $order ),
		] );
	}

	private function staging_awaiting_response( \WC_Order $order ): bool {
		$staging  = YeffoPrint_Web_Design_Project_Meta::get_staging( $order );
		$response = (string) $order->get_meta( YeffoPrint_Web_Design_Project_Meta::CLIENT_RESPONSE );
		return '' !== $staging['sent_at'] && '' === $response;
	}

	private function order( \WP_REST_Request $request ): \WC_Order {
		return wc_get_order( absint( $request->get_param( 'id' ) ) );
	}

	private function package_name( \WC_Order $order ): string {
		$package_id = YeffoPrint_Web_Design_Project_Meta::get_package_id( $order );
		$package    = $package_id ? get_post( $package_id ) : null;
		return $package ? $package->post_title : __( 'your web design project', 'yeffoprint-core' );
	}

	private function client_ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
	}

	/** Same internal "something needs your attention" alert as class-proof-approval-controller.php's own notify_admin(). */
	private function notify_admin( string $subject, string $body ): void {
		wp_mail( get_option( 'admin_email' ), $subject, $body );
	}
}
