<?php
/**
 * `/admin/next/payouts` — the Payouts panel on the new admin's Today
 * screen (direct request: "Are we able to add woocommerce payout
 * information to our dashboard? Like current/available balance and
 * upcoming/past payouts?"; Jeff picked layout A, mockups/payouts/).
 *
 * Card, Klarna, Afterpay and Affirm money all runs through WooPayments,
 * so balances and payouts are read from WooPayments' own REST routes
 * (the same ones its Payments > Overview page uses), in-process via
 * rest_do_request() so its permission checks and API client apply:
 *
 * - `/wc/v3/payments/deposits/overview-all` — available + pending
 *   balance, last paid payout, payout schedule.
 * - `/wc/v3/payments/deposits` — the most recent payouts, newest first
 *   (pending / in transit / paid / failed).
 *
 * Venmo, Zelle and NOWPayments crypto go straight to Jeff, so there is
 * no payout to show; the panel lists what paid orders recorded through
 * them in the last 7 days instead.
 *
 * WooPayments calls its servers on every read, so the result is cached
 * for CACHE_MINUTES; `?refresh=1` skips the cache.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_Payouts_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	private const CACHE_KEY = 'yeffoprint_next_payouts';

	private const CACHE_MINUTES = 15;

	/** How many payouts the Recent payouts list shows. */
	private const RECENT_COUNT = 6;

	/** How far back "Other money in" reaches. */
	private const OTHER_DAYS = 7;

	/** Statuses that mean the customer has paid (same as the Today revenue tiles). */
	private const PAID_STATUSES = [ 'processing', 'in-design', 'in-production', 'shipped', 'completed' ];

	/** Gateways that pay Jeff directly (no WooPayments payout). */
	private const DIRECT_GATEWAYS = [
		'yeffoprint_venmo'       => 'Venmo',
		'yeffoprint_zelle'       => 'Zelle',
		'yeffoprint_nowpayments' => 'Crypto',
	];

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/admin/next/payouts', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_payouts' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );
	}

	/** @return \WP_REST_Response */
	public function get_payouts( \WP_REST_Request $request ) {
		$cached = $request->get_param( 'refresh' ) ? false : get_transient( self::CACHE_KEY );

		if ( is_array( $cached ) ) {
			$wcpay = $cached;
		} else {
			$wcpay = $this->wcpay_payload();
			// Only cache real answers, so a brief WooPayments outage doesn't stick for 15 minutes.
			if ( empty( $wcpay['error'] ) ) {
				set_transient( self::CACHE_KEY, $wcpay, self::CACHE_MINUTES * MINUTE_IN_SECONDS );
			}
		}

		return rest_ensure_response( [
			'woopayments'     => $wcpay,
			'other'           => $this->direct_payments(),
			'other_days'      => self::OTHER_DAYS,
			'cache_minutes'   => self::CACHE_MINUTES,
			'currency_symbol' => function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol() ) : '$',
		] );
	}

	/**
	 * Balance + payouts from WooPayments, amounts in dollars.
	 */
	private function wcpay_payload(): array {
		$payload = [
			'connected'   => false,
			'error'       => '',
			'currency'    => '',
			'available'   => null,
			'pending'     => null,
			'last_paid'   => null,
			'next'        => null,
			'schedule'    => '',
			'bank'        => '',
			'payouts'     => [],
			'manage_url'  => admin_url( 'admin.php?page=wc-admin&path=/payments/payouts' ),
			'updated_at'  => gmdate( 'c' ),
		];

		if ( ! class_exists( 'WC_Payments' ) ) {
			$payload['error'] = 'WooPayments isn’t active.';
			return $payload;
		}

		$overview = $this->wcpay_get( '/wc/v3/payments/deposits/overview-all' );
		if ( is_wp_error( $overview ) ) {
			$payload['error'] = $overview->get_error_message();
			return $payload;
		}

		$payload['connected'] = true;
		$account              = is_array( $overview['account'] ?? null ) ? $overview['account'] : [];
		$currency             = strtolower( (string) ( $account['default_currency'] ?? get_option( 'woocommerce_currency', 'usd' ) ) );
		$payload['currency']  = strtoupper( $currency );

		$available = $this->for_currency( $overview['balance']['available'] ?? [], $currency );
		$pending   = $this->for_currency( $overview['balance']['pending'] ?? [], $currency );
		$last_paid = $this->for_currency( $overview['deposit']['last_paid'] ?? [], $currency );

		$payload['available'] = $available ? $this->amount( $available['amount'] ?? 0, $currency ) : 0.0;
		$payload['pending']   = $pending ? $this->amount( $pending['amount'] ?? 0, $currency ) : 0.0;
		$payload['last_paid'] = $last_paid ? $this->payout_row( $last_paid, $currency ) : null;
		$payload['schedule']  = $this->schedule_label( $account['deposits_schedule'] ?? [] );
		$payload['bank']      = $this->bank_label( $account, $currency );

		$list = $this->wcpay_get( '/wc/v3/payments/deposits', [
			'page'              => 1,
			'pagesize'          => self::RECENT_COUNT,
			'sort'              => 'date',
			'direction'         => 'desc',
			'store_currency_is' => $currency,
		] );

		if ( ! is_wp_error( $list ) ) {
			foreach ( (array) ( $list['data'] ?? [] ) as $deposit ) {
				if ( is_array( $deposit ) ) {
					$payload['payouts'][] = $this->payout_row( $deposit, $currency );
				}
			}
		}

		// The next payout is the soonest one that hasn't landed yet.
		$upcoming = array_values( array_filter( $payload['payouts'], static function ( array $row ): bool {
			return in_array( $row['status'], [ 'pending', 'in_transit' ], true ) && 'deposit' === $row['type'];
		} ) );
		usort( $upcoming, static function ( array $a, array $b ): int {
			return strcmp( $a['date'], $b['date'] );
		} );
		$payload['next'] = $upcoming[0] ?? null;

		if ( ! $payload['last_paid'] ) {
			foreach ( $payload['payouts'] as $row ) {
				if ( 'paid' === $row['status'] && 'deposit' === $row['type'] ) {
					$payload['last_paid'] = $row;
					break;
				}
			}
		}

		return $payload;
	}

	/** @return array|\WP_Error */
	private function wcpay_get( string $route, array $params = [] ) {
		$request = new \WP_REST_Request( 'GET', $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			$error = $response->as_error();
			if ( 404 === $response->get_status() ) {
				return new \WP_Error( 'yeffoprint_wcpay_missing', 'WooPayments isn’t connected yet.' );
			}
			return $error;
		}

		$data = $response->get_data();
		return is_array( $data ) ? $data : new \WP_Error( 'yeffoprint_wcpay_bad_response', 'WooPayments sent back something unexpected.' );
	}

	private function for_currency( $rows, string $currency ): ?array {
		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) && strtolower( (string) ( $row['currency'] ?? '' ) ) === $currency ) {
				return $row;
			}
		}
		return null;
	}

	/** WooPayments amounts are in cents (minor units). */
	private function amount( $cents, string $currency ): float {
		$zero_decimal = [ 'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf' ];
		return in_array( $currency, $zero_decimal, true ) ? (float) $cents : round( (float) $cents / 100, 2 );
	}

	private function payout_row( array $deposit, string $currency ): array {
		$date = $deposit['date'] ?? '';
		if ( is_numeric( $date ) ) {
			// Some WooPayments responses send milliseconds.
			$date = gmdate( 'c', (int) ( $date > 9999999999 ? $date / 1000 : $date ) );
		}

		return [
			'id'     => (string) ( $deposit['id'] ?? '' ),
			'date'   => (string) $date,
			'amount' => $this->amount( $deposit['amount'] ?? 0, $currency ),
			'status' => (string) ( $deposit['status'] ?? '' ),
			'type'   => (string) ( $deposit['type'] ?? 'deposit' ),
			'url'    => ! empty( $deposit['id'] ) ? admin_url( 'admin.php?page=wc-admin&path=/payments/payouts/details&id=' . rawurlencode( (string) $deposit['id'] ) ) : '',
		];
	}

	private function schedule_label( $schedule ): string {
		$interval = is_array( $schedule ) ? (string) ( $schedule['interval'] ?? '' ) : '';
		switch ( $interval ) {
			case 'daily':
				return 'Daily payouts';
			case 'weekly':
				$day = (string) ( $schedule['weekly_anchor'] ?? '' );
				return $day ? 'Weekly payouts on ' . ucfirst( $day ) : 'Weekly payouts';
			case 'monthly':
				$anchor = (int) ( $schedule['monthly_anchor'] ?? 0 );
				return $anchor ? 'Monthly payouts on day ' . $anchor : 'Monthly payouts';
			case 'manual':
				return 'Manual payouts';
		}
		return '';
	}

	private function bank_label( array $account, string $currency ): string {
		foreach ( (array) ( $account['default_external_accounts'] ?? [] ) as $bank ) {
			if ( ! is_array( $bank ) || strtolower( (string) ( $bank['currency'] ?? '' ) ) !== $currency ) {
				continue;
			}
			$name  = trim( (string) ( $bank['bank_name'] ?? '' ) );
			$last4 = trim( (string) ( $bank['last4'] ?? '' ) );
			return trim( ( $name ?: 'Bank' ) . ( $last4 ? ' ••' . $last4 : '' ) );
		}
		return '';
	}

	/**
	 * Paid orders through Venmo, Zelle and NOWPayments in the last
	 * OTHER_DAYS days, net of refunds.
	 */
	private function direct_payments(): array {
		$rows = [];
		foreach ( self::DIRECT_GATEWAYS as $id => $label ) {
			$rows[ $id ] = [ 'id' => $id, 'label' => $label, 'total' => 0.0, 'orders' => 0 ];
		}

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array_values( $rows );
		}

		$orders = wc_get_orders( [
			'type' => 'shop_order',
			'status'    => self::PAID_STATUSES,
			'date_paid' => '>' . ( time() - self::OTHER_DAYS * DAY_IN_SECONDS ),
			'limit'     => -1,
		] );

		foreach ( $orders as $order ) {
			$method = $order->get_payment_method();
			if ( ! isset( $rows[ $method ] ) ) {
				continue;
			}
			$rows[ $method ]['total']  += (float) $order->get_total() - (float) $order->get_total_refunded();
			$rows[ $method ]['orders'] += 1;
		}

		foreach ( $rows as &$row ) {
			$row['total'] = round( $row['total'], 2 );
		}

		return array_values( $rows );
	}
}
