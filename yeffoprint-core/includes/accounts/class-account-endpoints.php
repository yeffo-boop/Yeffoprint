<?php
/**
 * The branded My Account dashboard's non-native tabs (PROJECT_SPEC
 * §16: "Orders, Saved Designs, Rewards, Proofs, Addresses").
 *
 * Orders and Addresses are native WooCommerce — presentation-only
 * restyling lives in the theme. Rewards is still a V1 non-goal
 * (PROJECT_SPEC §19): its tab exists so the account navigation matches
 * spec, but its content is a plain "coming soon" state, not a feature.
 * Saved Designs is real as of the V2 pass — see
 * includes/rest/class-saved-design-controller.php for the create/list/
 * fetch/delete REST endpoints this tab's list and "Remove" action use.
 * Proofs is real too: it queries the customer's own CustomOrders and
 * any Proofs uploaded against them — this is the "foundation" half of
 * "Proof Foundation" (Phase 8) actually being visible to the customer
 * it belongs to, still short of the customer-facing approve/request-
 * changes UI that's its own V2 item.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Account_Endpoints {

	private const ENDPOINTS = [
		'saved-designs' => 'Saved Designs',
		'rewards'       => 'Rewards',
		'proofs'        => 'Proofs',
	];

	private const REMOVE_NONCE_ACTION = 'yeffoprint_remove_saved_design';
	private const REMOVE_NONCE_NAME   = 'yp_saved_design_nonce';

	public function __construct() {
		add_action( 'init', [ $this, 'register_endpoints' ] );
		add_filter( 'query_vars', [ $this, 'register_query_vars' ] );
		add_filter( 'woocommerce_account_menu_items', [ $this, 'reorder_menu_items' ] );
		add_action( 'template_redirect', [ $this, 'maybe_handle_remove_saved_design' ] );

		add_action( 'woocommerce_account_saved-designs_endpoint', [ $this, 'render_saved_designs' ] );
		add_action( 'woocommerce_account_rewards_endpoint', [ $this, 'render_rewards' ] );
		add_action( 'woocommerce_account_proofs_endpoint', [ $this, 'render_proofs' ] );
	}

	/**
	 * Plain POST-and-redirect, no REST/JS needed — the "Remove" form
	 * posts back to the same account page, this processes it on
	 * template_redirect (before any output starts, so a real redirect
	 * is still possible) and sends the customer back to a clean URL
	 * rather than leaving a resubmit-on-refresh POST in their history.
	 */
	public function maybe_handle_remove_saved_design(): void {
		if ( empty( $_POST['yp_remove_saved_design'] ) || ! isset( $_POST[ self::REMOVE_NONCE_NAME ] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( wp_unslash( $_POST[ self::REMOVE_NONCE_NAME ] ), self::REMOVE_NONCE_ACTION ) ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			return;
		}

		$design_id = absint( $_POST['yp_remove_saved_design'] );
		$design    = get_post( $design_id );

		if ( $design && 'yp_saved_design' === $design->post_type && (int) $design->post_author === get_current_user_id() ) {
			wp_delete_post( $design_id, true );
		}

		wp_safe_redirect( function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'saved-designs' ) : home_url( '/my-account/saved-designs/' ) );
		exit;
	}

	public function register_endpoints(): void {
		foreach ( array_keys( self::ENDPOINTS ) as $endpoint ) {
			add_rewrite_endpoint( $endpoint, EP_ROOT | EP_PAGES );
		}
	}

	public function register_query_vars( array $vars ): array {
		return array_merge( $vars, array_keys( self::ENDPOINTS ) );
	}

	/**
	 * WooCommerce's default order is Dashboard, Orders, Downloads*,
	 * Addresses, Payment methods*, Account details, Logout (*if
	 * applicable). PROJECT_SPEC §16 wants Orders, Saved Designs,
	 * Rewards, Proofs, Addresses — rebuilt here rather than just
	 * appended, preserving any WC-provided items (like Downloads) this
	 * store might still show.
	 */
	public function reorder_menu_items( array $items ): array {
		$after_orders = [];
		foreach ( self::ENDPOINTS as $slug => $label ) {
			$after_orders[ $slug ] = __( $label, 'yeffoprint-core' );
		}

		$ordered = [];
		foreach ( $items as $key => $label ) {
			$ordered[ $key ] = $label;
			if ( 'orders' === $key ) {
				$ordered = array_merge( $ordered, $after_orders );
			}
		}

		// If this store has no native "orders" item for some reason,
		// still surface ours rather than silently dropping them.
		if ( ! isset( $items['orders'] ) ) {
			$ordered = array_merge( $ordered, $after_orders );
		}

		return $ordered;
	}

	public function render_saved_designs(): void {
		$design_ids = YeffoPrint_Saved_Design_Meta::get_for_customer( get_current_user_id() );

		if ( ! $design_ids ) {
			echo '<p>' . esc_html__( "You haven't saved any designs yet — while customizing a label, use \"Save this design\" to bookmark it and pick it back up later.", 'yeffoprint-core' ) . '</p>';
			echo '<p><a class="wp-block-button__link" href="' . esc_url( home_url( '/shop-labels/' ) ) . '">' . esc_html__( 'Browse Designs', 'yeffoprint-core' ) . '</a></p>';
			return;
		}

		echo '<div class="yp-saved-designs-list">';
		foreach ( $design_ids as $design_id ) {
			$this->render_saved_design_card( $design_id );
		}
		echo '</div>';
	}

	private function render_saved_design_card( int $design_id ): void {
		$template_id = (int) get_post_meta( $design_id, YeffoPrint_Saved_Design_Meta::TEMPLATE_ID, true );
		$template    = $template_id ? get_post( $template_id ) : null;
		$size        = get_post( (int) get_post_meta( $design_id, YeffoPrint_Saved_Design_Meta::SIZE_ID, true ) );
		$material    = get_post( (int) get_post_meta( $design_id, YeffoPrint_Saved_Design_Meta::MATERIAL_ID, true ) );
		$variants    = (array) get_post_meta( $design_id, YeffoPrint_Saved_Design_Meta::VARIANTS, true );
		$thumbnail   = $template ? get_the_post_thumbnail_url( $template, 'thumbnail' ) : '';
		$edit_url    = $template ? add_query_arg( 'saved', $design_id, get_permalink( $template ) ) : '';
		?>
		<div class="yp-saved-design-card">
			<?php if ( $thumbnail ) : ?>
				<img class="yp-saved-design-card__thumb" src="<?php echo esc_url( $thumbnail ); ?>" alt="" />
			<?php endif; ?>
			<div class="yp-saved-design-card__body">
				<strong><?php echo esc_html( $template ? get_the_title( $template ) : __( '(design removed)', 'yeffoprint-core' ) ); ?></strong>
				<span>
					<?php
					echo esc_html( implode( ' · ', array_filter( [
						$size ? $size->post_title : '',
						$material ? $material->post_title : '',
						sprintf(
							/* translators: %d: number of label variants in the batch */
							_n( '%d label variant', '%d label variants', count( $variants ), 'yeffoprint-core' ),
							count( $variants )
						),
					] ) ) );
					?>
				</span>
				<span class="yp-saved-design-card__date"><?php echo esc_html( get_the_date( '', $design_id ) ); ?></span>
				<div class="yp-saved-design-card__actions">
					<?php if ( $edit_url ) : ?>
						<a class="wp-block-button__link" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit & Order', 'yeffoprint-core' ); ?></a>
					<?php endif; ?>
					<form method="post">
						<?php wp_nonce_field( self::REMOVE_NONCE_ACTION, self::REMOVE_NONCE_NAME ); ?>
						<button type="submit" name="yp_remove_saved_design" value="<?php echo esc_attr( $design_id ); ?>" class="button-link-delete"><?php esc_html_e( 'Remove', 'yeffoprint-core' ); ?></button>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	public function render_rewards(): void {
		echo '<p>' . esc_html__( 'YeffoPrint Rewards is coming soon — you\'ll earn credit toward future orders every time you print.', 'yeffoprint-core' ) . '</p>';
	}

	public function render_proofs(): void {
		$custom_order_ids = get_posts( [
			'post_type'      => 'yp_custom_order',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'meta_query'     => [
				[
					'key'   => YeffoPrint_Custom_Order_Meta::CUSTOMER_ID,
					'value' => get_current_user_id(),
				],
			],
		] );

		if ( ! $custom_order_ids ) {
			echo '<p>' . esc_html__( "You don't have any custom design requests yet.", 'yeffoprint-core' ) . '</p>';
			echo '<p><a class="wp-block-button__link" href="' . esc_url( home_url( '/custom-design/' ) ) . '">' . esc_html__( 'Create a Custom Label', 'yeffoprint-core' ) . '</a></p>';
			return;
		}

		echo '<div class="yp-proofs-list">';
		foreach ( $custom_order_ids as $custom_order_id ) {
			$this->render_custom_order_card( $custom_order_id );
		}
		echo '</div>';
	}

	private function render_custom_order_card( int $custom_order_id ): void {
		$status      = (string) get_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::STATUS, true );
		$brand       = get_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::BRAND_NAME, true );
		$proof_ids   = YeffoPrint_Proof_Meta::get_for_custom_order( $custom_order_id );
		?>
		<div class="yp-proof-card">
			<div class="yp-proof-card__header">
				<strong><?php echo esc_html( $brand ?: get_the_title( $custom_order_id ) ); ?></strong>
				<span class="yp-proof-card__status"><?php echo esc_html( YeffoPrint_Custom_Order_Meta::get_status_label( $status ) ?: __( 'Submitted', 'yeffoprint-core' ) ); ?></span>
			</div>
			<p class="yp-proof-card__date"><?php echo esc_html( get_the_date( '', $custom_order_id ) ); ?></p>
			<?php if ( $proof_ids ) : ?>
				<ul class="yp-proof-card__files">
					<?php foreach ( $proof_ids as $proof_id ) :
						$file_id = (int) get_post_meta( $proof_id, YeffoPrint_Proof_Meta::FILE_ID, true );
						$url     = $file_id ? wp_get_attachment_url( $file_id ) : '';
						if ( ! $url ) {
							continue;
						}
						?>
						<li><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View proof', 'yeffoprint-core' ); ?> — <?php echo esc_html( get_the_date( '', $proof_id ) ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'No proof uploaded yet.', 'yeffoprint-core' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
