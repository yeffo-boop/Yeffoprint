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
	 * Stickers), 'sticker' (Custom Stickers), or 'template' (a real
	 * Template order manually created via the admin app's "Create Order"
	 * screen with proof approval requested — see class-manual-order-
	 * creator.php's own docblock for why this is the one case that needs
	 * its own ORDER_TYPE: unlike 'label'/'sticker', which are both
	 * freeform Custom Design/Sticker submissions, a 'template' shell
	 * wraps a real, existing yp_template + its own field_schema/variants,
	 * something the customer-facing flow never routes through the proof-
	 * approval pipeline at all — only staff manually opting in to it can).
	 * Defaults to 'label' via get_order_type() below so every pre-existing
	 * CustomOrder, which has no meta row for this at all, keeps behaving
	 * exactly as before without a migration. SIZE_ID/MATERIAL_ID/QUANTITY
	 * are reused as-is across all three flows — a size/material/quantity
	 * is a size/material/quantity regardless of which product it's for;
	 * SIZE_ID just points at a yp_size post for a label or template order
	 * and a yp_sticker_size post for a sticker one, which every reader of
	 * this record already has to branch on ORDER_TYPE for anyway.
	 */
	public const ORDER_TYPE = '_yp_order_type';

	public const ORDER_TYPES = [
		'label'    => 'Custom Label',
		'sticker'  => 'Custom Sticker',
		'template' => 'Template Label',
	];

	public const SIZE_ID             = '_yp_size_id';
	public const MATERIAL_ID         = '_yp_material_id';
	public const QUANTITY            = '_yp_quantity';
	public const COMPOUND_STRENGTH   = '_yp_compound_strength';
	public const BRAND_NAME          = '_yp_brand_name';
	public const STYLE_NOTES         = '_yp_style_notes';
	public const INSTRUCTIONS        = '_yp_instructions';
	public const INSPIRATION_UPLOADS = '_yp_inspiration_uploads';
	/**
	 * The actual print-ready artwork file(s), a distinct field from
	 * INSPIRATION_UPLOADS (a label order's visual reference images,
	 * never printed as-is). Originally sticker-only; also used by the
	 * label flow's "I have my own print-ready design" path (CUSTOMER_
	 * PROVIDED_DESIGN below) — same real meaning both times, so this
	 * one field covers both rather than adding a near-duplicate key.
	 */
	public const ARTWORK_UPLOADS     = '_yp_artwork_uploads';
	/** Sticker orders only — 'sheet' or one of YeffoPrint_Sticker_Pricing::TYPES. */
	public const STICKER_TYPE        = '_yp_sticker_type';
	/** Sticker orders only — one of YeffoPrint_Sticker_Pricing::SHAPES. 'custom' means contour-cut to the artwork's own outline, not a separately uploaded cut path (direct decision). */
	public const SHAPE               = '_yp_shape';
	/** Sticker orders only, and only meaningful when SIZE_ID points at the Sticker Size marked is_custom — the customer's own entered dimensions. */
	public const CUSTOM_WIDTH_IN     = '_yp_custom_width_in';
	public const CUSTOM_HEIGHT_IN    = '_yp_custom_height_in';
	/** Template orders only — the yp_template this shell wraps. */
	public const TEMPLATE_ID         = '_yp_co_template_id';
	/**
	 * Template orders only — a JSON-encoded array of
	 * { quantity, values: { field_id: value } }, same shape as a cart
	 * item's own VARIANTS (YeffoPrint_Cart_Item_Keys) — one entry per
	 * distinct customization in the batch, exactly what
	 * YeffoPrint_Field_Schema::sanitize_variants() already validates for
	 * every other entry point into this data shape.
	 */
	public const TEMPLATE_VARIANTS   = '_yp_co_template_variants';
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

	/**
	 * Direct request: two ways to skip the $25 design fee. Points back to
	 * the source yp_custom_order this one was reordered from — absent
	 * means this order was either a normal new-design submission or a
	 * CUSTOMER_PROVIDED_DESIGN one, never both at once with this.
	 */
	public const SOURCE_CUSTOM_ORDER_ID = '_yp_source_custom_order_id';

	/**
	 * '1' when the customer already had their own completed, print-ready
	 * design and just wants it printed — no design work needed, so the
	 * fee is skipped. ARTWORK_UPLOADS holds the actual file(s) in this
	 * case, not INSPIRATION_UPLOADS.
	 */
	public const CUSTOMER_PROVIDED_DESIGN = '_yp_customer_provided_design';

	/**
	 * Batching: a JSON-encoded array of
	 * { size_id, material_id, quantity, compound_strength }, one entry
	 * per label in the order — direct request, so a customer needing more
	 * than one compound/strength/size doesn't have to submit (and pay
	 * the fee) separately for each. A repeating structured unit, the one
	 * case PROJECT_SPEC's "never one opaque blob" rule already carves out
	 * for _yp_variants on the Template side (class-order-item-meta.php) —
	 * same reasoning applies here. SIZE_ID/MATERIAL_ID/QUANTITY/
	 * COMPOUND_STRENGTH above stay populated from this array's first row
	 * on every order (including pre-batching ones, which only ever had
	 * one row), so anything reading only those single-row fields keeps
	 * working unchanged.
	 */
	public const BATCH = '_yp_batch';

	/** In pipeline order — PROJECT_SPEC §13. */
	public const STATUSES = [
		'design_in_progress' => 'Design in progress',
		'proof_ready'        => 'Proof ready',
		'awaiting_approval'  => 'Awaiting Proof Approval',
		'approved'           => 'Approved',
		'printing'           => 'Printing',
		'shipped'            => 'Shipped',
	];

	/**
	 * Which statuses mean a past order's design is actually finished and
	 * reusable enough to reorder without paying the fee again — anything
	 * still design_in_progress/proof_ready/awaiting_approval hasn't
	 * produced a real, finished design yet, so a "reorder" of one of
	 * those would just be asking for fresh (unpaid) design work.
	 */
	public const FEE_FREE_REORDER_STATUSES = [ 'approved', 'printing', 'shipped' ];

	public function __construct() {
		add_filter( 'manage_yp_custom_order_posts_columns', [ $this, 'columns' ] );
		add_action( 'manage_yp_custom_order_posts_custom_column', [ $this, 'render_column' ], 10, 2 );
	}

	public static function get_status_label( string $status ): string {
		return self::STATUSES[ $status ] ?? '';
	}

	/**
	 * Creates a fresh, unpublished yp_custom_order shell with a real
	 * ACCESS_TOKEN already set — the one thing every creation path needs
	 * before it can add its own mode-specific meta (batch rows, sticker
	 * fields, whatever). Extracted from class-custom-order-controller.php's
	 * submit() so the admin app's manual-order creator (which never runs
	 * through that customer-facing endpoint) can mint the same kind of
	 * record. Stays 'draft' until class-custom-order-payment.php's
	 * link_paid_custom_orders() publishes it once a linked WC order line
	 * item actually reaches 'processing' — same rule regardless of origin.
	 *
	 * $customer_id/$customer_email/$customer_name are passed through as
	 * given rather than looked up here — the customer submission path
	 * still leaves them empty at this point (filled in later from the WC
	 * order's billing details, unchanged), while the admin manual-order
	 * path already has a real customer to record immediately.
	 *
	 * @return int 0 on failure.
	 */
	public static function create_shell( string $order_type, string $title, int $customer_id, string $customer_email, string $customer_name ): int {
		$id = wp_insert_post( [
			'post_type'   => 'yp_custom_order',
			'post_status' => 'draft',
			'post_title'  => $title,
		], true );

		if ( is_wp_error( $id ) ) {
			return 0;
		}

		update_post_meta( $id, self::ORDER_TYPE, $order_type );
		update_post_meta( $id, self::ACCESS_TOKEN, wp_generate_password( 40, false ) );
		update_post_meta( $id, self::CUSTOMER_ID, $customer_id );
		update_post_meta( $id, self::CUSTOMER_EMAIL, $customer_email );
		update_post_meta( $id, self::CUSTOMER_NAME, $customer_name );

		return $id;
	}

	/** Defaults to 'label' — every CustomOrder created before Custom Stickers existed has no ORDER_TYPE meta row at all. */
	public static function get_order_type( int $post_id ): string {
		$type = get_post_meta( $post_id, self::ORDER_TYPE, true );
		return array_key_exists( $type, self::ORDER_TYPES ) ? $type : 'label';
	}

	/**
	 * Whether $customer_id may reorder $custom_order_id without paying
	 * the design fee again — published, a label order (stickers have no
	 * flat fee to skip in the first place — see class-custom-order-
	 * payment.php's find_design_fee()), owned by this customer, and far
	 * enough along the pipeline that a finished design actually exists.
	 */
	public static function is_eligible_for_fee_free_reorder( int $custom_order_id, int $customer_id ): bool {
		if ( ! $customer_id || 'publish' !== get_post_status( $custom_order_id ) ) {
			return false;
		}

		if ( 'label' !== self::get_order_type( $custom_order_id ) ) {
			return false;
		}

		$owner_id = (int) get_post_meta( $custom_order_id, self::CUSTOMER_ID, true );
		if ( $owner_id !== $customer_id ) {
			return false;
		}

		$status = (string) get_post_meta( $custom_order_id, self::STATUS, true );
		return in_array( $status, self::FEE_FREE_REORDER_STATUSES, true );
	}

	/**
	 * Whether this order's $25 fee was intentionally never charged (a
	 * customer-provided design, or a fee-free reorder) — so "no fee line
	 * item on the WooCommerce order" is never mistaken for "the fee just
	 * hasn't been paid yet" anywhere that checks for one (find_design_fee(),
	 * the admin editor, class-reorder.php's own reorder-link rendering).
	 */
	public static function is_fee_skipped( int $custom_order_id ): bool {
		return (bool) get_post_meta( $custom_order_id, self::CUSTOMER_PROVIDED_DESIGN, true )
			|| (bool) get_post_meta( $custom_order_id, self::SOURCE_CUSTOM_ORDER_ID, true );
	}

	/**
	 * The batch rows for this order, decoded from BATCH — falling back to
	 * a single row built from the legacy SIZE_ID/MATERIAL_ID/QUANTITY/
	 * COMPOUND_STRENGTH fields for any order submitted before batching
	 * existed (which never wrote BATCH at all).
	 *
	 * @return array<int, array{size_id:int, material_id:int, quantity:int, compound_strength:string}>
	 */
	public static function get_batch_rows( int $custom_order_id ): array {
		$raw  = (string) get_post_meta( $custom_order_id, self::BATCH, true );
		$rows = $raw ? json_decode( $raw, true ) : null;

		if ( is_array( $rows ) && $rows ) {
			return $rows;
		}

		return [ [
			'size_id'           => (int) get_post_meta( $custom_order_id, self::SIZE_ID, true ),
			'material_id'       => (int) get_post_meta( $custom_order_id, self::MATERIAL_ID, true ),
			'quantity'          => (int) get_post_meta( $custom_order_id, self::QUANTITY, true ),
			'compound_strength' => (string) get_post_meta( $custom_order_id, self::COMPOUND_STRENGTH, true ),
		] ];
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
