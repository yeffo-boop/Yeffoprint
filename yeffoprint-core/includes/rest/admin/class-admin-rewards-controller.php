<?php
/**
 * Admin REST endpoint for the Rewards screen (docs/ARCHITECTURE.md,
 * Phase 7) — four routes covering everything `class-rewards-admin.php`'s
 * classic page does: the points/referral rate settings, the customer
 * lookup (balance + full history), the manual adjust form, and the
 * global recent-adjustments log. `adjust()` is a thin wrapper, not a
 * reimplementation — it calls the exact same `YeffoPrint_Rewards::adjust_balance()`
 * the classic page's `admin_post_yeffoprint_rewards_adjust` handler
 * already calls, same user-resolution rule (email or username) too.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_Rewards_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/admin/rewards-settings', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_settings' ],
				'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
			],
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'save_settings' ],
				'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/admin/rewards-history', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_history' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/admin/rewards-lookup', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'lookup' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/admin/rewards-adjust', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'adjust' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );
	}

	public function get_settings(): \WP_REST_Response {
		return rest_ensure_response( $this->settings_payload() );
	}

	public function save_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_json_params() ?: [];
		$M      = 'YeffoPrint_Admin_Menu';

		update_option( $M::REWARDS_POINTS_PER_DOLLAR_OPTION, max( 0, (float) ( $params['points_per_dollar'] ?? $M::REWARDS_POINTS_PER_DOLLAR_DEFAULT ) ) );
		update_option( $M::REWARDS_DOLLARS_PER_POINT_OPTION, max( 0, (float) ( $params['dollars_per_point'] ?? $M::REWARDS_DOLLARS_PER_POINT_DEFAULT ) ) );
		update_option( $M::REFERRAL_POINTS_OPTION, max( 0, (float) ( $params['referral_points'] ?? $M::REFERRAL_POINTS_DEFAULT ) ) );

		return rest_ensure_response( $this->settings_payload() );
	}

	private function settings_payload(): array {
		$M = 'YeffoPrint_Admin_Menu';

		return [
			'points_per_dollar' => (float) get_option( $M::REWARDS_POINTS_PER_DOLLAR_OPTION, $M::REWARDS_POINTS_PER_DOLLAR_DEFAULT ),
			'dollars_per_point' => (float) get_option( $M::REWARDS_DOLLARS_PER_POINT_OPTION, $M::REWARDS_DOLLARS_PER_POINT_DEFAULT ),
			'referral_points'   => (float) get_option( $M::REFERRAL_POINTS_OPTION, $M::REFERRAL_POINTS_DEFAULT ),
		];
	}

	public function get_history(): \WP_REST_Response {
		$entries = YeffoPrint_Rewards::get_recent_adjustments( 50 );

		$rows = array_map( function ( array $entry ) {
			$user     = get_userdata( (int) ( $entry['user_id'] ?? 0 ) );
			$admin_id = (int) ( $entry['admin_id'] ?? 0 );
			$admin    = $admin_id ? get_userdata( $admin_id ) : null;

			return [
				'date'          => (string) ( $entry['date'] ?? '' ),
				'customer_email' => $user ? $user->user_email : '',
				'delta'         => (int) ( $entry['delta'] ?? 0 ),
				'reason'        => (string) ( $entry['reason'] ?? '' ),
				'by'            => $admin ? $admin->display_name : ( 0 === $admin_id ? __( 'System', 'yeffoprint-core' ) : '' ),
			];
		}, $entries );

		return rest_ensure_response( $rows );
	}

	public function lookup( \WP_REST_Request $request ): \WP_REST_Response {
		$identifier = sanitize_text_field( (string) $request->get_param( 'user' ) );
		$user       = self::resolve_user( $identifier );

		if ( ! $user ) {
			return rest_ensure_response( [ 'found' => false ] );
		}

		$history = array_map( function ( array $entry ) {
			$order = $entry['order_id'] ? wc_get_order( $entry['order_id'] ) : null;
			return [
				'timestamp'     => (int) $entry['timestamp'],
				'label'         => (string) $entry['label'],
				'earned'        => (int) $entry['earned'],
				'redeemed'      => (int) $entry['redeemed'],
				'order_edit_url' => $order ? $order->get_edit_order_url() : '',
				'by'            => (string) ( $entry['by'] ?? '' ),
			];
		}, YeffoPrint_Rewards::get_history_for_user( $user->ID, 50 ) );

		return rest_ensure_response( [
			'found'        => true,
			'user_id'      => $user->ID,
			'display_name' => $user->display_name,
			'email'        => $user->user_email,
			'balance'      => YeffoPrint_Rewards::get_balance( $user->ID ),
			'history'      => $history,
		] );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function adjust( \WP_REST_Request $request ) {
		$params     = $request->get_json_params() ?: [];
		$identifier = sanitize_text_field( (string) ( $params['user'] ?? '' ) );
		$points     = isset( $params['points'] ) ? (int) $params['points'] : 0;
		$reason     = sanitize_text_field( (string) ( $params['reason'] ?? '' ) );

		$user = self::resolve_user( $identifier );
		if ( ! $user ) {
			return new \WP_Error( 'yeffoprint_customer_not_found', __( 'No customer found with that email or username.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}

		if ( ! $points || '' === $reason ) {
			return new \WP_Error( 'yeffoprint_invalid_adjustment', __( 'Please enter a non-zero point amount and a reason.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$balance = YeffoPrint_Rewards::adjust_balance( $user->ID, $points, $reason, get_current_user_id() );

		return rest_ensure_response( [
			'user_email' => $user->user_email,
			'balance'    => $balance,
		] );
	}

	/** Same rule as class-rewards-admin.php's own resolve_user() — an email looks up by email, anything else is tried as a login/username. */
	private static function resolve_user( string $identifier ): ?\WP_User {
		if ( '' === $identifier ) {
			return null;
		}

		$user = is_email( $identifier ) ? get_user_by( 'email', $identifier ) : get_user_by( 'login', $identifier );

		return $user ?: null;
	}
}
