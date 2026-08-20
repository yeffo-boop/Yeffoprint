<?php
/**
 * Shared base for "pay me directly, I'll confirm it myself" gateways
 * (Venmo, Zelle) — direct request: accept these at checkout, put the
 * order on hold until the payment is actually confirmed, then move it
 * to processing automatically once matched (class-payment-webhook-
 * controller.php).
 *
 * Same shape WooCommerce's own core BACS/Cheque gateways use: no real
 * payment processing happens here at all — process_payment() just
 * parks the order on hold and shows the customer where to actually
 * send the money. The two subclasses (class-venmo-gateway.php,
 * class-zelle-gateway.php) only differ in id/title/default copy.
 */

defined( 'ABSPATH' ) || exit;

abstract class YeffoPrint_Manual_Payment_Gateway extends \WC_Payment_Gateway {

	/** @return string 'venmo' or 'zelle' — matches the webhook payload's own `method` value (class-payment-webhook-controller.php). */
	abstract protected function method_slug(): string;

	/** @return string Label for the "send to" field, e.g. "Venmo username" or "Zelle email or phone". */
	abstract protected function handle_field_label(): string;

	public function __construct() {
		$this->has_fields = false;

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled      = $this->get_option( 'enabled' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
		add_action( 'woocommerce_thankyou_' . $this->id, [ $this, 'thankyou_page' ] );
		add_action( 'woocommerce_email_before_order_table', [ $this, 'email_instructions' ], 10, 3 );
	}

	public function init_form_fields(): void {
		$webhook_url = YeffoPrint_Payment_Webhook_Secret::webhook_url( $this->method_slug() );

		$this->form_fields = [
			'enabled' => [
				'title'   => __( 'Enable/Disable', 'yeffoprint-core' ),
				'type'    => 'checkbox',
				'label'   => sprintf( /* translators: %s: gateway title */ __( 'Enable %s', 'yeffoprint-core' ), $this->method_title ),
				'default' => 'no',
			],
			'title' => [
				'title'       => __( 'Title', 'yeffoprint-core' ),
				'type'        => 'text',
				'description' => __( 'Shown to the customer at checkout.', 'yeffoprint-core' ),
				'default'     => $this->method_title,
				'desc_tip'    => true,
			],
			'description' => [
				'title'       => __( 'Description', 'yeffoprint-core' ),
				'type'        => 'textarea',
				'description' => __( 'Shown to the customer at checkout, under the title.', 'yeffoprint-core' ),
				'default'     => $this->default_description(),
			],
			'handle' => [
				'title'       => $this->handle_field_label(),
				'type'        => 'text',
				'description' => __( 'Shown to the customer after checkout, and in their order confirmation email, as where to actually send the payment.', 'yeffoprint-core' ),
				'default'     => '',
				'desc_tip'    => true,
			],
			'webhook_info' => [
				'title'       => __( 'Automatic matching', 'yeffoprint-core' ),
				'type'        => 'title',
				/* translators: %s: the ready-to-use webhook URL, with the site's secret token already included */
				'description' => sprintf(
					__( 'Orders paid this way go "On hold" until the payment is matched automatically. Point your email-forwarding automation (see the setup guide) at this URL — it already includes this site\'s secret token, so nothing else needs configuring here:<br /><code>%s</code><br />Treat this URL as a secret — anyone with it could report fake payments. If it ever leaks, regenerating the token (via WP-CLI or by asking your developer) invalidates it immediately.', 'yeffoprint-core' ),
					esc_url( $webhook_url )
				),
			],
		];
	}

	abstract protected function default_description(): string;

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		$order->update_status( 'on-hold', __( 'Awaiting payment confirmation.', 'yeffoprint-core' ) );
		wc_reduce_stock_levels( $order_id );

		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return [
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		];
	}

	private function instructions_text( \WC_Order $order ): string {
		$handle = $this->get_option( 'handle' );

		return sprintf(
			/* translators: 1: amount owed, 2: Venmo/Zelle handle, 3: order number */
			__( 'Please send %1$s to %2$s. Include your order number, %3$s, in the payment note so it\'s matched automatically — otherwise it may take us longer to confirm.', 'yeffoprint-core' ),
			wc_price( $order->get_total() ),
			$handle ? '<strong>' . esc_html( $handle ) . '</strong>' : __( 'the account shown at checkout', 'yeffoprint-core' ),
			'#' . $order->get_order_number()
		);
	}

	public function thankyou_page( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_payment_method() !== $this->id ) {
			return;
		}

		echo '<div class="woocommerce-message woocommerce-message--info" style="margin-top:1.5rem;">' . wp_kses_post( $this->instructions_text( $order ) ) . '</div>';
	}

	public function email_instructions( $order, $sent_to_admin, $plain_text = false ): void {
		if ( $sent_to_admin || ! $order instanceof \WC_Order || $order->get_payment_method() !== $this->id || ! $order->has_status( 'on-hold' ) ) {
			return;
		}

		if ( $plain_text ) {
			echo wp_strip_all_tags( $this->instructions_text( $order ) ) . "\n\n";
		} else {
			// Styled by the theme's email-styles.php override (.yp-email-callout)
			// as a highlighted box — this is the one thing in an on-hold order
			// email a customer actually needs to act on, so it shouldn't read
			// as just another paragraph.
			echo '<table class="yp-email-callout" role="presentation" cellpadding="0" cellspacing="0" width="100%"><tr><td>';
			echo '<span class="yp-email-callout-label">' . esc_html__( 'Payment instructions', 'yeffoprint-core' ) . '</span>';
			echo '<p>' . wp_kses_post( $this->instructions_text( $order ) ) . '</p>';
			echo '</td></tr></table>';
		}
	}
}
