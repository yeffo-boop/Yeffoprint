<?php
/**
 * YeffoBot notice on customer-facing order-lifecycle emails — a bigger
 * card with the homepage mascot and two explained ways to reach the
 * bot (Telegram + the on-site chat widget), positioned right after the
 * greeting rather than after the order table. Direct feedback across
 * two rounds: "make [it] more obvious and maybe include a fun robot
 * graphic themed to match the site" (mockup approved), then "I'd like
 * it somewhere closer to the top and it doesn't really explain what the
 * links do, just says telegram and web chat" — both addressed below.
 *
 * No inline SVG mascot, unlike the homepage promo (patterns/telegram-
 * bot-promo.php) — inline SVG isn't reliably supported across email
 * clients (Outlook desktop's rendering engine drops it entirely) — so
 * this uses a real rasterized PNG export of that exact same mascot
 * instead (assets/images/yeffobot-mascot.png).
 *
 * Hooked to woocommerce_email_before_order_table rather than the
 * _after_ hook the original version used — that fires from WC core's
 * own emails/email-order-details.php, right before the order-items
 * table opens, on every email below (already the hook class-manual-
 * payment-gateway.php's own payment-instructions callout uses), which
 * is exactly "right after the greeting" without this needing its own
 * new hook point in any of the four theme email templates.
 *
 * Shown on four emails now, not just the original order-confirmation —
 * direct approval, "Can you mockup the other email templates with this
 * change as well?" — each with its own headline tuned to that moment in
 * the lifecycle; the shipped-order variant also calls out that the bot
 * can look up live tracking status, direct follow-up feedback on the
 * mockup ("add something to the bot area that lets them know they can
 * track the order status with the bot").
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Telegram_Order_Email_Badge {

	/** @var array<string,string> WC_Email id => headline for this notice on that email. */
	private const HEADLINES = [
		'customer_processing_order' => 'Questions? YeffoBot’s got you.',
		'customer_shipped_order'    => 'Ask YeffoBot where it’s at',
		'customer_completed_order'  => 'Loved it? Reorder in seconds.',
		'customer_invoice'          => 'Questions before you pay?',
	];

	public function __construct() {
		add_action( 'woocommerce_email_before_order_table', [ $this, 'render' ], 10, 4 );
	}

	public function render( \WC_Order $order, bool $sent_to_admin, bool $plain_text, \WC_Email $email ): void {
		if ( $sent_to_admin || ! isset( self::HEADLINES[ $email->id ] ) ) {
			return;
		}

		$telegram_url = YeffoPrint_Telegram_Settings::public_url();
		if ( ! $telegram_url ) {
			return;
		}

		$chat_url    = add_query_arg( 'yp_chat', 'open', home_url( '/' ) );
		$headline    = self::HEADLINES[ $email->id ];
		$is_shipped  = 'customer_shipped_order' === $email->id;
		$site_host   = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		$telegram_sub = $is_shipped
			? __( 'Opens Telegram — get your tracking status instantly', 'yeffoprint-core' )
			: __( 'Opens Telegram — replies land right in the app', 'yeffoprint-core' );

		/* translators: %s: the site's own domain, e.g. yeffodesign.com */
		$chat_sub = $is_shipped
			? sprintf( __( 'Opens on %s — ask for a tracking update', 'yeffoprint-core' ), $site_host )
			: sprintf( __( 'Opens a chat window right on %s', 'yeffoprint-core' ), $site_host );

		if ( $plain_text ) {
			echo esc_html( $headline ) . "\n";
			if ( $is_shipped ) {
				echo esc_html__( 'YeffoBot can look up this order\'s live tracking status for you.', 'yeffoprint-core' ) . "\n";
			}
			echo esc_html__( 'Message YeffoBot on Telegram:', 'yeffoprint-core' ) . ' ' . esc_url( $telegram_url ) . "\n";
			echo esc_html__( 'Or start a live chat on our site:', 'yeffoprint-core' ) . ' ' . esc_url( $chat_url ) . "\n\n";
			return;
		}

		$mascot_url = get_theme_file_uri( 'assets/images/yeffobot-mascot.png' );

		printf(
			'<table class="yp-bot-callout" role="presentation" cellpadding="0" cellspacing="0" width="100%%"><tr><td>' .
				'<table role="presentation" cellpadding="0" cellspacing="0" class="yp-bot-callout-head"><tr>' .
					'<td width="46" valign="middle"><img src="%1$s" width="46" height="51" alt="" /></td>' .
					'<td valign="middle">' .
						'<span class="yp-bot-callout-eyebrow">%2$s</span>' .
						'<span class="yp-bot-callout-title">%3$s</span>' .
					'</td>' .
				'</tr></table>',
			esc_url( $mascot_url ),
			esc_html__( 'Say hi', 'yeffoprint-core' ),
			esc_html( $headline )
		);

		if ( $is_shipped ) {
			printf(
				'<p class="yp-bot-callout-intro">%s</p>',
				esc_html__( 'It can look up this order\'s live tracking status for you — no need to dig up the number below.', 'yeffoprint-core' )
			);
		}

		printf(
			'<table class="yp-bot-option" role="presentation" cellpadding="0" cellspacing="0" width="100%%"><tr><td>' .
				'<a href="%1$s">' .
					'<span class="yp-bot-option-title">%2$s</span>' .
					'<span class="yp-bot-option-sub">%3$s</span>' .
				'</a>' .
			'</td></tr></table>' .
			'<table class="yp-bot-option" role="presentation" cellpadding="0" cellspacing="0" width="100%%"><tr><td>' .
				'<a href="%4$s">' .
					'<span class="yp-bot-option-title">%5$s</span>' .
					'<span class="yp-bot-option-sub">%6$s</span>' .
				'</a>' .
			'</td></tr></table>' .
			'</td></tr></table>',
			esc_url( $telegram_url ),
			esc_html__( 'Message YeffoBot on Telegram', 'yeffoprint-core' ),
			esc_html( $telegram_sub ),
			esc_url( $chat_url ),
			esc_html__( 'Start live chat on our site', 'yeffoprint-core' ),
			esc_html( $chat_sub )
		);
	}
}
