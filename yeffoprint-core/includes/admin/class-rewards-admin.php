<?php
/**
 * Manual rewards-points adjustments — Dashboard → YeffoPrint → Rewards.
 * Direct request: migrating a customer's balance over from the old
 * site, or making a customer-service situation right, neither of which
 * has a real order behind it for the normal earn/redeem flow
 * (class-rewards.php) to hook into.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Rewards_Admin {

	private const CAP           = 'manage_options';
	private const NONCE_ACTION  = 'yeffoprint_rewards_adjust';
	private const NONCE_NAME    = 'yeffoprint_rewards_adjust_nonce';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_post_yeffoprint_rewards_adjust', [ $this, 'handle_adjust' ] );
	}

	public function register_menu(): void {
		add_submenu_page(
			'yeffoprint',
			__( 'Rewards', 'yeffoprint-core' ),
			__( 'Rewards', 'yeffoprint-core' ),
			self::CAP,
			'yeffoprint-rewards',
			[ $this, 'render_page' ]
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'yeffoprint-core' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Rewards', 'yeffoprint-core' ); ?></h1>

			<?php $this->render_notice(); ?>

			<h2><?php esc_html_e( 'Award or adjust points', 'yeffoprint-core' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'For migrating a balance from the old site, or making a customer-service situation right — anything with no real order behind it. A negative amount deducts points; the balance never goes below zero.', 'yeffoprint-core' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="yeffoprint_rewards_adjust" />
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="yp-rewards-user"><?php esc_html_e( 'Customer', 'yeffoprint-core' ); ?></label></th>
						<td>
							<input type="text" id="yp-rewards-user" name="user" class="regular-text" required placeholder="<?php esc_attr_e( 'Email or username', 'yeffoprint-core' ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="yp-rewards-points"><?php esc_html_e( 'Points', 'yeffoprint-core' ); ?></label></th>
						<td>
							<input type="number" id="yp-rewards-points" name="points" step="1" required style="width:120px;" />
							<p class="description"><?php esc_html_e( 'Positive to award, negative to deduct.', 'yeffoprint-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="yp-rewards-reason"><?php esc_html_e( 'Reason', 'yeffoprint-core' ); ?></label></th>
						<td>
							<input type="text" id="yp-rewards-reason" name="reason" class="regular-text" required placeholder="<?php esc_attr_e( 'e.g. Migrated balance from old site, Customer service credit for order #1842', 'yeffoprint-core' ); ?>" />
							<p class="description"><?php esc_html_e( 'Shown in the log below — be specific, this is the only record of why.', 'yeffoprint-core' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Apply', 'yeffoprint-core' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Recent adjustments', 'yeffoprint-core' ); ?></h2>
			<?php $this->render_history(); ?>
		</div>
		<?php
	}

	public function handle_adjust(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'yeffoprint-core' ) );
		}
		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		$identifier = sanitize_text_field( wp_unslash( $_POST['user'] ?? '' ) );
		$points     = isset( $_POST['points'] ) ? (int) $_POST['points'] : 0;
		$reason     = sanitize_text_field( wp_unslash( $_POST['reason'] ?? '' ) );

		$redirect = admin_url( 'admin.php?page=yeffoprint-rewards' );
		$user     = is_email( $identifier ) ? get_user_by( 'email', $identifier ) : get_user_by( 'login', $identifier );

		if ( ! $user ) {
			wp_safe_redirect( add_query_arg( 'yp_rewards_error', rawurlencode( __( 'No customer found with that email or username.', 'yeffoprint-core' ) ), $redirect ) );
			exit;
		}

		if ( ! $points || '' === $reason ) {
			wp_safe_redirect( add_query_arg( 'yp_rewards_error', rawurlencode( __( 'Please enter a non-zero point amount and a reason.', 'yeffoprint-core' ) ), $redirect ) );
			exit;
		}

		$new_balance = YeffoPrint_Rewards::adjust_balance( $user->ID, $points, $reason, get_current_user_id() );

		wp_safe_redirect( add_query_arg( [
			'yp_rewards_success' => 1,
			'yp_rewards_user'    => rawurlencode( $user->user_email ),
			'yp_rewards_balance' => $new_balance,
		], $redirect ) );
		exit;
	}

	private function render_notice(): void {
		if ( isset( $_GET['yp_rewards_error'] ) ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html( wp_unslash( $_GET['yp_rewards_error'] ) )
			);
		}

		if ( isset( $_GET['yp_rewards_success'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( sprintf(
					/* translators: 1: customer email, 2: new points balance */
					__( 'Updated %1$s — new balance: %2$s points.', 'yeffoprint-core' ),
					isset( $_GET['yp_rewards_user'] ) ? sanitize_email( wp_unslash( $_GET['yp_rewards_user'] ) ) : '',
					isset( $_GET['yp_rewards_balance'] ) ? number_format_i18n( (int) $_GET['yp_rewards_balance'] ) : ''
				) )
			);
		}
	}

	private function render_history(): void {
		$entries = YeffoPrint_Rewards::get_recent_adjustments( 50 );

		if ( ! $entries ) {
			echo '<p class="description">' . esc_html__( 'No manual adjustments yet.', 'yeffoprint-core' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'yeffoprint-core' ); ?></th>
					<th><?php esc_html_e( 'Customer', 'yeffoprint-core' ); ?></th>
					<th><?php esc_html_e( 'Points', 'yeffoprint-core' ); ?></th>
					<th><?php esc_html_e( 'Reason', 'yeffoprint-core' ); ?></th>
					<th><?php esc_html_e( 'By', 'yeffoprint-core' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entries as $entry ) :
					$user  = get_userdata( (int) ( $entry['user_id'] ?? 0 ) );
					$admin = get_userdata( (int) ( $entry['admin_id'] ?? 0 ) );
					$delta = (int) ( $entry['delta'] ?? 0 );
					?>
					<tr>
						<td><?php echo esc_html( wp_date( 'Y-m-d H:i', strtotime( (string) ( $entry['date'] ?? '' ) ) ?: null ) ); ?></td>
						<td><?php echo esc_html( $user ? $user->user_email : __( '(deleted user)', 'yeffoprint-core' ) ); ?></td>
						<td style="color:<?php echo $delta >= 0 ? '#00a32a' : '#d63638'; ?>; font-weight:600;">
							<?php echo esc_html( ( $delta >= 0 ? '+' : '' ) . number_format_i18n( $delta ) ); ?>
						</td>
						<td><?php echo esc_html( (string) ( $entry['reason'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( $admin ? $admin->display_name : '—' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
