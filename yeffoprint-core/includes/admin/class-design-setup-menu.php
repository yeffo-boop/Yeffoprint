<?php
/**
 * Tab bar tying Templates, Sizes, Materials, and Sticker Sizes together
 * into one "Design Setup" section of the sidebar (direct request — four
 * separate CPT submenu items was too much sidebar clutter).
 *
 * Each of the four keeps its own native list table, editor screens, and
 * columns exactly as before (see class-post-type-registry.php's
 * $show_in_menu overrides — Sizes/Materials/Sticker Sizes just don't get
 * their own sidebar entry anymore, and Templates' entry is relabeled
 * "Design Setup" and doubles as this group's landing page). This class
 * only adds the tab strip at the top of each one's list-table screen so
 * they read as one consolidated page rather than four unrelated ones.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Design_Setup_Menu {

	public function __construct() {
		add_action( 'current_screen', [ $this, 'render_tabs_maybe' ] );
	}

	/** post_type => tab label. Order here is tab order; translated inline since __() can't sit in a class constant. */
	private function tabs(): array {
		return [
			'yp_template'     => __( 'Templates', 'yeffoprint-core' ),
			'yp_size'         => __( 'Sizes', 'yeffoprint-core' ),
			'yp_material'     => __( 'Materials', 'yeffoprint-core' ),
			'yp_sticker_size' => __( 'Sticker Sizes', 'yeffoprint-core' ),
		];
	}

	public function render_tabs_maybe( \WP_Screen $screen ): void {
		if ( 'edit' !== $screen->base || ! array_key_exists( $screen->post_type, $this->tabs() ) ) {
			return;
		}

		add_action( 'admin_notices', [ $this, 'render_tabs' ] );
	}

	public function render_tabs(): void {
		$screen       = get_current_screen();
		$current_type = $screen ? $screen->post_type : '';
		?>
		<h2 class="nav-tab-wrapper" style="margin-bottom: 1em;">
			<?php foreach ( $this->tabs() as $post_type => $label ) : ?>
				<a
					href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . $post_type ) ); ?>"
					class="nav-tab<?php echo $post_type === $current_type ? ' nav-tab-active' : ''; ?>"
				><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</h2>
		<?php
	}
}
