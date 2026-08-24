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
			// Same load-order reasoning as class-template-editor.php's
			// own copy of this call — see there.
			[ 'yeffoprint-core-admin-shell' ],
			yeffoprint_core_asset_version( 'assets/admin/admin.css' )
		);

		wp_enqueue_script(
			'yeffoprint-core-pricing-tiers',
			YEFFOPRINT_CORE_URL . 'assets/admin/pricing-tiers.js',
			[],
			yeffoprint_core_asset_version( 'assets/admin/pricing-tiers.js' ),
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

		// A second, independent repeater for sticker bulk-discount tiers —
		// its own script file (not a second load of pricing-tiers.js,
		// which hardcodes one global name and one set of DOM ids and so
		// can't run twice on the same page) targeting its own DOM ids.
		// Sticker quantities/thresholds are a different scale than label
		// ones, so they're never mixed into one tier list
		// (YeffoPrint_Sticker_Pricing's own docblock explains why the two
		// formulas are kept separate).
		wp_enqueue_script(
			'yeffoprint-core-sticker-pricing-tiers',
			YEFFOPRINT_CORE_URL . 'assets/admin/sticker-pricing-tiers.js',
			[],
			yeffoprint_core_asset_version( 'assets/admin/sticker-pricing-tiers.js' ),
			true
		);

		wp_localize_script( 'yeffoprint-core-sticker-pricing-tiers', 'yeffoprintStickerPricingTiers', [
			'tiers' => YeffoPrint_Sticker_Pricing::get_tiers(),
			'types' => YeffoPrint_Pricing_Rule::TIER_TYPES,
			'i18n'  => [
				'addTier'    => __( 'Add Tier', 'yeffoprint-core' ),
				'removeTier' => __( 'Remove', 'yeffoprint-core' ),
				'empty'      => __( 'No sticker bulk discount tiers yet — every order prices at the base rate.', 'yeffoprint-core' ),
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

		add_meta_box(
			'yp-pricing-rule-sticker',
			__( 'Sticker Pricing', 'yeffoprint-core' ),
			[ $this, 'render_sticker_box' ],
			'yp_pricing_rule',
			'normal'
		);

		add_meta_box(
			'yp-pricing-rule-sticker-tiers',
			__( 'Sticker Bulk Discount Tiers', 'yeffoprint-core' ),
			[ $this, 'render_sticker_tiers_box' ],
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
			<p class="description"><?php esc_html_e( 'The highest threshold at or below the customer\'s combined label count applies to their whole order — they can mix and match different designs, sizes, and materials to reach it, it doesn\'t need to come from one design alone. The discount only ever applies to the base price per label; material and size upcharges are always added on top afterward, at full price. "Percent off base price" discounts a share of the base price; "Fixed resulting base price" sets the base price directly for that tier.', 'yeffoprint-core' ); ?></p>
		</div>
		<?php
	}

	public function render_sticker_box( \WP_Post $post ): void {
		$rate = get_post_meta( $post->ID, YeffoPrint_Sticker_Pricing::META_CUSTOM_RATE_PER_SQ_IN, true );
		$type_adjustments  = YeffoPrint_Sticker_Pricing::get_type_adjustments();
		$shape_adjustments = YeffoPrint_Sticker_Pricing::get_shape_adjustments();
		?>
		<p>
			<label for="yp-sticker-custom-rate"><?php esc_html_e( 'Custom size rate ($ per sq. inch)', 'yeffoprint-core' ); ?></label><br />
			<input type="number" step="0.01" min="0" id="yp-sticker-custom-rate" name="yp_sticker_custom_rate_per_sq_in" value="<?php echo esc_attr( $rate !== '' ? $rate : '0.75' ); ?>" />
			<p class="description"><?php esc_html_e( 'Used only for the Sticker Size marked "Custom size" — price = this rate × the width × height the customer enters. Every other size tier uses its own fixed price instead (Sticker Sizes screen).', 'yeffoprint-core' ); ?></p>
		</p>
		<hr />
		<p><strong><?php esc_html_e( 'Sticker type adjustment ($, added to size price)', 'yeffoprint-core' ); ?></strong></p>
		<?php foreach ( YeffoPrint_Sticker_Pricing::TYPES as $key => $label ) : ?>
			<p>
				<label for="yp-sticker-type-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label><br />
				<input type="number" step="0.01" id="yp-sticker-type-<?php echo esc_attr( $key ); ?>" name="yp_sticker_type_adjustments[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $type_adjustments[ $key ] ); ?>" />
			</p>
		<?php endforeach; ?>
		<hr />
		<p><strong><?php esc_html_e( 'Shape adjustment ($, added to size price)', 'yeffoprint-core' ); ?></strong></p>
		<?php foreach ( YeffoPrint_Sticker_Pricing::SHAPES as $key => $label ) : ?>
			<p>
				<label for="yp-sticker-shape-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label><br />
				<input type="number" step="0.01" id="yp-sticker-shape-<?php echo esc_attr( $key ); ?>" name="yp_sticker_shape_adjustments[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $shape_adjustments[ $key ] ); ?>" />
			</p>
		<?php endforeach; ?>
		<?php
	}

	public function render_sticker_tiers_box(): void {
		?>
		<div id="yp-sticker-pricing-tiers-app">
			<div class="yp-sticker-pricing-tiers-list"></div>
			<p>
				<button type="button" class="button button-secondary" id="yp-sticker-pricing-tiers-add"><?php esc_html_e( 'Add Tier', 'yeffoprint-core' ); ?></button>
			</p>
			<input type="hidden" name="yp_sticker_bulk_discount_tiers" id="yp-sticker-pricing-tiers-input" />
			<p class="description"><?php esc_html_e( 'Same rules as the label tiers above, evaluated separately against the customer\'s combined sticker quantity — a sticker order never counts toward the label bulk discount, or vice versa. The discount only ever applies to the sticker size\'s base price; material/type/shape upcharges are always added on top afterward, at full price.', 'yeffoprint-core' ); ?></p>
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

		$sticker_rate = isset( $_POST['yp_sticker_custom_rate_per_sq_in'] ) ? (float) wp_unslash( $_POST['yp_sticker_custom_rate_per_sq_in'] ) : 0;

		$type_adjustments = [];
		if ( isset( $_POST['yp_sticker_type_adjustments'] ) && is_array( $_POST['yp_sticker_type_adjustments'] ) ) {
			$type_adjustments = array_map( 'floatval', wp_unslash( $_POST['yp_sticker_type_adjustments'] ) );
		}

		$shape_adjustments = [];
		if ( isset( $_POST['yp_sticker_shape_adjustments'] ) && is_array( $_POST['yp_sticker_shape_adjustments'] ) ) {
			$shape_adjustments = array_map( 'floatval', wp_unslash( $_POST['yp_sticker_shape_adjustments'] ) );
		}

		$sticker_tiers = [];
		if ( isset( $_POST['yp_sticker_bulk_discount_tiers'] ) ) {
			$decoded       = json_decode( wp_unslash( $_POST['yp_sticker_bulk_discount_tiers'] ), true );
			$sticker_tiers = is_array( $decoded ) ? $decoded : [];
		}

		YeffoPrint_Sticker_Pricing::save( $post_id, $sticker_rate, $type_adjustments, $shape_adjustments, $sticker_tiers );
	}
}
