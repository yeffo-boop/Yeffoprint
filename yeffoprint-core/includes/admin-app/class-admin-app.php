<?php
/**
 * Bootstrap for the new custom admin dashboard (docs/ARCHITECTURE.md) —
 * Phase 1 of the plan: an app shell that replaces wp-admin's own chrome
 * on the `yeffoprint` top-level page with a custom-branded one, backed
 * by a new admin REST API (includes/rest/admin/) instead of classic
 * post-edit-screen forms.
 *
 * Deliberately thin, same division of responsibility as every other
 * class in this plugin: this only ever renders the empty app-root div
 * and enqueues assets — every real screen's data and behavior lives in
 * assets/admin-app/app.js and the REST controllers it calls, never
 * here.
 *
 * Registered at the same `yeffoprint` menu slug the old Dashboard used
 * (class-admin-menu.php), so bookmarks/muscle memory keep working —
 * only the render callback changed. YeffoPrint_Dashboard_Widgets and
 * YeffoPrint_Admin_Menu::render_dashboard() are unused for now (kept
 * for a real Dashboard home view in a later phase, or removal once this
 * migration is done — see the plan).
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_App {

	/** Set by class-admin-menu.php with add_menu_page()'s own return value — see YeffoPrint_Admin_Shell::register_page_hook() for the same "no pattern to guess" reasoning. */
	private static string $hook_suffix = '';

	public static function set_hook_suffix( string $hook_suffix ): void {
		self::$hook_suffix = $hook_suffix;
	}

	public function __construct() {
		add_filter( 'admin_body_class', [ $this, 'add_body_class' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function add_body_class( string $classes ): string {
		return $this->is_own_screen() ? $classes . ' yeffoprint-app' : $classes;
	}

	public static function render(): void {
		echo '<div id="yp-admin-app"></div>';
	}

	public function enqueue_assets( string $hook ): void {
		if ( ! $this->is_own_screen() ) {
			return;
		}

		// Same brand fonts the storefront loads (functions.php), same
		// CSS2 URL pattern already used for wp-login.php and the
		// existing admin reskin — see YeffoPrint_Admin_Shell::enqueue_assets().
		wp_enqueue_style(
			'yeffoprint-admin-app-fonts',
			'https://fonts.googleapis.com/css2?family=Geist:wght@500;600;700;800&family=Inter:wght@400;500;600&family=IBM+Plex+Mono:wght@500;600&display=swap',
			[],
			null
		);

		// The theme's own stylesheet, not a copy — buttons, the
		// .yp-drawer primitive, form inputs, and every color/spacing
		// token this app uses come straight from here, so the admin app
		// can never visually drift from the storefront the way a
		// separately-authored admin CSS file could. yeffoprint_asset_version()
		// is the theme's own function (functions.php) — safe to call
		// directly by the time this fires (admin_enqueue_scripts, long
		// after the active theme's functions.php has loaded), guarded
		// with function_exists() only in case a different theme is ever
		// active.
		wp_enqueue_style(
			'yeffoprint-admin-app-theme',
			get_theme_file_uri( 'assets/css/global.css' ),
			[ 'yeffoprint-admin-app-fonts' ],
			function_exists( 'yeffoprint_asset_version' ) ? yeffoprint_asset_version( 'assets/css/global.css' ) : YEFFOPRINT_CORE_VERSION
		);

		wp_register_style( 'yeffoprint-admin-app-tokens', false, [ 'yeffoprint-admin-app-theme' ] );
		wp_enqueue_style( 'yeffoprint-admin-app-tokens' );
		wp_add_inline_style( 'yeffoprint-admin-app-tokens', YeffoPrint_Admin_Token_Bridge::inline_css() );

		wp_enqueue_style(
			'yeffoprint-admin-app-shell',
			YEFFOPRINT_CORE_URL . 'assets/admin-app/app-shell.css',
			[ 'yeffoprint-admin-app-tokens' ],
			yeffoprint_core_asset_version( 'assets/admin-app/app-shell.css' )
		);

		// List/table/form styles shared by every catalog CRUD screen
		// (Phase 2: Materials, Sizes) — see that file's own docblock.
		wp_enqueue_style(
			'yeffoprint-admin-app-records',
			YEFFOPRINT_CORE_URL . 'assets/admin-app/records.css',
			[ 'yeffoprint-admin-app-shell' ],
			yeffoprint_core_asset_version( 'assets/admin-app/records.css' )
		);

		// Templates' compatible-sizes/materials checklists and the
		// drag-to-position field-schema editor (Phase 5) — kept out of
		// records.css since nothing else uses these components.
		wp_enqueue_style(
			'yeffoprint-admin-app-field-schema',
			YEFFOPRINT_CORE_URL . 'assets/admin-app/field-schema.css',
			[ 'yeffoprint-admin-app-records' ],
			yeffoprint_core_asset_version( 'assets/admin-app/field-schema.css' )
		);

		// wp.media() — Materials' swatch/hover image pickers (Phase 2)
		// and every future screen with an image field. Same call every
		// other editor with a picker already makes (e.g.
		// class-material-size-editor.php) — the modal/scripts it loads
		// are otherwise absent from an admin screen.
		wp_enqueue_media();

		wp_enqueue_script(
			'yeffoprint-admin-app',
			YEFFOPRINT_CORE_URL . 'assets/admin-app/app.js',
			[],
			yeffoprint_core_asset_version( 'assets/admin-app/app.js' ),
			[ 'strategy' => 'defer' ]
		);

		$badges = [];
		foreach ( YeffoPrint_Template_Meta::BADGES as $badge ) {
			$badges[ $badge ] = '' === $badge ? __( 'None', 'yeffoprint-core' ) : yeffoprint_core_badge_label( $badge );
		}

		wp_localize_script( 'yeffoprint-admin-app', 'yeffoprintAdminApp', [
			'restUrl'         => esc_url_raw( rest_url( 'yeffoprint-core/v1/' ) ),
			'wpApiUrl'        => esc_url_raw( rest_url( 'wp/v2/' ) ),
			'nonce'           => wp_create_nonce( 'wp_rest' ),
			'exitUrl'         => esc_url_raw( admin_url() ),
			'currentUserName' => wp_get_current_user()->display_name,
			// Static constants the Templates/Field Presets screens need
			// (Phase 5) before any record/id exists yet — an "Add" drawer
			// must render its full field-schema editor and Badge/etc
			// selects before the first Save creates the post, so these
			// can't ride along on a per-record REST response the way
			// Pricing Rules' tier_types does (see
			// class-admin-template-controller.php's own docblock).
			'fieldSchema'     => [
				'types'                => YeffoPrint_Field_Schema::TYPES,
				'alignments'           => YeffoPrint_Field_Schema::ALIGNMENTS,
				'formattingRules'      => YeffoPrint_Field_Schema::FORMATTING_RULES,
				'previewBehaviors'     => YeffoPrint_Field_Schema::PREVIEW_BEHAVIORS,
				'qrMinMaxChars'        => YeffoPrint_Field_Schema::QR_MIN_MAX_CHARS,
				'qrMaxChars'           => YeffoPrint_Field_Schema::QR_MAX_CHARS,
			],
			'badges'                  => $badges,
			'previewFontSuggestions' => YeffoPrint_Template_Meta::PREVIEW_FONT_SUGGESTIONS,
			// Custom Orders' status filter dropdown (Phase 6) needs this
			// before any order has loaded — same "list screen needs it
			// before the first detail response could carry it" reasoning
			// as fieldSchema above; the detail endpoint still sends its
			// own copy back too (class-admin-custom-order-controller.php),
			// used to build that one order's own Status <select>.
			'customOrderStatuses'    => YeffoPrint_Custom_Order_Meta::STATUSES,
		] );

		// Shared repeater widget (Phase 5) — depended on by both
		// views/templates.js and views/field-presets.js, so it must load
		// (and be ready) before either. See its own docblock.
		wp_enqueue_script(
			'yeffoprint-admin-app-field-schema-editor',
			YEFFOPRINT_CORE_URL . 'assets/admin-app/field-schema-editor.js',
			[ 'yeffoprint-admin-app' ],
			yeffoprint_core_asset_version( 'assets/admin-app/field-schema-editor.js' ),
			[ 'strategy' => 'defer' ]
		);

		// Each view script registers itself into YPAdminApp.views (see
		// app.js's own docblock) — every one of them depends on
		// 'yeffoprint-admin-app' and shares its `defer` strategy, so they
		// always finish loading (and registering) before app.js's own
		// DOMContentLoaded-triggered first route() call needs them.
		foreach ( [ 'materials', 'sizes', 'sticker-sizes', 'templates', 'field-presets', 'web-design-packages', 'maintenance', 'pricing', 'orders', 'proofs', 'rewards', 'surcharge', 'settings', 'manual-order' ] as $view ) {
			wp_enqueue_script(
				'yeffoprint-admin-app-view-' . $view,
				YEFFOPRINT_CORE_URL . 'assets/admin-app/views/' . $view . '.js',
				in_array( $view, [ 'templates', 'field-presets' ], true )
					? [ 'yeffoprint-admin-app', 'yeffoprint-admin-app-field-schema-editor' ]
					: [ 'yeffoprint-admin-app' ],
				yeffoprint_core_asset_version( 'assets/admin-app/views/' . $view . '.js' ),
				[ 'strategy' => 'defer' ]
			);
		}
	}

	private function is_own_screen(): bool {
		$screen = get_current_screen();
		return self::$hook_suffix && $screen && self::$hook_suffix === $screen->id;
	}
}
