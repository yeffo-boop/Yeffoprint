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

	/**
	 * The classic "Pay for order" page (checkout/form-pay.php) — a
	 * declined-payment retry link or an admin-created order's customer
	 * payment link — is the one place a customer picks this gateway
	 * without ever seeing the fuller instructions (they only otherwise
	 * show up after checkout, on the thank-you page and in the on-hold
	 * email — see thankyou_page()/email_instructions() below). It's not
	 * a gap on the real Checkout page: that one is block-based, and
	 * shows its own copy of the "send payment to" handle through
	 * venmo-payment-method.js/zelle-payment-method.js (class-manual-
	 * payment-blocks-support.php) instead of this server-rendered
	 * payment_fields() output, which the block Checkout never calls.
	 * The Pay for Order page always renders the classic (non-block)
	 * template, though, and an order already exists there — so this can
	 * show the same exact-amount/handle/order-number instructions (plus
	 * the pay button/QR) the thank-you page shows, rather than just the
	 * generic admin-written description.
	 */
	public function payment_fields() {
		parent::payment_fields();

		if ( ! function_exists( 'is_checkout_pay_page' ) || ! is_checkout_pay_page() ) {
			return;
		}

		$order_id = absint( get_query_var( 'order-pay' ) );
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		echo '<div class="yp-blocks-payment-handle">' . wp_kses_post( $this->instructions_text( $order ) ) . '</div>';
		echo wp_kses_post( $this->payment_action_html( 'button' ) );
	}

	/**
	 * @return string|null A link the customer can tap/click to open this
	 *   method's own app and pay directly — null when there isn't a
	 *   reliable universal one. Zelle never overrides this: unlike
	 *   Venmo, there's no cross-bank Zelle payment URL — Zelle lives
	 *   inside each customer's own bank app, with no shared link format
	 *   across banks — so making one up would just be a broken button.
	 */
	protected function pay_url(): ?string {
		return null;
	}

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

		echo '<div class="woocommerce-message woocommerce-message--info" style="margin-top:1.5rem;">';
		echo wp_kses_post( $this->instructions_text( $order ) );
		// "button" — the same classic-WooCommerce button class the rest
		// of this site's own woocommerce.css already themes (`.woocommerce
		// a.button`), so this needs no page-specific CSS of its own.
		echo wp_kses_post( $this->payment_action_html( 'button' ) );
		echo '</div>';
	}

	public function email_instructions( $order, $sent_to_admin, $plain_text = false ): void {
		if ( $sent_to_admin || ! $order instanceof \WC_Order || $order->get_payment_method() !== $this->id || ! $order->has_status( 'on-hold' ) ) {
			return;
		}

		if ( $plain_text ) {
			echo wp_strip_all_tags( $this->instructions_text( $order ) ) . "\n\n";
			echo $this->payment_action_plain(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text email body (a URL built from esc_url()-safe input), not HTML.
		} else {
			// Styled by the theme's email-styles.php override (.yp-email-callout)
			// as a highlighted box — this is the one thing in an on-hold order
			// email a customer actually needs to act on, so it shouldn't read
			// as just another paragraph.
			echo '<table class="yp-email-callout" role="presentation" cellpadding="0" cellspacing="0" width="100%"><tr><td>';
			echo '<span class="yp-email-callout-label">' . esc_html__( 'Payment instructions', 'yeffoprint-core' ) . '</span>';
			echo '<p>' . wp_kses_post( $this->instructions_text( $order ) ) . '</p>';
			echo wp_kses_post( $this->payment_action_html( 'yp-email-button' ) );
			echo '</td></tr></table>';
		}
	}

	/**
	 * A "Pay with Venmo" button plus a QR code pointed at the same link
	 * (scanning it just opens that link on the customer's phone) — direct
	 * request. Renders nothing for a gateway with no pay_url() (Zelle).
	 * Reuses the plugin's own public QR endpoint (class-qr-controller.php,
	 * already built for the label configurator) as a plain <img src>
	 * rather than embedding a generated image inline — the inline
	 * approach (a data: URI) is unreliable in email specifically: several
	 * major email clients (Outlook desktop among them) don't render
	 * data: URIs in HTML email at all, while a normal external image URL
	 * works the same way any other image in a marketing email does.
	 *
	 * @param string $button_class CSS class for the link — the site's
	 *   own classic-WooCommerce button class on the thank-you page,
	 *   email-styles.php's own button class in the order email; the two
	 *   contexts are styled completely differently, so this can't be a
	 *   single hardcoded class.
	 */
	private function payment_action_html( string $button_class ): string {
		$url = $this->pay_url();
		if ( ! $url ) {
			return '';
		}

		$qr_url = add_query_arg(
			[ 'text' => rawurlencode( $url ), 'format' => 'png', 'module_px' => 8 ],
			rest_url( 'yeffoprint-core/v1/qr' )
		);

		return sprintf(
			'<p style="margin-top:14px;margin-bottom:6px;"><a class="%1$s" href="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a></p>' .
			'<img class="yp-payment-qr" src="%4$s" alt="%5$s" width="140" height="140" />' .
			'<p class="yp-payment-qr-caption">%6$s</p>',
			esc_attr( $button_class ),
			esc_url( $url ),
			/* translators: %s: gateway title, e.g. "Venmo" */
			esc_html( sprintf( __( 'Pay with %s', 'yeffoprint-core' ), $this->method_title ) ),
			esc_url( $qr_url ),
			/* translators: %s: gateway title, e.g. "Venmo" */
			esc_attr( sprintf( __( 'QR code to pay with %s', 'yeffoprint-core' ), $this->method_title ) ),
			/* translators: %s: gateway title, e.g. "Venmo" */
			esc_html( sprintf( __( 'Or scan to open %s on your phone', 'yeffoprint-core' ), $this->method_title ) )
		);
	}

	/** Plain-text-email counterpart to payment_action_html() above — just the raw link, no button/QR possible in plain text. */
	private function payment_action_plain(): string {
		$url = $this->pay_url();
		if ( ! $url ) {
			return '';
		}

		/* translators: %s: a Venmo pay link */
		return sprintf( __( 'Pay directly: %s', 'yeffoprint-core' ), $url ) . "\n\n";
	}
}
