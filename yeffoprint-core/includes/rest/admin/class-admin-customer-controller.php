<?php
/**
 * Admin REST endpoints for the new Customers screen — direct request:
 * "customer list/CRM... I want to be able to add notes to customers so
 * when I print their future orders I can refer to them."
 *
 * The customer list itself is built from WordPress user accounts
 * (`WP_User_Query`, every non-administrator user — administrator is
 * this store's only staff-capable role, since `admin_write` already
 * gates every route in `includes/rest/admin/` on `manage_options`), not
 * a new aggregate table: `wc_get_customer_order_count()`/
 * `wc_get_customer_total_spent()`/`wc_get_customer_last_order()` are
 * WooCommerce's own official, storage-backend-agnostic helpers for
 * exactly these numbers (`WC_Customer` under the hood), so there's
 * nothing to reimplement or keep in sync here.
 *
 * Deliberate scope limit: this only ever lists registered accounts. A
 * guest order (no account) still gets full note support — the note
 * itself is keyed by email (`YeffoPrint_Customer_Notes`), and the order
 * drawer surfaces/adds notes straight from `class-admin-order-controller.php`'s
 * own `customer_notes` field — but a guest who's never created an
 * account won't show up as a browsable row on this list screen. Staff
 * reach that person's notes via the order they're looking at instead.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_Customer_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/admin/customers', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'list_customers' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/admin/customer/(?P<id>\d+)', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_customer' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		// Notes are keyed by email, not user id — the same endpoints
		// serve both this screen (which knows a user id and resolves it
		// to an email first) and the order drawer (which only ever has
		// the order's billing email, guest or not).
		register_rest_route( self::NAMESPACE, '/admin/customer-notes', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_notes' ],
				'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'add_note' ],
				'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/admin/customer-notes/(?P<id>\d+)', [
			'methods'             => \WP_REST_Server::DELETABLE,
			'callback'            => [ $this, 'delete_note' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );
	}

	public function list_customers( \WP_REST_Request $request ): \WP_REST_Response {
		$search   = trim( (string) $request->get_param( 'search' ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) );

		$args = [
			'number'       => $per_page,
			'paged'        => $page,
			'orderby'      => 'display_name',
			'order'        => 'ASC',
			'role__not_in' => [ 'administrator' ],
			'fields'       => 'all',
		];

		if ( '' !== $search ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = [ 'user_login', 'user_email', 'display_name' ];
		}

		$query = new \WP_User_Query( $args );
		$users = $query->get_results();

		return rest_ensure_response( [
			'customers'     => array_map( [ $this, 'summary_payload' ], $users ),
			'total'         => (int) $query->get_total(),
			'max_num_pages' => (int) ceil( $query->get_total() / $per_page ),
			'page'          => $page,
		] );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function get_customer( \WP_REST_Request $request ) {
		$user = get_userdata( (int) $request['id'] );
		if ( ! $user ) {
			return new \WP_Error( 'yeffoprint_customer_not_found', __( 'That customer could not be found.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}

		$orders = wc_get_orders( [
			'customer_id' => $user->ID,
			'limit'       => 10,
			'orderby'     => 'date',
			'order'       => 'DESC',
		] );

		return rest_ensure_response( array_merge(
			$this->summary_payload( $user ),
			[
				'registered'    => $user->user_registered,
				'notes'         => YeffoPrint_Customer_Notes::get_notes( $user->user_email ),
				'recent_orders' => array_map( static function ( \WC_Order $order ): array {
					return [
						'id'           => $order->get_id(),
						'number'       => $order->get_order_number(),
						'date'         => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : null,
						'status'       => $order->get_status(),
						'status_label' => wc_get_order_status_name( $order->get_status() ),
						'total'        => (float) $order->get_total(),
					];
				}, $orders ),
			]
		) );
	}

	private function summary_payload( \WP_User $user ): array {
		$last_order = wc_get_customer_last_order( $user->ID );

		return [
			'id'               => $user->ID,
			'name'             => $user->display_name,
			'email'            => $user->user_email,
			'order_count'      => wc_get_customer_order_count( $user->ID ),
			'total_spent'      => (float) wc_get_customer_total_spent( $user->ID ),
			'last_order_date'  => $last_order && $last_order->get_date_created() ? $last_order->get_date_created()->date( 'c' ) : null,
			'note_count'       => YeffoPrint_Customer_Notes::count_notes( $user->user_email ),
		];
	}

	public function get_notes( \WP_REST_Request $request ): \WP_REST_Response {
		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		return rest_ensure_response( YeffoPrint_Customer_Notes::get_notes( $email ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function add_note( \WP_REST_Request $request ) {
		$params = $request->get_json_params() ?: [];
		$email  = sanitize_email( (string) ( $params['email'] ?? '' ) );
		$note   = sanitize_textarea_field( (string) ( $params['note'] ?? '' ) );

		$note_id = YeffoPrint_Customer_Notes::add_note( $email, $note, get_current_user_id() );
		if ( is_wp_error( $note_id ) ) {
			return $note_id;
		}

		return rest_ensure_response( YeffoPrint_Customer_Notes::get_notes( $email ) );
	}

	public function delete_note( \WP_REST_Request $request ): \WP_REST_Response {
		YeffoPrint_Customer_Notes::delete_note( (int) $request['id'] );
		return rest_ensure_response( [ 'deleted' => true ] );
	}
}
