<?php
/**
 * Class LabelEligibilityRESTController
 *
 * @package Automattic\WCShipping\LabelPurchase
 */

namespace Automattic\WCShipping\LabelPurchase;

use Automattic\WCShipping\WCShippingRESTController;
use Automattic\WCShipping\Connect\WC_Connect_Service_Settings_Store;
use Automattic\WCShipping\Connect\WC_Connect_Payment_Methods_Store;
use Automattic\WCShipping\Validators;

use WP_Error;
use WP_REST_Request;
use WP_REST_Server;
use WC_Order;
use Throwable;

/**
 * REST controller for checking shipping label eligibility.
 */
class EligibilityRESTController extends WCShippingRESTController {

	/**
	 * API endpoint path.
	 *
	 * @var string
	 */
	protected $rest_base = 'eligibility';

	/**
	 * Label purchase service.
	 *
	 * @var LabelPurchaseService
	 */
	private $label_purchase_service;

	/**
	 * Settings store.
	 *
	 * @var SettingsStore
	 */
	private $settings_store;

	/**
	 * Constructor.
	 *
	 * @param ViewService $view_service Service to manage label purchases.
	 */

	/**
	 * @var WC_Connect_Payment_Methods_Store
	 */
	private $payment_methods_store;

	public function __construct( ViewService $view_service, WC_Connect_Service_Settings_Store $settings_store, WC_Connect_Payment_Methods_Store $payment_methods_store ) {
		$this->view_service          = $view_service;
		$this->settings_store        = $settings_store;
		$this->payment_methods_store = $payment_methods_store;
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<order_id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_eligibility' ),
				'permission_callback' => array( $this, 'ensure_rest_permission' ),
				'args'                => array(
					'order_id'                  => array(
						'description' => __( 'The order ID.', 'woocommerce-shipping' ),
						'type'        => 'integer',
						'required'    => true,
					),
					'can_create_customs_form'   => array(
						'description'       => __( 'Whether the client can create a customs form.', 'woocommerce-shipping' ),
						'required'          => false,
						'validate_callback' => array( Validators::class, 'validate_boolean_like' ),
					),
					'can_create_package'        => array(
						'description'       => __( 'Whether the client can create a package.', 'woocommerce-shipping' ),
						'required'          => false,
						'validate_callback' => array( Validators::class, 'validate_boolean_like' ),
					),
					'can_create_payment_method' => array(
						'description'       => __( 'Whether the client can create a payment method.', 'woocommerce-shipping' ),
						'required'          => false,
						'validate_callback' => array( Validators::class, 'validate_boolean_like' ),
					),
				),
			),
		);
	}

	/**
	 * Check if an order is eligible for shipping label creation.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or WP_Error.
	 */
	public function get_eligibility( $request ) {
		list( $order_id ) = $this->get_and_check_request_params( $request, array( 'order_id' ) );

		// It may throw for none-valid order IDs.
		try {
			$order = new WC_Order( $order_id );
		} catch ( Throwable $e ) {
			return rest_ensure_response(
				array(
					'is_eligible' => false,
					'reason'      => 'order_id_is_not_valid',
				),
			);
		}

		if ( ! $order ) {
			return rest_ensure_response(
				array(
					'is_eligible' => false,
					'reason'      => 'order_not_found',
				),
			);
		}

		// Check if the store is eligible for shipping label creation.
		if ( ! $this->view_service->is_store_eligible_for_shipping_label_creation( $order ) ) {
			return rest_ensure_response(
				array(
					'is_eligible' => false,
					'reason'      => 'store_not_eligible',
				),
			);
		}

		// If the client cannot create a customs form:
		// - The store address has to be in the US.
		// - The origin and destination addresses have to be in the US.
		$client_can_create_customs_form = isset( $request['can_create_customs_form'] ) ? filter_var( $request['can_create_customs_form'], FILTER_VALIDATE_BOOLEAN ) : true;
		$store_country                  = wc_get_base_location()['country'];
		if ( ! $client_can_create_customs_form ) {
			// The store address has to be in the US.
			if ( 'US' !== $store_country ) {
				return rest_ensure_response(
					array(
						'is_eligible' => false,
						'reason'      => 'store_country_not_supported_when_customs_form_is_not_supported_by_client',
					),
				);
			}

			// The origin and destination addresses have to be in the US.
			$origin_address      = $this->settings_store->get_origin_address();
			$destination_address = $order->get_address( 'shipping' );
			if ( 'US' !== $origin_address['country'] || 'US' !== $destination_address['country'] ) {
				return rest_ensure_response(
					array(
						'is_eligible' => false,
						'reason'      => 'origin_or_destination_country_not_supported_when_customs_form_is_not_supported_by_client',
					),
				);
			}
		}

		// If the client cannot create a package (`can_create_package` param is set to `false`), a pre-existing package
		// is required.
		$client_can_create_package = isset( $request['can_create_package'] ) ? filter_var( $request['can_create_package'], FILTER_VALIDATE_BOOLEAN ) : true;
		if ( ! $client_can_create_package ) {
			if ( empty( $this->settings_store->get_packages() ) && empty( $this->settings_store->get_predefined_packages() ) ) {
				return rest_ensure_response(
					array(
						'is_eligible' => false,
						'reason'      => 'no_packages_when_client_cannot_create_package',
					),
				);
			}
		}

		// There is at least one non-refunded and shippable product.
		if ( ! $this->view_service->is_order_eligible_for_shipping_label_creation( $order ) ) {
			return rest_ensure_response(
				array(
					'is_eligible' => false,
					'reason'      => 'order_not_eligible',
				),
			);
		}

		// If the client cannot create a payment method (`can_create_payment_method` param is set to `false`), an existing payment method is required.
		$client_can_create_payment_method = isset( $request['can_create_payment_method'] ) ? filter_var( $request['can_create_payment_method'], FILTER_VALIDATE_BOOLEAN ) : true;
		if ( ! $client_can_create_payment_method && empty( $this->payment_methods_store->get_payment_methods() ) ) {
			return rest_ensure_response(
				array(
					'is_eligible' => false,
					'reason'      => 'no_payment_methods_and_client_cannot_create_one',
				),
			);
		}

		// There is a pre-selected payment method or the user can manage payment methods.
		if ( ! ( $this->settings_store->get_selected_payment_method_id() || $this->settings_store->can_user_manage_payment_methods() ) ) {
			return rest_ensure_response(
				array(
					'is_eligible' => false,
					'reason'      => 'no_selected_payment_method_and_user_cannot_manage_payment_methods',
				),
			);
		}

		return rest_ensure_response(
			array(
				'is_eligible' => true,
			),
		);
	}
}
