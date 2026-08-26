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
			'restUrl'      => esc_url_raw( rest_url( 'yeffoprint-core/v1/' ) ),
			// Sent on every upload/submit call — guests aren't checked
			// (class-rest-security.php), but a signed-in customer's
			// request needs a valid nonce to pass.
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			// Reorder mode has no guest path (class-custom-order-
			// controller.php's /custom-orders/eligible-reorders is
			// logged-in only) — told up front so the form can disable
			// that option for a guest instead of letting them pick it
			// and only then discovering the picker has nothing to show.
			'isLoggedIn'   => is_user_logged_in(),
		] );
	}

	if ( is_page() && in_array( get_page_template_slug(), [ 'custom-stickers-form', 'custom-stickers-form.html' ], true ) ) {
		wp_enqueue_style(
			'yeffoprint-configurator',
			get_theme_file_uri( 'assets/css/configurator.css' ),
			[ 'yeffoprint-global' ],
			yeffoprint_asset_version( 'assets/css/configurator.css' )
		);

		// Same custom-order.css both forms share — see that file's own
		// docblock for why it isn't split in two.
		wp_enqueue_style(
			'yeffoprint-custom-order',
			get_theme_file_uri( 'assets/css/custom-order.css' ),
			[ 'yeffoprint-configurator' ],
			yeffoprint_asset_version( 'assets/css/custom-order.css' )
		);

		wp_enqueue_script(
			'yeffoprint-custom-sticker-form',
			get_theme_file_uri( 'assets/js/custom-sticker-form.js' ),
			[],
			yeffoprint_asset_version( 'assets/js/custom-sticker-form.js' ),
			[ 'strategy' => 'defer' ]
		);

		// Same stale-nonce-from-a-cached-page risk as the configurator
		// above — see that comment.
		if ( is_user_logged_in() ) {
			nocache_headers();
		}

		wp_localize_script( 'yeffoprint-custom-sticker-form', 'yeffoprintCustomSticker', [
			'restUrl' => esc_url_raw( rest_url( 'yeffoprint-core/v1/' ) ),
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

	if ( is_page() && in_array( get_page_template_slug(), [ 'contact-form', 'contact-form.html' ], true ) ) {
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
			'yeffoprint-contact-form',
			get_theme_file_uri( 'assets/js/contact-form.js' ),
			[],
			yeffoprint_asset_version( 'assets/js/contact-form.js' ),
			[ 'strategy' => 'defer' ]
		);

		// Same stale-nonce-from-a-cached-page risk as the configurator
		// above — see that comment.
		if ( is_user_logged_in() ) {
			nocache_headers();
		}

		wp_localize_script( 'yeffoprint-contact-form', 'yeffoprintContact', [
			'restUrl' => esc_url_raw( rest_url( 'yeffoprint-core/v1/' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
		] );
	}
} );

/**
 * "We've upgraded" homepage splash — Dashboard → YeffoPrint → Settings
 * → Splash Screen. Reuses the same accessible drawer primitive already
 * driving the header's search/cart drawers (assets/js/site.js's
 * openDrawer/closeDrawer + the .yp-drawer markup convention), just a
 * centered-modal variant instead of a side panel — see global.css's
 * .yp-drawer--center rules and site.js's initSplashScreen(). Markup
 * only exists in the page at all when there's something to show,
 * rather than always being present and CSS/JS-hidden — a disabled or
 * unconfigured splash costs this page nothing.
 */
add_action( 'wp_footer', function () {
	if ( ! is_front_page() || ! get_option( YeffoPrint_Admin_Menu::SPLASH_ENABLED_OPTION ) ) {
		return;
	}

	$image_id  = (int) get_option( YeffoPrint_Admin_Menu::SPLASH_IMAGE_ID_OPTION );
	$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'large' ) : '';
	if ( ! $image_url ) {
		return; // Nothing configured yet — see the Settings screen's own description.
	}
	?>
	<div id="yp-splash-drawer" class="yp-drawer yp-drawer--center" aria-hidden="true">
		<div class="yp-drawer__backdrop"></div>
		<div class="yp-drawer__panel yp-splash" role="dialog" aria-modal="true" aria-labelledby="yp-splash-heading">
			<button type="button" class="yp-icon-button yp-splash__close" data-yp-drawer-close aria-label="<?php esc_attr_e( 'Close', 'yeffoprint' ); ?>">
				<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
					<line x1="2" y1="2" x2="14" y2="14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
					<line x1="14" y1="2" x2="2" y2="14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
				</svg>
			</button>
			<img class="yp-splash__image" src="<?php echo esc_url( $image_url ); ?>" alt="<?php esc_attr_e( 'A preview of the new YeffoPrint site', 'yeffoprint' ); ?>" />
			<div class="yp-splash__body">
				<p class="yp-eyebrow"><?php esc_html_e( "We've Upgraded", 'yeffoprint' ); ?></p>
				<h2 id="yp-splash-heading"><?php esc_html_e( 'Welcome to the New YeffoPrint', 'yeffoprint' ); ?></h2>
				<p><?php esc_html_e( "You're looking at our newly rebuilt site — faster, and easier to order from. We're still smoothing out a few rough edges after the move, so if anything looks off or isn't working the way it should, we'd genuinely appreciate you letting us know.", 'yeffoprint' ); ?></p>
				<div class="yp-splash__actions">
					<button type="button" class="wp-block-button__link is-style-accent" data-yp-drawer-close><?php esc_html_e( 'Continue to the site', 'yeffoprint' ); ?></button>
					<a href="/contact/" class="yp-splash__dismiss"><?php esc_html_e( 'Report an Issue', 'yeffoprint' ); ?></a>
				</div>
			</div>
		</div>
	</div>
	<?php
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
	register_block_type( get_theme_file_path( 'blocks/promo-banner' ) );
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

/**
 * Login page — direct request to style wp-login.php like the rest of
 * the site, plus make sure it's the *only* login screen a visitor ever
 * sees. WordPress deliberately never loads the theme's normal
 * stylesheet or template parts on wp-login.php, so this styles it
 * directly (assets/css/login.css) rather than trying to route it
 * through global.css's theme.json custom properties, which don't exist
 * on that page at all.
 *
 * The consolidation half: WooCommerce renders its own inline login
 * form on the My Account page for a logged-out visitor (same idea as
 * the Mini Cart block hook stripped above — a second, independently-
 * styled UI competing with the one this theme actually owns) instead of
 * sending them here. Redirecting that one specific case to wp-login.php
 * is enough to cover every entry point on this site: the header's
 * Customer Account icon and the configurator's "Log in to save this
 * design" button (assets/js/configurator.js) both just link to the My
 * Account page already, wp-admin's own access check already sends
 * logged-out staff to wp-login.php on its own, and this deliberately
 * leaves the "Lost your password?" flow alone — WooCommerce sends its
 * own branded reset email (woocommerce/emails/customer-reset-
 * password.php) from its own version of that flow, not core's, so
 * rerouting it here would silently swap that for WordPress's plain
 * default email instead.
 */
add_action( 'login_enqueue_scripts', function () {
	wp_enqueue_style(
		'yeffoprint-fonts',
		'https://fonts.googleapis.com/css2?family=Geist:wght@600;700;800&family=Inter:wght@400;500;600&display=swap',
		[],
		null
	);

	wp_enqueue_style(
		'yeffoprint-login',
		get_theme_file_uri( 'assets/css/login.css' ),
		[ 'login', 'yeffoprint-fonts' ],
		yeffoprint_asset_version( 'assets/css/login.css' )
	);
} );

add_filter( 'login_headerurl', function () {
	return home_url( '/' );
} );

add_filter( 'login_headertext', function () {
	return get_bloginfo( 'name' );
} );

/** Same brand lockup markup as parts/header.html's — WordPress's own logo/h1 is hidden via login.css instead of overridden in place, so this doesn't have to fight its background-image approach. */
add_action( 'login_header', function () {
	?>
	<div class="yp-login-lockup">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="yp-brand-lockup" aria-label="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
			<svg class="yp-brand-lockup__mark" viewBox="0 0 30 34" aria-hidden="true" focusable="false">
				<clipPath id="ypLoginBrandMarkClip"><path d="M4 6C4 3.79 5.79 2 8 2h14c2.21 0 4 1.79 4 4v22c0 3.31-2.69 6-6 6h-10c-3.31 0-6-2.69-6-6V6z" /></clipPath>
				<g clip-path="url(#ypLoginBrandMarkClip)">
					<rect x="4" y="2" width="7.3" height="34" fill="#00AEEF" />
					<rect x="11.3" y="2" width="7.3" height="34" fill="#EC008C" />
					<rect x="18.7" y="2" width="7.3" height="34" fill="#FFF200" />
				</g>
			</svg>
			<span class="yp-brand-lockup__word"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
		</a>
	</div>
	<?php
} );

/** The consolidation redirect described above. */
add_action( 'template_redirect', function () {
	if ( is_user_logged_in() || ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
		return;
	}

	if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'lost-password' ) ) {
		return; // Left on WooCommerce's own page — see the doc comment above.
	}

	// wp-login.php only shows a "Register" link/form when the site-wide
	// users_can_register option is on — a separate setting from this
	// one, which is what actually controls the Register column WC's own
	// login form shows here. If a store has this on, it's actively using
	// self-service registration from this exact page, so redirecting
	// away would silently remove that ability rather than just
	// reskinning it; leaving WooCommerce's page in place for that case
	// is the safe default until wp-login.php's own register flow is
	// confirmed to cover the same ground.
	if ( 'yes' === get_option( 'woocommerce_enable_myaccount_registration' ) ) {
		return;
	}

	wp_safe_redirect( wp_login_url( home_url( add_query_arg( [] ) ) ) );
	exit;
} );

/**
 * WooCommerce's own login form used to default a customer straight back
 * to the My Account page after signing in; now that this is the only
 * login screen, this replicates that so a customer who lands here
 * directly (no explicit redirect_to already set, e.g. by the
 * consolidation redirect above) doesn't end up dropped into wp-admin,
 * which they have no reason to be in. Staff/admin logins are
 * untouched — manage_options is the same capability every other
 * admin-only screen in this codebase gates on (e.g. class-rewards-
 * admin.php).
 */
add_filter( 'login_redirect', function ( $redirect_to, $requested_redirect_to, $user ) {
	if ( '' !== $requested_redirect_to || ! ( $user instanceof WP_User ) || user_can( $user, 'manage_options' ) ) {
		return $redirect_to;
	}

	return function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : $redirect_to;
}, 10, 3 );

/**
 * The Material Guide's per-material copy plus its resolved live data
 * (real photo, thickness-derived spec) — shared by the guide itself
 * (patterns/material-guide.php, on the How It Works page) and the
 * label configurator's Material info modal
 * (patterns/material-info-modal.php), so "the material information"
 * only exists in one place and the two surfaces can't drift apart.
 *
 * The business copy (name/body/note) is still deliberately hardcoded
 * here rather than derived from a Material record's own post_content
 * blurb — see material-guide.php's own docblock for why. Only the
 * data-fetching (matching each entry's slug against a published
 * yp_material record for its photo/thickness) moved here; both
 * patterns' own rendering stayed where it was.
 */
function yeffoprint_material_guide_entries(): array {
	$materials = [
		[
			'slug'   => 'glossy-white',
			'name'   => 'Standard White Glossy',
			'spec'   => '2.75mil · Glossy',
			'finish' => 'Glossy',
			'body'   => 'Our standard material, recommended for most projects. A bright white base with a slight shine, and very easy to apply.',
			'note'   => '',
		],
		[
			'slug'   => 'matte-white',
			'name'   => 'Standard White Matte',
			'spec'   => '2.75mil · Matte',
			'finish' => 'Matte',
			'body'   => 'Our standard material, recommended for most projects. The same bright white base as our glossy option, without the shine, and very easy to apply.',
			'note'   => '',
		],
		[
			'slug'   => 'holographic',
			'name'   => 'Holographic',
			'spec'   => 'Rainbow Sheen',
			'finish' => 'Rainbow Sheen',
			'body'   => 'Our most popular holographic option, slightly thicker than our standard labels. Anywhere your design shows white, it takes on a rainbow, holographic sheen in the light.',
			'note'   => "Designs with highly saturated solid colors can occasionally cause holographic sheets to curl slightly during shipping. This never affects a label's print quality or stickiness, but it does mean holographic orders ship about 24 hours later than usual to account for it.",
		],
		[
			'slug'   => 'prism',
			'name'   => 'Prism',
			'spec'   => 'Prism Pattern',
			'finish' => 'Prism Pattern',
			'body'   => 'One of the newest additions to our lineup, slightly thicker than our standard labels. Anywhere your design shows white, it takes on our prism pattern — best suited to simpler designs, since a busier design can make the effect feel overwhelming.',
			'note'   => '',
		],
		[
			'slug'   => 'metallic',
			'name'   => 'Metallic',
			'spec'   => '4mil · Chrome',
			'finish' => 'Chrome',
			'body'   => 'A newer addition with a true chrome finish. Anywhere your design shows white, it takes on a metallic shine.',
			'note'   => '',
		],
		[
			'slug'   => 'clear',
			'name'   => 'Transparent (Clear)',
			'spec'   => 'Clear',
			'finish' => 'Clear',
			'body'   => 'Exactly what it sounds like — a fully clear label. Works best with simpler designs and less image detail, since fine detail can oversaturate during printing and edges can appear slightly blurred.',
			'note'   => '',
		],
	];

	return array_map( function ( $material ) {
		$record    = get_posts( [
			'name'           => $material['slug'],
			'post_type'      => 'yp_material',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
		] );
		$photo_url = $record ? get_the_post_thumbnail_url( $record[0], 'thumbnail' ) : '';
		$hover_id  = $record ? (int) get_post_meta( $record[0]->ID, YeffoPrint_Commerce_Record_Meta::HOVER_IMAGE, true ) : 0;
		$zoom_url  = $hover_id ? wp_get_attachment_image_url( $hover_id, 'large' ) : ( $record ? get_the_post_thumbnail_url( $record[0], 'large' ) : '' );
		$thickness = $record ? (float) get_post_meta( $record[0]->ID, YeffoPrint_Commerce_Record_Meta::THICKNESS_MIL, true ) : 0;
		$spec      = $material['spec'];

		if ( $thickness > 0 ) {
			$thickness_display = rtrim( rtrim( number_format( $thickness, 2, '.', '' ), '0' ), '.' );
			$spec               = $thickness_display . 'mil · ' . $material['finish'];
		}

		return array_merge( $material, [
			'photo_url' => $photo_url ?: '',
			'zoom_url'  => $zoom_url ?: '',
			'spec'      => $spec,
		] );
	}, $materials );
}

