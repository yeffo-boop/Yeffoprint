<?php
/**
 * Coinbase Commerce checkout option — direct request: accept BTC/USDC/
 * USDT, and auto-move an order to Processing once the payment is
 * actually verified. Unlike the Venmo/Zelle "manual" gateways (park on-
 * hold, trust an outside automation to report a match), this one calls
 * Coinbase's own API to create a real Charge and sends the customer to
 * Coinbase's own hosted checkout page to actually pay and pick which
 * currency/chain to use — this plugin never touches a wallet address or
 * a blockchain directly. The order still sits on-hold in the meantime;
 * class-coinbase-webhook-controller.php is what verifies the payment
 * (via Coinbase's signed webhook, not a self-reported one) and calls
 * payment_complete(), the same WooCommerce API every other automated
 * gateway on this site already uses to reach Processing.
 *
 * Configured the same way Venmo/Zelle are — WooCommerce's own native
 * Settings → Payments → Coinbase Commerce screen — not the plugin's own
 * custom admin app Settings page, since (unlike Shippo, which isn't a
 * checkout gateway at all) this is a real WC_Payment_Gateway with its
 * own settings storage already built for exactly this.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Coinbase_Gateway extends \WC_Payment_Gateway {

	public function __construct() {
		$this->id                 = 'yeffoprint_coinbase';
		$this->icon                = '';
		$this->has_fields          = false;
		$this->method_title        = __( 'Coinbase Commerce', 'yeffoprint-core' );
		$this->method_description  = __( 'Accept Bitcoin, USDC, and USDT via Coinbase Commerce. The customer is sent to Coinbase\'s own hosted checkout to pay; the order moves to Processing automatically once Coinbase confirms the payment — see the "Webhook" section below to finish setup.', 'yeffoprint-core' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled      = $this->get_option( 'enabled' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
	}

	public function init_form_fields(): void {
		$webhook_url = rest_url( 'yeffoprint-core/v1/coinbase/webhook' );

		$this->form_fields = [
			'enabled' => [
				'title'   => __( 'Enable/Disable', 'yeffoprint-core' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Coinbase Commerce', 'yeffoprint-core' ),
				'default' => 'no',
			],
			'title' => [
				'title'       => __( 'Title', 'yeffoprint-core' ),
				'type'        => 'text',
				'description' => __( 'Shown to the customer at checkout.', 'yeffoprint-core' ),
				'default'     => __( 'Pay with Crypto (BTC, USDC, USDT)', 'yeffoprint-core' ),
				'desc_tip'    => true,
			],
			'description' => [
				'title'       => __( 'Description', 'yeffoprint-core' ),
				'type'        => 'textarea',
				'description' => __( 'Shown to the customer at checkout, under the title.', 'yeffoprint-core' ),
				'default'     => __( 'You\'ll be sent to Coinbase to complete payment with Bitcoin, USDC, USDT, or another supported currency.', 'yeffoprint-core' ),
			],
			'api_key' => [
				'title'       => __( 'API Key', 'yeffoprint-core' ),
				'type'        => 'password',
				'description' => __( 'From Coinbase Commerce → Settings → Security → API keys.', 'yeffoprint-core' ),
				'default'     => '',
				'desc_tip'    => true,
			],
			'webhook_secret' => [
				'title'       => __( 'Webhook Shared Secret', 'yeffoprint-core' ),
				'type'        => 'password',
				'description' => __( 'From Coinbase Commerce → Settings → Notifications, after adding the webhook endpoint below.', 'yeffoprint-core' ),
				'default'     => '',
				'desc_tip'    => true,
			],
			'webhook_info' => [
				'title'       => __( 'Webhook endpoint', 'yeffoprint-core' ),
				'type'        => 'title',
				/* translators: %s: the webhook URL to paste into Coinbase Commerce */
				'description' => sprintf(
					__( 'In Coinbase Commerce → Settings → Notifications, add a webhook endpoint pointed at this URL, then copy the "Shared Secret" it shows you into the field above:<br /><code>%s</code>', 'yeffoprint-core' ),
					esc_url( $webhook_url )
				),
			],
		];
	}

	/** @return string The API key configured above — read by class-coinbase-webhook-controller.php too, which has no gateway instance of its own to call get_option() on. */
	public static function api_key(): string {
		return (string) ( get_option( 'woocommerce_yeffoprint_coinbase_settings', [] )['api_key'] ?? '' );
	}

	/** @return string @see api_key() — same reasoning, for signature verification. */
	public static function webhook_secret(): string {
		return (string) ( get_option( 'woocommerce_yeffoprint_coinbase_settings', [] )['webhook_secret'] ?? '' );
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		$client = new YeffoPrint_Coinbase_Commerce_Client( self::api_key() );
		$charge = $client->create_charge( [
			'name'         => sprintf( /* translators: %s: order number */ __( 'Order #%s', 'yeffoprint-core' ), $order->get_order_number() ),
			'description'  => sprintf( /* translators: %s: site name */ __( '%s order payment', 'yeffoprint-core' ), get_bloginfo( 'name' ) ),
			'amount'       => number_format( (float) $order->get_total(), 2, '.', '' ),
			'currency'     => $order->get_currency(),
			'order_id'     => (string) $order->get_id(),
			'redirect_url' => $this->get_return_url( $order ),
			'cancel_url'   => $order->get_cancel_order_url_raw(),
		] );

		if ( is_wp_error( $charge ) || '' === $charge['hosted_url'] ) {
			wc_add_notice(
				is_wp_error( $charge )
					? $charge->get_error_message()
					: __( 'Coinbase Commerce didn\'t return a checkout link. Please try again.', 'yeffoprint-core' ),
				'error'
			);
			return [ 'result' => 'failure' ];
		}

		// Same on-hold-until-verified shape as the Venmo/Zelle gateways
		// (class-manual-payment-gateway.php) — the difference is *what*
		// verifies it: an outside automation there, Coinbase's own signed
		// webhook here. The charge code is how the webhook finds this
		// order back again (metadata.order_id is the primary lookup;
		// this is the fallback if that's ever missing).
		$order->update_meta_data( '_yeffoprint_coinbase_charge_code', $charge['code'] );
		$order->update_status( 'on-hold', __( 'Awaiting crypto payment confirmation via Coinbase Commerce.', 'yeffoprint-core' ) );
		$order->save();

		wc_reduce_stock_levels( $order_id );

		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return [
			'result'   => 'success',
			'redirect' => $charge['hosted_url'],
		];
	}
}
