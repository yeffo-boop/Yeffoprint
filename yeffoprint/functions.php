<?php
/**
 * YeffoPrint theme bootstrap.
 *
 * Presentation-only setup. Business logic (pricing, templates, orders,
 * proofs) lives entirely in the yeffoprint-core plugin — see
 * docs/ARCHITECTURE.md §1 for the split.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'after_setup_theme', function () {
	add_theme_support( 'woocommerce' );
	add_theme_support( 'wp-block-styles' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'editor-styles' );
	add_theme_support( 'html5', [ 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'script', 'style' ] );
	add_theme_support( 'custom-logo', [
		'height'      => 48,
		'width'       => 160,
		'flex-height' => true,
		'flex-width'  => true,
	] );
} );

/**
 * Global presentation assets: component styles + header/drawer behavior.
 * Business logic never lives here — see docs/ARCHITECTURE.md §1.
 */
/**
 * A content hash of the asset file itself, not the theme's declared
 * style.css Version — busts every browser/CDN/host cache automatically
 * whenever the file's actual bytes change, rather than depending on
 * remembering to bump a version string by hand. That manual version
 * was never once bumped across dozens of CSS/JS-only changes in this
 * theme's history, meaning every one of them risked being served stale
 * from cache indefinitely — exactly the "the fix is live but I don't
 * see it" symptom this replaces.
 *
 * A content hash rather than filemtime() on purpose: some git-based
 * deploy tools don't reliably bump a file's modified-time on checkout
 * (only truly-changed files, or none, depending on how the sync is
 * done), which would silently reintroduce the same stale-cache problem
 * for any deploy path where mtimes aren't trustworthy. Hashing the
 * actual bytes has no such dependency — it changes if and only if the
 * file's content did, regardless of how it got onto the server.
 */
function yeffoprint_asset_version( string $relative_path ) {
	$path = get_theme_file_path( $relative_path );
	if ( ! file_exists( $path ) ) {
		return wp_get_theme()->get( 'Version' );
	}

	$hash = md5_file( $path );
	return $hash ? substr( $hash, 0, 12 ) : (string) filemtime( $path );
}

add_filter( 'wp_resource_hints', function ( $urls, $relation_type ) {
	if ( 'preconnect' === $relation_type ) {
		$urls[] = [ 'href' => 'https://fonts.gstatic.com', 'crossorigin' ];
	}
	return $urls;
}, 10, 2 );

add_action( 'wp_enqueue_scripts', function () {
	// theme.json declares Geist/Inter/IBM Plex Mono as the brand's font
	// stack, but nothing actually loaded those files — every page was
	// silently falling back to each visitor's OS default sans-serif.
	// Google Fonts serves all three; the system-font fallback chain
	// already on each theme.json family stays in place for the brief
	// window before this loads (or if the CDN is unreachable).
	wp_enqueue_style(
		'yeffoprint-fonts',
		'https://fonts.googleapis.com/css2?family=Geist:wght@500;600;700;800&family=Inter:wght@400;500;600&family=IBM+Plex+Mono:wght@500;600&display=swap',
		[],
		null
	);

	wp_enqueue_style(
		'yeffoprint-global',
		get_theme_file_uri( 'assets/css/global.css' ),
		[ 'yeffoprint-fonts' ],
		yeffoprint_asset_version( 'assets/css/global.css' )
	);

	// Homepage/storefront section styling — kept separate from
	// global.css so that file stays scoped to header/footer/drawer/
	// form/button chrome. See docs/ARCHITECTURE.md §9.
	wp_enqueue_style(
		'yeffoprint-patterns',
		get_theme_file_uri( 'assets/css/patterns.css' ),
		[ 'yeffoprint-global' ],
		yeffoprint_asset_version( 'assets/css/patterns.css' )
	);

	wp_enqueue_script(
		'yeffoprint-site',
		get_theme_file_uri( 'assets/js/site.js' ),
		[],
		yeffoprint_asset_version( 'assets/js/site.js' ),
		[ 'strategy' => 'defer' ]
	);

	wp_enqueue_script(
		'yeffoprint-search',
		get_theme_file_uri( 'assets/js/search.js' ),
		[],
		yeffoprint_asset_version( 'assets/js/search.js' ),
		[ 'strategy' => 'defer' ]
	);

	wp_localize_script( 'yeffoprint-search', 'yeffoprintSearch', [
		'restUrl' => esc_url_raw( rest_url( 'wp/v2/yp_template' ) ),
	] );

	// Cart drawer data — global (the cart icon/drawer live in the
	// header on every page), not just on the configurator screen.
	if ( function_exists( 'WC' ) ) {
		wp_localize_script( 'yeffoprint-site', 'yeffoprintCart', [
			'restUrl' => esc_url_raw( rest_url( 'yeffoprint-core/v1/' ) ),
		] );
	}

	if ( is_singular( 'yp_template' ) ) {
		wp_enqueue_style(
			'yeffoprint-configurator',
			get_theme_file_uri( 'assets/css/configurator.css' ),
			[ 'yeffoprint-global' ],
			yeffoprint_asset_version( 'assets/css/configurator.css' )
		);

		wp_enqueue_script(
			'yeffoprint-configurator',
			get_theme_file_uri( 'assets/js/configurator.js' ),
			[],
			yeffoprint_asset_version( 'assets/js/configurator.js' ),
			[ 'strategy' => 'defer' ]
		);

		// Direct request: Label View's live preview should render in
		// whatever font the real printed label actually uses, set per
		// Template from the admin (class-template-editor.php) — loaded
		// only here, on that one Template's own page, not site-wide,
		// since it's specific to this one design. Requesting a handful
		// of weights covers the field-fitting range configurator.js
		// already uses (field_size_min/max) without pulling every cut
		// of the family.
		$preview_font = get_post_meta( get_the_ID(), YeffoPrint_Template_Meta::PREVIEW_FONT, true );
		if ( $preview_font ) {
			wp_enqueue_style(
				'yeffoprint-preview-font',
				// urlencode(), not rawurlencode() — Google Fonts' family
				// param expects a space as "+" (the convention the
				// hardcoded Geist/Inter/IBM Plex Mono link elsewhere in
				// this function already uses), which is what urlencode()
				// produces; rawurlencode() would emit "%20" instead.
				'https://fonts.googleapis.com/css2?family=' . urlencode( $preview_font ) . ':wght@400;500;600;700&display=swap',
				[],
				null
			);
		}

		// This page bakes a nonce for the *current visitor's session*
		// into its HTML (below). If a page cache (host-level cache
		// plugin, a CDN) ever serves that same cached response back to
		// a different or later session, every visitor gets that one
		// stale nonce — REST requests then fail cookie/nonce validation
		// ("Cookie check failed") for no reason a visitor would
		// associate with caching. Guests aren't affected (class-rest-
		// security.php doesn't nonce-check them), so this only needs to
		// run for a logged-in visitor, and only tells a well-behaved
		// cache not to store *this* response — it can't override a
		// cache that already served a stale copy without ever asking
		// our server this time.
		if ( is_user_logged_in() ) {
			nocache_headers();
		}

		wp_localize_script( 'yeffoprint-configurator', 'yeffoprintConfigurator', [
			'restUrl'     => esc_url_raw( rest_url( 'yeffoprint-core/v1/' ) ),
			'templateId'  => get_the_ID(),
			// Required for the ?reorder= flow (class-order-item-controller.php
			// requires a logged-in request) and sent on every cart/add call
			// too — guests aren't checked (class-rest-security.php), but a
			// signed-in customer's request needs a valid nonce to pass.
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			// Saved Designs needs an account (nothing to attach an
			// anonymous save to) — the button's label/behavior branches
			// on this rather than hiding it outright, since
			// templates/*.html isn't PHP and can't conditionally omit it.
			'isLoggedIn'  => is_user_logged_in(),
			'accountUrl'  => function_exists( 'wc_get_page_permalink' ) ? esc_url_raw( wc_get_page_permalink( 'myaccount' ) ) : esc_url_raw( home_url( '/my-account/' ) ),
		] );
	}

	if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
		wp_enqueue_style(
			'yeffoprint-woocommerce',
			get_theme_file_uri( 'assets/css/woocommerce.css' ),
			[ 'yeffoprint-global' ],
			yeffoprint_asset_version( 'assets/css/woocommerce.css' )
		);
	}

	// Custom template slug is stored without the .html extension on
	// some WP versions and with it on others — check both rather than
	// guessing which this install uses.
	if ( is_page() && in_array( get_page_template_slug(), [ 'custom-design-form', 'custom-design-form.html' ], true ) ) {
		wp_enqueue_style(
			'yeffoprint-configurator',
			get_theme_file_uri( 'assets/css/configurator.css' ),
			[ 'yeffoprint-global' ],
			yeffoprint_asset_version( 'assets/css/configurator.css' )
		);

		wp_enqueue_style(
			'yeffoprint-custom-order',
			get_theme_file_uri( 'assets/css/custom-order.css' ),
			[ 'yeffoprint-configurator' ],
			yeffoprint_asset_version( 'assets/css/custom-order.css' )
		);

		wp_enqueue_script(
			'yeffoprint-custom-order-form',
			get_theme_file_uri( 'assets/js/custom-order-form.js' ),
			[],
			yeffoprint_asset_version( 'assets/js/custom-order-form.js' ),
			[ 'strategy' => 'defer' ]
		);

		// Same stale-nonce-from-a-cached-page risk as the configurator
		// above — see that comment.
		if ( is_user_logged_in() ) {
			nocache_headers();
		}

		wp_localize_script( 'yeffoprint-custom-order-form', 'yeffoprintCustomOrder', [
			'restUrl' => esc_url_raw( rest_url( 'yeffoprint-core/v1/' ) ),
			// Sent on every upload/submit call — guests aren't checked
			// (class-rest-security.php), but a signed-in customer's
			// request needs a valid nonce to pass.
			'nonce'   => wp_create_nonce( 'wp_rest' ),
		] );
	}

	if ( is_page() && in_array( get_page_template_slug(), [ 'proof-approval', 'proof-approval.html' ], true ) ) {
		wp_enqueue_style(
			'yeffoprint-configurator',
			get_theme_file_uri( 'assets/css/configurator.css' ),
			[ 'yeffoprint-global' ],
			yeffoprint_asset_version( 'assets/css/configurator.css' )
		);

		wp_enqueue_style(
			'yeffoprint-proof-approval',
			get_theme_file_uri( 'assets/css/proof-approval.css' ),
			[ 'yeffoprint-configurator' ],
			yeffoprint_asset_version( 'assets/css/proof-approval.css' )
		);

		wp_enqueue_script(
			'yeffoprint-proof-approval',
			get_theme_file_uri( 'assets/js/proof-approval.js' ),
			[],
			yeffoprint_asset_version( 'assets/js/proof-approval.js' ),
			[ 'strategy' => 'defer' ]
		);

		// Same stale-nonce-from-a-cached-page risk as the configurator
		// above — see that comment.
		if ( is_user_logged_in() ) {
			nocache_headers();
		}

		wp_localize_script( 'yeffoprint-proof-approval', 'yeffoprintProofApproval', [
			'restUrl' => esc_url_raw( rest_url( 'yeffoprint-core/v1/' ) ),
			// Only meaningful for a logged-in customer/staff viewing
			// their own request — a guest is authenticated by the
			// `token` query param instead (class-proof-approval-
			// controller.php's check_access()), which needs no nonce.
			'nonce'   => wp_create_nonce( 'wp_rest' ),
		] );
	}

	if ( is_page() && in_array( get_page_template_slug(), [ 'track-order', 'track-order.html' ], true ) ) {
		wp_enqueue_style(
			'yeffoprint-configurator',
			get_theme_file_uri( 'assets/css/configurator.css' ),
			[ 'yeffoprint-global' ],
			yeffoprint_asset_version( 'assets/css/configurator.css' )
		);

		wp_enqueue_style(
			'yeffoprint-track-order',
			get_theme_file_uri( 'assets/css/track-order.css' ),
			[ 'yeffoprint-configurator' ],
			yeffoprint_asset_version( 'assets/css/track-order.css' )
		);

		wp_enqueue_script(
			'yeffoprint-track-order',
			get_theme_file_uri( 'assets/js/track-order.js' ),
			[],
			yeffoprint_asset_version( 'assets/js/track-order.js' ),
			[ 'strategy' => 'defer' ]
		);

		// Same stale-nonce-from-a-cached-page risk as the configurator
		// above — see that comment.
		if ( is_user_logged_in() ) {
			nocache_headers();
		}

		wp_localize_script( 'yeffoprint-track-order', 'yeffoprintTrackOrder', [
			'restUrl' => esc_url_raw( rest_url( 'yeffoprint-core/v1/' ) ),
			// Only meaningful for a logged-in customer/staff viewing
			// their own order — a guest is authenticated by the `key`
			// query param instead (class-order-tracking-controller.php's
			// check_access()), which needs no nonce.
			'nonce'   => wp_create_nonce( 'wp_rest' ),
		] );
	}
} );

/**
 * The Shop Labels gallery card. Server-rendered (no editor script
 * needed) so Query Loop can place it as the Post Template's content —
 * see blocks/template-card/render.php.
 */
add_action( 'init', function () {
	register_block_type( get_theme_file_path( 'blocks/template-card' ) );
	register_block_type( get_theme_file_path( 'blocks/gallery-toolbar' ) );
	register_block_type( get_theme_file_path( 'blocks/announcement-bar' ) );
} );

/**
 * Homepage/storefront patterns register themselves from patterns/*.php
 * (core auto-discovers that directory); this just gives them a
 * dedicated category so they're easy to find in the inserter.
 */
add_action( 'init', function () {
	register_block_pattern_category( 'yeffoprint', [
		'label' => __( 'YeffoPrint', 'yeffoprint' ),
	] );
} );

/**
 * WooCommerce auto-injects its own Mini Cart block into block-theme
 * headers via the Block Hooks API. The header already has its own
 * cart icon + slide-out drawer (parts/header.html, assets/js/site.js)
 * wired to yeffoprint-core's cart endpoints, so the auto-injected one
 * is a second, independently-updating cart UI rather than a fallback —
 * strip it instead of letting both render. See docs/ARCHITECTURE.md §9.
 */
add_filter( 'hooked_block_types', function ( $hooked_block_types, $relative_position, $anchor_block_type, $context ) {
	// No type hints: WordPress core controls what it passes here, and
	// a strict scalar hint (e.g. string $anchor_block_type) throws a
	// fatal TypeError the moment core passes something that doesn't
	// match exactly — not worth risking on a value this filter doesn't
	// even use.
	if ( ! is_array( $hooked_block_types ) ) {
		return $hooked_block_types;
	}

	return array_values( array_diff( $hooked_block_types, [ 'woocommerce/mini-cart' ] ) );
}, 20, 4 );

