<?php
/**
 * "Local pickup" — direct request: "I want to add a local pickup option
 * to my site, but I only want it to be available for certain items."
 *
 * Each shippable product carries its own eligibility flag (META_ELIGIBLE,
 * set from the admin app's Settings > Shipping > Local Pickup checklist),
 * and the pickup rate is offered at checkout only when EVERY item in the
 * shipping package is eligible — one ineligible item in the cart means
 * the whole order has to ship, so pickup disappears until it's removed.
 * Virtual items (the design fee, web design packages) never reach a
 * shipping package, so they neither need the flag nor block pickup.
 *
 * Added on top of whatever the live WooCommerce shipping zone already
 * returns, through woocommerce_package_rates, rather than as a
 * WooCommerce "Local pickup" method in each zone: a zone method can't
 * look at the cart's items, and this keeps the on/off switch, label and
 * item list together on one admin screen. WooCommerce caches rates per
 * package hash (which folds in the "shipping" transient version), so
 * bust_rate_cache() runs on every save of these settings — otherwise a
 * cart already in someone's session would keep its old rate list.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Local_Pickup {

	const ENABLED_OPTION      = 'yeffoprint_local_pickup_enabled';
	const LABEL_OPTION        = 'yeffoprint_local_pickup_label';
	const INSTRUCTIONS_OPTION = 'yeffoprint_local_pickup_instructions';

	const META_ELIGIBLE = '_yp_local_pickup';

	/** Shipping rate/method id — also how a placed order is recognised as a pickup order. */
	const METHOD_ID = 'yp_local_pickup';

	/** @var string[] WC_Email ids that carry the pickup instructions. */
	private const EMAIL_IDS = [
		'customer_processing_order',
		'customer_on_hold_order',
		'customer_completed_order',
		'customer_invoice',
	];

	public function __construct() {
		// Late, so it's appended after every zone method's own rates.
		add_filter( 'woocommerce_package_rates', [ $this, 'add_rate' ], 100, 2 );
		add_action( 'woocommerce_email_after_order_table', [ $this, 'render_email_instructions' ], 10, 4 );
		add_action( 'woocommerce_order_details_after_order_table', [ $this, 'render_order_instructions' ] );
	}

	public static function is_enabled(): bool {
		return (bool) get_option( self::ENABLED_OPTION, false );
	}

	public static function label(): string {
		$label = trim( (string) get_option( self::LABEL_OPTION, '' ) );
		return '' !== $label ? $label : __( 'Local pickup', 'yeffoprint-core' );
	}

	public static function instructions(): string {
		return trim( (string) get_option( self::INSTRUCTIONS_OPTION, '' ) );
	}

	public static function is_eligible( int $product_id ): bool {
		return 'yes' === get_post_meta( $product_id, self::META_ELIGIBLE, true );
	}

	/** True when the order's customer chose pickup at checkout. */
	public static function is_pickup_order( \WC_Order $order ): bool {
		foreach ( $order->get_shipping_methods() as $method ) {
			if ( self::METHOD_ID === $method->get_method_id() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Every published, shippable product a customer can put in the cart —
	 * one per Template (class-linked-product.php), plus Custom Stickers
	 * and Custom Order Labels — for the admin checklist.
	 *
	 * @return array<int, array{id:int, name:string, slug:string, eligible:bool}>
	 */
	public static function products(): array {
		$products = wc_get_products( [
			'status'  => 'publish',
			'virtual' => false,
			'limit'   => -1,
			'orderby' => 'title',
			'order'   => 'ASC',
		] );

		return array_map( static function ( \WC_Product $product ): array {
			return [
				'id'       => $product->get_id(),
				'name'     => $product->get_name(),
				'slug'     => $product->get_slug(),
				'eligible' => self::is_eligible( $product->get_id() ),
			];
		}, $products );
	}

	/**
	 * Saves the admin screen's whole Local Pickup panel. $eligible_ids is
	 * the complete list of ticked products, so anything unticked is
	 * cleared — limited to products() so a stale or hand-built request
	 * can't flag arbitrary posts.
	 *
	 * @param int[] $eligible_ids
	 */
	public static function save( bool $enabled, string $label, string $instructions, array $eligible_ids ): void {
		update_option( self::ENABLED_OPTION, $enabled );
		update_option( self::LABEL_OPTION, sanitize_text_field( $label ) );
		update_option( self::INSTRUCTIONS_OPTION, sanitize_textarea_field( $instructions ) );

		$eligible_ids = array_map( 'absint', $eligible_ids );
		foreach ( self::products() as $product ) {
			if ( in_array( $product['id'], $eligible_ids, true ) ) {
				update_post_meta( $product['id'], self::META_ELIGIBLE, 'yes' );
			} else {
				delete_post_meta( $product['id'], self::META_ELIGIBLE );
			}
		}

		self::bust_rate_cache();
	}

	public static function bust_rate_cache(): void {
		if ( class_exists( 'WC_Cache_Helper' ) ) {
			\WC_Cache_Helper::get_transient_version( 'shipping', true );
		}
	}

	/**
	 * @param \WC_Shipping_Rate[] $rates
	 * @return \WC_Shipping_Rate[]
	 */
	public function add_rate( array $rates, array $package ): array {
		if ( ! self::is_enabled() || empty( $package['contents'] ) ) {
			return $rates;
		}

		foreach ( $package['contents'] as $item ) {
			$product_id = (int) ( $item['product_id'] ?? 0 );
			if ( ! $product_id || ! self::is_eligible( $product_id ) ) {
				return $rates;
			}
		}

		$rates[ self::METHOD_ID ] = new \WC_Shipping_Rate(
			self::METHOD_ID,
			self::label(),
			0,
			[],
			self::METHOD_ID,
			0,
			'none',
			// Shown under the option in the block Checkout's shipping list.
			self::instructions()
		);
		return $rates;
	}

	public function render_email_instructions( \WC_Order $order, bool $sent_to_admin, bool $plain_text, \WC_Email $email ): void {
		if ( $sent_to_admin || ! in_array( $email->id, self::EMAIL_IDS, true ) || ! self::is_pickup_order( $order ) ) {
			return;
		}

		$instructions = self::instructions();
		if ( '' === $instructions ) {
			return;
		}

		if ( $plain_text ) {
			echo esc_html( self::label() ) . "\n" . esc_html( $instructions ) . "\n\n";
			return;
		}

		printf(
			'<h2>%1$s</h2><p style="margin:0 0 24px;">%2$s</p>',
			esc_html( self::label() ),
			nl2br( esc_html( $instructions ) )
		);
	}

	/** Thank-you page and My Account > order view. */
	public function render_order_instructions( \WC_Order $order ): void {
		if ( ! self::is_pickup_order( $order ) ) {
			return;
		}

		$instructions = self::instructions();
		if ( '' === $instructions ) {
			return;
		}

		printf(
			'<section class="yp-local-pickup-instructions"><h2>%1$s</h2><p>%2$s</p></section>',
			esc_html( self::label() ),
			nl2br( esc_html( $instructions ) )
		);
	}
}
