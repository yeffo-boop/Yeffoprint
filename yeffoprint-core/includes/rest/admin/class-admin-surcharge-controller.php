<?php
/**
 * Admin REST endpoint for the Card Surcharge screen (docs/ARCHITECTURE.md,
 * Phase 7) — `YeffoPrint_Admin_Menu::SURCHARGE_GATEWAY_RATES_OPTION` is
 * an array-of-arrays option deliberately kept `show_in_rest => false`
 * (`class-surcharge-admin.php`'s own docblock: "needs an explicit
 * schema to be REST-visible; nothing here reads it over REST" — true
 * until now), so this is the first thing that actually exposes it. The
 * sanitize rule below is the same one `YeffoPrint_Surcharge_Admin::sanitize_gateway_rates()`
 * already enforces for the classic Settings-API page: only currently-
 * registered gateway ids, and only a strictly-positive rate — dropping
 * a stale or zeroed-out row rather than saving it as a no-op.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_Surcharge_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/admin/surcharge', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_surcharge' ],
				'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
			],
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'save_surcharge' ],
				'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
			],
		] );
	}

	public function get_surcharge(): \WP_REST_Response {
		return rest_ensure_response( $this->payload() );
	}

	public function save_surcharge( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_json_params() ?: [];
		update_option( YeffoPrint_Admin_Menu::SURCHARGE_GATEWAY_RATES_OPTION, $this->sanitize_rates( is_array( $params['rates'] ?? null ) ? $params['rates'] : [] ) );

		return rest_ensure_response( $this->payload() );
	}

	/**
	 * @param array $raw
	 * @return array<string, array{rate:float, label:string}>
	 */
	private function sanitize_rates( array $raw ): array {
		$known_ids = function_exists( 'WC' ) && WC()->payment_gateways()
			? array_keys( WC()->payment_gateways()->payment_gateways() )
			: [];

		$result = [];
		foreach ( $raw as $gateway_id => $row ) {
			$gateway_id = sanitize_key( (string) $gateway_id );
			$rate       = max( 0, (float) ( $row['rate'] ?? 0 ) );

			if ( ! in_array( $gateway_id, $known_ids, true ) || $rate <= 0 ) {
				continue;
			}

			$result[ $gateway_id ] = [
				'rate'  => $rate,
				'label' => sanitize_text_field( (string) ( $row['label'] ?? '' ) ),
			];
		}

		return $result;
	}

	private function payload(): array {
		$saved    = YeffoPrint_Card_Surcharge::get_gateway_rates();
		$gateways = function_exists( 'WC' ) && WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];

		return [
			'gateways' => array_values( array_map( function ( $gateway ) use ( $saved ) {
				$row = $saved[ $gateway->id ] ?? [];
				return [
					'id'      => $gateway->id,
					'title'   => $gateway->get_title(),
					'enabled' => 'yes' === $gateway->enabled,
					'rate'    => (float) ( $row['rate'] ?? 0 ),
					'label'   => (string) ( $row['label'] ?? '' ),
				];
			}, $gateways ) ),
			'label_default' => YeffoPrint_Admin_Menu::SURCHARGE_LABEL_DEFAULT,
		];
	}
}
