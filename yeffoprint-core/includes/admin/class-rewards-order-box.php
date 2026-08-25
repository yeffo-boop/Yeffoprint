<?php
/**
 * "Rewards Points" meta box on the native WooCommerce order edit screen
 * (both classic post-based and HPOS) — direct request: staff wanted to
 * see, right on the order they're looking at, how many points it's
 * going to earn once paid, whether that's already happened, and a way
 * to award them by hand if it hasn't (e.g. an order manually walked to
 * a paid status through some path that never fired the usual
 * woocommerce_order_status_processing/_completed/woocommerce_payment_
 * complete hooks class-rewards.php's own finalize_order() normally
 * runs on).
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Rewards_Order_Box {

	/** Same bar as class-rewards-admin.php's manual-adjustment page — this is the same class of "manually change someone's points" action. */
	private const CAP = 'manage_options';

	private const NONCE_ACTION = 'yeffoprint_rewards_award_now';
	private const NONCE_NAME   = 'yeffoprint_rewards_award_now_nonce';

	public function __construct() {
		add_action( 'add_meta_boxes', [ $this, 'register_meta_box' ] );
		add_action( 'admin_post_yeffoprint_rewards_award_now', [ $this, 'handle_award_now' ] );
	}

	/** wc_get_page_screen_id() resolves to the right screen id for whichever order storage (classic post type vs. HPOS custom table) is actually active — one registration works for both. */
	public function register_meta_box(): void {
		$screen_id = wc_get_page_screen_id( 'shop_order' );
		if ( ! $screen_id ) {
			return;
		}

		add_meta_box(
			'yeffoprint-rewards-points',
			__( 'Rewards Points', 'yeffoprint-core' ),
			[ $this, 'render' ],
			$screen_id,
			'side',
			'default'
		);
	}

	/** @param \WP_Post|\WC_Order $post_or_order_object add_meta_box() hands this a WP_Post on the classic screen, a WC_Order on the HPOS one. */
	public function render( $post_or_order_object ): void {
		$order = ( $post_or_order_object instanceof \WP_Post ) ? wc_get_order( $post_or_order_object->ID ) : $post_or_order_object;
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$this->render_notice();

		$user_id = $order->get_customer_id();
		if ( ! $user_id ) {
			echo '<p>' . esc_html__( 'Guest order — not eligible for rewards points.', 'yeffoprint-core' ) . '</p>';
			return;
		}

		if ( $order->get_meta( YeffoPrint_Rewards::ORDER_PROCESSED_META ) ) {
			$this->render_processed( $order, $user_id );
		} else {
			$this->render_pending( $order );
		}
	}

	/** Points have already been awarded/deducted (class-rewards.php's finalize_order() already ran) — show what actually happened, not an estimate. */
	private function render_processed( \WC_Order $order, int $user_id ): void {
		$earned   = (int) $order->get_meta( YeffoPrint_Rewards::ORDER_POINTS_EARNED_META );
		$redeemed = (int) $order->get_meta( YeffoPrint_Rewards::ORDER_POINTS_REDEEMED_META );
		$balance  = YeffoPrint_Rewards::get_balance( $user_id );
		$customer = get_userdata( $user_id );
		?>
		<p><strong style="color:#0078A4;"><?php esc_html_e( 'Awarded', 'yeffoprint-core' ); ?></strong></p>

		<?php if ( $earned > 0 ) : ?>
			<p style="margin:4px 0;"><?php
				printf(
					/* translators: %s: points earned on this order */
					esc_html__( 'Earned: +%s points', 'yeffoprint-core' ),
					esc_html( number_format_i18n( $earned ) )
				);
			?></p>
		<?php endif; ?>

		<?php if ( $redeemed > 0 ) : ?>
			<p style="margin:4px 0;"><?php
				printf(
					/* translators: %s: points redeemed on this order */
					esc_html__( 'Redeemed: −%s points', 'yeffoprint-core' ),
					esc_html( number_format_i18n( $redeemed ) )
				);
			?></p>
		<?php endif; ?>

		<?php if ( ! $earned && ! $redeemed ) : ?>
			<p class="description"><?php esc_html_e( 'No points were earned or redeemed on this order.', 'yeffoprint-core' ); ?></p>
		<?php endif; ?>

		<p class="description">
			<?php
			printf(
				/* translators: %s: customer's current points balance, which may differ from what this order alone contributed */
				esc_html__( "Customer's current balance: %s points.", 'yeffoprint-core' ),
				esc_html( number_format_i18n( $balance ) )
			);
			?>
		</p>

		<?php if ( current_user_can( self::CAP ) && $customer ) : ?>
			<p>
				<a href="<?php echo esc_url( add_query_arg( 'lookup_user', rawurlencode( $customer->user_email ), admin_url( 'admin.php?page=yeffoprint-rewards' ) ) ); ?>">
					<?php esc_html_e( 'View full rewards history', 'yeffoprint-core' ); ?> &rarr;
				</a>
			</p>
		<?php endif; ?>
		<?php
	}

	/** Not processed yet — show what finalize_order() would compute right now (a live estimate, not a stored value) plus, for an admin, a button to run it by hand. */
	private function render_pending( \WC_Order $order ): void {
		$points = YeffoPrint_Rewards::calculate_points( $order );
		?>
		<p style="margin-bottom:4px;">
			<?php if ( $points['earned'] > 0 ) : ?>
				<?php
				printf(
					/* translators: %s: points this order will earn once processed */
					esc_html__( 'Will earn: %s points', 'yeffoprint-core' ),
					'<strong>+' . esc_html( number_format_i18n( $points['earned'] ) ) . '</strong>'
				);
				?>
				<br>
			<?php endif; ?>
			<?php if ( $points['redeemed'] > 0 ) : ?>
				<?php
				printf(
					/* translators: %s: points this order will redeem once processed */
					esc_html__( 'Will redeem: %s points', 'yeffoprint-core' ),
					'<strong>−' . esc_html( number_format_i18n( $points['redeemed'] ) ) . '</strong>'
				);
				?>
			<?php endif; ?>
			<?php if ( ! $points['earned'] && ! $points['redeemed'] ) : ?>
				<?php esc_html_e( 'No points will be earned or redeemed on this order.', 'yeffoprint-core' ); ?>
			<?php endif; ?>
		</p>

		<p class="description">
			<?php esc_html_e( 'Not yet awarded — happens automatically once the order is marked Processing or Completed.', 'yeffoprint-core' ); ?>
		</p>

		<?php if ( current_user_can( self::CAP ) && ( $points['earned'] > 0 || $points['redeemed'] > 0 ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="yeffoprint_rewards_award_now" />
				<input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>" />
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<?php submit_button( __( 'Award now', 'yeffoprint-core' ), 'secondary', 'submit', false ); ?>
			</form>
			<p class="description">
				<?php esc_html_e( "Only use this if the order has genuinely been paid — it can't be automatically reversed.", 'yeffoprint-core' ); ?>
			</p>
		<?php endif; ?>
		<?php
	}

	public function handle_award_now(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'yeffoprint-core' ) );
		}
		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		$redirect = $order ? $order->get_edit_order_url() : admin_url( 'edit.php?post_type=shop_order' );

		if ( ! $order ) {
			wp_safe_redirect( add_query_arg( 'yp_rewards_box_error', rawurlencode( __( 'Order not found.', 'yeffoprint-core' ) ), $redirect ) );
			exit;
		}

		if ( $order->get_meta( YeffoPrint_Rewards::ORDER_PROCESSED_META ) ) {
			wp_safe_redirect( add_query_arg( 'yp_rewards_box_error', rawurlencode( __( 'Points were already awarded for this order.', 'yeffoprint-core' ) ), $redirect ) );
			exit;
		}

		// finalize_order() is a hooked instance method (registered via
		// [$this,...] in YeffoPrint_Rewards's own constructor, same as
		// every other hook that class), not a static utility — a fresh
		// instance is enough to call it directly. Its own
		// ORDER_PROCESSED_META guard (just re-checked above too, so the
		// error message above is accurate) makes this safe even if
		// somehow called twice.
		( new YeffoPrint_Rewards() )->finalize_order( $order_id );

		wp_safe_redirect( add_query_arg( 'yp_rewards_box_success', 1, $redirect ) );
		exit;
	}

	private function render_notice(): void {
		if ( isset( $_GET['yp_rewards_box_error'] ) ) {
			printf(
				'<div class="notice notice-error inline"><p>%s</p></div>',
				esc_html( wp_unslash( $_GET['yp_rewards_box_error'] ) )
			);
		}

		if ( isset( $_GET['yp_rewards_box_success'] ) ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Points awarded.', 'yeffoprint-core' ) . '</p></div>';
		}
	}
}
