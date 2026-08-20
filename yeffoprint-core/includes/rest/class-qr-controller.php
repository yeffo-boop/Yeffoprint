<?php
/**
 * Public REST endpoint for rendering a QR code from arbitrary text.
 *
 * Backs two different call sites with the same one code path: the
 * configurator's live preview for a `qr_code` field (an <img> pointed
 * at this endpoint, re-fetched as the customer types — see
 * assets/js/configurator.js), and staff downloading a print-ready
 * PNG/PDF for a specific order's QR field value from the admin order
 * screen (direct request: "so I don't need to use a 3rd party site").
 * No auth/ownership check makes sense here — the endpoint is a pure
 * function of whatever text is passed to it, nothing it returns is
 * tied to a specific customer or order, same reasoning as the existing
 * public /pricing/calculate endpoint (class-pricing-controller.php).
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Qr_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	// A generated QR image is real (if modest) CPU work per request,
	// same abuse shape as the unauthenticated custom-order upload
	// endpoint that got a rate limit in the Phase 10 security audit —
	// same window/cap pattern, reused independently rather than shared
	// since the two endpoints have nothing else in common.
	private const RATE_LIMIT_WINDOW = 600; // 10 minutes
	private const RATE_LIMIT_MAX    = 60;

	// Well beyond any real printed URL's length, but far short of a QR
	// code's own ~2953-byte byte-mode ceiling — caps worst-case encode
	// cost (higher QR versions are markedly more expensive to mask-
	// score) before YeffoPrint_QrCodeGen_QrCode ever sees the input.
	private const MAX_TEXT_LENGTH = 500;

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/qr', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'render' ],
			'permission_callback' => '__return_true',
		] );
	}

	public function render( \WP_REST_Request $request ) {
		$rate_limited = $this->check_rate_limit();
		if ( is_wp_error( $rate_limited ) ) {
			return $rate_limited;
		}

		$text = (string) $request->get_param( 'text' );
		if ( '' === trim( $text ) ) {
			return new \WP_Error( 'yeffoprint_qr_empty', __( 'No text to encode.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}
		if ( strlen( $text ) > self::MAX_TEXT_LENGTH ) {
			return new \WP_Error( 'yeffoprint_qr_too_long', __( 'That text is too long to encode.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$format = (string) $request->get_param( 'format' );
		$format = in_array( $format, [ 'png', 'pdf' ], true ) ? $format : 'png';

		if ( 'pdf' === $format ) {
			$size_in = (float) $request->get_param( 'size_in' );
			$size_in = $size_in > 0 ? min( 12, max( 0.5, $size_in ) ) : 2.0;
			$data    = YeffoPrint_Qr_Renderer::render_pdf( $text, $size_in );
			$mime    = 'application/pdf';
			$ext     = 'pdf';
		} else {
			$module_px = absint( $request->get_param( 'module_px' ) );
			$module_px = $module_px > 0 ? min( 40, max( 2, $module_px ) ) : 10;
			$data      = YeffoPrint_Qr_Renderer::render_png( $text, $module_px );
			$mime      = 'image/png';
			$ext       = 'png';
		}

		if ( is_wp_error( $data ) ) {
			$data->add_data( [ 'status' => 500 ] );
			return $data;
		}

		if ( headers_sent() ) {
			return new \WP_Error( 'yeffoprint_qr_headers_sent', __( 'Could not send the QR code.', 'yeffoprint-core' ), [ 'status' => 500 ] );
		}

		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . strlen( $data ) );
		// Re-fetching the exact same text+format+size is a pure function
		// of its inputs — a short public cache window cuts repeat
		// requests (e.g. a customer pausing mid-typing) without this
		// endpoint needing its own generated-image cache.
		header( 'Cache-Control: public, max-age=300' );
		if ( $request->get_param( 'download' ) ) {
			header( 'Content-Disposition: attachment; filename="qr-code.' . $ext . '"' );
		}

		echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw binary image/PDF data, not HTML.
		exit;
	}

	/** @return \WP_Error|null Error if this IP has hit the window's cap; null (and the attempt is now counted) otherwise. */
	private function check_rate_limit(): ?\WP_Error {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' === $ip ) {
			return null; // Can't key a limit without an IP — fail open rather than block legitimate requests.
		}

		$key   = 'yp_qr_rl_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT_MAX ) {
			return new \WP_Error(
				'yeffoprint_rate_limited',
				__( 'Too many QR code requests. Please wait a few minutes and try again.', 'yeffoprint-core' ),
				[ 'status' => 429 ]
			);
		}

		set_transient( $key, $count + 1, self::RATE_LIMIT_WINDOW );
		return null;
	}
}
