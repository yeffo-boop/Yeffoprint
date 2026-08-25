<?php
/**
 * Admin editor for the Web Design Package record — one price/tagline/
 * "Most Popular" flag/feature list per pricing-table tier (yeffoprint
 * theme, patterns/web-design-packages.php). Direct follow-up request:
 * "I'd like to make it future proof and be able to adjust prices from
 * the YeffoPrint admin panel" instead of editing the hardcoded array
 * that pattern used to hold.
 *
 * Deliberately no repeater/JS for the feature list — a plain textarea,
 * one bullet per line, covers the same "reorder, add, remove bullets"
 * need with far less code than the JS-driven bulk-discount-tier
 * repeater (class-pricing-rule-editor.php) needs for its genuinely
 * multi-field rows; this is a flat list of single strings.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Web_Design_Package_Editor {

	private const NONCE_ACTION = 'yeffoprint_save_web_design_package';
	private const NONCE_NAME   = 'yeffoprint_web_design_package_nonce';

	public function __construct() {
		add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
		add_action( 'save_post_yp_web_design_pkg', [ $this, 'save' ] );

		add_filter( 'manage_yp_web_design_pkg_posts_columns', [ $this, 'columns' ] );
		add_action( 'manage_yp_web_design_pkg_posts_custom_column', [ $this, 'render_column' ], 10, 2 );
	}

	public function add_meta_boxes(): void {
		add_meta_box(
			'yp-web-design-package-details',
			__( 'Package Details', 'yeffoprint-core' ),
			[ $this, 'render_box' ],
			'yp_web_design_pkg',
			'normal'
		);
	}

	public function render_box( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$price    = get_post_meta( $post->ID, YeffoPrint_Web_Design_Package_Meta::PRICE, true );
		$tagline  = get_post_meta( $post->ID, YeffoPrint_Web_Design_Package_Meta::TAGLINE, true );
		$featured = (bool) get_post_meta( $post->ID, YeffoPrint_Web_Design_Package_Meta::FEATURED, true );
		$features = (array) get_post_meta( $post->ID, YeffoPrint_Web_Design_Package_Meta::FEATURES, true );
		?>
		<p>
			<label for="yp-package-price"><strong><?php esc_html_e( 'Price', 'yeffoprint-core' ); ?></strong></label><br />
			<input type="text" id="yp-package-price" name="yp_package_price" value="<?php echo esc_attr( $price ); ?>" class="widefat" placeholder="$1,500" />
			<span class="description"><?php esc_html_e( 'Shown exactly as typed on the page — include the $ sign, "Starting at," etc. however you\'d like it to read.', 'yeffoprint-core' ); ?></span>
		</p>
		<p>
			<label for="yp-package-tagline"><strong><?php esc_html_e( 'Tagline', 'yeffoprint-core' ); ?></strong></label><br />
			<input type="text" id="yp-package-tagline" name="yp_package_tagline" value="<?php echo esc_attr( $tagline ); ?>" class="widefat" />
		</p>
		<p>
			<label for="yp-package-features"><strong><?php esc_html_e( "What's included", 'yeffoprint-core' ); ?></strong></label><br />
			<textarea id="yp-package-features" name="yp_package_features" rows="6" class="widefat"><?php echo esc_textarea( implode( "\n", $features ) ); ?></textarea>
			<span class="description"><?php esc_html_e( 'One item per line.', 'yeffoprint-core' ); ?></span>
		</p>
		<p>
			<label>
				<input type="checkbox" name="yp_package_featured" value="1" <?php checked( $featured ); ?> />
				<?php esc_html_e( 'Featured ("Most Popular" badge, highlighted card)', 'yeffoprint-core' ); ?>
			</label>
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

		update_post_meta(
			$post_id,
			YeffoPrint_Web_Design_Package_Meta::PRICE,
			isset( $_POST['yp_package_price'] ) ? sanitize_text_field( wp_unslash( $_POST['yp_package_price'] ) ) : ''
		);

		update_post_meta(
			$post_id,
			YeffoPrint_Web_Design_Package_Meta::TAGLINE,
			isset( $_POST['yp_package_tagline'] ) ? sanitize_text_field( wp_unslash( $_POST['yp_package_tagline'] ) ) : ''
		);

		update_post_meta( $post_id, YeffoPrint_Web_Design_Package_Meta::FEATURED, isset( $_POST['yp_package_featured'] ) );

		$features = [];
		if ( isset( $_POST['yp_package_features'] ) ) {
			$lines    = explode( "\n", (string) wp_unslash( $_POST['yp_package_features'] ) );
			$features = array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'trim', $lines ) ) ) );
		}
		update_post_meta( $post_id, YeffoPrint_Web_Design_Package_Meta::FEATURES, $features );
	}

	public function columns( array $columns ): array {
		$result = [];

		foreach ( $columns as $key => $label ) {
			$result[ $key ] = $label;
			if ( 'title' === $key ) {
				$result['yp_price']    = __( 'Price', 'yeffoprint-core' );
				$result['yp_featured'] = __( 'Featured', 'yeffoprint-core' );
			}
		}

		return $result;
	}

	public function render_column( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'yp_price':
				echo esc_html( (string) get_post_meta( $post_id, YeffoPrint_Web_Design_Package_Meta::PRICE, true ) ?: '—' );
				break;

			case 'yp_featured':
				echo get_post_meta( $post_id, YeffoPrint_Web_Design_Package_Meta::FEATURED, true ) ? '✓' : '—';
				break;
		}
	}
}
