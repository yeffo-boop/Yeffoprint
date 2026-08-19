<?php
/**
 * Data model for the Fully Custom Design flow (PROJECT_SPEC §13).
 *
 * Separate from Template/Batch/Variant entirely — a CustomOrder is a
 * one-off request, not a premade design (Architecture §2). Payment
 * and production tracking are deliberately split, same pattern as
 * Material/Size's post_status reuse: `post_status` is draft while the
 * $25 design fee is unpaid, publish once it's paid (see
 * class-custom-order-payment.php), and the customer-facing 6-state
 * `_yp_status` value only starts meaning anything at that point —
 * "WooCommerce order status drives payment/fulfillment; CustomOrder.
 * status drives the production workflow" (Architecture §6).
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Custom_Order_Meta {

	public const SIZE_ID             = '_yp_size_id';
	public const MATERIAL_ID         = '_yp_material_id';
	public const QUANTITY            = '_yp_quantity';
	public const COMPOUND_STRENGTH   = '_yp_compound_strength';
	public const BRAND_NAME          = '_yp_brand_name';
	public const STYLE_NOTES         = '_yp_style_notes';
	public const INSTRUCTIONS        = '_yp_instructions';
	public const INSPIRATION_UPLOADS = '_yp_inspiration_uploads';
	public const DESIGN_FEE          = '_yp_design_fee';
	public const STATUS              = '_yp_status';
	public const WC_ORDER_ID         = '_yp_wc_order_id';
	public const CUSTOMER_EMAIL      = '_yp_customer_email';
	public const CUSTOMER_NAME       = '_yp_customer_name';
	/** 0 for a guest order — set from $order->get_customer_id() at payment time. Lets the My Account "Proofs" tab (class-account-endpoints.php) query directly by meta instead of cross-referencing every order. */
	public const CUSTOMER_ID         = '_yp_customer_id';
	/**
	 * A long random secret, generated once at submission (class-custom-
	 * order-controller.php) and never rotated — the sole guest-access
	 * credential for the public proof-approval page (V2: "not everyone
	 * has an account... accessible with a link"). Anyone holding the
	 * exact link can view/respond to that one request's proofs, same
	 * trust model as an unguessable share link generally; it never
	 * grants access to anything else the customer has.
	 */
	public const ACCESS_TOKEN         = '_yp_access_token';
	/** The customer's own free-text feedback from the last "Request changes" response — staff-visible on the CustomOrder admin screen. */
	public const CHANGE_REQUEST_NOTES = '_yp_change_request_notes';

	/** In pipeline order — PROJECT_SPEC §13. */
	public const STATUSES = [
		'design_in_progress' => 'Design in progress',
		'proof_ready'        => 'Proof ready',
		'awaiting_approval'  => 'Awaiting Proof Approval',
		'approved'           => 'Approved',
		'printing'           => 'Printing',
		'shipped'            => 'Shipped',
	];

	public function __construct() {
		add_filter( 'manage_yp_custom_order_posts_columns', [ $this, 'columns' ] );
		add_action( 'manage_yp_custom_order_posts_custom_column', [ $this, 'render_column' ], 10, 2 );
	}

	public static function get_status_label( string $status ): string {
		return self::STATUSES[ $status ] ?? '';
	}

	public function columns( array $columns ): array {
		$result = [];

		foreach ( $columns as $key => $label ) {
			$result[ $key ] = $label;
			if ( 'title' === $key ) {
				$result['yp_status']   = __( 'Status', 'yeffoprint-core' );
				$result['yp_customer'] = __( 'Customer', 'yeffoprint-core' );
				$result['yp_paid']     = __( 'Paid', 'yeffoprint-core' );
			}
		}

		return $result;
	}

	public function render_column( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'yp_status':
				$status = (string) get_post_meta( $post_id, self::STATUS, true );
				echo $status ? esc_html( self::get_status_label( $status ) ) : esc_html__( '—', 'yeffoprint-core' );

				// A change request is only "live" for the design_in_progress
				// cycle it caused — once staff upload a new proof, status
				// moves on and this note is stale, so it's never shown after
				// that even though the meta itself isn't cleared.
				if ( 'design_in_progress' === $status && get_post_meta( $post_id, self::CHANGE_REQUEST_NOTES, true ) ) {
					echo ' <strong style="color:#b32d2e;">' . esc_html__( '(changes requested)', 'yeffoprint-core' ) . '</strong>';
				}
				break;

			case 'yp_customer':
				echo esc_html( get_post_meta( $post_id, self::CUSTOMER_NAME, true ) ?: get_post_meta( $post_id, self::CUSTOMER_EMAIL, true ) );
				break;

			case 'yp_paid':
				echo 'publish' === get_post_status( $post_id ) ? '✓' : '—';
				break;
		}
	}
}
