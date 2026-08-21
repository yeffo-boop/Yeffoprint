<?php
/**
 * WooCommerce Shipping ability registration.
 *
 * @package Automattic\WCShipping\Abilities
 */

namespace Automattic\WCShipping\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks WooCommerce Shipping ability definitions into Woo's abilities loader.
 */
class Abilities {

	/**
	 * Initialize ability registration hooks when Woo's loader contract exists.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( ! interface_exists( '\Automattic\WooCommerce\Abilities\AbilityDefinition' ) ) {
			return;
		}

		add_filter( 'woocommerce_ability_definition_classes', array( __CLASS__, 'register_definitions' ) );
	}

	/**
	 * Add WooCommerce Shipping ability definitions to Woo's loader.
	 *
	 * @param array $classes Ability definition class names.
	 * @return array
	 */
	public static function register_definitions( array $classes ): array {
		$classes[] = ShippingLabelsReport::class;

		return $classes;
	}
}
