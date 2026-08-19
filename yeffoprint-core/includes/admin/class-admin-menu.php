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
		// Runs after WordPress has built every submenu (including the
		// Custom Orders CPT one, auto-attached via show_in_menu =>
		// 'yeffoprint' in class-post-type-registry.php rather than
		// registered here) — a fixed late priority is simpler and just
		// as reliable as chasing an exact "after CPT registration" hook.
		add_action( 'admin_menu', [ $this, 'add_needs_attention_badge' ], 999 );
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

	/**
	 * The in-admin notification asked for: a Comments/Orders-style
	 * bubble count on both "Custom Orders" and the top-level
	 * "YeffoPrint" menu (so it's visible even collapsed), counting every
	 * Custom Order currently in "Design in progress" — the one status
	 * that always means "staff owes this customer a proof," whether
	 * that's because the order is brand new or because the customer
	 * just requested changes on the last one (class-proof-approval-
	 * controller.php's request_changes() sends it back to this exact
	 * status). One shared count rather than two separate ones: the
	 * action that clears either case is identical (upload a new proof),
	 * so there's nothing a split count would let staff do differently.
	 */
	public function add_needs_attention_badge(): void {
		global $submenu, $menu;

		$count = $this->count_needing_a_proof();
		if ( ! $count ) {
			return;
		}

		$badge = sprintf(
			' <span class="awaiting-mod count-%1$d"><span class="pending-count">%1$d</span></span>',
			$count
		);

		if ( ! empty( $submenu['yeffoprint'] ) ) {
			foreach ( $submenu['yeffoprint'] as &$item ) {
				if ( isset( $item[2] ) && 'edit.php?post_type=yp_custom_order' === $item[2] ) {
					$item[0] .= $badge;
					break;
				}
			}
			unset( $item );
		}

		foreach ( $menu as &$top_level_item ) {
			if ( isset( $top_level_item[2] ) && 'yeffoprint' === $top_level_item[2] ) {
				$top_level_item[0] .= $badge;
				break;
			}
		}
		unset( $top_level_item );
	}

	private function count_needing_a_proof(): int {
		$query = new \WP_Query( [
			'post_type'      => 'yp_custom_order',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [
				[
					'key'   => YeffoPrint_Custom_Order_Meta::STATUS,
					'value' => 'design_in_progress',
				],
			],
		] );

		return (int) $query->found_posts;
	}
}
