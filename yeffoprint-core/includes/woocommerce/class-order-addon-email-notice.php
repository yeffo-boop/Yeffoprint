<?php
/**
 * The emailed nudge from the "Ship it together" mockup — a card on the
 * order-confirmation family of emails inviting the customer to add
 * another order on before this one ships, with no extra shipping
 * charge. Same hook/gating idiom as class-away-mode-email-notice.php,
 * but cyan-tinted rather than amber (see email-styles.php) since this
 * is an upsell, not a heads-up. Only on customer_processing_order and
 * customer_on_hold_order — not customer_invoice, which can also fire
 * for an order that hasn't been paid yet, so there's nothing "processed
 * and shipping soon" to add on to.
 *
 * Reuses YeffoPrint_Order_Addon::eligibility() directly, same as every
 * other consumer of this feature — if $order is itself already an
 * add-on, this resolves straight to its root and still offers a link
 * there (up to MAX_ADDONS total), same behavior as the My Account
 * button (class-order-addon-account.php).
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Order_Addon_Email_Notice {

	/** @var string[] WC_Email ids this notice appears on. */
	private const EMAIL_IDS = [
		'customer_processing_order',
		'customer_on_hold_order',
	];

	public function __construct() {
		add_action( 'woocommerce_email_after_order_table', [ $this, 'render' ], 10, 4 );
	}

	public function render( \WC_Order $order, bool $sent_to_admin, bool $plain_text, \WC_Email $email ): void {
		if ( $sent_to_admin || ! in_array( $email->id, self::EMAIL_IDS, true ) ) {
			return;
		}

		$eligibility = class_exists( 'YeffoPrint_Order_Addon' ) ? YeffoPrint_Order_Addon::eligibility( $order ) : null;
		if ( ! $eligibility || ! $eligibility['eligible'] ) {
			return;
		}

		$root = $eligibility['root'];
		$url  = add_query_arg(
			[ 'order' => $root->get_id(), 'key' => $root->get_order_key() ],
			home_url( '/add-to-order/' )
		);

		if ( $plain_text ) {
			echo esc_html__( "Forgot something? Add it to this order and we'll ship it together — no extra shipping charge, as long as this order hasn't shipped yet:", 'yeffoprint-core' ) . ' ' . esc_url( $url ) . "\n\n";
			return;
		}

		printf(
			'<table class="yp-addon-email-notice" role="presentation" cellpadding="0" cellspacing="0" width="100%%"><tr><td>' .
				'<span class="yp-addon-email-notice-eyebrow">%1$s</span>' .
				'<p class="yp-addon-email-notice-body">%2$s</p>' .
				'<p style="text-align:center;margin:16px 0 6px;"><a href="%3$s" class="yp-email-button">%4$s</a></p>' .
			'</td></tr></table>',
			esc_html__( 'Forgot something?', 'yeffoprint-core' ),
			esc_html__( "Add it to this order and we'll ship it together — no extra shipping charge, as long as this order hasn't shipped yet.", 'yeffoprint-core' ),
			esc_url( $url ),
			esc_html__( 'Add to this order', 'yeffoprint-core' )
		);
	}
}
