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
add_action( 'wp_enqueue_scripts', function () {
	$theme_version = wp_get_theme()->get( 'Version' );

	wp_enqueue_style(
		'yeffoprint-global',
		get_theme_file_uri( 'assets/css/global.css' ),
		[],
		$theme_version
	);

	// Homepage/storefront section styling — kept separate from
	// global.css so that file stays scoped to header/footer/drawer/
	// form/button chrome. See docs/ARCHITECTURE.md §9.
	wp_enqueue_style(
		'yeffoprint-patterns',
		get_theme_file_uri( 'assets/css/patterns.css' ),
		[ 'yeffoprint-global' ],
		$theme_version
	);

	wp_enqueue_script(
		'yeffoprint-site',
		get_theme_file_uri( 'assets/js/site.js' ),
		[],
		$theme_version,
		[ 'strategy' => 'defer' ]
	);

	wp_enqueue_script(
		'yeffoprint-search',
		get_theme_file_uri( 'assets/js/search.js' ),
		[],
		$theme_version,
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
			$theme_version
		);

		wp_enqueue_script(
			'yeffoprint-configurator',
			get_theme_file_uri( 'assets/js/configurator.js' ),
			[],
			$theme_version,
			[ 'strategy' => 'defer' ]
		);

		wp_localize_script( 'yeffoprint-configurator', 'yeffoprintConfigurator', [
			'restUrl'    => esc_url_raw( rest_url( 'yeffoprint-core/v1/' ) ),
			'templateId' => get_the_ID(),
		] );
	}

	if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
		wp_enqueue_style(
			'yeffoprint-woocommerce',
			get_theme_file_uri( 'assets/css/woocommerce.css' ),
			[ 'yeffoprint-global' ],
			$theme_version
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
			$theme_version
		);

		wp_enqueue_style(
			'yeffoprint-custom-order',
			get_theme_file_uri( 'assets/css/custom-order.css' ),
			[ 'yeffoprint-configurator' ],
			$theme_version
		);

		wp_enqueue_script(
			'yeffoprint-custom-order-form',
			get_theme_file_uri( 'assets/js/custom-order-form.js' ),
			[],
			$theme_version,
			[ 'strategy' => 'defer' ]
		);

		wp_localize_script( 'yeffoprint-custom-order-form', 'yeffoprintCustomOrder', [
			'restUrl' => esc_url_raw( rest_url( 'yeffoprint-core/v1/' ) ),
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
