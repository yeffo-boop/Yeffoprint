<?php
/**
 * WooCommerceBlocks Integration class.
 *
 * @package Automattic\WCShipping
 */

namespace Automattic\WCShipping\Integrations;

use Automattic\WCShipping\Checkout\CheckoutService;
use Automattic\WCShipping\StoreApi\StoreApiExtendSchema;
use Automattic\WCShipping\Utils;
use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerceBlocks Integration class.
 */
class WooCommerceBlocksIntegration implements IntegrationInterface {

	/**
	 * The name of the integration.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return StoreApiExtendSchema::IDENTIFIER;
	}

	/**
	 * When called invokes any initialization/setup for the integratidon.
	 */
	public function initialize() {
		$this->register_scripts();
	}

	/**
	 * Returns an array of script handles to enqueue in the frontend context.
	 *
	 * @return string[]
	 */
	public function get_script_handles(): array {
		$script_handles = array();

		// Only enqueue the address validation script if address validation is enabled.
		if ( CheckoutService::is_address_validation_enabled() ) {
			$script_handles[] = 'woocommerce-shipping-checkout-address-validation';
		}

		return $script_handles;
	}

	/**
	 * Returns an array of script handles to enqueue in the editor context.
	 *
	 * @return string[]
	 */
	public function get_editor_script_handles(): array {
		return array();
	}

	/**
	 * An array of key, value pairs of data made available to the block on the client side.
	 *
	 * @return array
	 */
	public function get_script_data(): array {
		return array();
	}

	/**
	 * Registers the scripts and styles for the integration.
	 */
	public function register_scripts() {
		foreach ( $this->get_script_handles() as $handle ) {
			$this->register_script( $handle );
		}
	}

	/**
	 * Register a script for the integration.
	 *
	 * @param string $handle Script handle.
	 */
	protected function register_script( string $handle ) {
		$script_name         = "$handle.js";
		$script_path         = WCSHIPPING_PLUGIN_DIST_DIR . $script_name;
		$script_url          = Utils::get_enqueue_base_url() . $script_name;
		$script_asset_path   = WCSHIPPING_PLUGIN_DIST_DIR . $handle . '.asset.php';
		$script_asset        = file_exists( $script_asset_path )
		? require $script_asset_path : array();  // nosemgrep: audit.php.lang.security.file.inclusion-arg --- This is a safe file inclusion.
		$script_dependencies = Utils::filter_dev_dependencies( $script_asset['dependencies'] ?? array() );
		$script_version      = $script_asset['version'] ?? Utils::get_file_version( $script_path );

		wp_register_script(
			$handle,
			$script_url,
			$script_dependencies,
			$script_version,
			true
		);
	}
}
