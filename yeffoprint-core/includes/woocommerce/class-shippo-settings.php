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
	public const SHIP_FROM_PHONE_OPTION   = 'yeffoprint_shippo_ship_from_phone';

	/**
	 * Manual order creation's preset shipping methods — direct request:
	 * "I don't need to rate shop to add shipping, just use my default
	 * shipping options." Lives here beside the rest of the shipping
	 * config even though it's a deliberate Shippo *bypass*, not a Shippo
	 * API concept — a flat, admin-edited {label, amount} list staff pick
	 * from directly on the Manual Order screen instead of live rate-
	 * shopping, since the owner already knows what they charge for each
	 * service without needing a live quote per order.
	 */
	public const MANUAL_ORDER_SHIPPING_OPTIONS_OPTION = 'yeffoprint_manual_order_shipping_options';

	// A padded 8x6 bubble mailer with a few sheets of vinyl labels inside —
	// direct answer, "not sure how much the envelope weighs" — a starting
	// point meant to be corrected in Settings once a real package has been
	// weighed, not a measured value.
	private const DEFAULT_WEIGHT_OZ = 4.0;
	private const DEFAULT_LENGTH_IN = 8.0;
	private const DEFAULT_WIDTH_IN  = 6.0;
	private const DEFAULT_HEIGHT_IN = 1.0;

	// Seeded from the site owner's own actual rates, direct request.
	private const DEFAULT_MANUAL_ORDER_SHIPPING_OPTIONS = [
		[ 'label' => 'USPS Ground Advantage', 'amount' => 6.00 ],
		[ 'label' => 'UPS 2nd Day Air', 'amount' => 25.00 ],
		[ 'label' => 'USPS First Class International', 'amount' => 25.00 ],
	];

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

	/** @return array<int, array{label:string, amount:float}> */
	public static function get_manual_order_shipping_options(): array {
		$stored = get_option( self::MANUAL_ORDER_SHIPPING_OPTIONS_OPTION, null );
		if ( ! is_array( $stored ) ) {
			return self::DEFAULT_MANUAL_ORDER_SHIPPING_OPTIONS;
		}

		return self::sanitize_manual_order_shipping_options( $stored );
	}

	/** Shared by get_manual_order_shipping_options() (reading whatever's already stored) and class-admin-settings-controller.php's own save handler (sanitizing a fresh submission before it's stored) — one validation rule, not two. @return array<int, array{label:string, amount:float}> */
	public static function sanitize_manual_order_shipping_options( array $raw ): array {
		$options = [];

		foreach ( $raw as $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}

			$label = sanitize_text_field( (string) ( $option['label'] ?? '' ) );
			if ( '' === $label ) {
				continue;
			}

			$options[] = [ 'label' => $label, 'amount' => max( 0.0, (float) ( $option['amount'] ?? 0 ) ) ];
		}

		return $options;
	}

	/**
	 * The address a label ships from — direct confirmation: "the address
	 * in woocommerce settings is the from address." Reuses WooCommerce's
	 * own Settings → General store-address options rather than adding a
	 * second place to maintain the same address.
	 *
	 * `email`/`phone` were added after a direct report purchasing a real
	 * label: "Seller info missing email or phone. Seller email and phone
	 * number required for USPS." — Shippo/USPS require the *sender's*
	 * contact info on a label, not just the destination's, and this
	 * class originally sent neither. Email reuses WordPress's own
	 * `admin_email` (already configured, no new field needed); this
	 * store has no existing business-phone setting anywhere, so
	 * SHIP_FROM_PHONE_OPTION adds the one new field this actually
	 * required (Settings → Shipping, alongside the rest of Shippo's
	 * config).
	 *
	 * @return array{name:string,street1:string,street2:string,city:string,state:string,zip:string,country:string,email:string,phone:string}
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
			'email'   => (string) get_option( 'admin_email', '' ),
			'phone'   => (string) get_option( self::SHIP_FROM_PHONE_OPTION, '' ),
		];
	}
}
