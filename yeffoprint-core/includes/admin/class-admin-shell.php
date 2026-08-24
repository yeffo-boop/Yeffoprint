<?php
/**
 * The admin reskin's shared plumbing (direct request: the native
 * WordPress post-edit screen — title field, block editor, Publish box
 * — used for every YeffoPrint data record "uses the native WordPress
 * post UI... not easy on the eyes"). Confirmed approach: reskin in
 * place, not a rebuild — every editor class's `save_post_{type}`/
 * `add_meta_box()` flow (class-material-size-editor.php and friends)
 * is completely independent of how the screen renders, so this class
 * only ever touches chrome, never save/validation logic.
 *
 * Three jobs, all screen-agnostic so a later phase (Pricing Rules,
 * Custom Orders, Proofs, Field Presets, the Settings/Card Surcharge
 * pages) only ever needs to add itself to is_yeffoprint_post_type()
 * or is_yeffoprint_admin_page(), never touch this class's logic:
 *  1. Turn off the block editor for every YeffoPrint CPT — none of
 *     them need more than a short plain-text description (Templates'/
 *     Materials' own 'editor' support), so the block canvas/toolbar is
 *     pure visual noise here, not a feature anyone uses.
 *  2. Remove WordPress's own "Custom Fields" meta box — a raw,
 *     unstyled key/value editor that's redundant with (and confusing
 *     next to) this plugin's own purpose-built boxes.
 *  3. Add a `yeffoprint-admin` body class and enqueue the shared
 *     admin-shell.css/fonts — everything visual actually lives in that
 *     one stylesheet, scoped under that class, so this class stays
 *     pure plumbing.
 *
 * Deliberately never touches `#adminmenu`/`#wpadminbar` (the sidebar
 * and top admin bar are shared with every other wp-admin screen, not
 * just this plugin's — reskinning them would make the sidebar itself
 * visually flicker between styles as staff navigate to Posts/Plugins/
 * etc., which is worse than leaving it alone) or the Publish meta box's
 * actual controls (Draft/Publish is real functional data for a
 * Material/Size's active/inactive state and a Template's storefront
 * visibility — admin-shell.css restyles its *appearance* only).
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_Shell {

	/** Every YeffoPrint CPT — kept in one place so a later phase's editor class never needs its own copy of this list. */
	private const POST_TYPES = [
		'yp_template',
		'yp_material',
		'yp_size',
		'yp_sticker_size',
		'yp_pricing_rule',
		'yp_custom_order',
		'yp_proof',
		'yp_field_preset',
	];

	public function __construct() {
		add_filter( 'use_block_editor_for_post_type', [ $this, 'disable_block_editor' ], 10, 2 );
		add_action( 'add_meta_boxes', [ $this, 'remove_custom_fields_box' ], 20 );
		add_filter( 'admin_body_class', [ $this, 'add_body_class' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function disable_block_editor( bool $use_block_editor, string $post_type ): bool {
		return in_array( $post_type, self::POST_TYPES, true ) ? false : $use_block_editor;
	}

	public function remove_custom_fields_box(): void {
		foreach ( self::POST_TYPES as $post_type ) {
			remove_meta_box( 'postcustom', $post_type, 'normal' );
		}
	}

	public function add_body_class( string $classes ): string {
		return $this->is_yeffoprint_screen() ? $classes . ' yeffoprint-admin' : $classes;
	}

	/** wp.media (vial-mockup-picker.js, already used by several editors) stays each editor's own responsibility to enqueue — this only ever adds the shared skin on top of whatever a given screen already loads. */
	public function enqueue_assets(): void {
		if ( ! $this->is_yeffoprint_screen() ) {
			return;
		}

		// Same Google Fonts CSS2 URL + weight set already used for
		// wp-login.php (functions.php's login-screen enqueue) — this is
		// the first time this plugin (not the theme) needs the brand
		// fonts, so it's registered fresh here rather than trying to
		// reach across the plugin/theme boundary for the theme's own
		// already-registered handle, which admin screens don't load.
		wp_enqueue_style(
			'yeffoprint-core-admin-fonts',
			'https://fonts.googleapis.com/css2?family=Geist:wght@500;600;700;800&family=Inter:wght@400;500;600&display=swap',
			[],
			null
		);

		wp_enqueue_style(
			'yeffoprint-core-admin-shell',
			YEFFOPRINT_CORE_URL . 'assets/admin/admin-shell.css',
			[ 'yeffoprint-core-admin-fonts' ],
			yeffoprint_core_asset_version( 'assets/admin/admin-shell.css' )
		);
	}

	private function is_yeffoprint_screen(): bool {
		$screen = get_current_screen();
		return $screen && in_array( $screen->post_type, self::POST_TYPES, true );
	}
}
