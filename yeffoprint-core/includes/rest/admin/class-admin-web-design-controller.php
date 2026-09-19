<?php
/**
 * Admin REST endpoints for the Web Design post-purchase workflow
 * (class-web-design-project-meta.php) — the Agreement/Staging Site/
 * Go-Live panel the admin app's order drawer shows for any order
 * containing a Web Design Package line item
 * (class-admin-order-controller.php's own detail_payload() adds the
 * `web_design` key this panel is built from). Staff-only, same
 * `admin_write` permission every other `/admin/*` route in this plugin
 * already requires.
 *
 * Deliberately its own controller rather than folded into
 * YeffoPrint_Admin_Order_Controller — that controller is scoped to
 * "read-only past status, plus refunds/shipping," and this workflow has
 * enough of its own surface (agreement drafting, two outbound emails, a
 * secrets-reveal endpoint) to earn a dedicated file, same reasoning
 * class-admin-proof-controller.php already exists separately from it.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_Web_Design_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		// Direct request: "a new link on the admin panel to show me all
		// active web design orders... especially if I have more than one
		// web design project going at a time" — registered before the
		// parameterized /admin/web-design/{id} route below so WordPress's
		// route matching never mistakes the literal "orders" segment for
		// an {id}.
		register_rest_route( self::NAMESPACE, '/admin/web-design-orders', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'list_orders' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/admin/web-design/(?P<id>\d+)', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_project' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/admin/web-design/(?P<id>\d+)/agreement', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'save_agreement' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/admin/web-design/(?P<id>\d+)/agreement/send', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'send_agreement' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		// Direct request: milestones (and their due dates) need to stay
		// editable as a project actually progresses — signed agreement or
		// not — so this is its own always-available route rather than
		// going through /agreement above, which the admin app locks once
		// the customer has signed.
		register_rest_route( self::NAMESPACE, '/admin/web-design/(?P<id>\d+)/milestones', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'save_milestones' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/admin/web-design/(?P<id>\d+)/staging', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'save_staging' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/admin/web-design/(?P<id>\d+)/staging/send', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'send_staging' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/admin/web-design/(?P<id>\d+)/reveal', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'reveal' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/admin/web-design/(?P<id>\d+)/mark-live', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'mark_live' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );
	}

	/**
	 * Every Web Design Package order, newest first, each already carrying
	 * enough to render a row without a second request per order — see
	 * YeffoPrint_Web_Design_Project_Meta::get_all_orders()'s own docblock
	 * for why this is one bounded query rather than the drawer's own
	 * per-order line-item scan.
	 */
	public function list_orders(): \WP_REST_Response {
		$rows = array_map( function ( \WC_Order $order ) {
			$package_id = YeffoPrint_Web_Design_Project_Meta::get_package_id( $order );
			$package    = $package_id ? get_post( $package_id ) : null;
			$agreement  = YeffoPrint_Web_Design_Project_Meta::get_agreement( $order );

			return [
				'id'            => $order->get_id(),
				'number'        => $order->get_order_number(),
				'customer_name' => trim( $order->get_formatted_billing_full_name() ),
				'customer_email' => $order->get_billing_email(),
				'package_name'  => $package ? $package->post_title : __( 'Web Design', 'yeffoprint-core' ),
				'stage'         => YeffoPrint_Web_Design_Project_Meta::get_stage( $order ),
				'is_live'       => YeffoPrint_Web_Design_Project_Meta::is_live( $order ),
				'kickoff_date'  => $agreement['kickoff_date'],
				'staging_due'   => $agreement['staging_due'],
				'golive_due'    => $agreement['golive_due'],
				'total'         => (float) $order->get_total(),
				'date'          => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : null,
			];
		}, YeffoPrint_Web_Design_Project_Meta::get_all_orders() );

		// Active projects first (not-yet-live), most recently placed
		// within each group — usort is stable in PHP 8, so ties keep the
		// query's own newest-first order.
		usort( $rows, static fn( array $a, array $b ) => $a['is_live'] <=> $b['is_live'] );

		return rest_ensure_response( [ 'orders' => $rows ] );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function get_project( \WP_REST_Request $request ) {
		$order = $this->validate_order( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		return rest_ensure_response( $this->project_payload( $order ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function save_agreement( \WP_REST_Request $request ) {
		$order = $this->validate_order( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$fields = $request->get_json_params() ?: [];
		YeffoPrint_Web_Design_Project_Meta::save_agreement( $order, $fields );

		return rest_ensure_response( $this->project_payload( $order ) );
	}

	/**
	 * Always available, signed or not — see the route registration above
	 * and YeffoPrint_Web_Design_Project_Meta::save_milestones()'s own
	 * docblock for why milestones don't go through save_agreement().
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_milestones( \WP_REST_Request $request ) {
		$order = $this->validate_order( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$params = $request->get_json_params() ?: [];
		YeffoPrint_Web_Design_Project_Meta::save_milestones( $order, is_array( $params['milestones'] ?? null ) ? $params['milestones'] : [] );

		return rest_ensure_response( $this->project_payload( $order ) );
	}

	/**
	 * Emails the customer their sign-the-agreement link. Best-effort like
	 * every other customer notice in this plugin (YeffoPrint_Proof_Meta's
	 * own docblock) — a missing/invalid billing email never blocks
	 * anything, staff can always copy get_agreement_url() and send it by
	 * hand, so this reports success either way rather than erroring the
	 * whole request over a mail problem.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function send_agreement( \WP_REST_Request $request ) {
		$order = $this->validate_order( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$email = $order->get_billing_email();
		if ( $email && is_email( $email ) ) {
			$package_name = $this->package_name( $order );
			$site_name    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

			WC()->mailer();
			require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-email-web-design-agreement-notice.php';
			( new YeffoPrint_Email_Web_Design_Agreement_Notice() )->send_notice(
				$email,
				sprintf(
					/* translators: %s: site name */
					__( 'Please sign your web design agreement — %s', 'yeffoprint-core' ),
					$site_name
				),
				[
					'email_heading' => __( 'Please review & sign your agreement', 'yeffoprint-core' ),
					'name'          => $order->get_billing_first_name() ?: __( 'there', 'yeffoprint-core' ),
					'package_name'  => $package_name,
					'cta_url'       => YeffoPrint_Web_Design_Project_Meta::get_agreement_url( $order ),
					'stepper_html'  => YeffoPrint_Order_Status_Stepper::render_email_html( YeffoPrint_Web_Design_Project_Meta::get_stepper_steps( $order ) ),
				]
			);
		}

		$order->update_meta_data( YeffoPrint_Web_Design_Project_Meta::AGREEMENT_SENT_AT, current_time( 'mysql' ) );
		$order->add_order_note( __( 'Agreement emailed to the customer for signature.', 'yeffoprint-core' ) );
		$order->save();

		return rest_ensure_response( $this->project_payload( $order ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function save_staging( \WP_REST_Request $request ) {
		$order = $this->validate_order( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$fields = $request->get_json_params() ?: [];
		YeffoPrint_Web_Design_Project_Meta::save_staging( $order, $fields );

		return rest_ensure_response( $this->project_payload( $order ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function send_staging( \WP_REST_Request $request ) {
		$order = $this->validate_order( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$staging = YeffoPrint_Web_Design_Project_Meta::get_staging( $order );
		if ( '' === $staging['staging_url'] ) {
			return new \WP_Error( 'yeffoprint_staging_not_ready', __( 'Add a staging URL before sending credentials to the customer.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$email = $order->get_billing_email();
		if ( $email && is_email( $email ) ) {
			$revealed  = YeffoPrint_Web_Design_Project_Meta::get_staging( $order, true );
			$due_meta  = (string) $order->get_meta( YeffoPrint_Web_Design_Project_Meta::STAGING_DUE_DATE );
			$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

			WC()->mailer();
			require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-email-web-design-staging-notice.php';
			( new YeffoPrint_Email_Web_Design_Staging_Notice() )->send_notice(
				$email,
				sprintf(
					/* translators: %s: site name */
					__( 'Your staging site is ready to preview — %s', 'yeffoprint-core' ),
					$site_name
				),
				[
					'email_heading'    => __( 'Your staging site is ready for review', 'yeffoprint-core' ),
					'name'             => $order->get_billing_first_name() ?: __( 'there', 'yeffoprint-core' ),
					'staging_url'      => $staging['staging_url'],
					'preview_user'     => $staging['preview_user'],
					'preview_password' => $revealed['preview_password'],
					'note'             => $staging['note'],
					'cta_review_url'   => YeffoPrint_Web_Design_Project_Meta::get_staging_review_url( $order ),
					'revisions_due'    => $due_meta,
					'stepper_html'     => YeffoPrint_Order_Status_Stepper::render_email_html( YeffoPrint_Web_Design_Project_Meta::get_stepper_steps( $order ) ),
				]
			);
		}

		YeffoPrint_Web_Design_Project_Meta::mark_staging_sent( $order );

		return rest_ensure_response( $this->project_payload( $order ) );
	}

	/**
	 * Returns decrypted secrets on demand — kept out of the main GET
	 * payload above (which only ever reports `has_password` flags) so a
	 * plain "view the order" request never carries a plaintext credential
	 * over the wire, and so every reveal leaves its own order-note trail
	 * (YeffoPrint_Web_Design_Project_Meta::mark_credentials_revealed()).
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function reveal( \WP_REST_Request $request ) {
		$order = $this->validate_order( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$params = $request->get_json_params() ?: [];
		$which  = sanitize_key( (string) ( $params['which'] ?? '' ) );

		if ( ! in_array( $which, [ 'staging_admin', 'staging_preview', 'golive' ], true ) ) {
			return new \WP_Error( 'yeffoprint_invalid_reveal', __( 'Nothing to reveal.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		if ( 'golive' === $which ) {
			$golive = YeffoPrint_Web_Design_Project_Meta::get_golive( $order, true );
			$value  = $golive['password'];
			$label  = __( 'go-live', 'yeffoprint-core' );
		} else {
			$staging = YeffoPrint_Web_Design_Project_Meta::get_staging( $order, true );
			$value   = 'staging_admin' === $which ? $staging['admin_password'] : $staging['preview_password'];
			$label   = 'staging_admin' === $which ? __( 'staging admin', 'yeffoprint-core' ) : __( 'staging preview', 'yeffoprint-core' );
		}

		YeffoPrint_Web_Design_Project_Meta::mark_credentials_revealed( $order, $label );
		$order->save();

		return rest_ensure_response( [ 'value' => $value ] );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function mark_live( \WP_REST_Request $request ) {
		$order = $this->validate_order( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		YeffoPrint_Web_Design_Project_Meta::mark_live( $order );

		if ( 'completed' !== $order->get_status() ) {
			$order->set_status( 'completed', __( 'Web design site marked live.', 'yeffoprint-core' ) );
			$order->save();
		}

		return rest_ensure_response( $this->project_payload( $order ) );
	}

	/** @return \WC_Order|\WP_Error */
	private function validate_order( int $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return new \WP_Error( 'yeffoprint_woocommerce_inactive', __( 'WooCommerce is not active.', 'yeffoprint-core' ), [ 'status' => 500 ] );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error( 'yeffoprint_order_not_found', __( 'That order could not be found.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}

		if ( ! YeffoPrint_Web_Design_Project_Meta::is_web_design_order( $order ) ) {
			return new \WP_Error( 'yeffoprint_not_web_design_order', __( 'This order has no Web Design Package on it.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		return $order;
	}

	private function package_name( \WC_Order $order ): string {
		$package_id = YeffoPrint_Web_Design_Project_Meta::get_package_id( $order );
		$package    = $package_id ? get_post( $package_id ) : null;
		return $package ? $package->post_title : __( 'your web design project', 'yeffoprint-core' );
	}

	private function project_payload( \WC_Order $order ): array {
		return [
			'order_id'       => $order->get_id(),
			'package_id'     => YeffoPrint_Web_Design_Project_Meta::get_package_id( $order ),
			'package_name'   => $this->package_name( $order ),
			'stage'          => YeffoPrint_Web_Design_Project_Meta::get_stage( $order ),
			'stepper_html'   => YeffoPrint_Order_Status_Stepper::render_html( YeffoPrint_Web_Design_Project_Meta::get_stepper_steps( $order ) ),
			// Always available to copy/resend by hand — same "staff can
			// always fall back to a direct link" reasoning as
			// yeffoprint_core_proof_approval_url()'s own callers; a
			// bounced/missing billing email should never leave staff with
			// no way to reach the customer.
			'links'          => [
				'agreement'      => YeffoPrint_Web_Design_Project_Meta::get_agreement_url( $order ),
				'staging_review' => YeffoPrint_Web_Design_Project_Meta::get_staging_review_url( $order ),
				'golive'         => YeffoPrint_Web_Design_Project_Meta::get_golive_url( $order ),
			],
			'agreement'      => YeffoPrint_Web_Design_Project_Meta::get_agreement( $order ),
			'staging'        => YeffoPrint_Web_Design_Project_Meta::get_staging( $order ),
			'client_response' => [
				'response' => (string) $order->get_meta( YeffoPrint_Web_Design_Project_Meta::CLIENT_RESPONSE ),
				'notes'    => (string) $order->get_meta( YeffoPrint_Web_Design_Project_Meta::CLIENT_RESPONSE_NOTES ),
				'at'       => (string) $order->get_meta( YeffoPrint_Web_Design_Project_Meta::CLIENT_RESPONSE_AT ),
			],
			'golive'         => YeffoPrint_Web_Design_Project_Meta::get_golive( $order ),
			'is_live'        => YeffoPrint_Web_Design_Project_Meta::is_live( $order ),
		];
	}
}
