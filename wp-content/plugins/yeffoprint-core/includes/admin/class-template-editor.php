<?php
/**
 * Admin editing experience for Templates — the Phase 4 deliverable
 * that proves a nondeveloper can add a template without touching
 * code (PROJECT_SPEC §21 Phase 4, §17 Admin Experience).
 *
 * Two meta boxes: "Gallery & Compatibility" (the flat fields —
 * featured/badge/popularity/vial mockup — plus which Sizes/Materials
 * this template supports) and "Customization Fields" (the field
 * schema repeater, backed by YeffoPrint_Field_Schema).
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Template_Editor {

	private const NONCE_ACTION = 'yeffoprint_save_template';
	private const NONCE_NAME   = 'yeffoprint_template_nonce';

	public function __construct() {
		add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
		add_action( 'save_post_yp_template', [ $this, 'save' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		add_filter( 'manage_yp_template_posts_columns', [ $this, 'columns' ] );
		add_action( 'manage_yp_template_posts_custom_column', [ $this, 'render_column' ], 10, 2 );
	}

	public function enqueue_assets( string $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'yp_template' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_style(
			'yeffoprint-core-admin',
			YEFFOPRINT_CORE_URL . 'assets/admin/admin.css',
			[],
			YEFFOPRINT_CORE_VERSION
		);

		wp_enqueue_script(
			'yeffoprint-core-field-schema',
			YEFFOPRINT_CORE_URL . 'assets/admin/field-schema.js',
			[],
			YEFFOPRINT_CORE_VERSION,
			true
		);

		wp_enqueue_script(
			'yeffoprint-core-vial-mockup-picker',
			YEFFOPRINT_CORE_URL . 'assets/admin/vial-mockup-picker.js',
			[ 'media-editor' ],
			YEFFOPRINT_CORE_VERSION,
			true
		);

		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;

		wp_localize_script( 'yeffoprint-core-field-schema', 'yeffoprintFieldSchema', [
			'fields'    => $post_id ? YeffoPrint_Field_Schema::get( $post_id ) : [],
			'types'     => YeffoPrint_Field_Schema::TYPES,
			'alignments' => YeffoPrint_Field_Schema::ALIGNMENTS,
			'formattingRules' => YeffoPrint_Field_Schema::FORMATTING_RULES,
			'previewBehaviors' => YeffoPrint_Field_Schema::PREVIEW_BEHAVIORS,
			'i18n'      => [
				'addField'    => __( 'Add Field', 'yeffoprint-core' ),
				'removeField' => __( 'Remove', 'yeffoprint-core' ),
				'moveUp'      => __( 'Move up', 'yeffoprint-core' ),
				'moveDown'    => __( 'Move down', 'yeffoprint-core' ),
				'empty'       => __( 'No customization fields yet. Add one below.', 'yeffoprint-core' ),
			],
		] );
	}

	public function add_meta_boxes(): void {
		add_meta_box(
			'yp-template-gallery',
			__( 'Gallery & Compatibility', 'yeffoprint-core' ),
			[ $this, 'render_gallery_box' ],
			'yp_template',
			'side'
		);

		add_meta_box(
			'yp-template-fields',
			__( 'Customization Fields', 'yeffoprint-core' ),
			[ $this, 'render_field_schema_box' ],
			'yp_template',
			'normal'
		);
	}

	public function render_gallery_box( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$featured    = (bool) get_post_meta( $post->ID, YeffoPrint_Template_Meta::FEATURED, true );
		$badge       = (string) get_post_meta( $post->ID, YeffoPrint_Template_Meta::BADGE, true );
		$popularity  = (int) get_post_meta( $post->ID, YeffoPrint_Template_Meta::POPULARITY, true );
		$vial_id     = (int) get_post_meta( $post->ID, YeffoPrint_Template_Meta::VIAL_MOCKUP, true );
		$vial_url    = $vial_id ? wp_get_attachment_image_url( $vial_id, 'thumbnail' ) : '';
		$compat_sizes = array_map( 'absint', (array) get_post_meta( $post->ID, YeffoPrint_Template_Meta::COMPATIBLE_SIZES, true ) );
		$compat_materials = array_map( 'absint', (array) get_post_meta( $post->ID, YeffoPrint_Template_Meta::COMPATIBLE_MATERIALS, true ) );
		?>
		<p>
			<label>
				<input type="checkbox" name="yp_featured" value="1" <?php checked( $featured ); ?> />
				<?php esc_html_e( 'Featured', 'yeffoprint-core' ); ?>
			</label>
		</p>
		<p>
			<label for="yp-badge"><?php esc_html_e( 'Badge', 'yeffoprint-core' ); ?></label><br />
			<select id="yp-badge" name="yp_badge" class="widefat">
				<?php foreach ( YeffoPrint_Template_Meta::BADGES as $value ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $badge, $value ); ?>>
						<?php echo esc_html( '' === $value ? __( 'None', 'yeffoprint-core' ) : yeffoprint_core_badge_label( $value ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="yp-popularity"><?php esc_html_e( 'Popularity score', 'yeffoprint-core' ); ?></label><br />
			<input type="number" min="0" id="yp-popularity" name="yp_popularity" value="<?php echo esc_attr( $popularity ); ?>" class="widefat" />
			<span class="description"><?php esc_html_e( 'Drives "Most Popular" sort on the Shop Labels gallery. Higher is more popular.', 'yeffoprint-core' ); ?></span>
		</p>
		<p>
			<label><?php esc_html_e( 'Vial mockup image', 'yeffoprint-core' ); ?></label><br />
			<span id="yp-vial-mockup-preview">
				<?php if ( $vial_url ) : ?>
					<img src="<?php echo esc_url( $vial_url ); ?>" alt="" style="max-width:100%;height:auto;" />
				<?php endif; ?>
			</span>
			<input type="hidden" id="yp-vial-mockup-id" name="yp_vial_mockup_id" value="<?php echo esc_attr( $vial_id ); ?>" />
			<button type="button" class="button" id="yp-vial-mockup-select"><?php esc_html_e( 'Select image', 'yeffoprint-core' ); ?></button>
			<button type="button" class="button-link" id="yp-vial-mockup-remove" <?php echo $vial_id ? '' : 'style="display:none;"'; ?>><?php esc_html_e( 'Remove', 'yeffoprint-core' ); ?></button>
			<span class="description"><?php esc_html_e( 'Shown for Vial View and the gallery card hover-swap.', 'yeffoprint-core' ); ?></span>
		</p>
		<hr />
		<p>
			<strong><?php esc_html_e( 'Compatible Sizes', 'yeffoprint-core' ); ?></strong><br />
			<?php foreach ( $this->published_records( 'yp_size' ) as $size ) : ?>
				<label style="display:block;">
					<input type="checkbox" name="yp_compatible_sizes[]" value="<?php echo esc_attr( $size->ID ); ?>" <?php checked( in_array( $size->ID, $compat_sizes, true ) ); ?> />
					<?php echo esc_html( $size->post_title ); ?>
				</label>
			<?php endforeach; ?>
			<?php if ( empty( $this->published_records( 'yp_size' ) ) ) : ?>
				<span class="description"><?php echo wp_kses_post( sprintf( __( 'No sizes yet — <a href="%s">add one</a>.', 'yeffoprint-core' ), esc_url( admin_url( 'post-new.php?post_type=yp_size' ) ) ) ); ?></span>
			<?php endif; ?>
		</p>
		<p>
			<strong><?php esc_html_e( 'Compatible Materials', 'yeffoprint-core' ); ?></strong><br />
			<?php foreach ( $this->published_records( 'yp_material' ) as $material ) : ?>
				<label style="display:block;">
					<input type="checkbox" name="yp_compatible_materials[]" value="<?php echo esc_attr( $material->ID ); ?>" <?php checked( in_array( $material->ID, $compat_materials, true ) ); ?> />
					<?php echo esc_html( $material->post_title ); ?>
				</label>
			<?php endforeach; ?>
			<?php if ( empty( $this->published_records( 'yp_material' ) ) ) : ?>
				<span class="description"><?php echo wp_kses_post( sprintf( __( 'No materials yet — <a href="%s">add one</a>.', 'yeffoprint-core' ), esc_url( admin_url( 'post-new.php?post_type=yp_material' ) ) ) ); ?></span>
			<?php endif; ?>
		</p>
		<?php
	}

	public function render_field_schema_box( \WP_Post $post ): void {
		?>
		<div id="yp-field-schema-app">
			<div class="yp-field-schema-list" role="list"></div>
			<p>
				<button type="button" class="button button-secondary" id="yp-field-schema-add"><?php esc_html_e( 'Add Field', 'yeffoprint-core' ); ?></button>
			</p>
			<input type="hidden" name="yp_field_schema" id="yp-field-schema-input" />
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

		update_post_meta( $post_id, YeffoPrint_Template_Meta::FEATURED, ! empty( $_POST['yp_featured'] ) );

		if ( isset( $_POST['yp_badge'] ) ) {
			$badge = sanitize_key( wp_unslash( $_POST['yp_badge'] ) );
			if ( in_array( $badge, YeffoPrint_Template_Meta::BADGES, true ) ) {
				update_post_meta( $post_id, YeffoPrint_Template_Meta::BADGE, $badge );
			}
		}

		if ( isset( $_POST['yp_popularity'] ) ) {
			update_post_meta( $post_id, YeffoPrint_Template_Meta::POPULARITY, absint( $_POST['yp_popularity'] ) );
		}

		if ( isset( $_POST['yp_vial_mockup_id'] ) ) {
			update_post_meta( $post_id, YeffoPrint_Template_Meta::VIAL_MOCKUP, absint( $_POST['yp_vial_mockup_id'] ) );
		}

		$compatible_sizes = isset( $_POST['yp_compatible_sizes'] )
			? array_map( 'absint', (array) wp_unslash( $_POST['yp_compatible_sizes'] ) )
			: [];
		update_post_meta( $post_id, YeffoPrint_Template_Meta::COMPATIBLE_SIZES, $compatible_sizes );

		$compatible_materials = isset( $_POST['yp_compatible_materials'] )
			? array_map( 'absint', (array) wp_unslash( $_POST['yp_compatible_materials'] ) )
			: [];
		update_post_meta( $post_id, YeffoPrint_Template_Meta::COMPATIBLE_MATERIALS, $compatible_materials );

		if ( isset( $_POST['yp_field_schema'] ) ) {
			$decoded = json_decode( wp_unslash( $_POST['yp_field_schema'] ), true );
			YeffoPrint_Field_Schema::update( $post_id, is_array( $decoded ) ? $decoded : [] );
		}
	}

	/** @return \WP_Post[] */
	private function published_records( string $post_type ): array {
		static $cache = [];

		if ( isset( $cache[ $post_type ] ) ) {
			return $cache[ $post_type ];
		}

		$cache[ $post_type ] = get_posts( [
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
		] );

		return $cache[ $post_type ];
	}

	public function columns( array $columns ): array {
		$result = [];

		foreach ( $columns as $key => $label ) {
			$result[ $key ] = $label;
			if ( 'title' === $key ) {
				$result['yp_badge']      = __( 'Badge', 'yeffoprint-core' );
				$result['yp_featured']   = __( 'Featured', 'yeffoprint-core' );
				$result['yp_popularity'] = __( 'Popularity', 'yeffoprint-core' );
				$result['yp_fields']     = __( 'Fields', 'yeffoprint-core' );
			}
		}

		return $result;
	}

	public function render_column( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'yp_badge':
				$badge = (string) get_post_meta( $post_id, YeffoPrint_Template_Meta::BADGE, true );
				echo $badge ? esc_html( yeffoprint_core_badge_label( $badge ) ) : '—';
				break;

			case 'yp_featured':
				echo get_post_meta( $post_id, YeffoPrint_Template_Meta::FEATURED, true ) ? '★' : '—';
				break;

			case 'yp_popularity':
				echo esc_html( (int) get_post_meta( $post_id, YeffoPrint_Template_Meta::POPULARITY, true ) );
				break;

			case 'yp_fields':
				echo esc_html( (string) count( YeffoPrint_Field_Schema::get( $post_id ) ) );
				break;
		}
	}
}
