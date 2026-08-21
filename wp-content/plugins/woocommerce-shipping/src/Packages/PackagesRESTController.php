<?php

namespace Automattic\WCShipping\Packages;

use Automattic\WCShipping\Connect\WC_Connect_Service_Settings_Store;
use Automattic\WCShipping\Connect\WC_Connect_Package_Settings;
use Automattic\WCShipping\Exceptions\RESTRequestException;
use Automattic\WCShipping\WCShippingRESTController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class PackagesRESTController extends WCShippingRESTController {
	protected $rest_base = 'packages';

	private $settings_store;

	/**
	 * @var WC_Connect_Package_Settings
	 */
	protected $package_settings;

	public function __construct( WC_Connect_Service_Settings_Store $settings_store, WC_Connect_Package_Settings $package_settings ) {
		$this->settings_store   = $settings_store;
		$this->package_settings = $package_settings;
	}

	public function register_routes() {

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get' ),
					'permission_callback' => array( $this, 'ensure_rest_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'post' ),
					'permission_callback' => array( $this, 'ensure_rest_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'put' ),
					'permission_callback' => array( $this, 'ensure_rest_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			// Accepts alphanumeric, underscores, hyphens, and digits.
			'/' . $this->rest_base . '/(?P<type>custom|predefined)/(?P<id>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => array( $this, 'ensure_rest_permission' ),
					'args'                => array(
						'type' => array(
							'required'          => true,
							'type'              => 'string',
							'enum'              => array( 'custom', 'predefined' ),
							'validate_callback' => function ( $param ) {
								return in_array( $param, array( 'custom', 'predefined' ), true );
							},
							'sanitize_callback' => 'sanitize_text_field',
						),
						'id'   => array(
							'required'          => true,
							'type'              => 'string',
							'validate_callback' => function ( $param ) {
								return (bool) preg_match( '/^[a-zA-Z0-9_-]+$/', $param );
							},
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	/**
	 * Get the package settings.
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public function get( WP_REST_Request $request ) {
		$package_settings   = $this->package_settings->get( $request->get_param( 'features_supported_by_client' ) );
		$packages_as_arrays = ( new PackagesAsArraysSanitizer( $package_settings['formData']['custom'], false ) )->to_packages_as_api_arrays();

		return rest_ensure_response(
			array(
				'success'      => true,
				'storeOptions' => $package_settings['storeOptions'],
				'packages'     => array(
					'saved'      => array(
						'custom'     => $packages_as_arrays,
						'predefined' => $package_settings['formData']['predefined'],
					),
					'predefined' => $package_settings['formSchema']['predefined'],
				),
			)
		);
	}

	/**
	 * Update the existing custom and predefined packages.
	 *
	 * @param  WP_REST_Request $request The request body contains the custom/predefined packages to replace.
	 * @return WP_REST_Response
	 */
	public function put( $request ) {
		$packages = $request->get_json_params();

		if ( isset( $packages['custom'] ) ) {
			try {
				$this->settings_store->update_packages( $packages['custom'] );
			} catch ( PackageValidationException $e ) {
				return $e->get_error_response();
			}
		}

		if ( isset( $packages['predefined'] ) ) {
			$this->settings_store->update_predefined_packages( $packages['predefined'] );
		}

		return rest_ensure_response(
			array(
				'predefined' => $this->settings_store->get_predefined_packages(),
				'custom'     => $this->settings_store->get_packages(),
			)
		);
	}

	/**
	 * Create custom and/or predefined packages.
	 *
	 * @param  WP_REST_Request $request The request body contains the custom/predefined packages to create.
	 * @return WP_Error|WP_REST_Response
	 */
	public function post( $request ) {
		$packages = $request->get_json_params();

		$custom_packages     = isset( $packages['custom'] ) ? $packages['custom'] : array();
		$predefined_packages = isset( $packages['predefined'] ) ? $packages['predefined'] : array();

		// Handle new custom packages. The custom packages are structured as an array of packages as dictionaries.
		if ( ! empty( $custom_packages ) ) {
			// Validate that the new custom packages have unique names.
			$map_package_name            = function ( $package ) {
				return $package['name'];
			};
			$custom_package_names        = array_map( $map_package_name, $custom_packages );
			$unique_custom_package_names = array_unique( $custom_package_names );

			if ( count( $unique_custom_package_names ) < count( $custom_package_names ) ) {
				$duplicate_package_names = array_diff_assoc( $custom_package_names, $unique_custom_package_names );
				$error                   = array(
					'code'    => 'duplicate_custom_package_names',
					'message' => __( 'The new custom package names are not unique.', 'woocommerce-shipping' ),
					'data'    => array( 'package_names' => array_values( $duplicate_package_names ) ),
				);
				return new WP_REST_Response( $error, 400 );
			}

			// Validate that the new custom packages do not have the same names as existing custom packages.
			$existing_custom_packages      = $this->settings_store->get_packages();
			$existing_custom_package_names = array_map( $map_package_name, $existing_custom_packages );
			$duplicate_package_names       = array_intersect( $existing_custom_package_names, $custom_package_names );

			if ( ! empty( $duplicate_package_names ) ) {
				$error = array(
					'code'    => 'duplicate_custom_package_names_of_existing_packages',
					'message' => __( 'At least one of the new custom packages has the same name as existing packages.', 'woocommerce-shipping' ),
					'data'    => array( 'package_names' => array_values( $duplicate_package_names ) ),
				);
				return new WP_REST_Response( $error, 400 );
			}

			try {
				// If no duplicate custom packages, create the given packages.
				$this->settings_store->create_packages( $custom_packages );
			} catch ( PackageValidationException $e ) {
				return $e->get_error_response();
			}
		}

		// Handle new predefined packages. The predefined packages are structured as a dictionary from carrier name to
		// an array of package names.
		if ( ! empty( $predefined_packages ) ) {
			$duplicate_package_names_by_carrier = array();

			// Validate that the new predefined packages have unique names for each carrier.
			foreach ( $predefined_packages as $carrier => $package_names ) {
				$unique_package_names = array_unique( $package_names );
				if ( count( $unique_package_names ) < count( $package_names ) ) {
					$duplicate_package_names                        = array_diff_assoc( $package_names, $unique_package_names );
					$duplicate_package_names_by_carrier[ $carrier ] = array_values( $duplicate_package_names );
				}
			}

			if ( ! empty( $duplicate_package_names_by_carrier ) ) {
				$error = array(
					'code'    => 'duplicate_predefined_package_names',
					'message' => __( 'At least one of the new carrier package names is not unique.', 'woocommerce-shipping' ),
					'data'    => array( 'package_names_by_carrier' => $duplicate_package_names_by_carrier ),
				);
				return new WP_REST_Response( $error, 400 );
			}

			// Validate that the new predefined packages for each carrier do not have the same names as existing predefined packages.
			$existing_predefined_packages = $this->settings_store->get_predefined_packages();
			if ( ! empty( $existing_predefined_packages ) ) {
				foreach ( $existing_predefined_packages as $carrier => $existing_package_names ) {
					$new_package_names       = isset( $predefined_packages[ $carrier ] ) ? $predefined_packages[ $carrier ] : array();
					$duplicate_package_names = array_intersect( $existing_package_names, $new_package_names );
					if ( ! empty( $duplicate_package_names ) ) {
						$duplicate_package_names_by_carrier[ $carrier ] = array_values( $duplicate_package_names );
					}
				}
			}

			if ( ! empty( $duplicate_package_names_by_carrier ) ) {
				$error = array(
					'code'    => 'duplicate_predefined_package_names_of_existing_packages',
					'message' => __( 'At least one of the new predefined packages has the same name as an existing package.', 'woocommerce-shipping' ),
					'data'    => array( 'package_names_by_carrier' => $duplicate_package_names_by_carrier ),
				);
				return new WP_REST_Response( $error, 400 );
			}

			// If no duplicate predefined packages, create the given packages.
			$this->settings_store->create_predefined_packages( $predefined_packages );
		}

		return rest_ensure_response(
			array(
				'predefined' => $this->settings_store->get_predefined_packages(),
				'custom'     => $this->settings_store->get_packages(),
			)
		);
	}

	/**
	 * Delete a saved package.
	 *
	 * @param  WP_REST_Request $request The request parameters contain the package type and id to delete.
	 * @return WP_Error|WP_REST_Response
	 */
	public function delete( WP_REST_Request $request ) {
		try {
			list( $type, $id ) = $this->get_and_check_request_params( $request, array( 'type', 'id' ) );
		} catch ( RESTRequestException $error ) {
			return rest_ensure_response( $error->get_error_response() );
		}

		if ( 'custom' === $type ) {
			$packages = $this->settings_store->get_packages();
			$found    = false;

			foreach ( $packages as $key => $package ) {
				if ( $package['id'] === $id ) {
					unset( $packages[ $key ] );
					$found = true;
				}
			}

			if ( ! $found ) {
				return rest_ensure_response(
					array(
						'success' => false,
						'error'   => array(
							'code'    => 'package_not_found',
							'message' => __( 'Custom package not found.', 'woocommerce-shipping' ),
						),
					)
				);
			}

			// Reindex the array since we've just plugged a hole.
			$packages = array_values( $packages );

			try {
				$this->settings_store->update_packages( $packages );
			} catch ( PackageValidationException $e ) {
				return $e->get_error_response();
			}
		} else {
			$predefined_packages = $this->settings_store->get_predefined_packages();
			$found               = false;

			foreach ( $predefined_packages as $carrier => $package_names ) {
				if ( false !== ( $key = array_search( $id, $package_names, true ) ) ) {
					unset( $predefined_packages[ $carrier ][ $key ] );
					$predefined_packages[ $carrier ] = array_values( $predefined_packages[ $carrier ] );

					if ( empty( $predefined_packages[ $carrier ] ) ) {
						unset( $predefined_packages[ $carrier ] );
					}
					$found = true;
					break;
				}
			}

			if ( ! $found ) {
				return rest_ensure_response(
					array(
						'success' => false,
						'error'   => array(
							'code'    => 'package_not_found',
							'message' => __( 'Predefined package not found.', 'woocommerce-shipping' ),
						),
					)
				);
			}
			try {
				$this->settings_store->update_predefined_packages( $predefined_packages );
			} catch ( PackageValidationException $e ) {
				return $e->get_error_response();
			}
		}

		return rest_ensure_response(
			array(
				'success'    => true,
				'predefined' => $this->settings_store->get_predefined_packages(),
				'custom'     => $this->settings_store->get_packages(),
			)
		);
	}
}
