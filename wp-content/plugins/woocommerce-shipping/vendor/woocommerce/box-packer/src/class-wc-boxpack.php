<?php
/**
 * WooCommerce Box Packer
 *
 * @version 1.2.0
 *
 * @package woocommerce/box-packer
 */

namespace WooCommerce\BoxPacker;

/**
 * Box Packer loader.
 */
class WC_Boxpack {

	/**
	 * Loaded packer.
	 *
	 * @var Abstract_Packer
	 */
	private $packer;

	/**
	 * Box packer libraries.
	 *
	 * @var array
	 */
	private $libraries = array(
		'original' => 'WooCommerce\BoxPacker\Original\Packer',
		'dvdoug'   => 'WooCommerce\BoxPacker\DVDoug\Packer',
	);

	public function __construct( string $dimension_unit, string $weight_unit, string $library = 'original', array $options = array() ) {
		$library = strtolower( $library );

		/**
		 * If the requested box packer library doesn't exist, use the original
		 */
		if ( ! array_key_exists( $library, $this->libraries ) ) {
			$library = 'original';
		}

		/**
		 * If the PHP version is older than 7.1, use the original
		 */
		if ( version_compare( phpversion(), '7.1', '<' ) ) {
			$library = 'original';
		}

		$this->packer = new $this->libraries[ $library ]( $dimension_unit, $weight_unit, $options );
	}

	public function get_packer() {
		return $this->packer;
	}
}
