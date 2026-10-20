<?php
/**
 * Holographic vinyl's extra production day and border choice.
 *
 * Direct request: holographic vinyl needs an extra 24 hours after
 * printing to cure (de-gas) so it isn't damaged in shipping, and prints
 * better with a thin unprinted holographic border, so customers are
 * asked whether they'd like one. Only the 'holographic' swatch finish
 * counts (Prism doesn't need it), and Express orders aren't delayed.
 *
 * The storefront pickers (yeffoprint/assets/js/label-pickers.js) show the
 * notice and border question; this class carries the border answer from
 * the cart onto the order, repeats the notice above Place Order, and
 * tells the admin app which orders need the extra day.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Holographic_Vinyl {

	/** Order item meta: 'yes' or 'no', only on holographic lines. */
	public const ITEM_BORDER_META = '_yp_holo_border';

	public function __construct() {
		add_filter( 'woocommerce_get_item_data', [ $this, 'display_item_data' ], 20, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'snapshot_border' ], 20, 4 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_checkout_notice' ] );
	}

	public static function is_holographic( int $material_id ): bool {
		$material = $material_id ? get_post( $material_id ) : null;
		return $material instanceof \WP_Post
			&& 'yp_material' === $material->post_type
			&& 'holographic' === YeffoPrint_Commerce_Record_Meta::swatch_finish( $material );
	}

	/** 'yes'/'no' for a holographic material (anything but 'no' means yes, the recommended default), '' otherwise. */
	public static function border_choice( $raw, int $material_id ): string {
		if ( ! self::is_holographic( $material_id ) ) {
			return '';
		}
		return 'no' === $raw ? 'no' : 'yes';
	}

	/** Any holographic line on the order (labels or stickers), read from the material snapshot so a later rename doesn't change it. */
	public static function order_has_holographic( \WC_Order $order ): bool {
		foreach ( $order->get_items() as $item ) {
			$snapshot = json_decode( (string) $item->get_meta( '_yp_material_snapshot' ), true );
			if ( is_array( $snapshot ) && ! empty( $snapshot['id'] ) && self::is_holographic( (int) $snapshot['id'] ) ) {
				return true;
			}
		}
		return false;
	}

	/** True when the order gets the extra cure day: holographic and not Express. */
	public static function needs_cure_day( \WC_Order $order ): bool {
		return ! YeffoPrint_Express_Order::is_express( $order ) && self::order_has_holographic( $order );
	}

	/** null when the line isn't holographic, else whether the customer wants the border. */
	public static function item_border( \WC_Order_Item $item ): ?bool {
		$value = (string) $item->get_meta( self::ITEM_BORDER_META );
		return '' === $value ? null : 'yes' === $value;
	}

	private static function cart_has_holographic(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( self::is_holographic( (int) ( $cart_item[ YeffoPrint_Cart_Item_Keys::MATERIAL_ID ] ?? 0 ) ) ) {
				return true;
			}
		}
		return false;
	}

	private static function border_label( string $choice ): string {
		return 'no' === $choice ? __( 'No border', 'yeffoprint-core' ) : __( 'Yes', 'yeffoprint-core' );
	}

	/** "Holographic border: Yes" on the cart, cart drawer and checkout rows, right after Material. */
	public function display_item_data( array $item_data, array $cart_item ): array {
		$choice = (string) ( $cart_item[ YeffoPrint_Cart_Item_Keys::HOLO_BORDER ] ?? '' );
		if ( '' === $choice ) {
			return $item_data;
		}

		$row   = [ 'key' => __( 'Holographic border', 'yeffoprint-core' ), 'value' => self::border_label( $choice ) ];
		$after = count( $item_data );
		foreach ( $item_data as $index => $entry ) {
			if ( __( 'Material', 'yeffoprint-core' ) === ( $entry['key'] ?? '' ) ) {
				$after = $index + 1;
				break;
			}
		}
		array_splice( $item_data, $after, 0, [ $row ] );

		return $item_data;
	}

	/** Runs after YeffoPrint_Order_Item_Meta::snapshot() (priority 10), so the readable row lands after Material. */
	public function snapshot_border( \WC_Order_Item_Product $item, $cart_item_key, $values, $order ): void {
		$choice = (string) ( $values[ YeffoPrint_Cart_Item_Keys::HOLO_BORDER ] ?? '' );
		if ( '' === $choice ) {
			return;
		}
		$item->add_meta_data( self::ITEM_BORDER_META, $choice, true );
		$item->add_meta_data( __( 'Holographic border', 'yeffoprint-core' ), self::border_label( $choice ), true );
	}

	/** The same "Worth the extra day" note above Place Order (same insertion approach as class-away-mode-checkout-notice.php). */
	public function enqueue_checkout_notice(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || ! self::cart_has_holographic() ) {
			return;
		}

		$path = 'assets/blocks/holographic-checkout.js';
		wp_enqueue_script(
			'yeffoprint-holographic-checkout',
			YEFFOPRINT_CORE_URL . $path,
			[ 'wc-blocks-checkout' ],
			yeffoprint_core_asset_version( $path ),
			true
		);

		wp_localize_script( 'yeffoprint-holographic-checkout', 'yeffoprintHoloCheckout', [
			'html' => sprintf(
				'<div class="yp-holo-note yp-holo-note--checkout"><span class="yp-holo-note__icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18" focusable="false"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 7v5l3 2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span><div><p class="yp-holo-note__title">%1$s</p><p class="yp-holo-note__body">%2$s</p><p class="yp-holo-note__small">%3$s</p></div></div>',
				esc_html__( 'Worth the extra day ✨', 'yeffoprint-core' ),
				esc_html__( 'We let holographic labels rest for 24 hours after printing before we pack them. That gives the vinyl time to settle, so your labels arrive crisp, flat and scratch-free.', 'yeffoprint-core' ),
				esc_html__( 'Adds 1 business day before your order ships. Express orders aren’t delayed.', 'yeffoprint-core' )
			),
		] );
	}
}
