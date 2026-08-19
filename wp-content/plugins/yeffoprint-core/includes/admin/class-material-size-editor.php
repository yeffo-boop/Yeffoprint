<?php
/**
 * Admin meta boxes + list columns for Materials and Sizes.
 *
 * Both are simple enough that no repeater/JS is needed — just a
 * couple of number fields per record, saved through the classic
 * nonce-verified meta box flow. Active/inactive uses the native
 * Publish/Draft status control WordPress already provides, so there's
 * nothing to build for that.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Material_Size_Editor {

	private const NONCE_ACTION = 'yeffoprint_save_commerce_record';
	private const NONCE_NAME   = 'yeffoprint_commerce_record_nonce';

	public function __construct() {
		add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
		add_action( 'save_post_yp_material', [ $this, 'save' ] );
		add_action( 'save_post_yp_size', [ $this, 'save' ] );

		add_filter( 'manage_yp_material_posts_columns', [ $this, 'material_columns' ] );
		add_action( 'manage_yp_material_posts_custom_column', [ $this, 'render_material_column' ], 10, 2 );

		add_filter( 'manage_yp_size_posts_columns', [ $this, 'size_columns' ] );
		add_action( 'manage_yp_size_posts_custom_column', [ $this, 'render_size_column' ], 10, 2 );
	}

	public function add_meta_boxes(): void {
		add_meta_box(
			'yp-material-pricing',
			__( 'Pricing', 'yeffoprint-core' ),
			[ $this, 'render_material_box' ],
			'yp_material',
			'side'
		);

		add_meta_box(
			'yp-size-details',
			__( 'Size Details', 'yeffoprint-core' ),
			[ $this, 'render_size_box' ],
			'yp_size',
			'side'
		);
	}

	public function render_material_box( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		$adjustment = get_post_meta( $post->ID, YeffoPrint_Commerce_Record_Meta::PRICE_ADJUSTMENT, true );
		?>
		<p>
			<label for="yp-price-adjustment"><?php esc_html_e( 'Price adjustment per label ($)', 'yeffoprint-core' ); ?></label><br />
			<input type="number" step="0.01" id="yp-price-adjustment" name="yp_price_adjustment" value="<?php echo esc_attr( $adjustment !== '' ? $adjustment : '0' ); ?>" class="widefat" />
		</p>
		<p class="description"><?php esc_html_e( 'Added to the base unit price when this material is selected. The swatch image is this post\'s featured image; sort order is set by dragging on the list screen.', 'yeffoprint-core' ); ?></p>
		<?php
	}

	public function render_size_box( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		$adjustment = get_post_meta( $post->ID, YeffoPrint_Commerce_Record_Meta::PRICE_ADJUSTMENT, true );
		$width      = get_post_meta( $post->ID, YeffoPrint_Commerce_Record_Meta::PRINT_WIDTH_MM, true );
		$height     = get_post_meta( $post->ID, YeffoPrint_Commerce_Record_Meta::PRINT_HEIGHT_MM, true );
		?>
		<p>
			<label for="yp-print-width"><?php esc_html_e( 'Print width (mm)', 'yeffoprint-core' ); ?></label><br />
			<input type="number" step="0.1" min="0" id="yp-print-width" name="yp_print_width_mm" value="<?php echo esc_attr( $width !== '' ? $width : '0' ); ?>" class="widefat" />
		</p>
		<p>
			<label for="yp-print-height"><?php esc_html_e( 'Print height (mm)', 'yeffoprint-core' ); ?></label><br />
			<input type="number" step="0.1" min="0" id="yp-print-height" name="yp_print_height_mm" value="<?php echo esc_attr( $height !== '' ? $height : '0' ); ?>" class="widefat" />
		</p>
		<p>
			<label for="yp-size-price-adjustment"><?php esc_html_e( 'Price adjustment per label ($)', 'yeffoprint-core' ); ?></label><br />
			<input type="number" step="0.01" id="yp-size-price-adjustment" name="yp_price_adjustment" value="<?php echo esc_attr( $adjustment !== '' ? $adjustment : '0' ); ?>" class="widefat" />
		</p>
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

		if ( isset( $_POST['yp_price_adjustment'] ) ) {
			update_post_meta( $post_id, YeffoPrint_Commerce_Record_Meta::PRICE_ADJUSTMENT, (float) wp_unslash( $_POST['yp_price_adjustment'] ) );
		}

		if ( isset( $_POST['yp_print_width_mm'] ) ) {
			update_post_meta( $post_id, YeffoPrint_Commerce_Record_Meta::PRINT_WIDTH_MM, (float) wp_unslash( $_POST['yp_print_width_mm'] ) );
		}

		if ( isset( $_POST['yp_print_height_mm'] ) ) {
			update_post_meta( $post_id, YeffoPrint_Commerce_Record_Meta::PRINT_HEIGHT_MM, (float) wp_unslash( $_POST['yp_print_height_mm'] ) );
		}
	}

	public function material_columns( array $columns ): array {
		return $this->insert_after( $columns, 'title', [
			'yp_swatch'    => __( 'Swatch', 'yeffoprint-core' ),
			'yp_price_adj' => __( 'Price Adj.', 'yeffoprint-core' ),
		] );
	}

	public function render_material_column( string $column, int $post_id ): void {
		if ( 'yp_swatch' === $column ) {
			echo get_the_post_thumbnail( $post_id, [ 32, 32 ] ) ?: '—';
		}

		if ( 'yp_price_adj' === $column ) {
			$adjustment = (float) get_post_meta( $post_id, YeffoPrint_Commerce_Record_Meta::PRICE_ADJUSTMENT, true );
			echo esc_html( $this->format_price_adjustment( $adjustment ) );
		}
	}

	public function size_columns( array $columns ): array {
		return $this->insert_after( $columns, 'title', [
			'yp_dimensions' => __( 'Print Dimensions', 'yeffoprint-core' ),
			'yp_price_adj'  => __( 'Price Adj.', 'yeffoprint-core' ),
		] );
	}

	public function render_size_column( string $column, int $post_id ): void {
		if ( 'yp_dimensions' === $column ) {
			$width  = get_post_meta( $post_id, YeffoPrint_Commerce_Record_Meta::PRINT_WIDTH_MM, true );
			$height = get_post_meta( $post_id, YeffoPrint_Commerce_Record_Meta::PRINT_HEIGHT_MM, true );
			echo $width && $height
				? esc_html( sprintf( '%s × %s mm', $width, $height ) )
				: '—';
		}

		if ( 'yp_price_adj' === $column ) {
			$adjustment = (float) get_post_meta( $post_id, YeffoPrint_Commerce_Record_Meta::PRICE_ADJUSTMENT, true );
			echo esc_html( $this->format_price_adjustment( $adjustment ) );
		}
	}

	private function format_price_adjustment( float $adjustment ): string {
		if ( 0.0 === $adjustment ) {
			return '—';
		}

		return ( $adjustment > 0 ? '+' : '' ) . '$' . number_format_i18n( $adjustment, 2 );
	}

	private function insert_after( array $columns, string $after_key, array $new_columns ): array {
		$result = [];

		foreach ( $columns as $key => $label ) {
			$result[ $key ] = $label;
			if ( $key === $after_key ) {
				$result = array_merge( $result, $new_columns );
			}
		}

		return $result;
	}
}
