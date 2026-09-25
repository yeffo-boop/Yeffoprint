<?php
/**
 * NOWPayments checkout option — accept USDT/USDC (and other coins
 * NOWPayments supports), and auto-move an order to Processing once the
 * payment is actually verified. Replaces the old Coinbase Commerce
 * gateway, whose Charges API Coinbase retired (its successor, Coinbase
 * Business, only takes USDC on Base).
 *
 * Unlike the Venmo/Zelle "manual" gateways (park on-hold, trust an
 * outside automation to report a match), this one calls NOWPayments' own
 * API to create a real Invoice and sends the customer to NOWPayments'
 * hosted invoice page to pick a coin/chain and pay — this plugin never
 * touches a wallet address or a blockchain directly. The order sits
 * on-hold in the meantime; class-nowpayments-webhook-controller.php is
 * what verifies the payment (via NOWPayments' signed IPN, not a
 * self-reported one) and calls payment_complete(), the same WooCommerce
 * API every other automated gateway on this site already uses to reach
 * Processing.
 *
 * Configured the same way Venmo/Zelle are — WooCommerce's own native
 * Settings → Payments → NOWPayments screen.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_NOWPayments_Gateway extends \WC_Payment_Gateway {

	public const ID = 'yeffoprint_nowpayments';

	public function __construct() {
		$this->id                 = self::ID;
		$this->icon                = '';
		$this->has_fields          = false;
		$this->method_title        = __( 'NOWPayments (Crypto)', 'yeffoprint-core' );
		$this->method_description  = __( 'Accept USDT, USDC, and other crypto via NOWPayments. The customer is sent to NOWPayments\' hosted invoice page to pick a coin and network and pay; the order moves to Processing automatically once NOWPayments confirms the payment — see the "IPN" section below to finish setup.', 'yeffoprint-core' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled      = $this->get_option( 'enabled' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
	}

	public function init_form_fields(): void {
		$this->form_fields = [
			'enabled' => [
				'title'   => __( 'Enable/Disable', 'yeffoprint-core' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable NOWPayments', 'yeffoprint-core' ),
				'default' => 'no',
			],
			'title' => [
				'title'       => __( 'Title', 'yeffoprint-core' ),
				'type'        => 'text',
				'description' => __( 'Shown to the customer at checkout.', 'yeffoprint-core' ),
				'default'     => __( 'Pay with Crypto (USDT, USDC)', 'yeffoprint-core' ),
				'desc_tip'    => true,
			],
			'description' => [
				'title'       => __( 'Description', 'yeffoprint-core' ),
				'type'        => 'textarea',
				'description' => __( 'Shown to the customer at checkout, under the title.', 'yeffoprint-core' ),
				'default'     => __( 'You\'ll be sent to a secure NOWPayments page to pay with USDT, USDC, or another supported coin on the network of your choice.', 'yeffoprint-core' ),
			],
			'api_key' => [
				'title'       => __( 'API Key', 'yeffoprint-core' ),
				'type'        => 'password',
				'description' => __( 'From NOWPayments → Settings → Payments → API keys.', 'yeffoprint-core' ),
				'default'     => '',
				'desc_tip'    => true,
			],
			'ipn_secret' => [
				'title'       => __( 'IPN Secret Key', 'yeffoprint-core' ),
				'type'        => 'password',
				'description' => __( 'From NOWPayments → Settings → Payments → Instant payment notifications.', 'yeffoprint-core' ),
				'default'     => '',
				'desc_tip'    => true,
			],
			'ipn_info' => [
				'title'       => __( 'IPN (payment confirmations)', 'yeffoprint-core' ),
				'type'        => 'title',
				'description' => sprintf(
					/* translators: %s: the IPN callback URL */
					__( 'Each invoice tells NOWPayments to post payment updates to this URL automatically — nothing to paste. Just generate the IPN secret key in NOWPayments → Settings → Payments → Instant payment notifications and copy it into the field above. Also add your USDT/USDC payout wallet(s) under NOWPayments → Settings → Payments → Payment details.<br /><code>%s</code>', 'yeffoprint-core' ),
					esc_url( self::ipn_url() )
				),
			],
		];
	}

	public static function ipn_url(): string {
		return rest_url( 'yeffoprint-core/v1/nowpayments/ipn' );
	}

	/** @return string The API key configured above. */
	public static function api_key(): string {
		return trim( (string) ( get_option( 'woocommerce_' . self::ID . '_settings', [] )['api_key'] ?? '' ) );
	}

	/** @return string Read by class-nowpayments-webhook-controller.php, which has no gateway instance of its own to call get_option() on. */
	public static function ipn_secret(): string {
		return trim( (string) ( get_option( 'woocommerce_' . self::ID . '_settings', [] )['ipn_secret'] ?? '' ) );
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		$client  = new YeffoPrint_NOWPayments_Client( self::api_key() );
		$invoice = $client->create_invoice( [
			'amount'      => number_format( (float) $order->get_total(), 2, '.', '' ),
			'currency'    => $order->get_currency(),
			'order_id'    => (string) $order->get_id(),
			'description' => sprintf(
				/* translators: 1: site name, 2: order number */
				__( '%1$s order #%2$s', 'yeffoprint-core' ),
				get_bloginfo( 'name' ),
				$order->get_order_number()
			),
			'ipn_url'     => self::ipn_url(),
			'success_url' => $this->get_return_url( $order ),
			'cancel_url'  => $order->get_cancel_order_url_raw(),
		] );

		if ( is_wp_error( $invoice ) || '' === $invoice['invoice_url'] ) {
			wc_add_notice(
				is_wp_error( $invoice )
					? $invoice->get_error_message()
					: __( 'NOWPayments didn\'t return a payment link. Please try again.', 'yeffoprint-core' ),
				'error'
			);
			return [ 'result' => 'failure' ];
		}

		// Same on-hold-until-verified shape as the Venmo/Zelle gateways
		// (class-manual-payment-gateway.php) — the difference is *what*
		// verifies it: an outside automation there, NOWPayments' own
		// signed IPN here. The invoice id is how the IPN finds this order
		// back again if its order_id is ever missing.
		$order->update_meta_data( '_yeffoprint_nowpayments_invoice_id', $invoice['id'] );
		$order->update_status( 'on-hold', __( 'Awaiting crypto payment confirmation via NOWPayments.', 'yeffoprint-core' ) );
		$order->save();

		wc_reduce_stock_levels( $order_id );

		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return [
			'result'   => 'success',
			'redirect' => $invoice['invoice_url'],
		];
	}
}
