<?php
namespace Automattic\WCShipping\Connect;

use WC_Admin_Status;
use stdClass;
use Automattic\WCShipping\DOM\Manipulation as DOM_Manipulation;
use Automattic\WCShipping\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Connect_Help_View {

	/**
	 * @var WC_Connect_Service_Schemas_Store
	 */
	protected $service_schemas_store;

	/**
	 * @var WC_Connect_Service_Settings_Store
	 */
	protected $service_settings_store;

	/**
	 * @var WC_Connect_Logger
	 */
	protected $logger;

	/**
	 * @array
	 */
	protected $fieldsets;

	public function __construct(
		WC_Connect_Service_Schemas_Store $service_schemas_store,
		WC_Connect_Service_Settings_Store $service_settings_store,
		WC_Connect_Logger $logger
	) {

		$this->service_schemas_store  = $service_schemas_store;
		$this->service_settings_store = $service_settings_store;
		$this->logger                 = $logger;

		add_filter( 'woocommerce_admin_status_tabs', array( $this, 'status_tabs' ) );
		add_action( 'woocommerce_admin_status_content_woocommerce-shipping', array( $this, 'page' ) );
	}

	protected function get_health_items() {
		$health_items = array();

		// WooCommerce
		// Only one of the following should present
		// Check that WooCommerce is at least 2.6 or higher (feature-plugin only)
		// Check that WooCommerce base_country is set
		$plugin_data        = get_plugin_data( WCSHIPPING_PLUGIN_FILE );
		$minimum_wc_version = defined( '\WC_Plugin_Updates::VERSION_REQUIRED_HEADER' ) ? $plugin_data[ \WC_Plugin_Updates::VERSION_REQUIRED_HEADER ] : $plugin_data['WC requires at least'];
		$base_country       = WC()->countries->get_base_country();
		if ( version_compare( WC()->version, $minimum_wc_version, '<' ) ) {
			$health_item = array(
				'state'   => 'error',
				'message' => sprintf(
					// translators: %1$s: minimum WooCommerce version, %2$s: current WooCommerce version
					__( 'WooCommerce %1$s or higher is required (You are running %2$s)', 'woocommerce-shipping' ),
					$minimum_wc_version,
					WC()->version
				),
			);
		} elseif ( empty( $base_country ) ) {
			$health_item = array(
				'state'   => 'error',
				'message' => __( 'Please set Base Location in WooCommerce Settings > General', 'woocommerce-shipping' ),
			);
		} else {
			$health_item = array(
				'state'   => 'success',
				'message' => sprintf(
					// translators: %s: current WooCommerce version
					__( 'WooCommerce %s is configured correctly', 'woocommerce-shipping' ),
					WC()->version
				),
			);
		}
		$health_items['woocommerce'] = $health_item;

		// WordPress.com connection.
		// Check that WooCommerce Shipping is connected to WordPress.com.
		$is_connected = WC_Connect_Jetpack::is_active() || WC_Connect_Jetpack::is_offline_mode();
		if ( ! $is_connected ) {
			$health_item = array(
				'state'   => 'error',
				'message' => __( 'WooCommerce Shipping is not connected to WordPress.com. WooCommerce Shipping requires you to connect to a WordPress.com account to be able to offer shipping rates and label purchases.', 'woocommerce-shipping' ),
			);
		} else {
			$health_item = array(
				'state'   => 'success',
				'message' => __( 'WooCommerce Shipping is connected to WordPress.com, and working correctly', 'woocommerce-shipping' ),
			);
		}
		$health_items['wordpress_com'] = $health_item;

		// Lastly, do the WooCommerce Shipping health check
		// Check that we have schema
		// Check that we are able to talk to the WooCommerce Shipping server
		$schemas                    = $this->service_schemas_store->get_service_schemas();
		$last_fetch_timestamp       = $this->service_schemas_store->get_last_fetch_timestamp();
		$health_items['wcshipping'] = array(
			'timestamp'           => intval( $last_fetch_timestamp ),
			'has_service_schemas' => ! is_null( $schemas ),
			'error_threshold'     => 3 * DAY_IN_SECONDS,
			'warning_threshold'   => DAY_IN_SECONDS,
		);

		if ( empty( $last_fetch_timestamp ) ) {
			$this->logger->log( 'Cannot retrieve last fetch timestamp information.', __METHOD__ );
		}

		return $health_items;
	}

	protected function get_services_items() {
		$available_service_method_ids = $this->service_schemas_store->get_all_shipping_method_ids();
		if ( empty( $available_service_method_ids ) ) {
			return false;
		}

		$service_items = array();

		$enabled_services = $this->service_settings_store->get_enabled_services();

		foreach ( (array) $enabled_services as $enabled_service ) {
			$last_failed_request_timestamp = intval( WC_Connect_Options::get_shipping_method_option( 'failure_timestamp', -1, $enabled_service->method_id, $enabled_service->instance_id ) );

			$service_settings_url = esc_url(
				add_query_arg(
					array(
						'page'        => 'wc-settings',
						'tab'         => 'shipping',
						'instance_id' => $enabled_service->instance_id,
					),
					admin_url( 'admin.php' )
				)
			);

			// Figure out if the service has any settings saved at all
			$service_settings = $this->service_settings_store->get_service_settings( $enabled_service->method_id, $enabled_service->instance_id );
			if ( empty( $service_settings ) ) {
				$state   = 'error';
				$message = __( 'Setup for this service has not yet been completed', 'woocommerce-shipping' );
			} elseif ( -1 === $last_failed_request_timestamp ) {
				$state   = 'warning';
				$message = __( 'No rate requests have yet been made for this service', 'woocommerce-shipping' );
			} elseif ( 0 === $last_failed_request_timestamp ) {
				$state   = 'success';
				$message = __( 'The most recent rate request was successful', 'woocommerce-shipping' );
			} else {
				$state   = 'error';
				$message = __( 'The most recent rate request failed', 'woocommerce-shipping' );
			}

			$subtitle = sprintf(
				// translators: %s: shipping zone name
				__( '%s Shipping Zone', 'woocommerce-shipping' ),
				$enabled_service->zone_name
			);

			$service_items[] = (object) array(
				'title'     => $enabled_service->title,
				'subtitle'  => $subtitle,
				'state'     => $state,
				'message'   => $message,
				'timestamp' => $last_failed_request_timestamp,
				'url'       => $service_settings_url,
			);
		}

		return $service_items;
	}

	/**
	 * Gets the last 10 lines from the WooCommerce Shipping log by feature, if it exists
	 */
	protected function get_debug_log_data( $feature = '' ) {
		$data       = new stdClass();
		$data->key  = '';
		$data->file = null;
		$data->tail = array();

		if ( ! method_exists( 'WC_Admin_Status', 'scan_log_files' ) ) {
			return $data;
		}

		$log_prefix = 'woocommerce-shipping';

		$logs             = WC_Admin_Status::scan_log_files();
		$latest_file_date = 0;

		foreach ( $logs as $log_key => $log_file ) {
			if ( ! preg_match( '/' . $log_prefix . '\-(?:\d{4}\-\d{2}\-\d{2}\-)?[0-9a-f]{32}\-log/', $log_key ) ) {
				continue;
			}

			$log_file_path = WC_LOG_DIR . $log_file;
			$file_date     = filemtime( $log_file_path );

			if ( $latest_file_date < $file_date ) {
				$latest_file_date = $file_date;
				$data->file       = $log_file_path;
				$data->key        = $log_key;
			}
		}

		if ( null !== $data->file ) {
			$complete_log = file( $data->file );
			if ( 'shipping' === $feature ) {
				/**
				 * Filter the $complete_log to only include lines that do not contain logs from the following method:
				 * Automattic\WCShipping\Connect\WC_Connect_Service_Schemas_Store::fetch_service_schemas_from_connect_server
				*/
				$complete_log = array_filter(
					$complete_log,
					function ( $complete_log ) {
						return strpos(
							$complete_log,
							'Automattic\WCShipping\Connect\WC_Connect_Service_Schemas_Store::fetch_service_schemas_from_connect_server)'
						) === false;
					}
				);
			}
			$data->tail = array_slice( $complete_log, -10 );
		}

		$line_count = count( $data->tail );
		if ( $line_count < 1 ) {
			$log_tail = array( __( 'Log is empty', 'woocommerce-shipping' ) );
		} else {
			$log_tail = $data->tail;
		}

		return array(
			'tail'  => implode( $log_tail ),
			'url'   => $url = add_query_arg(
				array(
					'page'     => 'wc-status',
					'tab'      => 'logs',
					'log_file' => $data->key,
				),
				admin_url( 'admin.php' )
			),
			'count' => $line_count,
		);
	}

	/**
	 * Filters the WooCommerce System Status Tabs to add connect
	 *
	 * @param array $tabs
	 * @return array
	 */
	public function status_tabs( $tabs ) {
		if ( ! is_array( $tabs ) ) {
			$tabs = array();
		}
		$tabs['woocommerce-shipping'] = _x( 'WooCommerce Shipping', 'The WooCommerce Shipping brandname', 'woocommerce-shipping' );
		return $tabs;
	}

	/**
	 * Returns the data bootstrap for the help page
	 *
	 * @return array
	 */
	protected function get_form_data() {
		return array(
			'health_items'    => $this->get_health_items(),
			'services'        => $this->get_services_items(),
			'logging_enabled' => $this->logger->is_logging_enabled(),
			'debug_enabled'   => $this->logger->is_debug_enabled(),
			'logs'            => array(
				'shipping' => $this->get_debug_log_data( 'shipping' ),
				'other'    => $this->get_debug_log_data(),
			),
		);
	}

	/**
	 * Localizes the bootstrap, enqueues the script and styles for the help page
	 */
	public function page() {
		?>
			<h2>
				<?php esc_html_e( 'WooCommerce Shipping Status', 'woocommerce-shipping' ); ?>
			</h2>
		<?php

		DOM_Manipulation::create_root_script_element( 'woocommerce-shipping-admin-status' );
		do_action(
			'wcshipping_enqueue_script',
			'woocommerce-shipping-admin-status',
			array(
				'formData'     => $this->get_form_data(),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'storeOptions' => $this->service_settings_store->get_store_options(),
				'paperSize'    => $this->service_settings_store->get_preferred_paper_size(),
				'constants'    => Utils::get_constants_for_js(),
			)
		);
	}
}
