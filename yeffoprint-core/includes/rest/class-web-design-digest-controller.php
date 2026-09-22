<?php
/**
 * Receives a Web Design project's nightly "Daily Site Update" digest —
 * direct request: "I want to... let them access all of the changes
 * that have been made on the site... a script to read an email that's
 * automatically sent to myself every night." Rather than a script
 * reading that email back out of an inbox, the project's own nightly
 * digest job POSTs the same content here directly; append_site_update()
 * (class-web-design-project-meta.php) stores it against the order, and
 * the customer portal's Updates tab (class-web-design-portal-
 * controller.php) reads it back out — no mailbox access needed
 * anywhere in this plugin.
 *
 * Auth is a per-order bearer token (SITE_UPDATE_TOKEN), deliberately
 * separate from ACCESS_TOKEN — the guest link already shared with the
 * customer on every other Web Design page. This one is a write
 * credential, staff-issued from the order drawer and never emailed to
 * the customer, so a leaked customer link can never be used to inject
 * fake site-activity entries.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Web_Design_Digest_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/web-design/(?P<id>\d+)/digest', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'ingest' ],
			'permission_callback' => [ $this, 'check_digest_access' ],
		] );
	}

	/** @return true|\WP_Error */
	public function check_digest_access( \WP_REST_Request $request ) {
		$order = $this->order( $request );
		if ( ! $order instanceof \WC_Order || ! YeffoPrint_Web_Design_Project_Meta::is_web_design_order( $order ) ) {
			return new \WP_Error( 'yeffoprint_order_not_found', __( 'That project was not found.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}

		$stored_token = (string) $order->get_meta( YeffoPrint_Web_Design_Project_Meta::SITE_UPDATE_TOKEN );
		$supplied     = $this->bearer_token( $request );

		if ( '' === $stored_token || '' === $supplied || ! hash_equals( $stored_token, $supplied ) ) {
			return new \WP_Error( 'yeffoprint_forbidden', __( 'Invalid or missing digest token.', 'yeffoprint-core' ), [ 'status' => 403 ] );
		}

		return true;
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 *
	 * Expected body: { "date": "YYYY-MM-DD", "site_url": "https://…",
	 *   "items": [ { "headline": "…", "description": "…",
	 *     "prs": [ { "number": 24, "title": "…" } ] } ] }
	 */
	public function ingest( \WP_REST_Request $request ) {
		$order  = $this->order( $request );
		$params = $request->get_json_params() ?: [];

		$date = sanitize_text_field( (string) ( $params['date'] ?? '' ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new \WP_Error( 'yeffoprint_invalid_date', __( '"date" must be in YYYY-MM-DD format.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$items = is_array( $params['items'] ?? null ) ? $params['items'] : [];
		if ( ! $items ) {
			return new \WP_Error( 'yeffoprint_missing_items', __( '"items" must be a non-empty array.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$stored = YeffoPrint_Web_Design_Project_Meta::append_site_update( $order, [
			'date'     => $date,
			'site_url' => (string) ( $params['site_url'] ?? '' ),
			'items'    => $items,
		] );

		return rest_ensure_response( [ 'success' => true, 'items_stored' => $stored ] );
	}

	private function order( \WP_REST_Request $request ): ?\WC_Order {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $request->get_param( 'id' ) ) ) : false;
		return $order instanceof \WC_Order ? $order : null;
	}

	private function bearer_token( \WP_REST_Request $request ): string {
		$header = (string) $request->get_header( 'authorization' );
		if ( 0 === stripos( $header, 'Bearer ' ) ) {
			return trim( substr( $header, 7 ) );
		}
		// Fallback for automation that can't easily set a custom header.
		return (string) $request->get_param( 'token' );
	}
}
