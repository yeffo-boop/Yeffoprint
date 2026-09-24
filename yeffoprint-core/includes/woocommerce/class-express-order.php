<?php
/**
 * "Express" — direct request: let a customer pay a flat fee at checkout
 * to skip the production line. The fee amount (starting at $15) and an
 * on/off switch live on the admin app's Settings screen (EXPRESS_*
 * options on YeffoPrint_Admin_Menu).
 *
 * Same Store API round trip class-card-surcharge-blocks-integration.php
 * already uses: the block Checkout never posts arbitrary form fields
 * back to PHP until the order is placed, so the checkbox
 * (assets/blocks/express-checkout.js) calls the `/cart/extensions`
 * endpoint on every toggle, which stores the choice in the session and
 * recalculates totals — re-running apply_fee() below — in that same
 * request.
 *
 * An order counts as express because it carries this class's own fee
 * line (flagged with FEE_ITEM_META as the order is created), not
 * because of a session value at payment time — so the flag survives a
 * later "Pay for order" retry, a different device, or an admin viewing
 * it months later, and removing the fee line from an order in wp-admin
 * un-expresses it. The paid-order side (Telegram escalation until
 * acknowledged or in production) is class-telegram-express-alerts.php.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Express_Order {

	// Must match the NAMESPACE constant assets/blocks/express-checkout.js sends.
	const NAMESPACE = 'yeffoprint-express';

	const FEE_ID        = 'yp-express';
	const FEE_ITEM_META = '_yp_express';

	private const SESSION_KEY = 'yp_express';

	public function __construct() {
		// Priority 18: after class-order-addon-checkout.php's shipping
		// waiver (15), before class-card-surcharge.php (20) — a card
		// surcharge should cover the express fee too, since it's charged
		// to the same card.
		add_action( 'woocommerce_cart_calculate_fees', [ $this, 'apply_fee' ], 18 );
		add_action( 'woocommerce_checkout_create_order_fee_item', [ $this, 'flag_fee_item' ], 10, 4 );
		add_action( 'woocommerce_blocks_loaded', [ $this, 'register_update_callback' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );

		// A placed order's choice shouldn't carry into the next cart.
		add_action( 'woocommerce_checkout_order_processed', [ __CLASS__, 'clear_session' ] );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ __CLASS__, 'clear_session' ] );
	}

	/**
	 * Off when switched off in Settings, and paused automatically while
	 * Away Mode is on (direct request) — skipping a line that isn't
	 * moving would be a promise the store can't keep. Resumes on its own
	 * the day Away Mode's return date passes, same as the notice itself.
	 */
	public static function is_enabled(): bool {
		return (bool) get_option( YeffoPrint_Admin_Menu::EXPRESS_ENABLED_OPTION, true )
			&& self::fee() > 0
			&& ! YeffoPrint_Admin_Menu::away_mode();
	}

	public static function fee(): float {
		return max( 0.0, (float) get_option( YeffoPrint_Admin_Menu::EXPRESS_FEE_OPTION, YeffoPrint_Admin_Menu::EXPRESS_FEE_DEFAULT ) );
	}

	public static function label(): string {
		return __( 'Express production', 'yeffoprint-core' );
	}

	/** True when the order carries the express fee line apply_fee() added. */
	public static function is_express( \WC_Order $order ): bool {
		foreach ( $order->get_fees() as $fee ) {
			if ( $fee->get_meta( self::FEE_ITEM_META ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Adds or removes the express fee line on an already-placed order —
	 * the "Pay for order" page's own Express checkbox (class-order-pay-
	 * address.php), which has no cart for apply_fee() to run on. The
	 * caller recalculates totals. Only adds while Express is enabled;
	 * removing always works.
	 */
	public static function set_on_order( \WC_Order $order, bool $express ): void {
		if ( $express === self::is_express( $order ) ) {
			return;
		}

		if ( ! $express ) {
			foreach ( $order->get_fees() as $item_id => $fee ) {
				if ( $fee->get_meta( self::FEE_ITEM_META ) ) {
					$order->remove_item( $item_id );
				}
			}
			return;
		}

		if ( ! self::is_enabled() ) {
			return;
		}

		$fee = new \WC_Order_Item_Fee();
		$fee->set_name( self::label() );
		$fee->set_amount( self::fee() );
		$fee->set_total( self::fee() );
		$fee->set_tax_status( 'none' ); // Same non-taxable treatment as apply_fee().
		$fee->add_meta_data( self::FEE_ITEM_META, 'yes', true );
		$order->add_item( $fee );
	}

	public static function is_chosen(): bool {
		return WC()->session && (bool) WC()->session->get( self::SESSION_KEY );
	}

	public static function clear_session(): void {
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::SESSION_KEY, null );
		}
	}

	public function apply_fee( \WC_Cart $cart ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		if ( ! self::is_enabled() || ! self::is_chosen() || $cart->is_empty() ) {
			return;
		}

		$cart->fees_api()->add_fee( [
			'id'      => self::FEE_ID,
			'name'    => self::label(),
			'amount'  => self::fee(),
			'taxable' => false,
		] );
	}

	/**
	 * Fires for both the classic and the block (Store API) checkout —
	 * both build fee lines through WC_Checkout::create_order_fee_lines().
	 *
	 * @param \WC_Order_Item_Fee $item
	 * @param string             $fee_key
	 * @param object             $fee The cart fee, with the `id` apply_fee() gave it.
	 * @param \WC_Order          $order
	 */
	public function flag_fee_item( $item, $fee_key, $fee, $order ): void {
		if ( isset( $fee->id ) && self::FEE_ID === $fee->id ) {
			$item->add_meta_data( self::FEE_ITEM_META, 'yes', true );
		}
	}

	public function register_update_callback(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			return;
		}

		woocommerce_store_api_register_update_callback( [
			'namespace' => self::NAMESPACE,
			'callback'  => [ $this, 'set_chosen' ],
		] );
	}

	/** @param array<string, mixed> $data The extension's own `data` payload. */
	public function set_chosen( $data ): void {
		if ( ! WC()->session ) {
			return;
		}

		$chosen = is_array( $data ) && ! empty( $data['express'] ) && self::is_enabled();
		WC()->session->set( self::SESSION_KEY, $chosen ? 1 : null );
	}

	public function enqueue(): void {
		if ( ! is_checkout() || is_wc_endpoint_url() || ! self::is_enabled() ) {
			return;
		}

		$path = 'assets/blocks/express-checkout.js';
		wp_enqueue_script(
			'yeffoprint-express-checkout',
			YEFFOPRINT_CORE_URL . $path,
			[ 'wp-data', 'wc-blocks-checkout' ],
			yeffoprint_core_asset_version( $path ),
			true
		);

		wp_localize_script( 'yeffoprint-express-checkout', 'yeffoprintExpressCheckout', [
			'namespace' => self::NAMESPACE,
			'chosen'    => self::is_chosen(),
			'html'      => self::option_html(),
		] );
	}

	/** Every substitution already escaped — the JS inserts this verbatim. Also rendered server-side on the "Pay for order" page (class-order-pay-address.php), with $input_attrs naming the checkbox so it posts with the payment form. */
	public static function option_html( string $input_attrs = '' ): string {
		$price = html_entity_decode( wp_strip_all_tags( wc_price( self::fee() ) ), ENT_QUOTES, 'UTF-8' );

		return sprintf(
			'<label class="yp-express-option"><input type="checkbox" class="yp-express-option__input"%5$s /><span class="yp-express-option__icon" aria-hidden="true">%1$s</span><span class="yp-express-option__text"><strong class="yp-express-option__title">%2$s</strong><span class="yp-express-option__body">%3$s</span></span><span class="yp-express-option__price">+%4$s</span></label>',
			'&#9889;',
			esc_html__( 'Express: skip the line', 'yeffoprint-core' ),
			esc_html__( 'Your order moves to the front of the production queue.', 'yeffoprint-core' ),
			esc_html( $price ),
			$input_attrs ? ' ' . $input_attrs : ''
		);
	}
}
