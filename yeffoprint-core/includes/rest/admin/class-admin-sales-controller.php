<?php
/**
 * `/admin/next/sales` — the Sales screen in the new admin (direct
 * request: run the business from the dashboard instead of the
 * WooCommerce app, whose Stats tab showed month, year and top sellers;
 * Today only covers today and this week).
 *
 * Counts paid orders by the day they were paid, net of refunds, for one
 * range (RANGES), compared with the same-length period just before it.
 * Top sellers reuse the Catalog hub's grouping
 * (YeffoPrint_Admin_Next_Controller::best_seller_row()).
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_Sales_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	/** Statuses that mean the customer has paid (same as Today and Payouts). */
	private const PAID_STATUSES = [ 'processing', 'in-design', 'in-production', 'shipped', 'completed', 'refunded' ];

	public const RANGES = [
		'7d'         => 'Last 7 days',
		'30d'        => 'Last 30 days',
		'month'      => 'This month',
		'last_month' => 'Last month',
		'year'       => 'This year',
		'last_year'  => 'Last year',
	];

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/admin/next/sales', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_sales' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function get_sales( \WP_REST_Request $request ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new \WP_Error( 'yeffoprint_woocommerce_inactive', __( 'WooCommerce is not active.', 'yeffoprint-core' ), [ 'status' => 500 ] );
		}

		$range = (string) $request->get_param( 'range' );
		if ( ! isset( self::RANGES[ $range ] ) ) {
			$range = '30d';
		}

		[ $start, $end, $prev_start, $prev_end ] = $this->bounds( $range );
		$by_month = in_array( $range, [ 'year', 'last_year' ], true );

		$current  = $this->orders( $start, $end );
		$previous = $this->orders( $prev_start, $prev_end );

		$totals      = $this->totals( $current );
		$prev_totals = $this->totals( $previous );

		return rest_ensure_response( [
			'range'           => $range,
			'ranges'          => self::RANGES,
			'label'           => self::RANGES[ $range ],
			'start'           => $start->format( 'Y-m-d' ),
			'end'             => $end->modify( '-1 second' )->format( 'Y-m-d' ),
			'totals'          => $totals,
			'previous'        => $prev_totals,
			'series'          => $this->series( $current, $start, $end, $by_month ),
			'by_month'        => $by_month,
			'top_products'    => $this->top_products( $current ),
			'payment_methods' => $this->payment_methods( $current ),
			'currency_symbol' => html_entity_decode( get_woocommerce_currency_symbol() ),
		] );
	}

	/** @return \DateTimeImmutable[] [ start, end (exclusive), previous start, previous end ] in the site's timezone. */
	private function bounds( string $range ): array {
		$tz       = wp_timezone();
		$tomorrow = new \DateTimeImmutable( 'tomorrow', $tz );

		switch ( $range ) {
			case '7d':
				$start = $tomorrow->modify( '-7 days' );
				$end   = $tomorrow;
				break;
			case 'month':
				$start = new \DateTimeImmutable( 'first day of this month 00:00', $tz );
				$end   = $tomorrow;
				// Same days of last month, so a half-done month compares fairly.
				// Counted in days from the 1st: "-1 month" from Mar 31 lands on Mar 3.
				$prev_start = $start->modify( '-1 month' );
				$prev_end   = min( $prev_start->modify( '+' . $start->diff( $end )->days . ' days' ), $start );
				return [ $start, $end, $prev_start, $prev_end ];
			case 'last_month':
				$start = new \DateTimeImmutable( 'first day of last month 00:00', $tz );
				$end   = new \DateTimeImmutable( 'first day of this month 00:00', $tz );
				return [ $start, $end, $start->modify( '-1 month' ), $start ];
			case 'year':
				$start = new \DateTimeImmutable( 'first day of january this year 00:00', $tz );
				$end   = $tomorrow;
				return [ $start, $end, $start->modify( '-1 year' ), $end->modify( '-1 year' ) ];
			case 'last_year':
				$start = new \DateTimeImmutable( 'first day of january last year 00:00', $tz );
				$end   = new \DateTimeImmutable( 'first day of january this year 00:00', $tz );
				return [ $start, $end, $start->modify( '-1 year' ), $start ];
			default:
				$start = $tomorrow->modify( '-30 days' );
				$end   = $tomorrow;
		}

		$length = $end->getTimestamp() - $start->getTimestamp();
		return [ $start, $end, $start->modify( '-' . $length . ' seconds' ), $start ];
	}

	/** @return \WC_Order[] Orders paid in [start, end). */
	private function orders( \DateTimeImmutable $start, \DateTimeImmutable $end ): array {
		return wc_get_orders( [
			'status'    => self::PAID_STATUSES,
			'limit'     => -1,
			'date_paid' => $start->getTimestamp() . '...' . ( $end->getTimestamp() - 1 ),
			'type'      => 'shop_order',
		] );
	}

	private function net( \WC_Order $order ): float {
		return (float) $order->get_total() - (float) $order->get_total_refunded();
	}

	private function totals( array $orders ): array {
		$revenue  = 0.0;
		$refunds  = 0.0;
		$units    = 0;
		$shipping = 0.0;
		$count    = 0;
		foreach ( $orders as $order ) {
			$refunded = (float) $order->get_total_refunded();
			$refunds += $refunded;
			// Fully refunded orders count toward refunds only, not orders,
			// items sold or the average.
			if ( $refunded > 0 && $this->net( $order ) <= 0 ) {
				continue;
			}
			++$count;
			$revenue  += $this->net( $order );
			$shipping += (float) $order->get_shipping_total();
			$units    += (int) $order->get_item_count();
		}
		return [
			'revenue'  => round( $revenue, 2 ),
			'orders'   => $count,
			'average'  => $count ? round( $revenue / $count, 2 ) : 0.0,
			'refunds'  => round( $refunds, 2 ),
			'shipping' => round( $shipping, 2 ),
			'units'    => $units,
		];
	}

	private function series( array $orders, \DateTimeImmutable $start, \DateTimeImmutable $end, bool $by_month ): array {
		$tz      = wp_timezone();
		$format  = $by_month ? 'Y-m' : 'Y-m-d';
		$step    = $by_month ? '+1 month' : '+1 day';
		$buckets = [];

		for ( $cursor = $start; $cursor < $end; $cursor = $cursor->modify( $step ) ) {
			$buckets[ $cursor->format( $format ) ] = [ 'date' => $cursor->format( $format ), 'revenue' => 0.0, 'orders' => 0 ];
		}

		foreach ( $orders as $order ) {
			$paid = $order->get_date_paid();
			if ( ! $paid ) {
				continue;
			}
			$key = $paid->setTimezone( $tz )->format( $format );
			if ( isset( $buckets[ $key ] ) ) {
				$buckets[ $key ]['revenue'] += $this->net( $order );
				$buckets[ $key ]['orders']++;
			}
		}

		return array_values( array_map( static function ( array $b ): array {
			$b['revenue'] = round( $b['revenue'], 2 );
			return $b;
		}, $buckets ) );
	}

	private function top_products( array $orders ): array {
		$rows = [];
		foreach ( $orders as $order ) {
			foreach ( $order->get_items() as $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product ) {
					continue;
				}
				$product_id = (int) $item->get_product_id();
				$key        = $product_id ?: 'name:' . $item->get_name();
				if ( ! isset( $rows[ $key ] ) ) {
					$rows[ $key ] = YeffoPrint_Admin_Next_Controller::best_seller_row( $product_id, $item->get_name() );
				}
				$rows[ $key ]['orders'][ $order->get_id() ] = true;
				$rows[ $key ]['units']                    += (int) $item->get_quantity();
				$rows[ $key ]['revenue']                  += (float) $item->get_total();
			}
		}

		$rows = array_map( static function ( array $row ): array {
			$row['orders']  = count( $row['orders'] );
			$row['revenue'] = round( $row['revenue'], 2 );
			return $row;
		}, array_values( $rows ) );

		usort( $rows, static fn( array $a, array $b ): int => $b['revenue'] <=> $a['revenue'] );

		return array_slice( $rows, 0, 10 );
	}

	private function payment_methods( array $orders ): array {
		$rows = [];
		foreach ( $orders as $order ) {
			$title = $order->get_payment_method_title() ?: __( 'Other', 'yeffoprint-core' );
			if ( ! isset( $rows[ $title ] ) ) {
				$rows[ $title ] = [ 'label' => $title, 'revenue' => 0.0, 'orders' => 0 ];
			}
			$rows[ $title ]['revenue'] += $this->net( $order );
			$rows[ $title ]['orders']++;
		}

		$rows = array_map( static function ( array $row ): array {
			$row['revenue'] = round( $row['revenue'], 2 );
			return $row;
		}, array_values( $rows ) );

		usort( $rows, static fn( array $a, array $b ): int => $b['revenue'] <=> $a['revenue'] );
		return $rows;
	}
}
