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

	/**
	 * Which flow created this request — 'label' (Fully Custom Design,
	 * PROJECT_SPEC §13, the only value that existed before Custom
	 * Stickers) or 'sticker' (Custom Stickers). Defaults to 'label' via
	 * get_order_type() below so every pre-existing CustomOrder, which
	 * has no meta row for this at all, keeps behaving exactly as before
	 * without a migration. SIZE_ID/MATERIAL_ID/QUANTITY are reused as-is
	 * for both flows — a size/material/quantity is a size/material/
	 * quantity regardless of which product it's for; SIZE_ID just
	 * points at a yp_size post for a label order and a yp_sticker_size
	 * post for a sticker one, which every reader of this record already
	 * has to branch on ORDER_TYPE for anyway.
	 */
	public const ORDER_TYPE = '_yp_order_type';

	public const ORDER_TYPES = [
		'label'   => 'Custom Label',
		'sticker' => 'Custom Sticker',
	];

	public const SIZE_ID             = '_yp_size_id';
	public const MATERIAL_ID         = '_yp_material_id';
	public const QUANTITY            = '_yp_quantity';
	public const COMPOUND_STRENGTH   = '_yp_compound_strength';
	public const BRAND_NAME          = '_yp_brand_name';
	public const STYLE_NOTES         = '_yp_style_notes';
	public const INSTRUCTIONS        = '_yp_instructions';
	public const INSPIRATION_UPLOADS = '_yp_inspiration_uploads';
	/** Sticker orders only — the actual print-ready artwork file(s), a distinct field from INSPIRATION_UPLOADS (a label order's visual reference images, never printed as-is). */
	public const ARTWORK_UPLOADS     = '_yp_artwork_uploads';
	/** Sticker orders only — 'sheet' or one of YeffoPrint_Sticker_Pricing::TYPES. */
	public const STICKER_TYPE        = '_yp_sticker_type';
	/** Sticker orders only — one of YeffoPrint_Sticker_Pricing::SHAPES. 'custom' means contour-cut to the artwork's own outline, not a separately uploaded cut path (direct decision). */
	public const SHAPE               = '_yp_shape';
	/** Sticker orders only, and only meaningful when SIZE_ID points at the Sticker Size marked is_custom — the customer's own entered dimensions. */
	public const CUSTOM_WIDTH_IN     = '_yp_custom_width_in';
	public const CUSTOM_HEIGHT_IN    = '_yp_custom_height_in';
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

	/** Defaults to 'label' — every CustomOrder created before Custom Stickers existed has no ORDER_TYPE meta row at all. */
	public static function get_order_type( int $post_id ): string {
		$type = get_post_meta( $post_id, self::ORDER_TYPE, true );
		return array_key_exists( $type, self::ORDER_TYPES ) ? $type : 'label';
	}

	public function columns( array $columns ): array {
		$result = [];

		foreach ( $columns as $key => $label ) {
			$result[ $key ] = $label;
			if ( 'title' === $key ) {
				$result['yp_order_type'] = __( 'Type', 'yeffoprint-core' );
				$result['yp_status']     = __( 'Status', 'yeffoprint-core' );
				$result['yp_customer']   = __( 'Customer', 'yeffoprint-core' );
				$result['yp_paid']       = __( 'Paid', 'yeffoprint-core' );
			}
		}

		return $result;
	}

	public function render_column( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'yp_order_type':
				echo esc_html( self::ORDER_TYPES[ self::get_order_type( $post_id ) ] );
				break;

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
