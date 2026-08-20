<?php
/**
 * Admin meta box + list columns for Sticker Size records.
 *
 * Same classic nonce-verified meta box pattern as
 * class-material-size-editor.php. The one wrinkle Material/Size don't
 * have: at most one Sticker Size can be the "Custom size" tier
 * (YeffoPrint_Sticker_Size_Meta::IS_CUSTOM) — checking it on one
 * record un-checks it everywhere else on save, so
 * YeffoPrint_Sticker_Size_Meta::get_custom_tier_id() never has to
 * pick among several.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Sticker_Size_Editor {

	private const NONCE_ACTION = 'yeffoprint_save_sticker_size';
	private const NONCE_NAME   = 'yeffoprint_sticker_size_nonce';

	public function __construct() {
		add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
		add_action( 'save_post_yp_sticker_size', [ $this, 'save' ] );

		add_filter( 'manage_yp_sticker_size_posts_columns', [ $this, 'columns' ] );
		add_action( 'manage_yp_sticker_size_posts_custom_column', [ $this, 'render_column' ], 10, 2 );
	}

	public function add_meta_boxes(): void {
		add_meta_box(
			'yp-sticker-size-details',
			__( 'Sticker Size Details', 'yeffoprint-core' ),
			[ $this, 'render_box' ],
			'yp_sticker_size',
			'side'
		);
	}

	public function render_box( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$width     = get_post_meta( $post->ID, YeffoPrint_Sticker_Size_Meta::WIDTH_IN, true );
		$height    = get_post_meta( $post->ID, YeffoPrint_Sticker_Size_Meta::HEIGHT_IN, true );
		$price     = get_post_meta( $post->ID, YeffoPrint_Sticker_Size_Meta::PRICE, true );
		$is_custom = (bool) get_post_meta( $post->ID, YeffoPrint_Sticker_Size_Meta::IS_CUSTOM, true );
		?>
		<p>
			<label>
				<input type="checkbox" id="yp-sticker-size-is-custom" name="yp_is_custom_size" value="1" <?php checked( $is_custom ); ?> />
				<?php esc_html_e( 'This is the "Custom size" tier', 'yeffoprint-core' ); ?>
			</label>
			<p class="description"><?php esc_html_e( 'The customer types an exact width/height instead of picking a fixed size; price is computed live from the $/sq in rate set on the Pricing Rules screen. Width, height, and price below are ignored for this record. Checking this box unchecks it on any other Sticker Size.', 'yeffoprint-core' ); ?></p>
		</p>
		<div id="yp-sticker-size-fixed-fields" <?php echo $is_custom ? 'style="display:none;"' : ''; ?>>
			<p>
				<label for="yp-sticker-width"><?php esc_html_e( 'Width (inches)', 'yeffoprint-core' ); ?></label><br />
				<input type="number" step="0.01" min="0" id="yp-sticker-width" name="yp_width_in" value="<?php echo esc_attr( $width !== '' ? $width : '0' ); ?>" class="widefat" />
			</p>
			<p>
				<label for="yp-sticker-height"><?php esc_html_e( 'Height (inches)', 'yeffoprint-core' ); ?></label><br />
				<input type="number" step="0.01" min="0" id="yp-sticker-height" name="yp_height_in" value="<?php echo esc_attr( $height !== '' ? $height : '0' ); ?>" class="widefat" />
			</p>
			<p>
				<label for="yp-sticker-price"><?php esc_html_e( 'Price per sticker ($)', 'yeffoprint-core' ); ?></label><br />
				<input type="number" step="0.01" min="0" id="yp-sticker-price" name="yp_price" value="<?php echo esc_attr( $price !== '' ? $price : '0' ); ?>" class="widefat" />
			</p>
		</div>
		<script>
		( function () {
			var checkbox = document.getElementById( 'yp-sticker-size-is-custom' );
			var fields = document.getElementById( 'yp-sticker-size-fixed-fields' );
			if ( checkbox && fields ) {
				checkbox.addEventListener( 'change', function () {
					fields.style.display = checkbox.checked ? 'none' : '';
				} );
			}
		} )();
		</script>
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

		$is_custom = ! empty( $_POST['yp_is_custom_size'] );
		update_post_meta( $post_id, YeffoPrint_Sticker_Size_Meta::IS_CUSTOM, $is_custom );

		if ( isset( $_POST['yp_width_in'] ) ) {
			update_post_meta( $post_id, YeffoPrint_Sticker_Size_Meta::WIDTH_IN, (float) wp_unslash( $_POST['yp_width_in'] ) );
		}

		if ( isset( $_POST['yp_height_in'] ) ) {
			update_post_meta( $post_id, YeffoPrint_Sticker_Size_Meta::HEIGHT_IN, (float) wp_unslash( $_POST['yp_height_in'] ) );
		}

		if ( isset( $_POST['yp_price'] ) ) {
			update_post_meta( $post_id, YeffoPrint_Sticker_Size_Meta::PRICE, (float) wp_unslash( $_POST['yp_price'] ) );
		}

		if ( $is_custom ) {
			$others = get_posts( [
				'post_type'      => 'yp_sticker_size',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'exclude'        => [ $post_id ],
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small, admin-managed table.
					[ 'key' => YeffoPrint_Sticker_Size_Meta::IS_CUSTOM, 'value' => '1' ],
				],
			] );

			foreach ( $others as $other_id ) {
				update_post_meta( $other_id, YeffoPrint_Sticker_Size_Meta::IS_CUSTOM, false );
			}
		}
	}

	public function columns( array $columns ): array {
		$result = [];

		foreach ( $columns as $key => $label ) {
			$result[ $key ] = $label;
			if ( 'title' === $key ) {
				$result['yp_dimensions'] = __( 'Dimensions', 'yeffoprint-core' );
				$result['yp_price']      = __( 'Price', 'yeffoprint-core' );
			}
		}

		return $result;
	}

	public function render_column( string $column, int $post_id ): void {
		$is_custom = (bool) get_post_meta( $post_id, YeffoPrint_Sticker_Size_Meta::IS_CUSTOM, true );

		if ( 'yp_dimensions' === $column ) {
			if ( $is_custom ) {
				echo esc_html__( 'Customer-entered', 'yeffoprint-core' );
				return;
			}

			$width  = get_post_meta( $post_id, YeffoPrint_Sticker_Size_Meta::WIDTH_IN, true );
			$height = get_post_meta( $post_id, YeffoPrint_Sticker_Size_Meta::HEIGHT_IN, true );
			echo $width && $height
				? esc_html( sprintf( '%s" × %s"', $width, $height ) )
				: '—';
		}

		if ( 'yp_price' === $column ) {
			if ( $is_custom ) {
				echo esc_html__( 'Computed ($/sq in)', 'yeffoprint-core' );
				return;
			}

			$price = (float) get_post_meta( $post_id, YeffoPrint_Sticker_Size_Meta::PRICE, true );
			echo esc_html( '$' . number_format_i18n( $price, 2 ) );
		}
	}
}
