<?php
/**
 * Admin editing experience for a reusable Field Preset — a
 * field_schema (same shape/meta key as a Template's own) authored
 * once and inserted into any Template's own fields instead of
 * recreating the same fields from scratch each time (direct request:
 * "creating them one by one every time is a lot"). Reuses the exact
 * same "Customization Fields" repeater UI/JS as the Template editor
 * (assets/admin/field-schema.js) — a preset's own screen just has no
 * artwork to drag-position fields against, so the drag-to-position
 * preview gracefully falls back to its existing "no preview yet"
 * empty state instead. Position still has to be set per-Template
 * after inserting a preset, since it's placed against that Template's
 * own artwork — only the field *definitions* are reusable.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Field_Preset_Editor {

	private const NONCE_ACTION = 'yeffoprint_save_field_preset';
	private const NONCE_NAME   = 'yeffoprint_field_preset_nonce';

	public function __construct() {
		add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
		add_action( 'save_post_yp_field_preset', [ $this, 'save' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		add_filter( 'manage_yp_field_preset_posts_columns', [ $this, 'columns' ] );
		add_action( 'manage_yp_field_preset_posts_custom_column', [ $this, 'render_column' ], 10, 2 );
	}

	public function enqueue_assets( string $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'yp_field_preset' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style(
			'yeffoprint-core-admin',
			YEFFOPRINT_CORE_URL . 'assets/admin/admin.css',
			[],
			yeffoprint_core_asset_version( 'assets/admin/admin.css' )
		);

		wp_enqueue_script(
			'yeffoprint-core-field-schema',
			YEFFOPRINT_CORE_URL . 'assets/admin/field-schema.js',
			[],
			yeffoprint_core_asset_version( 'assets/admin/field-schema.js' ),
			true
		);

		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;

		wp_localize_script( 'yeffoprint-core-field-schema', 'yeffoprintFieldSchema', [
			'fields'           => $post_id ? YeffoPrint_Field_Schema::get( $post_id ) : [],
			'types'            => YeffoPrint_Field_Schema::TYPES,
			'alignments'       => YeffoPrint_Field_Schema::ALIGNMENTS,
			'formattingRules'  => YeffoPrint_Field_Schema::FORMATTING_RULES,
			'previewBehaviors' => YeffoPrint_Field_Schema::PREVIEW_BEHAVIORS,
			// No artwork to preview/drag-position fields against here —
			// this screen only ever shows field-schema.js's existing
			// "Set a featured image..." empty state, which is close
			// enough to the truth (there's no image at all, on this
			// screen, ever) without a separate empty-state string.
			'previewImageUrl'  => '',
			'presets'          => [], // Not meaningful on the preset screen itself.
			'i18n'             => [
				'addField'    => __( 'Add Field', 'yeffoprint-core' ),
				'removeField' => __( 'Remove', 'yeffoprint-core' ),
				'moveUp'      => __( 'Move up', 'yeffoprint-core' ),
				'moveDown'    => __( 'Move down', 'yeffoprint-core' ),
				'empty'       => __( 'No fields in this preset yet. Add one below.', 'yeffoprint-core' ),
				'noPreview'   => __( 'Position isn\'t part of a preset — it\'s set per-Template after inserting these fields, against that Template\'s own artwork.', 'yeffoprint-core' ),
				'dragHint'    => __( 'Drag a label to reposition it on the artwork, or set exact percentages below. Click a label first, then use the arrow keys to nudge it precisely (hold Shift for bigger steps) — easier than dragging for a tight margin near an edge.', 'yeffoprint-core' ),
				'insertPreset' => __( 'Insert Preset', 'yeffoprint-core' ),
				'selectPreset' => __( '— Select a preset —', 'yeffoprint-core' ),
			],
		] );
	}

	public function add_meta_boxes(): void {
		add_meta_box(
			'yp-field-preset-fields',
			__( 'Fields', 'yeffoprint-core' ),
			[ $this, 'render_box' ],
			'yp_field_preset',
			'normal'
		);
	}

	public function render_box(): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<p class="description"><?php esc_html_e( 'Build a reusable set of fields here (label, type, max length, alignment, font size, formatting, tooltip text) — everything except position, which is set per-Template since it depends on that Template\'s own artwork. Insert this preset from any Template\'s "Customization Fields" box instead of recreating these fields from scratch.', 'yeffoprint-core' ); ?></p>
		<div id="yp-field-schema-app">
			<div id="yp-field-position-preview"></div>
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

		if ( isset( $_POST['yp_field_schema'] ) ) {
			$decoded = json_decode( wp_unslash( $_POST['yp_field_schema'] ), true );
			YeffoPrint_Field_Schema::update( $post_id, is_array( $decoded ) ? $decoded : [] );
		}
	}

	public function columns( array $columns ): array {
		$result = [];

		foreach ( $columns as $key => $label ) {
			$result[ $key ] = $label;
			if ( 'title' === $key ) {
				$result['yp_fields'] = __( 'Fields', 'yeffoprint-core' );
			}
		}

		return $result;
	}

	public function render_column( string $column, int $post_id ): void {
		if ( 'yp_fields' === $column ) {
			echo esc_html( (string) count( YeffoPrint_Field_Schema::get( $post_id ) ) );
		}
	}
}
