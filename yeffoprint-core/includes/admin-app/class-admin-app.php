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

	/**
	 * The redesigned app ("B Light": top tabs, phone tab bar, Today,
	 * Production board, hubs from next/next.js, styled by next/next.css)
	 * is the only admin now; the classic sidebar shell was retired
	 * (direct request: "retire the old admin dashboard and move the new
	 * one in place"). Both the `yeffoprint` page and the unlinked
	 * `yeffoprint-next` page it first shipped at render it.
	 */
	private static string $next_hook_suffix = '';

	public static function set_next_hook_suffix( string $hook_suffix ): void {
		self::$next_hook_suffix = $hook_suffix;
	}

	public function __construct() {
		add_filter( 'admin_body_class', [ $this, 'add_body_class' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_head', [ $this, 'print_install_tags' ] );
		add_action( 'wp_ajax_yeffoprint_admin_nonce', [ $this, 'ajax_fresh_nonce' ] );
	}

	/**
	 * A fresh wp_rest nonce for an app page left open longer than its
	 * baked-in nonce lives (the print station runs all day, and logins now
	 * last 90 days). Not the REST /session/nonce route: WordPress treats a
	 * REST request without a valid nonce as signed out, so that route only
	 * hands back a guest nonce. admin-ajax reads the login cookie itself,
	 * and the JSON has no CORS headers, so no other site can read it.
	 */
	public function ajax_fresh_nonce(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		if ( class_exists( 'YeffoPrint_Stay_Signed_In' ) ) {
			YeffoPrint_Stay_Signed_In::extend_current_login();
		}
		nocache_headers();
		wp_send_json_success( [ 'nonce' => wp_create_nonce( 'wp_rest' ) ] );
	}

	public function add_body_class( string $classes ): string {
		if ( ! $this->is_own_screen() ) {
			return $classes;
		}
		return $classes . ' yeffoprint-app' . ( $this->is_next_screen() ? ' yp-next' : '' );
	}

	/** Home Screen install for the new app (class-admin-app-shortcut.php serves the manifest and service worker). */
	public function print_install_tags(): void {
		if ( ! $this->is_next_screen() ) {
			return;
		}
		echo '<link rel="manifest" href="' . esc_url( YeffoPrint_Admin_App_Shortcut::manifest_url() ) . '">' . "\n";
		echo '<link rel="apple-touch-icon" href="' . esc_url( YEFFOPRINT_CORE_URL . 'assets/admin-app/next/icons/apple-touch-icon.png?v=2' ) . '">' . "\n";
		echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
		echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
		echo '<meta name="apple-mobile-web-app-title" content="YeffoDesign">' . "\n";
		echo '<meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n";
		echo '<meta name="theme-color" content="#FFFFFF">' . "\n";
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

		// The storefront's own order-status stepper (order-stepper.css) —
		// class-web-design-project-meta.php's get_stepper_steps() feeds
		// YeffoPrint_Order_Status_Stepper::render_html() the exact same
		// {state,label,sublabel} shape the storefront's Track Order/My
		// Account pages already render with this file, so the Web Design
		// panel's own progress row (app.js's renderWebDesignPanel())
		// needs it here too rather than a second copy of the same rules.
		wp_enqueue_style(
			'yeffoprint-admin-app-order-stepper',
			get_theme_file_uri( 'assets/css/order-stepper.css' ),
			[ 'yeffoprint-admin-app-records' ],
			function_exists( 'yeffoprint_asset_version' ) ? yeffoprint_asset_version( 'assets/css/order-stepper.css' ) : YEFFOPRINT_CORE_VERSION
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
			'wcApiUrl'        => esc_url_raw( rest_url( 'wc/v3/' ) ),
			// Printable invoice / packing slip page; the app adds &kind= and &ids=.
			'printOrdersUrl'  => YeffoPrint_Admin_Order_Actions_Controller::print_base_url(),
			'orderEmails'     => YeffoPrint_Admin_Order_Actions_Controller::email_options(),
			'nonce'           => wp_create_nonce( 'wp_rest' ),
			'nonceUrl'        => esc_url_raw( admin_url( 'admin-ajax.php?action=yeffoprint_admin_nonce' ) ),
			'exitUrl'         => esc_url_raw( admin_url() ),
			'currentUserName' => wp_get_current_user()->display_name,
			// 'next' on the redesigned app's page — app.js builds that
			// page's shell instead of the classic sidebar.
			'shell'           => $this->is_next_screen() ? 'next' : 'classic',
			'nextUrl'         => esc_url_raw( admin_url( 'admin.php?page=' . YeffoPrint_Admin_Push::APP_SLUG ) ),
			'swUrl'           => esc_url_raw( YeffoPrint_Admin_App_Shortcut::service_worker_url() ),
			'swScope'         => YeffoPrint_Admin_App_Shortcut::admin_scope(),
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
				'cornerStyleOptions'   => YeffoPrint_Field_Schema::CORNER_STYLE_OPTIONS,
			],
			// Materials screen's "Swatch finish" select.
			'swatchFinishes'          => YeffoPrint_Commerce_Record_Meta::SWATCH_FINISHES,
			'badges'                  => $badges,
			'previewFontSuggestions' => YeffoPrint_Template_Meta::PREVIEW_FONT_SUGGESTIONS,
			// Direct request: "I want to use the default template preset I
			// made... IF I add a field there, it adds to all templates."
			// null when no default is configured (Settings → Label
			// Configurator) — views/templates.js falls back to today's
			// per-Template interactive editor in that case, unchanged.
			// Every Template shares one field set now (Label Fields screen) —
			// ensure_global_preset() makes sure it exists before the
			// Templates screen reads it.
			'defaultColorChoices'    => YeffoPrint_Label_Color_Meta::default_choices(),
			'defaultFieldPreset'     => YeffoPrint_Field_Schema::ensure_global_preset() ? YeffoPrint_Field_Schema::default_preset() : null,
			// Custom Orders' status filter dropdown (Phase 6) needs this
			// before any order has loaded — same "list screen needs it
			// before the first detail response could carry it" reasoning
			// as fieldSchema above; the detail endpoint still sends its
			// own copy back too (class-admin-custom-order-controller.php),
			// used to build that one order's own Status <select>.
			'customOrderStatuses'    => YeffoPrint_Custom_Order_Meta::STATUSES,
			// Order History's status filter (direct request: "a way of
			// pulling up previous orders... searchable and should also
			// just list out previous orders") needs the full WooCommerce
			// status list before any order has loaded, same reasoning as
			// customOrderStatuses above — wc_get_order_statuses()'s own
			// keys carry the 'wc-' prefix, stripped here to match
			// WC_Order::get_status()'s unprefixed return value (same
			// stripping class-admin-order-controller.php's own
			// status_options() already does server-side per request).
			'wcOrderStatuses'        => array_combine(
				array_map( static fn( $key ) => preg_replace( '/^wc-/', '', $key ), array_keys( wc_get_order_statuses() ) ),
				array_values( wc_get_order_statuses() )
			),
			// Manual order creation's address-verify/shipping-rate step
			// (views/manual-order.js) needs this before any order exists to
			// carry its own copy the way class-admin-order-controller.php's
			// per-order detail_payload() already does for the existing
			// per-order Shippo panel.
			'shippo'                 => [
				'configured'                => YeffoPrint_Shippo_Settings::is_configured(),
				'defaultPackage'            => YeffoPrint_Shippo_Settings::get_default_package(),
				// Manual order creation's shipping-method picker (direct
				// request: "I don't need to rate shop to add shipping, just
				// use my default shipping options") — a flat preset list, not
				// a Shippo API call, so it's available even without an API
				// token configured (unlike defaultPackage/configured above,
				// which gate the order-detail Shippo panel's own rate-shop).
				'manualOrderShippingOptions' => YeffoPrint_Shippo_Settings::get_manual_order_shipping_options(),
				'domesticCountry'            => YeffoPrint_Shippo_Settings::domestic_country(),
			],
		] );

		// Shared repeater widget (Phase 5) — depended on by both
		// views/templates.js and views/label-fields.js, so it must load
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
		foreach ( [ 'materials', 'sizes', 'sticker-sizes', 'templates', 'label-fields', 'label-colors', 'compound-list', 'filament-colors', 'prints', 'web-design-packages', 'web-design-addons', 'maintenance', 'pricing', 'orders', 'order-history', 'abandoned-carts', 'web-design-orders', 'customers', 'reviews', 'tracker-feedback', 'coupons', 'proofs', 'rewards', 'surcharge', 'settings', 'manual-order', 'sales', 'messages', 'disputes', 'payments', 'shipping-zones', 'template-categories' ] as $view ) {
			wp_enqueue_script(
				'yeffoprint-admin-app-view-' . $view,
				YEFFOPRINT_CORE_URL . 'assets/admin-app/views/' . $view . '.js',
				in_array( $view, [ 'templates', 'label-fields' ], true )
					? [ 'yeffoprint-admin-app', 'yeffoprint-admin-app-field-schema-editor' ]
					: [ 'yeffoprint-admin-app' ],
				yeffoprint_core_asset_version( 'assets/admin-app/views/' . $view . '.js' ),
				[ 'strategy' => 'defer' ]
			);
		}

		if ( $this->is_next_screen() ) {
			wp_enqueue_style(
				'yeffoprint-admin-app-next',
				YEFFOPRINT_CORE_URL . 'assets/admin-app/next/next.css',
				[ 'yeffoprint-admin-app-field-schema', 'yeffoprint-admin-app-order-stepper' ],
				yeffoprint_core_asset_version( 'assets/admin-app/next/next.css' )
			);
			wp_enqueue_script(
				'yeffoprint-admin-app-next',
				YEFFOPRINT_CORE_URL . 'assets/admin-app/next/next.js',
				[ 'yeffoprint-admin-app' ],
				yeffoprint_core_asset_version( 'assets/admin-app/next/next.js' ),
				[ 'strategy' => 'defer' ]
			);
		}
	}

	private function is_own_screen(): bool {
		$screen = get_current_screen();
		return $screen && in_array( $screen->id, array_filter( [ self::$hook_suffix, self::$next_hook_suffix ] ), true );
	}

	private function is_next_screen(): bool {
		return $this->is_own_screen();
	}
}
