<?php
/**
 * Top-level "YeffoPrint" wp-admin menu.
 *
 * Post types register with show_in_menu => 'yeffoprint' (see
 * class-post-type-registry.php) and attach as submenus here. Richer
 * dashboard content lands in a later phase — see PROJECT_SPEC.md §17.
 *
 * The announcement bar text (below) is the first real Site Setting,
 * on its own explicitly-labeled "Settings" submenu — not on the
 * top-level dashboard page itself, which is only reachable through
 * WordPress's own default same-labeled ("YeffoPrint") self-link
 * submenu item it adds automatically once other real submenus exist
 * (the CPT ones above), easy to miss/mistake for just a toggle rather
 * than a page. A distinctly-named submenu is worth it even for one
 * field.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_Menu {

	/**
	 * Also read by yeffoprint_core_get_announcement_bar_text()
	 * (includes/api/template-api.php) as its own default — kept here,
	 * not duplicated, since this class owns the option's registration.
	 */
	const ANNOUNCEMENT_BAR_OPTION  = 'yeffoprint_announcement_bar_text';
	const ANNOUNCEMENT_BAR_DEFAULT = 'Free proofing on every fully custom order.';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		// Runs after WordPress has built every submenu (including the
		// Custom Orders CPT one, auto-attached via show_in_menu =>
		// 'yeffoprint' in class-post-type-registry.php rather than
		// registered here) — a fixed late priority is simpler and just
		// as reliable as chasing an exact "after CPT registration" hook.
		add_action( 'admin_menu', [ $this, 'add_needs_attention_badge' ], 999 );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
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

		add_submenu_page(
			'yeffoprint',
			__( 'Settings', 'yeffoprint-core' ),
			__( 'Settings', 'yeffoprint-core' ),
			'manage_options',
			'yeffoprint-settings',
			[ $this, 'render_settings_page' ]
		);
	}

	public function register_settings(): void {
		register_setting( 'yeffoprint_settings', self::ANNOUNCEMENT_BAR_OPTION, [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => self::ANNOUNCEMENT_BAR_DEFAULT,
		] );

		add_settings_section(
			'yeffoprint_announcement_bar',
			__( 'Announcement Bar', 'yeffoprint-core' ),
			'__return_false',
			'yeffoprint-settings'
		);

		add_settings_field(
			self::ANNOUNCEMENT_BAR_OPTION,
			__( 'Announcement text', 'yeffoprint-core' ),
			[ $this, 'render_announcement_bar_field' ],
			'yeffoprint-settings',
			'yeffoprint_announcement_bar'
		);
	}

	public function render_announcement_bar_field(): void {
		$value = get_option( self::ANNOUNCEMENT_BAR_OPTION, self::ANNOUNCEMENT_BAR_DEFAULT );
		?>
		<input
			type="text"
			class="regular-text"
			name="<?php echo esc_attr( self::ANNOUNCEMENT_BAR_OPTION ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
		/>
		<p class="description"><?php esc_html_e( 'Shown in the thin bar above the header, on every page. Leave blank to hide the bar entirely.', 'yeffoprint-core' ); ?></p>
		<?php
	}

	public function render_dashboard(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'YeffoPrint', 'yeffoprint-core' ) . '</h1>';
		echo '<p>' . esc_html__( 'Templates, Materials, Sizes, Pricing Rules, Custom Orders, and Proofs are managed from this menu.', 'yeffoprint-core' ) . '</p></div>';
	}

	public function render_settings_page(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'YeffoPrint Settings', 'yeffoprint-core' ) . '</h1>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'yeffoprint_settings' );
		do_settings_sections( 'yeffoprint-settings' );
		submit_button();
		echo '</form></div>';
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
