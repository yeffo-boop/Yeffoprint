<?php
/**
 * Top-level "YeffoPrint" wp-admin menu.
 *
 * Post types register with show_in_menu => 'yeffoprint' (see
 * class-post-type-registry.php) and attach as submenus here. Site
 * Settings and richer dashboard content land in a later phase — see
 * PROJECT_SPEC.md §17.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_Menu {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
	}

	public function register_menu(): void {
		add_menu_page(
			__( 'YeffoPrint', 'yeffoprint-core' ),
			__( 'YeffoPrint', 'yeffoprint-core' ),
			'manage_options',
			'yeffoprint',
			[ $this, 'render_dashboard' ],
			'dashicons-store',
			25
		);
	}

	public function render_dashboard(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'YeffoPrint', 'yeffoprint-core' ) . '</h1>';
		echo '<p>' . esc_html__( 'Templates, Materials, Sizes, Pricing Rules, Custom Orders, and Proofs are managed from this menu.', 'yeffoprint-core' ) . '</p></div>';
	}
}
