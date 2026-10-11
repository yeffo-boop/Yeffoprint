<?php
/**
 * Swaps WooCommerce's own WC_Email_Customer_Completed_Order for
 * class-email-customer-completed-order.php's subclass, via the same
 * `woocommerce_email_classes` filter every WC_Email (core's own or a
 * plugin's) goes through — same pattern as class-order-shipped-email.php,
 * just replacing a stock class instead of registering a brand new one.
 *
 * Lazily require_once'd inside register_email() for the same reason as
 * that file: the subclass declaration needs both WC_Email and
 * WC_Email_Customer_Completed_Order already loaded, and
 * `woocommerce_email_classes` only ever fires from deep inside
 * WooCommerce's fully-booted email registry, so this can never fire too
 * early.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Order_Completed_Email {

	public function __construct() {
		add_filter( 'woocommerce_email_classes', [ $this, 'register_email' ] );
	}

	/**
	 * Direct report, two ways: "the customer received 2 separate emails,
	 * one saying it was on the way and one saying it was delivered, both
	 * sent at the same time." Root cause: `WC_Emails::init()` keys its
	 * own `$this->emails` array by CLASS NAME
	 * (`'WC_Email_Customer_Completed_Order'`), not by the semantic email
	 * ID (`'customer_completed_order'`) — that ID only ever lives on the
	 * instance's own `$this->id` property, set inside the class's own
	 * constructor, entirely independent of whatever array key it's
	 * stored under. This method registered the override under
	 * `'customer_completed_order'` instead — a brand-new array entry,
	 * not a replacement — leaving WooCommerce's own stock
	 * `WC_Email_Customer_Completed_Order` instance (the generic "on its
	 * way!" copy) still sitting untouched under its real key. Both
	 * instances independently hook themselves to the exact same
	 * `woocommerce_order_status_completed_notification` action inside
	 * their shared parent constructor, so every single order completion
	 * fired both: the stock class's default copy and this override's
	 * corrected "delivered!" copy, back to back, to the same customer.
	 * Confirmed by checking recent auto-completed orders' notes: every
	 * one shows two identical "Email 'Completed order' sent." lines
	 * (both classes' `$this->id`/title are the same "customer_completed_
	 * order"/"Completed order", inherited unchanged from the stock
	 * parent) immediately before the delivery/status-change note.
	 *
	 * Fixed by keying this under the real class name, matching
	 * `WC_Emails::init()`'s own convention — this now genuinely replaces
	 * the stock instance instead of shadowing it with a second one.
	 * `class-order-shipped-email.php`'s own `'customer_shipped_order'`
	 * key was never wrong the same way: "Shipped" isn't a stock
	 * WooCommerce email at all, so there was no existing class-name-keyed
	 * entry for it to (fail to) replace in the first place.
	 */
	public function register_email( array $email_classes ): array {
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-email-customer-completed-order.php';

		self::unhook_stock_email( $email_classes['WC_Email_Customer_Completed_Order'] ?? null );
		$email_classes['WC_Email_Customer_Completed_Order'] = new YeffoPrint_Email_Customer_Completed_Order();
		return $email_classes;
	}

	/**
	 * Keying by class name alone was not enough: customers still got two
	 * identical emails ("Completed order" and "Processing order" both
	 * logged "sent." twice on every order, e.g. YP-532, YP-595). WC_Emails
	 * ::init() constructs the stock instance BEFORE it applies
	 * `woocommerce_email_classes`, and that constructor has already hooked
	 * its trigger() to the order status actions. Swapping the array entry
	 * drops the stock object from the registry but not from those hooks,
	 * so it kept firing next to the override. This removes every hook
	 * still pointing at the stock instance so only the override sends.
	 */
	public static function unhook_stock_email( $stock ): void {
		global $wp_filter;
		if ( ! is_object( $stock ) || empty( $wp_filter ) ) {
			return;
		}
		foreach ( $wp_filter as $hook_name => $hook ) {
			if ( ! $hook instanceof \WP_Hook ) {
				continue;
			}
			foreach ( $hook->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$fn = $callback['function'];
					if ( is_array( $fn ) && isset( $fn[0] ) && $fn[0] === $stock ) {
						remove_filter( $hook_name, $fn, $priority );
					}
				}
			}
		}
	}
}
