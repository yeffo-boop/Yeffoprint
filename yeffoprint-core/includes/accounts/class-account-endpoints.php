<?php
/**
 * The branded My Account dashboard's non-native tabs (PROJECT_SPEC
 * §16: "Orders, Saved Designs, Rewards, Proofs, Addresses").
 *
 * Orders and Addresses are native WooCommerce — presentation-only
 * restyling lives in the theme. Rewards is real as of this pass — see
 * includes/rewards/class-rewards.php for the actual points engine;
 * this tab is just the customer-facing balance/history/redeem view.
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

	private const REWARDS_NONCE_ACTION = 'yeffoprint_rewards_redeem';
	private const REWARDS_NONCE_NAME   = 'yp_rewards_nonce';

	public function __construct() {
		add_action( 'init', [ $this, 'register_endpoints' ] );
		add_filter( 'query_vars', [ $this, 'register_query_vars' ] );
		add_filter( 'woocommerce_account_menu_items', [ $this, 'reorder_menu_items' ] );
		add_action( 'template_redirect', [ $this, 'maybe_handle_remove_saved_design' ] );
		add_action( 'template_redirect', [ $this, 'maybe_handle_rewards_redeem' ] );

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

	/**
	 * Same plain POST-and-redirect pattern as
	 * maybe_handle_remove_saved_design() above — no REST/JS needed for
	 * a once-in-a-while account-page action. Two values only: apply the
	 * customer's full current balance to their next cart, or cancel an
	 * already-pending one. Actually spending it happens later, when
	 * that cart is checked out (YeffoPrint_Rewards::apply_pending_
	 * redemption(), then finalize_order() at payment) — this just
	 * records the customer's choice.
	 */
	public function maybe_handle_rewards_redeem(): void {
		if ( ! isset( $_POST['yp_rewards_action'], $_POST[ self::REWARDS_NONCE_NAME ] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( wp_unslash( $_POST[ self::REWARDS_NONCE_NAME ] ), self::REWARDS_NONCE_ACTION ) ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id = get_current_user_id();
		$action  = sanitize_text_field( wp_unslash( $_POST['yp_rewards_action'] ) );

		if ( 'apply' === $action ) {
			update_user_meta( $user_id, YeffoPrint_Rewards::PENDING_REDEEM_META, YeffoPrint_Rewards::get_balance( $user_id ) );
		} elseif ( 'cancel' === $action ) {
			update_user_meta( $user_id, YeffoPrint_Rewards::PENDING_REDEEM_META, 0 );
		}

		wp_safe_redirect( function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'rewards' ) : home_url( '/my-account/rewards/' ) );
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
		$user_id = get_current_user_id();
		$balance = YeffoPrint_Rewards::get_balance( $user_id );
		$pending = min( YeffoPrint_Rewards::get_pending_redeem( $user_id ), $balance );
		?>
		<div class="yp-rewards-balance">
			<span class="yp-rewards-balance__points"><?php echo esc_html( number_format_i18n( $balance ) ); ?></span>
			<span class="yp-rewards-balance__label"><?php esc_html_e( 'points', 'yeffoprint-core' ); ?></span>
			<span class="yp-rewards-balance__value">
				<?php
				echo wp_kses_post( wc_price( YeffoPrint_Rewards::points_to_dollars( $balance ) ) );
				esc_html_e( ' available to spend', 'yeffoprint-core' );
				?>
			</span>
		</div>

		<p class="description">
			<?php
			printf(
				/* translators: %s: points earned per dollar spent, formatted */
				esc_html__( 'Earn %s point(s) for every $1 you spend — added automatically once an order is paid.', 'yeffoprint-core' ),
				esc_html( YeffoPrint_Rewards::points_per_dollar_label() )
			);
			?>
		</p>

		<?php if ( $balance > 0 ) : ?>
			<form method="post" class="yp-rewards-redeem-form">
				<?php wp_nonce_field( self::REWARDS_NONCE_ACTION, self::REWARDS_NONCE_NAME ); ?>
				<?php if ( $pending > 0 ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: points pending redemption, 2: dollar value */
							esc_html__( 'Currently set to apply %1$s points (%2$s) to your next order.', 'yeffoprint-core' ),
							'<strong>' . esc_html( number_format_i18n( $pending ) ) . '</strong>',
							wp_kses_post( wc_price( YeffoPrint_Rewards::points_to_dollars( $pending ) ) )
						);
						?>
					</p>
					<button type="submit" name="yp_rewards_action" value="cancel" class="button-link"><?php esc_html_e( 'Stop applying rewards', 'yeffoprint-core' ); ?></button>
				<?php else : ?>
					<button type="submit" name="yp_rewards_action" value="apply" class="wp-block-button__link"><?php esc_html_e( 'Apply my balance to my next order', 'yeffoprint-core' ); ?></button>
				<?php endif; ?>
			</form>
		<?php endif; ?>

		<?php $this->render_referrals( $user_id ); ?>
		<?php $this->render_rewards_history( $user_id ); ?>
		<?php
	}

	private function render_referrals( int $user_id ): void {
		$link     = YeffoPrint_Referrals::referral_link( $user_id );
		$joined   = YeffoPrint_Referrals::count_referred( $user_id );
		$rewarded = YeffoPrint_Referrals::count_rewarded_referrals( $user_id );
		$points   = YeffoPrint_Referrals::points_per_referral();

		if ( $points <= 0 ) {
			return; // Admin has turned referral rewards off (Dashboard → YeffoPrint → Settings) — nothing to show or share.
		}
		?>
		<div class="yp-referrals">
			<h3><?php esc_html_e( 'Refer a Friend', 'yeffoprint-core' ); ?></h3>
			<p class="description">
				<?php
				printf(
					/* translators: %s: points awarded per successful referral */
					esc_html__( 'Share your link — once someone you refer places their first paid order, you earn %s points.', 'yeffoprint-core' ),
					'<strong>' . esc_html( number_format_i18n( $points ) ) . '</strong>'
				);
				?>
			</p>
			<div class="yp-referrals__link-row">
				<input type="text" readonly class="yp-referrals__link" value="<?php echo esc_url( $link ); ?>" onclick="this.select();" />
				<button type="button" class="button" data-yp-copy-referral-link><?php esc_html_e( 'Copy Link', 'yeffoprint-core' ); ?></button>
			</div>
			<?php if ( $joined > 0 ) : ?>
				<p class="yp-referrals__stats">
					<?php
					printf(
						/* translators: %s: number of people who signed up using this link */
						esc_html( _n( '%s person has joined using your link.', '%s people have joined using your link.', $joined, 'yeffoprint-core' ) ),
						esc_html( number_format_i18n( $joined ) )
					);
					?>
					<?php if ( $rewarded > 0 ) : ?>
						<?php
						printf(
							/* translators: %s: how many of those referrals have gone on to place a paid order */
							esc_html( _n( '%s has placed an order so far.', '%s have placed an order so far.', $rewarded, 'yeffoprint-core' ) ),
							esc_html( number_format_i18n( $rewarded ) )
						);
						?>
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_rewards_history( int $user_id ): void {
		$orders = wc_get_orders( [
			'customer_id' => $user_id,
			'limit'       => 10,
			'orderby'     => 'date',
			'order'       => 'DESC',
			'meta_query'  => [
				[
					'key'     => YeffoPrint_Rewards::ORDER_POINTS_EARNED_META,
					'compare' => 'EXISTS',
				],
			],
		] );

		if ( ! $orders ) {
			return;
		}

		echo '<h3>' . esc_html__( 'Recent activity', 'yeffoprint-core' ) . '</h3>';
		echo '<ul class="yp-rewards-history">';

		foreach ( $orders as $order ) {
			$earned   = (int) $order->get_meta( YeffoPrint_Rewards::ORDER_POINTS_EARNED_META );
			$redeemed = (int) $order->get_meta( YeffoPrint_Rewards::ORDER_POINTS_REDEEMED_META );

			if ( ! $earned && ! $redeemed ) {
				continue; // A guest order, or one with nothing to show either direction.
			}
			?>
			<li class="yp-rewards-history__row">
				<span class="yp-rewards-history__order">
					<?php
					printf(
						/* translators: %s: order number */
						esc_html__( 'Order #%s', 'yeffoprint-core' ),
						esc_html( $order->get_order_number() )
					);
					?>
					<span class="yp-rewards-history__date"><?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?></span>
				</span>
				<span class="yp-rewards-history__amounts">
					<?php if ( $earned > 0 ) : ?>
						<span class="yp-rewards-history__earned">+<?php echo esc_html( number_format_i18n( $earned ) ); ?></span>
					<?php endif; ?>
					<?php if ( $redeemed > 0 ) : ?>
						<span class="yp-rewards-history__redeemed">&minus;<?php echo esc_html( number_format_i18n( $redeemed ) ); ?></span>
					<?php endif; ?>
				</span>
			</li>
			<?php
		}

		echo '</ul>';
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
		// A Custom Order has no premade artwork to show a real thumbnail
		// of — this generic vial glyph fills that slot honestly (matches
		// the same "coming soon"-style placeholder treatment used for
		// Customer Inspiration tiles) until real AI-generated preview
		// images are wired up as a follow-up.
		$reorder_url = add_query_arg( 'reorder', $custom_order_id, home_url( '/custom-design/' ) );
		?>
		<div class="yp-proof-card">
			<div class="yp-proof-card__thumb" aria-hidden="true">
				<svg width="26" height="26" viewBox="0 0 20 20" fill="none" aria-hidden="true" focusable="false">
					<rect x="6" y="2" width="8" height="16" rx="2" stroke="currentColor" stroke-width="1.5" />
					<line x1="8" y1="6.5" x2="12" y2="6.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
					<line x1="8" y1="9.5" x2="12" y2="9.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
				</svg>
			</div>
			<div class="yp-proof-card__body">
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
				<?php if ( 'awaiting_approval' === $status ) : ?>
					<p class="yp-reorder-link"><a href="<?php echo esc_url( add_query_arg( 'custom_order', $custom_order_id, home_url( '/proof-approval/' ) ) ); ?>"><?php esc_html_e( 'Review & approve this proof', 'yeffoprint-core' ); ?></a></p>
				<?php endif; ?>
				<p class="yp-reorder-link"><a href="<?php echo esc_url( $reorder_url ); ?>"><?php esc_html_e( 'Reorder this custom design', 'yeffoprint-core' ); ?></a></p>
			</div>
		</div>
		<?php
	}
}
