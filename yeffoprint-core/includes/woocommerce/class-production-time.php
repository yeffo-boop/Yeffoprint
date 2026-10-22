<?php
/**
 * Live production time. Direct request: "Can we also mockup a 'live'
 * order wait time based on how many pending orders I have? A production
 * time estimate?" Then: "I don't want customers to see how many orders
 * ahead of them there are" and "I don't need the pill on the product
 * page".
 *
 * The wait is the paid orders still being made (Processing, In
 * Production, Curing) plus the customer's own, divided by how many orders
 * the shop finishes per business day, rounded up and never under the
 * minimum. Weekends and the closed dates in Settings don't count. Express
 * ships the next business day, and a holographic cart adds its cure day
 * (class-holographic-vinyl.php). Customers only ever see the resulting
 * ship date, never the order count.
 *
 * At checkout this shows as a card above Place Order, and each shipping
 * option's delivery line (class-live-shipping-rates.php) turns into real
 * arrival dates that include production. Nothing shows while Away Mode
 * is on, since that has its own notice.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Production_Time {

	public const ENABLED_OPTION       = 'yeffoprint_production_time_enabled';
	public const PER_DAY_OPTION       = 'yeffoprint_production_time_per_day';
	public const MIN_DAYS_OPTION      = 'yeffoprint_production_time_min_days';
	public const BUSY_DAYS_OPTION     = 'yeffoprint_production_time_busy_days';
	public const CLOSED_DATES_OPTION  = 'yeffoprint_production_time_closed_dates';

	/** Package key carrying the ship date, so WooCommerce's per-package rate cache picks up a new day, Express or holographic. */
	public const PACKAGE_KEY = 'yp_ship_date';

	private const DEFAULT_PER_DAY   = 4;
	private const DEFAULT_MIN_DAYS  = 1;
	private const DEFAULT_BUSY_DAYS = 4;

	private const COUNT_TRANSIENT = 'yp_production_open_orders';

	public function __construct() {
		add_filter( 'woocommerce_cart_shipping_packages', [ $this, 'tag_packages' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_checkout_card' ] );

		// A new paid order or a status change moves the queue.
		add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'forget_count' ] );
		add_action( 'woocommerce_new_order', [ __CLASS__, 'forget_count' ] );
	}

	public static function is_enabled(): bool {
		return (bool) get_option( self::ENABLED_OPTION, false ) && ! YeffoPrint_Admin_Menu::away_mode();
	}

	public static function per_day(): int {
		return max( 1, (int) get_option( self::PER_DAY_OPTION, self::DEFAULT_PER_DAY ) );
	}

	public static function min_days(): int {
		return max( 1, (int) get_option( self::MIN_DAYS_OPTION, self::DEFAULT_MIN_DAYS ) );
	}

	public static function busy_days(): int {
		return max( 1, (int) get_option( self::BUSY_DAYS_OPTION, self::DEFAULT_BUSY_DAYS ) );
	}

	/** @return string[] Y-m-d dates with no production, besides weekends. */
	public static function closed_dates(): array {
		$saved = get_option( self::CLOSED_DATES_OPTION, [] );
		return is_array( $saved ) ? array_values( $saved ) : [];
	}

	/** @return array<string, int> Paid orders still being made, per status. */
	public static function open_orders(): array {
		$cached = get_transient( self::COUNT_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$counts = [];
		foreach ( [ 'processing', YeffoPrint_Order_Production_Status::STATUS, YeffoPrint_Order_Curing_Status::STATUS ] as $status ) {
			$counts[ $status ] = count( wc_get_orders( [ 'type' => 'shop_order', 'status' => $status, 'limit' => -1, 'return' => 'ids' ] ) );
		}

		set_transient( self::COUNT_TRANSIENT, $counts, 10 * MINUTE_IN_SECONDS );
		return $counts;
	}

	public static function forget_count(): void {
		delete_transient( self::COUNT_TRANSIENT );
	}

	/** Business days until a new standard order ships: the queue plus this order, at per_day() a day. */
	public static function production_days(): int {
		$open = array_sum( self::open_orders() );
		return max( self::min_days(), (int) ceil( ( $open + 1 ) / self::per_day() ) );
	}

	/** Business days until a new order ships, for this cart's Express and holographic choices. */
	public static function days_for( bool $express, bool $holographic ): int {
		if ( $express ) {
			return 1;
		}
		return self::production_days() + ( $holographic ? 1 : 0 );
	}

	/** The date $days business days after $from (today when null), skipping weekends and closed dates. */
	public static function add_business_days( int $days, ?\DateTimeImmutable $from = null ): \DateTimeImmutable {
		$date   = ( $from ?? new \DateTimeImmutable( 'today', wp_timezone() ) )->setTime( 0, 0 );
		$closed = self::closed_dates();

		while ( $days > 0 ) {
			$date = $date->modify( '+1 day' );
			if ( (int) $date->format( 'N' ) < 6 && ! in_array( $date->format( 'Y-m-d' ), $closed, true ) ) {
				$days--;
			}
		}

		return $date;
	}

	public static function format_date( \DateTimeImmutable $date ): string {
		return wp_date( 'D, M j', $date->getTimestamp(), $date->getTimezone() );
	}

	/** Ship date for the current cart, or null when the estimate isn't shown. */
	public static function cart_ship_date(): ?\DateTimeImmutable {
		if ( ! self::is_enabled() ) {
			return null;
		}

		$express     = class_exists( 'YeffoPrint_Express_Order' ) && YeffoPrint_Express_Order::is_enabled() && YeffoPrint_Express_Order::is_chosen();
		$holographic = YeffoPrint_Holographic_Vinyl::cart_has_holographic();

		return self::add_business_days( self::days_for( $express, $holographic ) );
	}

	public function tag_packages( array $packages ): array {
		$ship_date = self::cart_ship_date();
		if ( ! $ship_date ) {
			return $packages;
		}

		foreach ( $packages as $i => $package ) {
			$packages[ $i ][ self::PACKAGE_KEY ] = $ship_date->format( 'Y-m-d' );
		}
		return $packages;
	}

	/**
	 * Delivery line for a shipping option whose transit time is $days
	 * business days ("2" or "2–5"), counted from the package's ship date.
	 * Null when there's no ship date, so the caller falls back to "after
	 * your order is made and shipped".
	 */
	public static function arrival_text( array $package, string $days ): ?string {
		$ship = (string) ( $package[ self::PACKAGE_KEY ] ?? '' );
		if ( '' === $ship ) {
			return null;
		}

		$range = array_map( 'intval', preg_split( '/[^0-9]+/', $days, -1, PREG_SPLIT_NO_EMPTY ) );
		if ( ! $range ) {
			return null;
		}

		$from  = new \DateTimeImmutable( $ship, wp_timezone() );
		$first = self::add_business_days( min( $range ), $from );
		$last  = self::add_business_days( max( $range ), $from );

		return $first == $last
			/* translators: %s: arrival date, e.g. Wed, Oct 28 */
			? sprintf( __( 'Arrives %s', 'yeffoprint-core' ), self::format_date( $first ) )
			/* translators: 1: earliest arrival date, 2: latest arrival date */
			: sprintf( __( 'Arrives %1$s – %2$s', 'yeffoprint-core' ), self::format_date( $first ), self::format_date( $last ) );
	}

	/** Settings screen payload, including the "Right now" numbers only Jeff sees. */
	public static function settings(): array {
		$open  = self::open_orders();
		$days  = self::production_days();
		return [
			'enabled'      => (bool) get_option( self::ENABLED_OPTION, false ),
			'per_day'      => self::per_day(),
			'min_days'     => self::min_days(),
			'busy_days'    => self::busy_days(),
			'closed_dates' => self::closed_dates(),
			'now'          => [
				'open'         => array_sum( $open ),
				'processing'   => $open['processing'] ?? 0,
				'production'   => $open[ YeffoPrint_Order_Production_Status::STATUS ] ?? 0,
				'curing'       => $open[ YeffoPrint_Order_Curing_Status::STATUS ] ?? 0,
				'days'         => $days,
				'ship_date'    => self::format_date( self::add_business_days( $days ) ),
				'express_date' => self::format_date( self::add_business_days( 1 ) ),
			],
		];
	}

	public static function save( array $params ): void {
		update_option( self::ENABLED_OPTION, (int) ! empty( $params['enabled'] ) );
		update_option( self::PER_DAY_OPTION, max( 1, (int) ( $params['per_day'] ?? self::DEFAULT_PER_DAY ) ) );
		update_option( self::MIN_DAYS_OPTION, max( 1, (int) ( $params['min_days'] ?? self::DEFAULT_MIN_DAYS ) ) );
		update_option( self::BUSY_DAYS_OPTION, max( 1, (int) ( $params['busy_days'] ?? self::DEFAULT_BUSY_DAYS ) ) );

		$dates = [];
		foreach ( (array) ( $params['closed_dates'] ?? [] ) as $date ) {
			$date = trim( (string) $date );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) && ! in_array( $date, $dates, true ) ) {
				$dates[] = $date;
			}
		}
		sort( $dates );
		update_option( self::CLOSED_DATES_OPTION, $dates );

		YeffoPrint_Local_Pickup::bust_rate_cache();
	}

	/** The card above Place Order (same insertion approach as class-holographic-vinyl.php). */
	public function enqueue_checkout_card(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url( 'order-received' ) || ! self::is_enabled() ) {
			return;
		}

		$days     = self::production_days();
		$ship     = self::add_business_days( $days );
		$busy     = $days >= self::busy_days();
		$express  = class_exists( 'YeffoPrint_Express_Order' ) && YeffoPrint_Express_Order::is_enabled();

		$title = 1 === $days
			? __( 'Ships in about 1 business day', 'yeffoprint-core' )
			/* translators: %d: number of business days */
			: sprintf( __( 'Ships in about %d business days', 'yeffoprint-core' ), $days );

		/* translators: %s: ship date, e.g. Mon, Oct 26 */
		$body = sprintf( __( 'Estimated ship date: %s.', 'yeffoprint-core' ), '<strong>' . esc_html( self::format_date( $ship ) ) . '</strong>' );
		if ( $express ) {
			/* translators: %s: Express ship date, e.g. Fri, Oct 23 */
			$body .= ' ' . sprintf( esc_html__( 'Need it sooner? Express ships %s.', 'yeffoprint-core' ), '<strong>' . esc_html( self::format_date( self::add_business_days( 1 ) ) ) . '</strong>' );
		}

		$path = 'assets/blocks/production-time-checkout.js';
		wp_enqueue_script(
			'yeffoprint-production-time-checkout',
			YEFFOPRINT_CORE_URL . $path,
			[ 'wc-blocks-checkout' ],
			yeffoprint_core_asset_version( $path ),
			true
		);

		wp_localize_script( 'yeffoprint-production-time-checkout', 'yeffoprintProductionTime', [
			'html' => sprintf(
				'<div class="yp-production-time%1$s"><span class="yp-production-time__dot" aria-hidden="true"></span><div><p class="yp-production-time__title">%2$s <span class="yp-production-time__live">%3$s</span></p><p class="yp-production-time__body">%4$s</p><p class="yp-production-time__small">%5$s</p></div></div>',
				$busy ? ' is-busy' : '',
				esc_html( $title ),
				esc_html( $busy ? __( 'Busy', 'yeffoprint-core' ) : __( 'Live', 'yeffoprint-core' ) ),
				$body,
				esc_html__( 'Arrival dates under each shipping option include production time. They are estimates, not guarantees.', 'yeffoprint-core' )
			),
		] );

		wp_register_style( 'yeffoprint-production-time', false, [], YEFFOPRINT_CORE_VERSION );
		wp_enqueue_style( 'yeffoprint-production-time' );
		wp_add_inline_style( 'yeffoprint-production-time', '
.yp-production-time{display:flex;gap:12px;align-items:flex-start;margin:0 0 16px;padding:14px 16px;border-radius:14px;border:1px solid #d6eadf;background:linear-gradient(135deg,#f3fbf6,#f6f4ff)}
.yp-production-time.is-busy{border-color:#f1dcb7;background:linear-gradient(135deg,#fff8ec,#fdf3f9)}
.yp-production-time__dot{flex:none;width:10px;height:10px;margin-top:7px;border-radius:50%;background:#1f9d57;box-shadow:0 0 0 4px rgba(31,157,87,.15)}
.yp-production-time.is-busy .yp-production-time__dot{background:#d98a00;box-shadow:0 0 0 4px rgba(217,138,0,.15)}
.yp-production-time p{margin:0}
.yp-production-time__title{font-weight:600;font-size:1.02em}
.yp-production-time__live{margin-left:6px;font-size:.68em;letter-spacing:.12em;text-transform:uppercase;font-weight:700;color:#1f7a4d}
.yp-production-time.is-busy .yp-production-time__live{color:#a86a00}
.yp-production-time__body{margin-top:3px!important;font-size:.92em;color:#444}
.yp-production-time__small{margin-top:4px!important;font-size:.82em;color:#767676}
' );
	}
}
