<?php
/**
 * Data for the new admin app ("YeffoDesign (new)", direct request: "Can
 * this live as a separate app in the backend until I confirm all
 * functionality. Then we will remove the old."). Everything else the new
 * app shows comes from the same endpoints the current app already uses;
 * these three only back its new pieces:
 *
 * - `/admin/next/board` — the Production board. Every open order
 *   (unpaid, paid, in production, plus anything shipped in the last
 *   SHIPPED_DAYS days), each placed in one board column. Paid orders are
 *   split by their custom design proof, read from the linked
 *   yp_custom_order the same way the Custom Orders screen does
 *   (`_yp_custom_order_id` item meta → `_yp_status`).
 * - `/admin/next/stats` — the Today screen's revenue and order counts.
 * - `/admin/next/switches` — the Settings hub's quick switches. Writes
 *   only the one option it is sent, unlike `/admin/settings`, which
 *   saves the whole Settings form at once.
 * - `/admin/next/push` — this device's phone alerts on/off, plus a test
 *   alert (class-admin-push.php).
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_Next_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	/** How far back the board's Shipped column reaches. */
	private const SHIPPED_DAYS = 14;

	/** Statuses that mean the customer has paid. Revenue on Today only counts these. */
	private const PAID_STATUSES = [ 'processing', 'in-production', 'shipped', 'completed' ];

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/admin/next/board', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_board' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/admin/next/stats', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_stats' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/admin/next/switches', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_switches' ],
				'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'save_switch' ],
				'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
			],
		] );
		register_rest_route( self::NAMESPACE, '/admin/next/push', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_push' ],
				'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'save_push' ],
				'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/admin/next/push/test', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'test_push' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function get_board() {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new \WP_Error( 'yeffoprint_woocommerce_inactive', __( 'WooCommerce is not active.', 'yeffoprint-core' ), [ 'status' => 500 ] );
		}

		$open = wc_get_orders( [
			'status'  => [ 'pending', 'on-hold', 'processing', YeffoPrint_Order_Production_Status::STATUS ],
			'limit'   => 200,
			'orderby' => 'date',
			'order'   => 'ASC',
		] );

		$shipped = wc_get_orders( [
			'status'        => YeffoPrint_Order_Shipment_Status::STATUS,
			'limit'         => 100,
			'orderby'       => 'modified',
			'order'         => 'DESC',
			'date_modified' => '>' . ( time() - self::SHIPPED_DAYS * DAY_IN_SECONDS ),
		] );

		$cards = array_map( [ $this, 'card_payload' ], array_merge( $open, $shipped ) );

		// Express orders first in every column, oldest first after that.
		usort( $cards, static function ( array $a, array $b ): int {
			if ( $a['express'] !== $b['express'] ) {
				return $a['express'] ? -1 : 1;
			}
			return strcmp( (string) $a['date'], (string) $b['date'] );
		} );

		return rest_ensure_response( [
			'orders'       => $cards,
			'shipped_days' => self::SHIPPED_DAYS,
		] );
	}

	private function card_payload( \WC_Order $order ): array {
		$status     = $order->get_status();
		$proof      = $this->proof_stage( $order );
		$items      = array_values( $order->get_items() );
		$first_name = $items ? $items[0]->get_name() : '';
		$more       = max( 0, count( $items ) - 1 );
		$date       = $order->get_date_created();

		if ( in_array( $status, [ 'pending', 'on-hold' ], true ) ) {
			$column = 'unpaid';
		} elseif ( 'processing' === $status ) {
			$column = 'needs_proof' === $proof ? 'proof' : ( 'proof_sent' === $proof ? 'approval' : 'ready' );
		} elseif ( YeffoPrint_Order_Production_Status::STATUS === $status ) {
			$column = 'printing';
		} else {
			$column = 'shipped';
		}

		return [
			'id'             => $order->get_id(),
			'number'         => $order->get_order_number(),
			'status'         => $status,
			'status_label'   => wc_get_order_status_name( $status ),
			'column'         => $column,
			'proof'          => $proof,
			'date'           => $date ? $date->date( 'c' ) : null,
			'customer'       => trim( $order->get_formatted_billing_full_name() ) ?: $order->get_billing_email(),
			'items'          => $first_name . ( $more ? sprintf( ' + %d more', $more ) : '' ),
			'total'          => (float) $order->get_total(),
			'express'        => YeffoPrint_Express_Order::is_express( $order ),
			'payment_method' => $order->get_payment_method_title(),
			'shipping'       => $order->get_shipping_method(),
		];
	}

	/**
	 * 'needs_proof' while any linked custom design still owes a proof,
	 * 'proof_sent' while one waits on the customer, '' otherwise (no
	 * custom design on the order, or every proof is approved).
	 */
	private function proof_stage( \WC_Order $order ): string {
		$stage = '';
		foreach ( $order->get_items() as $item ) {
			$custom_order_id = (int) $item->get_meta( '_yp_custom_order_id' );
			if ( ! $custom_order_id ) {
				continue;
			}
			$status = (string) get_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::STATUS, true );
			if ( in_array( $status, [ 'design_in_progress', 'proof_ready' ], true ) ) {
				return 'needs_proof';
			}
			if ( 'awaiting_approval' === $status ) {
				$stage = 'proof_sent';
			}
		}
		return $stage;
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function get_stats() {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new \WP_Error( 'yeffoprint_woocommerce_inactive', __( 'WooCommerce is not active.', 'yeffoprint-core' ), [ 'status' => 500 ] );
		}

		$timezone    = wp_timezone();
		$today_start = new \DateTimeImmutable( 'today', $timezone );
		$range_start = $today_start->modify( '-13 days' );

		$orders = wc_get_orders( [
			'status'       => array_merge( self::PAID_STATUSES, [ 'pending', 'on-hold' ] ),
			'limit'        => -1,
			'date_created' => '>=' . $range_start->getTimestamp(),
		] );

		$days = [];
		for ( $i = 0; $i < 14; $i++ ) {
			$days[ $range_start->modify( "+{$i} days" )->format( 'Y-m-d' ) ] = [ 'revenue' => 0.0, 'orders' => 0 ];
		}

		foreach ( $orders as $order ) {
			$created = $order->get_date_created();
			if ( ! $created ) {
				continue;
			}
			$key = $created->setTimezone( $timezone )->format( 'Y-m-d' );
			if ( ! isset( $days[ $key ] ) ) {
				continue;
			}
			$days[ $key ]['orders']++;
			if ( in_array( $order->get_status(), self::PAID_STATUSES, true ) ) {
				$days[ $key ]['revenue'] += (float) $order->get_total();
			}
		}

		$series = [];
		foreach ( $days as $date => $day ) {
			$series[] = [ 'date' => $date, 'revenue' => round( $day['revenue'], 2 ), 'orders' => $day['orders'] ];
		}

		$today     = $series[13];
		$last_week = $series[6];
		$week      = array_slice( $series, 7 );

		return rest_ensure_response( [
			'revenue_today'      => $today['revenue'],
			'revenue_last_week'  => $last_week['revenue'],
			'orders_today'       => $today['orders'],
			'revenue_7_days'     => round( array_sum( array_column( $week, 'revenue' ) ), 2 ),
			'days'               => $week,
			'in_production'      => count( wc_get_orders( [ 'status' => YeffoPrint_Order_Production_Status::STATUS, 'limit' => -1, 'return' => 'ids' ] ) ),
			'currency_symbol'    => html_entity_decode( get_woocommerce_currency_symbol() ),
		] );
	}

	/** Option name for each quick switch, plus what an unset option means. */
	private function switches(): array {
		return [
			'away_mode'    => [ YeffoPrint_Admin_Menu::AWAY_MODE_ENABLED_OPTION, false ],
			'express'      => [ YeffoPrint_Admin_Menu::EXPRESS_ENABLED_OPTION, true ],
			'local_pickup' => [ YeffoPrint_Local_Pickup::ENABLED_OPTION, false ],
			'promo'        => [ YeffoPrint_Admin_Menu::PROMO_ENABLED_OPTION, false ],
		];
	}

	public function get_switches(): \WP_REST_Response {
		$values = [];
		foreach ( $this->switches() as $key => [ $option, $default ] ) {
			$values[ $key ] = (bool) get_option( $option, $default );
		}
		return rest_ensure_response( $values );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function save_switch( \WP_REST_Request $request ) {
		$params   = $request->get_json_params() ?: [];
		$key      = sanitize_key( (string) ( $params['key'] ?? '' ) );
		$switches = $this->switches();

		if ( ! isset( $switches[ $key ] ) ) {
			return new \WP_Error( 'yeffoprint_invalid_switch', __( 'Unknown switch.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		update_option( $switches[ $key ][0], (bool) ( $params['value'] ?? false ) );

		return $this->get_switches();
	}

	public function get_push(): \WP_REST_Response {
		$subs = YeffoPrint_Admin_Push::subscriptions( get_current_user_id() );
		return rest_ensure_response( [
			'available'  => YeffoPrint_Admin_Push::available(),
			'public_key' => YeffoPrint_Admin_Push::available() ? YeffoPrint_Tracker_Push::public_key() : '',
			'devices'    => array_values( array_map( static function ( array $sub ): array {
				return [ 'endpoint' => $sub['endpoint'], 'device' => $sub['device'], 'created' => $sub['created'] ];
			}, $subs ) ),
		] );
	}

	/** Body: `{ subscription, device }` to turn this device on, `{ endpoint, off: true }` to turn it off. */
	public function save_push( \WP_REST_Request $request ) {
		$params = $request->get_json_params() ?: [];
		$user   = get_current_user_id();

		if ( ! empty( $params['off'] ) ) {
			YeffoPrint_Admin_Push::unsubscribe( $user, (string) ( $params['endpoint'] ?? '' ) );
			return $this->get_push();
		}

		if ( ! YeffoPrint_Admin_Push::subscribe( $user, (array) ( $params['subscription'] ?? [] ), (string) ( $params['device'] ?? '' ) ) ) {
			return new \WP_Error( 'yeffoprint_invalid_subscription', __( 'This device could not be signed up for alerts.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		return $this->get_push();
	}

	public function test_push(): \WP_REST_Response {
		$delivered = YeffoPrint_Admin_Push::send_to_admins( [
			'title' => __( 'Alerts are on', 'yeffoprint-core' ),
			'body'  => __( 'New orders, reviews and messages will show up here.', 'yeffoprint-core' ),
			'url'   => admin_url( 'admin.php?page=' . YeffoPrint_Admin_Push::APP_SLUG ),
			'tag'   => 'test',
		], get_current_user_id() );

		return rest_ensure_response( [ 'delivered' => $delivered ] );
	}
}
