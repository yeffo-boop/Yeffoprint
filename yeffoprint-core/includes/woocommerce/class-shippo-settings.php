<?php
/**
 * Shippo API configuration — direct request: "the woocommerce shipping
 * plugin is awful though. Can we build something with the shippo API to
 * replace it?" / "I'd like to run alongside it a bit." This is
 * deliberately additive, not a replacement: WooCommerce Shipping's own
 * embedded label form (class-admin-order-controller.php's
 * shipping_label_available, app.js's wcOrderShippingLabelHtml()) stays
 * exactly as-is; a second, independent "Shippo" panel sits beside it in
 * the same drawer (class-admin-shippo-controller.php) so staff can
 * compare before ever switching over.
 *
 * API token storage follows class-stripe-webhook-secret.php's own
 * pattern (admin-pasted, plain get_option()/update_option(), no
 * generation — the value originates from Shippo's own dashboard, not
 * this site). Kept as its own small class rather than folded into
 * class-admin-menu.php's already-large option-constant list, same as
 * every other secret in this plugin.
 *
 * The default-package fields exist because this store tracks no
 * product weight/shipping-dimension data anywhere (confirmed — every
 * WooCommerce product weight/dimension field is unset; custom vial
 * labels/stickers ship in a standard bubble mailer regardless of what's
 * inside). Rather than build a whole per-product weight system for a
 * first version, staff enter one reusable default here (a starting
 * estimate — see class-admin-settings-controller.php's own field hint)
 * and can override it per order in the drawer before rate-shopping.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Shippo_Settings {

	public const API_KEY_OPTION           = 'yeffoprint_shippo_api_key';
	public const DEFAULT_WEIGHT_OZ_OPTION = 'yeffoprint_shippo_default_weight_oz';
	public const DEFAULT_LENGTH_IN_OPTION = 'yeffoprint_shippo_default_length_in';
	public const DEFAULT_WIDTH_IN_OPTION  = 'yeffoprint_shippo_default_width_in';
	public const DEFAULT_HEIGHT_IN_OPTION = 'yeffoprint_shippo_default_height_in';

	// A padded 8x6 bubble mailer with a few sheets of vinyl labels inside —
	// direct answer, "not sure how much the envelope weighs" — a starting
	// point meant to be corrected in Settings once a real package has been
	// weighed, not a measured value.
	private const DEFAULT_WEIGHT_OZ = 4.0;
	private const DEFAULT_LENGTH_IN = 8.0;
	private const DEFAULT_WIDTH_IN  = 6.0;
	private const DEFAULT_HEIGHT_IN = 1.0;

	public static function get_api_key(): string {
		$key = get_option( self::API_KEY_OPTION, '' );

		return is_string( $key ) ? $key : '';
	}

	public static function is_configured(): bool {
		return '' !== self::get_api_key();
	}

	/** @return array{weight_oz:float,length_in:float,width_in:float,height_in:float} */
	public static function get_default_package(): array {
		return [
			'weight_oz' => (float) get_option( self::DEFAULT_WEIGHT_OZ_OPTION, self::DEFAULT_WEIGHT_OZ ),
			'length_in' => (float) get_option( self::DEFAULT_LENGTH_IN_OPTION, self::DEFAULT_LENGTH_IN ),
			'width_in'  => (float) get_option( self::DEFAULT_WIDTH_IN_OPTION, self::DEFAULT_WIDTH_IN ),
			'height_in' => (float) get_option( self::DEFAULT_HEIGHT_IN_OPTION, self::DEFAULT_HEIGHT_IN ),
		];
	}

	/**
	 * The address a label ships from — direct confirmation: "the address
	 * in woocommerce settings is the from address." Reuses WooCommerce's
	 * own Settings → General store-address options rather than adding a
	 * second place to maintain the same address.
	 *
	 * @return array{name:string,street1:string,street2:string,city:string,state:string,zip:string,country:string}
	 */
	public static function get_ship_from_address(): array {
		return [
			'name'    => get_bloginfo( 'name' ),
			'street1' => (string) get_option( 'woocommerce_store_address', '' ),
			'street2' => (string) get_option( 'woocommerce_store_address_2', '' ),
			'city'    => (string) get_option( 'woocommerce_store_city', '' ),
			'state'   => (string) WC()->countries->get_base_state(),
			'zip'     => (string) get_option( 'woocommerce_store_postcode', '' ),
			'country' => (string) WC()->countries->get_base_country(),
		];
	}
}
