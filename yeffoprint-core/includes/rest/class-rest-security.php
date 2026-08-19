<?php
/**
 * Shared REST permission callback for endpoints that must stay
 * reachable by guests (PROJECT_SPEC §20: "no required account to
 * purchase") but still need CSRF protection for a signed-in customer —
 * without this, a third-party page could silently POST to these
 * endpoints riding a logged-in customer's cookies (e.g. force-adding
 * items to their cart, or submitting a Custom Order on their behalf).
 *
 * Guests have no authenticated session to hijack, so they pass through
 * unchecked; a logged-in request must carry a valid `X-WP-Nonce`.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Rest_Security {

	/**
	 * @return true|\WP_Error
	 */
	public static function guest_or_nonced_write( \WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return true;
		}

		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return true;
		}

		return new \WP_Error(
			'yeffoprint_invalid_nonce',
			__( 'Your session has expired. Please refresh the page and try again.', 'yeffoprint-core' ),
			[ 'status' => 403 ]
		);
	}
}
