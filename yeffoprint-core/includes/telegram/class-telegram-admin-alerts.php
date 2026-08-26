<?php
/**
 * Pushes real-time alerts to the store owner's own Telegram chat — a
 * new order (or custom design request) getting paid, or a Contact
 * form submission — as a faster companion to the existing email
 * notifications, not a replacement for them. Hooks the same stable
 * events those email paths already fire on (`woocommerce_payment_complete`,
 * the new `yeffoprint_contact_form_submitted` action on
 * class-contact-controller.php) rather than duplicating their trigger
 * logic.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Telegram_Admin_Alerts {

	public function __construct() {
		add_action( 'yeffoprint_contact_form_submitted', [ $this, 'on_contact_form_submitted' ], 10, 5 );
		add_action( 'woocommerce_payment_complete', [ $this, 'on_payment_complete' ] );
	}

	public function on_contact_form_submitted( string $name, string $email, string $method, string $handle, string $message ): void {
		self::notify( sprintf(
			/* translators: 1: sender name, 2: sender email, 3: message text */
			__( "New contact form message\n\nFrom: %1\$s <%2\$s>\n\n%3\$s", 'yeffoprint-core' ),
			$name,
			$email,
			$message
		) );
	}

	public function on_payment_complete( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$is_custom_design = false;
		$item_lines        = [];

		foreach ( $order->get_items() as $item ) {
			if ( $item->get_meta( '_yp_custom_order_id' ) ) {
				$is_custom_design = true;
			}
			$item_lines[] = sprintf( '• %1$s × %2$d', $item->get_name(), $item->get_quantity() );
		}

		$heading = $is_custom_design
			? sprintf(
				/* translators: 1: order number, 2: formatted order total */
				__( 'New custom design request paid: %1$s (%2$s)', 'yeffoprint-core' ),
				$order->get_order_number(),
				wp_strip_all_tags( $order->get_formatted_order_total() )
			)
			: sprintf(
				/* translators: 1: order number, 2: formatted order total */
				__( 'New order paid: %1$s (%2$s)', 'yeffoprint-core' ),
				$order->get_order_number(),
				wp_strip_all_tags( $order->get_formatted_order_total() )
			);

		$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

		self::notify( implode( "\n", array_merge( [ $heading, $name, '' ], $item_lines ) ) );
	}

	public static function notify( string $text ): void {
		$chat_id = (int) get_option( YeffoPrint_Admin_Menu::TELEGRAM_ADMIN_CHAT_ID_OPTION, 0 );
		$token   = YeffoPrint_Telegram_Settings::get_bot_token();

		if ( ! $chat_id || '' === $token || ! YeffoPrint_Telegram_Settings::is_enabled() ) {
			return;
		}

		( new YeffoPrint_Telegram_Client( $token ) )->send_message( $chat_id, $text );
	}
}
