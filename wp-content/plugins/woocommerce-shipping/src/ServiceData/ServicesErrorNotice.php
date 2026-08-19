<?php
/**
 * Services Error Notice
 *
 * @package Automattic\WCShipping\ServiceData
 */

namespace Automattic\WCShipping\ServiceData;

use Automattic\WCShipping\Connect\WC_Connect_Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Show admin notice when services endpoint fetch fails due to internal plugin errors.
 *
 * This notice is displayed when the plugin cannot connect to the WooCommerce Shipping
 * server due to issues like Jetpack connection problems, token issues, etc.
 */
class ServicesErrorNotice {

	/**
	 * Option key for storing the error notice state.
	 */
	const OPTION_KEY = 'services_fetch_error';

	/**
	 * User meta key for dismissed notice.
	 */
	const DISMISSED_META_KEY = 'wcshipping_services_error_dismissed';

	/**
	 * Error codes that indicate internal plugin errors (should show warning).
	 *
	 * @var array
	 */
	private static $internal_error_codes = array(
		'jetpack_data_class_not_found',
		'jetpack_data_get_access_token_not_found',
		'request_body_should_be_array',
		'unable_to_json_encode_body',
		'missing_token',
		'invalid_token',
		'invalid_signature',
	);

	/**
	 * Initialize the notice hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_notices', array( $this, 'maybe_render_notice' ) );
		add_action( 'wp_ajax_wcshipping_dismiss_services_error', array( $this, 'dismiss_notice' ) );
	}

	/**
	 * Check if an error code is an internal plugin error.
	 *
	 * @param string $error_code The WP_Error code.
	 * @return bool
	 */
	public static function is_internal_error( $error_code ) {
		return in_array( $error_code, self::$internal_error_codes, true );
	}

	/**
	 * Enable the error notice.
	 *
	 * @param string $error_code The error code that occurred.
	 * @param string $error_message The error message.
	 * @return void
	 */
	public function enable_notice( $error_code, $error_message ) {
		WC_Connect_Options::update_option(
			self::OPTION_KEY,
			array(
				'code'      => $error_code,
				'message'   => $error_message,
				'timestamp' => time(),
			)
		);

		// Reset the dismissed state so the notice shows again.
		$this->reset_dismissed_state();
	}

	/**
	 * Disable the error notice.
	 *
	 * @return void
	 */
	public function disable_notice() {
		WC_Connect_Options::delete_option( self::OPTION_KEY );
	}

	/**
	 * Check if the notice is enabled.
	 *
	 * @return array|false Error data array or false if not enabled.
	 */
	public function is_notice_enabled() {
		$error_data = WC_Connect_Options::get_option( self::OPTION_KEY, false );
		if ( ! is_array( $error_data ) || empty( $error_data['code'] ) ) {
			return false;
		}
		return $error_data;
	}

	/**
	 * Check if the notice was dismissed by the current user.
	 *
	 * @return bool
	 */
	private function is_dismissed() {
		$user_id        = get_current_user_id();
		$dismissed_data = get_user_meta( $user_id, self::DISMISSED_META_KEY, true );
		$error_data     = $this->is_notice_enabled();

		if ( ! $dismissed_data || ! is_array( $dismissed_data ) || ! $error_data ) {
			return false;
		}

		// Check if dismissed for the same error timestamp.
		return isset( $dismissed_data['timestamp'] )
			&& $dismissed_data['timestamp'] === $error_data['timestamp'];
	}

	/**
	 * Reset the dismissed state for all users.
	 *
	 * @return void
	 */
	private function reset_dismissed_state() {
		delete_metadata( 'user', 0, self::DISMISSED_META_KEY, '', true );
	}

	/**
	 * AJAX handler for dismissing the notice.
	 *
	 * @return void
	 */
	public function dismiss_notice() {
		check_ajax_referer( 'wcshipping_dismiss_services_error', 'nonce' );

		$user_id    = get_current_user_id();
		$error_data = $this->is_notice_enabled();

		if ( $error_data ) {
			update_user_meta(
				$user_id,
				self::DISMISSED_META_KEY,
				array(
					'timestamp' => $error_data['timestamp'],
				)
			);
		}

		wp_send_json_success();
	}

	/**
	 * Maybe render the notice.
	 *
	 * @return void
	 */
	public function maybe_render_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( ! $this->should_show_on_current_screen() ) {
			return;
		}

		$error_data = $this->is_notice_enabled();
		if ( ! $error_data ) {
			return;
		}

		if ( $this->is_dismissed() ) {
			return;
		}

		$this->render_notice( $error_data );
	}

	/**
	 * Check if the notice should be shown on the current screen.
	 *
	 * @return bool
	 */
	private function should_show_on_current_screen() {
		if ( ! is_admin() ) {
			return false;
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return false;
		}

		// Show on WooCommerce pages.
		$target_screens = array(
			'woocommerce_page_wc-settings',
			'woocommerce_page_wc-orders',
			'edit-shop_order',
			'shop_order',
		);

		return in_array( $screen->id, $target_screens, true )
			|| 'woocommerce_page_wc-orders' === $screen->base;
	}

	/**
	 * Render the warning notice.
	 *
	 * @param array $error_data The error data (kept for future use/debugging).
	 *
	 * @return void
	 */
	private function render_notice( $error_data ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter kept for future use.
		$nonce = wp_create_nonce( 'wcshipping_dismiss_services_error' );
		?>
		<div class="notice notice-warning is-dismissible wcshipping-services-error-notice" data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<p>
				<strong><?php esc_html_e( 'WooCommerce Shipping', 'woocommerce-shipping' ); ?>:</strong>
				<?php
				esc_html_e(
					'Unable to connect to the WooCommerce Shipping server. Some features like promotions and service announcements may not be available.',
					'woocommerce-shipping'
				);
				?>
			</p>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-status&tab=woocommerce-shipping' ) ); ?>">
					<?php esc_html_e( 'Check your connection status', 'woocommerce-shipping' ); ?>
				</a>
			</p>
		</div>
		<script type="text/javascript">
			jQuery( document ).ready( function( $ ) {
				$( '.wcshipping-services-error-notice' ).on( 'click', '.notice-dismiss', function() {
					$.ajax( {
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'wcshipping_dismiss_services_error',
							nonce: $( '.wcshipping-services-error-notice' ).data( 'nonce' )
						}
					} );
				} );
			} );
		</script>
		<?php
	}
}
