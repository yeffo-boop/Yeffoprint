<?php
/**
 * REST Controller for Promotions
 *
 * @package Automattic\WCShipping
 */

namespace Automattic\WCShipping\Promo;

use Automattic\WCShipping\Exceptions\RESTRequestException;
use Automattic\WCShipping\WCShippingRESTController;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST controller for handling promotion-related actions.
 */
class PromoRESTController extends WCShippingRESTController {

	/**
	 * Route
	 *
	 * @var string
	 */
	protected $rest_base = 'promo';

	/**
	 * The promo service instance.
	 *
	 * @var PromoService
	 */
	protected $promo_service;

	/**
	 * Constructor.
	 *
	 * @param PromoService $promo_service The promo service instance.
	 */
	public function __construct( PromoService $promo_service ) {
		$this->promo_service = $promo_service;
	}

	/**
	 * Register API routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			// Accepts alphanumeric, underscores, hyphens, and digits.
			'/' . $this->rest_base . '/(?P<type>notice|banner)/(?P<id>[\w-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'dismiss_promotion' ),
					'permission_callback' => array( $this, 'ensure_rest_permission' ),
					'args'                => array(
						'type' => array(
							'required'          => true,
							'type'              => 'string',
							'enum'              => array( 'notice', 'banner' ),
							'validate_callback' => function ( $param ) {
								return in_array( $param, array( 'notice', 'banner' ), true );
							},
							'sanitize_callback' => 'sanitize_text_field',
						),
						'id'   => array(
							'required'          => true,
							'type'              => 'string',
							'validate_callback' => function ( $param ) {
								return (bool) preg_match( '/^[\w-]+$/', $param );
							},
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	/**
	 * Dismiss a promotion.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response
	 */
	public function dismiss_promotion( WP_REST_Request $request ) {
		try {
			list( $type, $id ) = $this->get_and_check_request_params( $request, array( 'type', 'id' ) );

			$promo = $this->promo_service->get_promotion();

			if ( ! $promo || $promo->id !== $id ) {
				throw new RESTRequestException( 'Promotion does not exist.' );
			}
		} catch ( RESTRequestException $error ) {
			return rest_ensure_response( $error->get_error_response() );
		}

		$this->promo_service->dismiss_promotion( $type, $id );

		return rest_ensure_response(
			array(
				'success' => true,
			)
		);
	}
}
