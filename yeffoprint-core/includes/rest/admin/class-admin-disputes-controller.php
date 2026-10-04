<?php
/**
 * Card disputes (chargebacks) in the admin app — direct request: run the
 * business from the dashboard without wp-admin or the WooCommerce app.
 * WooPayments disputes have a response deadline, and until now the only
 * place to answer one was its Payments > Disputes page in wp-admin.
 *
 * Reads and writes go through WooPayments' own REST routes in-process
 * (rest_do_request(), same approach as class-admin-payouts-controller.php),
 * so its permission checks and API client apply:
 *
 * - `GET  /wc/v3/payments/disputes`             — the list.
 * - `GET  /wc/v3/payments/disputes/{id}`        — one dispute + its evidence.
 * - `POST /wc/v3/payments/disputes/{id}`        — save evidence (submit: true sends it to the bank).
 * - `POST /wc/v3/payments/disputes/{id}/close`  — accept the dispute.
 * - `POST /wc/v3/payments/file`                 — upload an evidence file.
 *
 * The list is cached for CACHE_MINUTES (WooPayments calls its servers on
 * every read); any write clears it.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_Disputes_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	private const CACHE_KEY = 'yeffoprint_admin_disputes';

	private const CACHE_MINUTES = 15;

	/** Statuses that still need an answer from the store. */
	public const NEEDS_RESPONSE = [ 'needs_response', 'warning_needs_response' ];

	private const STATUS_LABELS = [
		'needs_response'         => 'Needs response',
		'warning_needs_response' => 'Inquiry: needs response',
		'under_review'           => 'Under review',
		'warning_under_review'   => 'Inquiry: under review',
		'warning_closed'         => 'Inquiry closed',
		'charge_refunded'        => 'Refunded',
		'won'                    => 'Won',
		'lost'                   => 'Lost',
	];

	private const REASON_LABELS = [
		'bank_cannot_process'       => 'Bank can’t process',
		'check_returned'            => 'Check returned',
		'credit_not_processed'      => 'Refund not processed',
		'customer_initiated'        => 'Customer initiated',
		'debit_not_authorized'      => 'Debit not authorized',
		'duplicate'                 => 'Duplicate charge',
		'fraudulent'                => 'Fraudulent',
		'general'                   => 'General',
		'incorrect_account_details' => 'Incorrect account details',
		'insufficient_funds'        => 'Insufficient funds',
		'product_not_received'      => 'Product not received',
		'product_unacceptable'      => 'Product unacceptable',
		'subscription_canceled'     => 'Subscription canceled',
		'unrecognized'              => 'Unrecognized charge',
	];

	/** Evidence fields the dashboard form edits (Stripe dispute evidence keys). */
	private const TEXT_EVIDENCE = [
		'uncategorized_text',
		'product_description',
		'customer_name',
		'customer_email_address',
		'billing_address',
		'shipping_address',
		'shipping_carrier',
		'shipping_tracking_number',
		'shipping_date',
	];

	/** Evidence fields that hold an uploaded file id. */
	private const FILE_EVIDENCE = [ 'receipt', 'shipping_documentation', 'customer_communication', 'uncategorized_file' ];

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/admin/disputes', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'list_disputes' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/admin/disputes/(?P<id>[A-Za-z0-9_]+)', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_dispute' ],
				'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'save_dispute' ],
				'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/admin/disputes/(?P<id>[A-Za-z0-9_]+)/accept', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'accept_dispute' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/admin/dispute-evidence-file', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'upload_file' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );
	}

	/** @return \WP_REST_Response */
	public function list_disputes( \WP_REST_Request $request ) {
		return rest_ensure_response( self::summary( (bool) $request->get_param( 'refresh' ) ) );
	}

	/**
	 * The dispute list, cached. Also read by the Today screen's queue.
	 *
	 * @return array{available:bool,error:string,disputes:array,open:int,currency_symbol:string}
	 */
	public static function summary( bool $refresh = false ): array {
		$cached = $refresh ? false : get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$payload = [
			'available'       => false,
			'error'           => '',
			'disputes'        => [],
			'open'            => 0,
			'currency_symbol' => function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol() ) : '$',
		];

		if ( ! class_exists( 'WC_Payments' ) ) {
			$payload['error'] = 'WooPayments isn’t active.';
			return $payload;
		}

		$list = self::wcpay( 'GET', '/wc/v3/payments/disputes', [
			'page'      => 1,
			'pagesize'  => 50,
			'sort'      => 'created',
			'direction' => 'desc',
		] );

		if ( is_wp_error( $list ) ) {
			$payload['error'] = $list->get_error_message();
			return $payload;
		}

		$payload['available'] = true;
		foreach ( (array) ( $list['data'] ?? [] ) as $row ) {
			if ( is_array( $row ) ) {
				$payload['disputes'][] = self::row_payload( $row );
			}
		}
		$payload['open'] = count( array_filter( $payload['disputes'], static fn( array $d ): bool => $d['needs_response'] ) );

		set_transient( self::CACHE_KEY, $payload, self::CACHE_MINUTES * MINUTE_IN_SECONDS );
		return $payload;
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function get_dispute( \WP_REST_Request $request ) {
		$dispute = self::wcpay( 'GET', '/wc/v3/payments/disputes/' . rawurlencode( (string) $request['id'] ) );
		if ( is_wp_error( $dispute ) ) {
			return $dispute;
		}

		return rest_ensure_response( $this->detail_payload( $dispute ) );
	}

	/**
	 * Saves evidence; `submit: true` sends it to the bank (no more
	 * changes after that).
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_dispute( \WP_REST_Request $request ) {
		$params   = $request->get_json_params() ?: [];
		$input    = is_array( $params['evidence'] ?? null ) ? $params['evidence'] : [];
		$evidence = [];

		foreach ( self::TEXT_EVIDENCE as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$evidence[ $key ] = sanitize_textarea_field( (string) $input[ $key ] );
			}
		}
		foreach ( self::FILE_EVIDENCE as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$evidence[ $key ] = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $input[ $key ] );
			}
		}

		$result = self::wcpay( 'POST', '/wc/v3/payments/disputes/' . rawurlencode( (string) $request['id'] ), [
			'evidence' => $evidence,
			'submit'   => ! empty( $params['submit'] ),
		] );
		delete_transient( self::CACHE_KEY );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->get_dispute( $request );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function accept_dispute( \WP_REST_Request $request ) {
		$result = self::wcpay( 'POST', '/wc/v3/payments/disputes/' . rawurlencode( (string) $request['id'] ) . '/close' );
		delete_transient( self::CACHE_KEY );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->get_dispute( $request );
	}

	/**
	 * One evidence file (receipt, proof of shipping, screenshots).
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function upload_file( \WP_REST_Request $request ) {
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) ) {
			return new \WP_Error( 'yeffoprint_dispute_no_file', __( 'Pick a file to attach.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$upload = new \WP_REST_Request( 'POST', '/wc/v3/payments/file' );
		$upload->set_file_params( [ 'file' => $files['file'] ] );
		$upload->set_param( 'purpose', 'dispute_evidence' );

		$response = rest_do_request( $upload );
		if ( $response->is_error() ) {
			return $response->as_error();
		}

		$data = (array) $response->get_data();
		return rest_ensure_response( [
			'id'       => (string) ( $data['id'] ?? '' ),
			'filename' => (string) ( $data['filename'] ?? ( $files['file']['name'] ?? '' ) ),
		] );
	}

	/** @return array|\WP_Error */
	private static function wcpay( string $method, string $route, array $params = [] ) {
		$request = new \WP_REST_Request( $method, $route );
		if ( 'GET' === $method ) {
			foreach ( $params as $key => $value ) {
				$request->set_param( $key, $value );
			}
		} elseif ( $params ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $params ) );
			foreach ( $params as $key => $value ) {
				$request->set_param( $key, $value );
			}
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			if ( 404 === $response->get_status() && 'GET' === $method && '/wc/v3/payments/disputes' === $route ) {
				return new \WP_Error( 'yeffoprint_wcpay_missing', 'WooPayments isn’t connected yet.' );
			}
			return $response->as_error();
		}

		$data = $response->get_data();
		return is_array( $data ) ? $data : [];
	}

	/** A list row from WooPayments' dispute list. */
	private static function row_payload( array $row ): array {
		$currency = strtolower( (string) ( $row['currency'] ?? 'usd' ) );
		$status   = (string) ( $row['status'] ?? '' );
		$order    = is_array( $row['order'] ?? null ) ? $row['order'] : [];

		return [
			'id'             => (string) ( $row['dispute_id'] ?? $row['id'] ?? '' ),
			'amount'         => self::amount( $row['amount'] ?? 0, $currency ),
			'currency'       => strtoupper( $currency ),
			'reason'         => (string) ( $row['reason'] ?? '' ),
			'reason_label'   => self::REASON_LABELS[ $row['reason'] ?? '' ] ?? ucfirst( str_replace( '_', ' ', (string) ( $row['reason'] ?? '' ) ) ),
			'status'         => $status,
			'status_label'   => self::STATUS_LABELS[ $status ] ?? ucfirst( str_replace( '_', ' ', $status ) ),
			'needs_response' => in_array( $status, self::NEEDS_RESPONSE, true ),
			'created'        => self::date( $row['created'] ?? '' ),
			'due_by'         => self::date( $row['due_by'] ?? '' ),
			'customer_name'  => (string) ( $row['customer_name'] ?? '' ),
			'order_number'   => (string) ( $order['number'] ?? $row['order_number'] ?? '' ),
			'order_id'       => self::order_id( $order, (string) ( $row['order_number'] ?? '' ) ),
		];
	}

	private function detail_payload( array $dispute ): array {
		$currency = strtolower( (string) ( $dispute['currency'] ?? 'usd' ) );
		$status   = (string) ( $dispute['status'] ?? '' );
		$order    = is_array( $dispute['order'] ?? null ) ? $dispute['order'] : [];
		$details  = is_array( $dispute['evidence_details'] ?? null ) ? $dispute['evidence_details'] : [];
		$evidence = is_array( $dispute['evidence'] ?? null ) ? $dispute['evidence'] : [];
		$order_id = self::order_id( $order, '' );

		$out = [];
		foreach ( array_merge( self::TEXT_EVIDENCE, self::FILE_EVIDENCE ) as $key ) {
			$out[ $key ] = is_scalar( $evidence[ $key ] ?? null ) ? (string) $evidence[ $key ] : '';
		}

		return [
			'id'             => (string) ( $dispute['id'] ?? '' ),
			'amount'         => self::amount( $dispute['amount'] ?? 0, $currency ),
			'currency'       => strtoupper( $currency ),
			'reason'         => (string) ( $dispute['reason'] ?? '' ),
			'reason_label'   => self::REASON_LABELS[ $dispute['reason'] ?? '' ] ?? ucfirst( str_replace( '_', ' ', (string) ( $dispute['reason'] ?? '' ) ) ),
			'status'         => $status,
			'status_label'   => self::STATUS_LABELS[ $status ] ?? ucfirst( str_replace( '_', ' ', $status ) ),
			'needs_response' => in_array( $status, self::NEEDS_RESPONSE, true ),
			'created'        => self::date( $dispute['created'] ?? '' ),
			'due_by'         => self::date( $details['due_by'] ?? '' ),
			'submitted'      => (int) ( $details['submission_count'] ?? 0 ) > 0,
			'evidence'       => $out,
			'order_id'       => $order_id,
			'order_number'   => (string) ( $order['number'] ?? '' ),
			// What the store already knows, to fill empty evidence fields.
			'suggested'      => $order_id ? self::suggested_evidence( $order_id ) : [],
			'manage_url'     => admin_url( 'admin.php?page=wc-admin&path=/payments/disputes/details&id=' . rawurlencode( (string) ( $dispute['id'] ?? '' ) ) ),
		];
	}

	private static function suggested_evidence( int $order_id ): array {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			return [];
		}

		$items = [];
		foreach ( $order->get_items() as $item ) {
			$items[] = $item->get_name() . ' × ' . $item->get_quantity();
		}

		$label = null;
		foreach ( YeffoPrint_Order_Tracking::get_shippo_labels( $order ) as $candidate ) {
			if ( ! $candidate['voided'] ) {
				$label = $candidate;
			}
		}

		$address = static fn( string $html ): string => trim( html_entity_decode( wp_strip_all_tags( str_replace( [ '<br/>', '<br>', '<br />' ], ', ', $html ) ) ) );

		return array_filter( [
			'product_description'      => implode( "\n", $items ),
			'customer_name'            => trim( $order->get_formatted_billing_full_name() ),
			'customer_email_address'   => $order->get_billing_email(),
			'billing_address'          => $address( (string) $order->get_formatted_billing_address() ),
			'shipping_address'         => $address( (string) ( $order->get_formatted_shipping_address() ?: $order->get_formatted_billing_address() ) ),
			'shipping_carrier'         => $label ? $label['carrier_label'] : '',
			'shipping_tracking_number' => $label ? $label['tracking_number'] : '',
		] );
	}

	/** The WooCommerce order id, from the order link WooPayments adds (post=123 or id=123), else by order number. */
	private static function order_id( array $order, string $fallback_number ): int {
		$url = (string) ( $order['url'] ?? '' );
		if ( preg_match( '/[?&](?:post|id)=(\d+)/', $url, $match ) ) {
			return (int) $match[1];
		}
		$number = (string) ( $order['number'] ?? $fallback_number );
		if ( '' !== $number && ctype_digit( $number ) && function_exists( 'wc_get_order' ) && wc_get_order( (int) $number ) ) {
			return (int) $number;
		}
		return 0;
	}

	/** WooPayments amounts are in cents (minor units). */
	private static function amount( $cents, string $currency ): float {
		$zero_decimal = [ 'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf' ];
		return in_array( $currency, $zero_decimal, true ) ? (float) $cents : round( (float) $cents / 100, 2 );
	}

	/** Unix seconds/milliseconds or a date string → ISO 8601. */
	private static function date( $value ): string {
		if ( is_numeric( $value ) && (int) $value > 0 ) {
			return gmdate( 'c', (int) ( $value > 9999999999 ? $value / 1000 : $value ) );
		}
		if ( is_string( $value ) && '' !== $value ) {
			$time = strtotime( $value . ( preg_match( '/[zZ]|[+-]\d\d:?\d\d$/', $value ) ? '' : ' UTC' ) );
			return $time ? gmdate( 'c', $time ) : '';
		}
		return '';
	}
}
