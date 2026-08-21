/**
 * A WP_Error-turned-REST response.
 *
 * @see rest_convert_error_to_response in wp-includes/rest-api.php
 */
export interface WPErrorRESTResponse {
	code: string;
	message: string;
	data: {
		status: number;
		params: Record< string, string >;
	};
}
