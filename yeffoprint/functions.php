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

	wp_enqueue_script(
		'yeffoprint-site',
		get_theme_file_uri( 'assets/js/site.js' ),
		[],
		$theme_version,
		[ 'strategy' => 'defer' ]
	);
} );
