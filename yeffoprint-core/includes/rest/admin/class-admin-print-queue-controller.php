<?php
/**
 * Print station for shipping labels (direct request: "is there a way to
 * somehow connect my label printer to the site so I can print shipping
 * labels from my phone?" — the printer is USB only, and Jeff asked for a
 * free option).
 *
 * A phone can't reach a USB printer, but the computer it's plugged into
 * can. The new admin's Print station page (`#/print-station`, next.js)
 * stays open on that computer and checks this queue every few seconds;
 * "Send to label printer" on a phone adds a job here, the station loads
 * the label and prints it. With Chrome started with --kiosk-printing it
 * prints straight to the default printer with no dialog.
 *
 * - `GET  /admin/next/print-queue` — recent jobs and whether a station
 *   has checked in lately. `?station=1` is the station itself checking
 *   in: it records the time and gets only jobs still waiting.
 * - `POST /admin/next/print-queue` — `{ order_id, tracking_number, kind }`
 *   queues one of that order's Shippo labels ('label') or its customs
 *   invoice ('invoice').
 * - `POST /admin/next/print-queue/{id}` — `{ status }` printed / failed /
 *   cancelled.
 * - `GET  /admin/next/print-queue/{id}/file` — the label PDF itself,
 *   served from this site so the station's page can print it (a PDF
 *   from Shippo's own domain can't be printed from script). The URL is
 *   looked up from the order's own saved labels, never taken from the
 *   request.
 *
 * Jobs live in one small option (the last MAX_JOBS); there is one shop
 * and one printer, so nothing heavier is needed.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_Print_Queue_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public const QUEUE_OPTION = 'yeffoprint_print_queue';

	public const STATION_SEEN_OPTION = 'yeffoprint_print_station_seen';

	private const MAX_JOBS = 30;

	/** A station that checked in within this many seconds counts as online. */
	private const ONLINE_SECONDS = 45;

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/admin/next/print-queue', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'list_jobs' ],
				'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'add_job' ],
				'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/admin/next/print-queue/(?P<id>[a-z0-9]+)', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'update_job' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/admin/next/print-queue/(?P<id>[a-z0-9]+)/file', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'job_file' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );
	}

	private function jobs(): array {
		$jobs = get_option( self::QUEUE_OPTION, [] );
		return is_array( $jobs ) ? $jobs : [];
	}

	private function save_jobs( array $jobs ): void {
		update_option( self::QUEUE_OPTION, array_slice( array_values( $jobs ), -self::MAX_JOBS ), false );
	}

	private function station_payload(): array {
		$seen = (int) get_option( self::STATION_SEEN_OPTION, 0 );
		return [
			'station_online' => $seen && ( time() - $seen ) <= self::ONLINE_SECONDS,
			'station_seen'   => $seen ? gmdate( 'c', $seen ) : null,
		];
	}

	public function list_jobs( \WP_REST_Request $request ): \WP_REST_Response {
		$jobs = $this->jobs();

		if ( $request->get_param( 'station' ) ) {
			update_option( self::STATION_SEEN_OPTION, time(), false );
			$jobs = array_values( array_filter( $jobs, static function ( array $job ): bool {
				return 'pending' === $job['status'];
			} ) );
		}

		return rest_ensure_response( array_merge( [ 'jobs' => array_reverse( $jobs ) ], $this->station_payload() ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function add_job( \WP_REST_Request $request ) {
		$params   = $request->get_json_params() ?: [];
		$order    = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $params['order_id'] ?? 0 ) ) : null;
		$tracking = sanitize_text_field( (string) ( $params['tracking_number'] ?? '' ) );
		$kind     = 'invoice' === ( $params['kind'] ?? '' ) ? 'invoice' : 'label';

		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error( 'yeffoprint_order_not_found', __( 'That order could not be found.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}

		if ( '' === $this->file_url( $order, $tracking, $kind ) ) {
			return new \WP_Error( 'yeffoprint_label_not_found', __( 'That label isn’t on this order anymore.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}

		$jobs   = $this->jobs();
		$jobs[] = [
			'id'              => strtolower( wp_generate_password( 12, false, false ) ),
			'order_id'        => $order->get_id(),
			'number'          => (string) $order->get_order_number(),
			'customer'        => trim( $order->get_formatted_shipping_full_name() ) ?: trim( $order->get_formatted_billing_full_name() ),
			'tracking_number' => $tracking,
			'kind'            => $kind,
			'status'          => 'pending',
			'created'         => gmdate( 'c' ),
			'updated'         => gmdate( 'c' ),
		];
		$this->save_jobs( $jobs );

		return rest_ensure_response( array_merge( [ 'job' => end( $jobs ) ], $this->station_payload() ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function update_job( \WP_REST_Request $request ) {
		$params = $request->get_json_params() ?: [];
		$status = sanitize_key( (string) ( $params['status'] ?? '' ) );
		if ( ! in_array( $status, [ 'printed', 'failed', 'cancelled', 'pending' ], true ) ) {
			return new \WP_Error( 'yeffoprint_invalid_status', __( 'That is not a valid status.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$jobs = $this->jobs();
		foreach ( $jobs as &$job ) {
			if ( $job['id'] === $request['id'] ) {
				$job['status']  = $status;
				$job['updated'] = gmdate( 'c' );
				$this->save_jobs( $jobs );
				return rest_ensure_response( [ 'job' => $job ] );
			}
		}
		unset( $job );

		return new \WP_Error( 'yeffoprint_job_not_found', __( 'That print job could not be found.', 'yeffoprint-core' ), [ 'status' => 404 ] );
	}

	/** Streams the job's PDF and ends the request; returns a WP_Error only when there's nothing to send. */
	public function job_file( \WP_REST_Request $request ) {
		$job = null;
		foreach ( $this->jobs() as $candidate ) {
			if ( $candidate['id'] === $request['id'] ) {
				$job = $candidate;
			}
		}

		$order = $job && function_exists( 'wc_get_order' ) ? wc_get_order( (int) $job['order_id'] ) : null;
		$url   = $order instanceof \WC_Order ? $this->file_url( $order, $job['tracking_number'], $job['kind'] ) : '';
		if ( '' === $url ) {
			return new \WP_Error( 'yeffoprint_label_not_found', __( 'That label isn’t on this order anymore.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}

		$response = wp_safe_remote_get( $url, [ 'timeout' => 20 ] );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_Error( 'yeffoprint_label_download_failed', __( 'The label couldn’t be downloaded from Shippo.', 'yeffoprint-core' ), [ 'status' => 502 ] );
		}

		$type = wp_remote_retrieve_header( $response, 'content-type' ) ?: 'application/pdf';
		nocache_headers();
		header( 'Content-Type: ' . ( false !== strpos( $type, 'pdf' ) ? 'application/pdf' : sanitize_mime_type( $type ) ) );
		header( 'Content-Disposition: inline; filename="label-' . sanitize_file_name( $job['number'] ) . '.pdf"' );
		echo wp_remote_retrieve_body( $response ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a PDF from the order's own Shippo label.
		exit;
	}

	/** The saved label (or its customs invoice) URL for this tracking number, '' if it isn't there or was voided. */
	private function file_url( \WC_Order $order, string $tracking, string $kind ): string {
		foreach ( YeffoPrint_Order_Tracking::get_shippo_labels( $order ) as $label ) {
			if ( $label['tracking_number'] !== $tracking || $label['voided'] ) {
				continue;
			}
			if ( 'invoice' === $kind ) {
				return (string) ( $label['customs']['commercial_invoice_url'] ?? '' );
			}
			return (string) $label['label_url'];
		}
		return '';
	}
}
