<?php
/**
 * Attaches guest orders to the customer account that owns the billing
 * email. Direct bug report: YP-656 was missing from a customer's My
 * Account orders page. They already had an account (and an earlier
 * order on it), but placed YP-656 without signing in, and WooCommerce
 * saves a signed-out checkout as a guest order (customer_id 0) even
 * when the email belongs to an account.
 *
 * Three places link them:
 * - Checkout: a signed-out order whose billing email matches a
 *   confirmed account is attached before payment, so rewards points
 *   land on that account too.
 * - My Account: opening any account page (or confirming the email)
 *   picks up older guest orders placed with that email.
 * - Admin app: "Link to account" in the order window, for anything
 *   the two above can't reach.
 *
 * The automatic paths only use an account whose current email is one
 * its owner has proven, so nobody can collect someone else's orders by
 * signing up with, or switching their account to, that person's
 * address. Accounts waiting on email confirmation and accounts whose
 * email was changed since are skipped (a password reset or social
 * sign-in proves the new one), as are staff accounts.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Guest_Order_Linking {

	/** The email this account last proved it owns; CHANGED once the account email is edited after that. */
	private const PROVEN_EMAIL_META = '_yp_order_link_email';
	private const CHANGED           = 'changed';

	public function __construct() {
		add_action( 'woocommerce_checkout_order_processed', [ $this, 'link_new_order' ], 5 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'link_new_order' ], 5 );
		// Before YeffoPrint_Rewards::finalize_order() (priority 10), for orders that reach paid some other way.
		add_action( 'woocommerce_order_status_processing', [ $this, 'link_new_order' ], 5 );
		// Before the orders list (or any My Account page) queries this customer's orders.
		add_action( 'woocommerce_account_content', [ $this, 'link_for_current_user' ], 1 );
		add_action( 'yeffoprint_email_verified', [ $this, 'on_email_verified' ] );
		add_action( 'profile_update', [ $this, 'on_profile_update' ], 10, 2 );
	}

	public function on_email_verified( int $user_id ): void {
		$user = get_userdata( $user_id );
		if ( $user ) {
			update_user_meta( $user_id, self::PROVEN_EMAIL_META, strtolower( $user->user_email ) );
			$this->link_past_orders( $user_id );
		}
	}

	/** @param \WP_User $old_user_data */
	public function on_profile_update( $user_id, $old_user_data ): void {
		$user = get_userdata( (int) $user_id );
		if ( $user && $old_user_data instanceof \WP_User && strtolower( $user->user_email ) !== strtolower( $old_user_data->user_email ) ) {
			update_user_meta( $user->ID, self::PROVEN_EMAIL_META, self::CHANGED );
		}
	}

	/** @param int|\WC_Order $order */
	public function link_new_order( $order ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order );
		if ( ! $order instanceof \WC_Order || $order->get_customer_id() ) {
			return;
		}

		$user = self::matching_account( $order );
		if ( $user ) {
			self::link( $order, $user->ID, __( 'Placed signed out; linked to the customer account with this email.', 'yeffoprint-core' ) );
		}
	}

	public function link_for_current_user(): void {
		if ( is_user_logged_in() ) {
			$this->link_past_orders( get_current_user_id() );
		}
	}

	public function link_past_orders( int $user_id ): void {
		$user = get_userdata( $user_id );
		if ( ! $user || ! self::can_auto_link( $user ) ) {
			return;
		}

		$orders = wc_get_orders( [
			'billing_email' => $user->user_email,
			'type'          => 'shop_order',
			'status'        => 'any',
			'limit'         => 50,
		] );

		foreach ( $orders as $order ) {
			if ( ! $order->get_customer_id() ) {
				self::link( $order, $user_id, __( 'Guest order linked to the customer account with this email.', 'yeffoprint-core' ) );
			}
		}
	}

	/** The account a guest order's billing email belongs to, if it's one the automatic paths may use. */
	public static function matching_account( \WC_Order $order ): ?\WP_User {
		$user = self::account_for_email( $order->get_billing_email() );
		return $user && self::can_auto_link( $user ) ? $user : null;
	}

	/** Any account with this email, for the admin app's manual link. */
	public static function account_for_email( string $email ): ?\WP_User {
		$email = sanitize_email( $email );
		if ( '' === $email ) {
			return null;
		}

		$user = get_user_by( 'email', $email );
		return $user ?: null;
	}

	/**
	 * Sets the order's customer and credits the rewards points a guest
	 * order skipped (YeffoPrint_Rewards::finalize_order() marks a paid
	 * guest order processed with nothing earned).
	 */
	public static function link( \WC_Order $order, int $user_id, string $note ): void {
		$order->set_customer_id( $user_id );
		$order->save();
		$order->add_order_note( $note );

		if ( $order->get_meta( YeffoPrint_Rewards::ORDER_PROCESSED_META ) && '' === $order->get_meta( YeffoPrint_Rewards::ORDER_POINTS_EARNED_META ) ) {
			YeffoPrint_Rewards::credit_linked_order( $order );
		}
	}

	private static function can_auto_link( \WP_User $user ): bool {
		if ( YeffoPrint_Email_Verification::is_unverified( $user->ID ) || user_can( $user, 'manage_woocommerce' ) ) {
			return false;
		}

		$proven = (string) get_user_meta( $user->ID, self::PROVEN_EMAIL_META, true );
		if ( '' === $proven ) {
			// Confirmed before this class existed and not changed since: its email is the one it confirmed.
			$proven = strtolower( $user->user_email );
			update_user_meta( $user->ID, self::PROVEN_EMAIL_META, $proven );
		}

		return strtolower( $user->user_email ) === $proven;
	}
}
