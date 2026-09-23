<?php
/**
 * A settable tile image per Product Type term.
 *
 * The homepage "What are you labeling?" tiles (theme pattern
 * product-type-browse.php) used to pull the newest template's image
 * in each term, and since one template usually sits in several
 * Product Types, every tile showed the same bottle. This adds an
 * "Image" media picker to the Product Type add/edit screens
 * (YeffoPrint → Product Types) so each tile can be chosen directly.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Product_Type_Image {

	public const TAXONOMY = 'yp_product_type';
	public const META_KEY = 'yp_product_type_image';

	private const NONCE_ACTION = 'yeffoprint_save_product_type_image';
	private const NONCE_NAME   = 'yeffoprint_product_type_image_nonce';

	public function __construct() {
		add_action( 'init', [ $this, 'register_meta' ] );

		add_action( self::TAXONOMY . '_add_form_fields', [ $this, 'render_add_field' ] );
		add_action( self::TAXONOMY . '_edit_form_fields', [ $this, 'render_edit_field' ] );
		add_action( 'created_' . self::TAXONOMY, [ $this, 'save' ] );
		add_action( 'edited_' . self::TAXONOMY, [ $this, 'save' ] );

		add_filter( 'manage_edit-' . self::TAXONOMY . '_columns', [ $this, 'columns' ] );
		add_filter( 'manage_' . self::TAXONOMY . '_custom_column', [ $this, 'render_column' ], 10, 3 );

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function register_meta(): void {
		register_term_meta( self::TAXONOMY, self::META_KEY, [
			'type'              => 'integer',
			'single'            => true,
			'default'           => 0,
			'sanitize_callback' => 'absint',
			'show_in_rest'      => true,
			'auth_callback'     => static function () {
				return current_user_can( 'manage_categories' );
			},
		] );
	}

	/**
	 * The term's chosen image as a front-end URL, or null when none is
	 * set (or the attachment was deleted).
	 */
	public static function get_url( int $term_id, string $size = 'medium_large' ): ?string {
		$attachment_id = (int) get_term_meta( $term_id, self::META_KEY, true );

		if ( ! $attachment_id ) {
			return null;
		}

		return wp_get_attachment_image_url( $attachment_id, $size ) ?: null;
	}

	public function enqueue_assets( string $hook ): void {
		if ( ! in_array( $hook, [ 'edit-tags.php', 'term.php' ], true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || self::TAXONOMY !== $screen->taxonomy ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_script(
			'yeffoprint-core-product-type-image',
			YEFFOPRINT_CORE_URL . 'assets/admin/product-type-image.js',
			[ 'media-editor' ],
			yeffoprint_core_asset_version( 'assets/admin/product-type-image.js' ),
			true
		);
	}

	public function render_add_field(): void {
		?>
		<div class="form-field term-yp-image-wrap">
			<label><?php esc_html_e( 'Image', 'yeffoprint-core' ); ?></label>
			<?php $this->render_picker( 0 ); ?>
		</div>
		<?php
	}

	public function render_edit_field( WP_Term $term ): void {
		?>
		<tr class="form-field term-yp-image-wrap">
			<th scope="row"><label><?php esc_html_e( 'Image', 'yeffoprint-core' ); ?></label></th>
			<td><?php $this->render_picker( (int) get_term_meta( $term->term_id, self::META_KEY, true ) ); ?></td>
		</tr>
		<?php
	}

	private function render_picker( int $attachment_id ): void {
		$url = $attachment_id ? wp_get_attachment_image_url( $attachment_id, 'medium' ) : '';

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME, false );
		?>
		<input type="hidden" name="yp_product_type_image" id="yp-product-type-image-id" value="<?php echo $url ? esc_attr( (string) $attachment_id ) : ''; ?>" />
		<div id="yp-product-type-image-preview" style="margin-bottom:8px;">
			<?php if ( $url ) : ?>
				<img src="<?php echo esc_url( $url ); ?>" alt="" style="max-width:200px;height:auto;" />
			<?php endif; ?>
		</div>
		<button type="button" class="button" id="yp-product-type-image-select"><?php esc_html_e( 'Choose image', 'yeffoprint-core' ); ?></button>
		<button type="button" class="button-link button-link-delete" id="yp-product-type-image-remove"<?php echo $url ? '' : ' style="display:none;"'; ?>><?php esc_html_e( 'Remove', 'yeffoprint-core' ); ?></button>
		<p class="description"><?php esc_html_e( 'Shown on this product type\'s tile in the homepage "What are you labeling?" section. Leave empty to use a template from this product type that isn\'t already on another tile.', 'yeffoprint-core' ); ?></p>
		<?php
	}

	public function save( int $term_id ): void {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		$attachment_id = isset( $_POST['yp_product_type_image'] ) ? absint( wp_unslash( $_POST['yp_product_type_image'] ) ) : 0;

		if ( $attachment_id && wp_attachment_is_image( $attachment_id ) ) {
			update_term_meta( $term_id, self::META_KEY, $attachment_id );
		} else {
			delete_term_meta( $term_id, self::META_KEY );
		}
	}

	public function columns( array $columns ): array {
		$new = [];
		foreach ( $columns as $key => $label ) {
			if ( 'name' === $key ) {
				$new['yp_image'] = __( 'Image', 'yeffoprint-core' );
			}
			$new[ $key ] = $label;
		}
		return $new;
	}

	public function render_column( string $content, string $column, int $term_id ): string {
		if ( 'yp_image' !== $column ) {
			return $content;
		}

		$url = self::get_url( $term_id, 'thumbnail' );

		return $url
			? '<img src="' . esc_url( $url ) . '" alt="" style="width:48px;height:48px;object-fit:cover;border-radius:4px;" />'
			: '<span aria-hidden="true">—</span>';
	}
}
