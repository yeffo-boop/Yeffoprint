<?php
/**
 * Admin editing experience for the Pricing Rule.
 *
 * A nondeveloper changes the base price, the $25 custom design fee,
 * and bulk discount tiers here — nothing about pricing is hard-coded
 * in a template (PROJECT_SPEC §12, §17, §20 "Do Not" list).
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Pricing_Rule_Editor {

	private const NONCE_ACTION = 'yeffoprint_save_pricing_rule';
	private const NONCE_NAME   = 'yeffoprint_pricing_rule_nonce';

	public function __construct() {
		add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
		add_action( 'save_post_yp_pricing_rule', [ $this, 'save' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function enqueue_assets( string $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'yp_pricing_rule' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style(
			'yeffoprint-core-admin',
			YEFFOPRINT_CORE_URL . 'assets/admin/admin.css',
			[],
			YEFFOPRINT_CORE_VERSION
		);

		wp_enqueue_script(
			'yeffoprint-core-pricing-tiers',
			YEFFOPRINT_CORE_URL . 'assets/admin/pricing-tiers.js',
			[],
			YEFFOPRINT_CORE_VERSION,
			true
		);

		wp_localize_script( 'yeffoprint-core-pricing-tiers', 'yeffoprintPricingTiers', [
			'tiers' => YeffoPrint_Pricing_Rule::get_tiers(),
			'types' => YeffoPrint_Pricing_Rule::TIER_TYPES,
			'i18n'  => [
				'addTier'    => __( 'Add Tier', 'yeffoprint-core' ),
				'removeTier' => __( 'Remove', 'yeffoprint-core' ),
				'empty'      => __( 'No bulk discount tiers yet — every order prices at the base rate.', 'yeffoprint-core' ),
			],
		] );
	}

	public function add_meta_boxes(): void {
		add_meta_box(
			'yp-pricing-rule-base',
			__( 'Base Pricing', 'yeffoprint-core' ),
			[ $this, 'render_base_box' ],
			'yp_pricing_rule',
			'normal'
		);

		add_meta_box(
			'yp-pricing-rule-tiers',
			__( 'Bulk Discount Tiers', 'yeffoprint-core' ),
			[ $this, 'render_tiers_box' ],
			'yp_pricing_rule',
			'normal'
		);
	}

	public function render_base_box( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$base_price = get_post_meta( $post->ID, YeffoPrint_Pricing_Rule::META_BASE_UNIT_PRICE, true );
		$design_fee = get_post_meta( $post->ID, YeffoPrint_Pricing_Rule::META_CUSTOM_DESIGN_FEE, true );
		?>
		<p>
			<label for="yp-base-unit-price"><?php esc_html_e( 'Base price per label ($)', 'yeffoprint-core' ); ?></label><br />
			<input type="number" step="0.01" min="0" id="yp-base-unit-price" name="yp_base_unit_price" value="<?php echo esc_attr( $base_price !== '' ? $base_price : '0.35' ); ?>" />
			<p class="description"><?php esc_html_e( 'Material and size price adjustments are set on each Material/Size record.', 'yeffoprint-core' ); ?></p>
		</p>
		<p>
			<label for="yp-custom-design-fee"><?php esc_html_e( 'Custom design fee ($)', 'yeffoprint-core' ); ?></label><br />
			<input type="number" step="0.01" min="0" id="yp-custom-design-fee" name="yp_custom_design_fee" value="<?php echo esc_attr( $design_fee !== '' ? $design_fee : '25.00' ); ?>" />
			<p class="description"><?php esc_html_e( 'One-time fee for the Fully Custom Design flow (shown separately from per-label price).', 'yeffoprint-core' ); ?></p>
		</p>
		<?php
	}

	public function render_tiers_box(): void {
		?>
		<div id="yp-pricing-tiers-app">
			<div class="yp-pricing-tiers-list"></div>
			<p>
				<button type="button" class="button button-secondary" id="yp-pricing-tiers-add"><?php esc_html_e( 'Add Tier', 'yeffoprint-core' ); ?></button>
			</p>
			<input type="hidden" name="yp_bulk_discount_tiers" id="yp-pricing-tiers-input" />
			<p class="description"><?php esc_html_e( 'The highest threshold at or below the order quantity applies. "Percent off" discounts a share of the per-label price; "Fixed resulting unit price" sets the per-label price directly for that tier.', 'yeffoprint-core' ); ?></p>
		</div>
		<?php
	}

	public function save( int $post_id ): void {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( wp_unslash( $_POST[ self::NONCE_NAME ] ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$base_price = isset( $_POST['yp_base_unit_price'] ) ? (float) wp_unslash( $_POST['yp_base_unit_price'] ) : 0;
		$design_fee = isset( $_POST['yp_custom_design_fee'] ) ? (float) wp_unslash( $_POST['yp_custom_design_fee'] ) : 0;

		$tiers = [];
		if ( isset( $_POST['yp_bulk_discount_tiers'] ) ) {
			$decoded = json_decode( wp_unslash( $_POST['yp_bulk_discount_tiers'] ), true );
			$tiers   = is_array( $decoded ) ? $decoded : [];
		}

		YeffoPrint_Pricing_Rule::save( $post_id, $base_price, $design_fee, $tiers );
	}
}
