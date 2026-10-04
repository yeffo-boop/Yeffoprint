<?php
/**
 * Settings › Payments in the admin app (direct request: run the business
 * from the dashboard without wp-admin). Lists every WooCommerce payment
 * method in checkout order, with an on/off switch for each. The store's
 * own methods (Venmo, Zelle, NOWPayments crypto) also show their text
 * settings (checkout title, description, Venmo/Zelle handle, NOWPayments
 * keys) — the same fields as their WooCommerce > Payments screens.
 * WooPayments and other plugins' methods only get the switch; their
 * account setup stays in WooCommerce.
 *
 * Saves through WC_Settings_API::update_option(), the same storage the
 * WooCommerce screens write. Password fields are never sent back, only
 * whether one is set; leaving one blank keeps it.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_Payments_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	/** Field types the dashboard edits. */
	private const EDITABLE_TYPES = [ 'text', 'textarea', 'password' ];

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/admin/payments', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'list_gateways' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/admin/payments/(?P<id>[a-z0-9_\-]+)', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'save_gateway' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function list_gateways() {
		if ( ! function_exists( 'WC' ) ) {
			return new \WP_Error( 'yeffoprint_woocommerce_inactive', __( 'WooCommerce is not active.', 'yeffoprint-core' ), [ 'status' => 500 ] );
		}

		return rest_ensure_response( [
			'gateways' => array_values( array_map( [ $this, 'gateway_payload' ], WC()->payment_gateways()->payment_gateways() ) ),
		] );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function save_gateway( \WP_REST_Request $request ) {
		if ( ! function_exists( 'WC' ) ) {
			return new \WP_Error( 'yeffoprint_woocommerce_inactive', __( 'WooCommerce is not active.', 'yeffoprint-core' ), [ 'status' => 500 ] );
		}

		$gateways = WC()->payment_gateways()->payment_gateways();
		$gateway  = $gateways[ (string) $request['id'] ] ?? null;
		if ( ! $gateway instanceof \WC_Payment_Gateway ) {
			return new \WP_Error( 'yeffoprint_gateway_not_found', __( 'That payment method could not be found.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}

		$params = $request->get_json_params() ?: [];

		if ( array_key_exists( 'enabled', $params ) ) {
			$on = ! empty( $params['enabled'] );
			// WooPayments has its own enable()/disable(), which also sync its account settings.
			if ( method_exists( $gateway, $on ? 'enable' : 'disable' ) ) {
				$gateway->{$on ? 'enable' : 'disable'}();
			} else {
				$gateway->update_option( 'enabled', $on ? 'yes' : 'no' );
			}
			$gateway->enabled = $on ? 'yes' : 'no';
		}

		$fields = is_array( $params['fields'] ?? null ) ? $params['fields'] : [];
		foreach ( $this->editable_fields( $gateway ) as $key => $field ) {
			if ( ! array_key_exists( $key, $fields ) ) {
				continue;
			}
			$value = 'textarea' === $field['type']
				? sanitize_textarea_field( (string) $fields[ $key ] )
				: sanitize_text_field( (string) $fields[ $key ] );
			if ( 'password' === $field['type'] && '' === trim( $value ) ) {
				continue; // Blank keeps the saved key.
			}
			$gateway->update_option( $key, trim( $value ) );
			if ( in_array( $key, [ 'title', 'description' ], true ) ) {
				$gateway->{$key} = trim( $value ); // Loaded once in the constructor.
			}
		}

		return rest_ensure_response( $this->gateway_payload( $gateway ) );
	}

	private function gateway_payload( \WC_Payment_Gateway $gateway ): array {
		$fields = [];
		foreach ( $this->editable_fields( $gateway ) as $key => $field ) {
			$value    = (string) $gateway->get_option( $key );
			$fields[] = [
				'key'   => $key,
				'label' => wp_strip_all_tags( (string) ( $field['title'] ?? $key ) ),
				'type'  => $field['type'],
				'hint'  => wp_strip_all_tags( (string) ( $field['description'] ?? '' ) ),
				'value' => 'password' === $field['type'] ? '' : $value,
				'set'   => '' !== trim( $value ),
			];
		}

		return [
			'id'           => $gateway->id,
			'name'         => wp_strip_all_tags( $gateway->get_method_title() ?: $gateway->get_title() ),
			'title'        => wp_strip_all_tags( $gateway->get_title() ),
			'enabled'      => 'yes' === $gateway->enabled,
			'own'          => $this->is_own( $gateway ),
			'fields'       => $fields,
			'refunds'      => $gateway->supports( 'refunds' ),
		];
	}

	private function is_own( \WC_Payment_Gateway $gateway ): bool {
		return 0 === strpos( $gateway->id, 'yeffoprint_' );
	}

	/** @return array<string,array> Text settings of the store's own methods, keyed by option name. */
	private function editable_fields( \WC_Payment_Gateway $gateway ): array {
		if ( ! $this->is_own( $gateway ) ) {
			return [];
		}

		$out = [];
		foreach ( $gateway->get_form_fields() as $key => $field ) {
			$type = (string) ( $field['type'] ?? 'text' );
			if ( in_array( $type, self::EDITABLE_TYPES, true ) ) {
				$out[ $key ] = array_merge( $field, [ 'type' => $type ] );
			}
		}
		return $out;
	}
}
