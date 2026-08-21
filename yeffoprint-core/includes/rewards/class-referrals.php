<?php
/**
 * Refer-a-friend: an existing customer gets a personal link
 * (?ref=CODE, class-account-endpoints.php's Rewards tab); a new
 * customer who signs up after visiting it is attributed to them, and
 * once that new customer's first order is actually paid, the referrer
 * earns a flat point bonus — reuses YeffoPrint_Rewards::adjust_balance()
 * (built for exactly this shape of "no per-dollar order to explain
 * it" balance change) rather than a second logging mechanism.
 *
 * Two decisions from that conversation, both deliberate:
 *  - Gated on the referred customer's first PAID order, not account
 *    creation alone — a signup is free and unlimited to fake; a paid
 *    order isn't.
 *  - Referrer-only reward — the new customer gets nothing beyond what
 *    anyone else gets, so there's no separate discount/coupon
 *    mechanism to build or reconcile against the surcharge/rewards-
 *    redemption fees already stacked at checkout.
 *
 * Attribution requires an account (same as earning/redeeming points
 * at all already does — "Create an account to start earning" per the
 * homepage's own rewards-promo.php) — a guest-checkout referral has no
 * user record for _yp_referred_by to live on, so it's never credited.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Referrals {

	public const CODE_META        = '_yp_referral_code';
	public const REFERRED_BY_META = '_yp_referred_by';
	public const REWARDED_META    = '_yp_referral_rewarded';

	private const COOKIE_NAME = 'yp_ref';
	private const COOKIE_DAYS = 30;

	public function __construct() {
		add_action( 'template_redirect', [ $this, 'maybe_capture_referral' ] );
		add_action( 'user_register', [ $this, 'attribute_new_user' ] );
		// Same three events, same reasoning, as class-rewards.php's own
		// finalize_order() — not every gateway this store uses fires
		// woocommerce_payment_complete (the manual/Venmo/Zelle gateways
		// move an order straight to "processing" instead).
		add_action( 'woocommerce_payment_complete', [ $this, 'maybe_reward_referrer' ] );
		add_action( 'woocommerce_order_status_processing', [ $this, 'maybe_reward_referrer' ] );
		add_action( 'woocommerce_order_status_completed', [ $this, 'maybe_reward_referrer' ] );
	}

	/** Admin-configurable (Dashboard → YeffoPrint → Settings). Default: 500 points (~$5 at the default redemption rate). */
	public static function points_per_referral(): int {
		return (int) get_option(
			YeffoPrint_Admin_Menu::REFERRAL_POINTS_OPTION,
			YeffoPrint_Admin_Menu::REFERRAL_POINTS_DEFAULT
		);
	}

	/** @return string A short code, generated once per customer and reused after. */
	public static function get_or_create_code( int $user_id ): string {
		$existing = (string) get_user_meta( $user_id, self::CODE_META, true );
		if ( '' !== $existing ) {
			return $existing;
		}

		do {
			$code = strtoupper( substr( wp_generate_password( 12, false, false ), 0, 7 ) );
		} while ( self::find_user_by_code( $code ) );

		update_user_meta( $user_id, self::CODE_META, $code );
		return $code;
	}

	public static function referral_link( int $user_id ): string {
		return add_query_arg( 'ref', self::get_or_create_code( $user_id ), home_url( '/' ) );
	}

	public static function referred_by( int $user_id ): int {
		return (int) get_user_meta( $user_id, self::REFERRED_BY_META, true );
	}

	/** @return int How many people have signed up using $user_id's link — joined, not necessarily purchased yet. */
	public static function count_referred( int $user_id ): int {
		$query = new \WP_User_Query( [
			'meta_key'   => self::REFERRED_BY_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- small, per-account query on the account's own Rewards tab, not a listing screen.
			'meta_value' => $user_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'fields'     => 'ID',
			'number'     => -1,
		] );

		return count( $query->get_results() );
	}

	/** @return int Of those, how many have gone on to place a paid order — i.e. actually earned $user_id points. */
	public static function count_rewarded_referrals( int $user_id ): int {
		$query = new \WP_User_Query( [
			'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- same reasoning as count_referred() above.
				[ 'key' => self::REFERRED_BY_META, 'value' => $user_id ],
				[ 'key' => self::REWARDED_META, 'value' => '1' ],
			],
			'fields' => 'ID',
			'number' => -1,
		] );

		return count( $query->get_results() );
	}

	private static function find_user_by_code( string $code ): int {
		if ( '' === $code ) {
			return 0;
		}

		$query = new \WP_User_Query( [
			'meta_key'   => self::CODE_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value' => $code, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'fields'     => 'ID',
			'number'     => 1,
		] );

		$results = $query->get_results();
		return $results ? (int) $results[0] : 0;
	}

	/**
	 * Just remembers the code for whenever this visitor eventually signs
	 * up (attribute_new_user() below is what actually validates it) —
	 * doing the real lookup on every single page view a referral link is
	 * clicked would be a query for the vast majority of visits that never
	 * convert to an account at all.
	 */
	public function maybe_capture_referral(): void {
		if ( is_admin() || headers_sent() ) {
			return;
		}

		$code = isset( $_GET['ref'] ) ? sanitize_text_field( wp_unslash( $_GET['ref'] ) ) : '';
		if ( '' === $code ) {
			return;
		}

		setcookie(
			self::COOKIE_NAME,
			$code,
			time() + self::COOKIE_DAYS * DAY_IN_SECONDS,
			COOKIEPATH ? COOKIEPATH : '/',
			COOKIE_DOMAIN,
			is_ssl(),
			true
		);
	}

	public function attribute_new_user( int $user_id ): void {
		$code = isset( $_COOKIE[ self::COOKIE_NAME ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) ) : '';
		if ( '' === $code ) {
			return;
		}

		$referrer_id = self::find_user_by_code( $code );
		// The self-referral check is defensive rather than a real-world
		// case (the new account doesn't exist yet at the point the
		// cookie was set, so it can't be its own referrer) — cheap
		// enough to guard anyway rather than assume that always holds.
		if ( ! $referrer_id || $referrer_id === $user_id ) {
			return;
		}

		update_user_meta( $user_id, self::REFERRED_BY_META, $referrer_id );
	}

	public function maybe_reward_referrer( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$user_id = $order->get_customer_id();
		if ( ! $user_id || get_user_meta( $user_id, self::REWARDED_META, true ) ) {
			return; // Guest order, or this customer's one-time referral bonus already paid out.
		}

		$referrer_id = self::referred_by( $user_id );
		if ( ! $referrer_id ) {
			return; // Not a referred customer.
		}

		$points = self::points_per_referral();
		if ( $points <= 0 ) {
			return;
		}

		// Set before adjust_balance(), not after — a referral bonus should
		// only ever be paid out once no matter how many times this order
		// re-fires across the three hooked events above.
		update_user_meta( $user_id, self::REWARDED_META, 1 );

		$referred_user = get_userdata( $user_id );

		YeffoPrint_Rewards::adjust_balance(
			$referrer_id,
			$points,
			sprintf(
				/* translators: %s: the referred customer's email */
				__( "Referral bonus — %s's first paid order", 'yeffoprint-core' ),
				$referred_user ? $referred_user->user_email : ( '#' . $user_id )
			),
			0 // System-triggered, no staff member behind it — class-rewards-admin.php's history table shows admin_id 0 as "System."
		);
	}
}
