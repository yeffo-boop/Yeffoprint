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

	/** Settings API group/page for the points-and-referral rates below — moved here (direct request) from the general Settings page. */
	private const SETTINGS_GROUP = 'yeffoprint_rewards_settings';
	private const SETTINGS_PAGE  = 'yeffoprint-rewards-settings';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_post_yeffoprint_rewards_adjust', [ $this, 'handle_adjust' ] );
	}

	public function register_menu(): void {
		$hook = (string) add_submenu_page(
			'yeffoprint',
			__( 'Rewards', 'yeffoprint-core' ),
			__( 'Rewards', 'yeffoprint-core' ),
			self::CAP,
			'yeffoprint-rewards',
			[ $this, 'render_page' ]
		);

		// Not a CPT screen — see class-admin-shell.php's own docblock on register_page_hook().
		YeffoPrint_Admin_Shell::register_page_hook( $hook );
	}

	public function register_settings(): void {
		register_setting( self::SETTINGS_GROUP, YeffoPrint_Admin_Menu::REWARDS_POINTS_PER_DOLLAR_OPTION, [
			'type'              => 'number',
			'sanitize_callback' => [ $this, 'sanitize_positive_number' ],
			'default'           => YeffoPrint_Admin_Menu::REWARDS_POINTS_PER_DOLLAR_DEFAULT,
		] );

		register_setting( self::SETTINGS_GROUP, YeffoPrint_Admin_Menu::REWARDS_DOLLARS_PER_POINT_OPTION, [
			'type'              => 'number',
			'sanitize_callback' => [ $this, 'sanitize_positive_number' ],
			'default'           => YeffoPrint_Admin_Menu::REWARDS_DOLLARS_PER_POINT_DEFAULT,
		] );

		register_setting( self::SETTINGS_GROUP, YeffoPrint_Admin_Menu::REFERRAL_POINTS_OPTION, [
			'type'              => 'number',
			'sanitize_callback' => [ $this, 'sanitize_positive_number' ],
			'default'           => YeffoPrint_Admin_Menu::REFERRAL_POINTS_DEFAULT,
		] );

		add_settings_section( 'yeffoprint_rewards_rates', '', '__return_false', self::SETTINGS_PAGE );

		add_settings_field(
			YeffoPrint_Admin_Menu::REWARDS_POINTS_PER_DOLLAR_OPTION,
			__( 'Points earned per $1 spent', 'yeffoprint-core' ),
			[ $this, 'render_points_per_dollar_field' ],
			self::SETTINGS_PAGE,
			'yeffoprint_rewards_rates'
		);

		add_settings_field(
			YeffoPrint_Admin_Menu::REWARDS_DOLLARS_PER_POINT_OPTION,
			__( 'Redemption value per point', 'yeffoprint-core' ),
			[ $this, 'render_dollars_per_point_field' ],
			self::SETTINGS_PAGE,
			'yeffoprint_rewards_rates'
		);

		add_settings_field(
			YeffoPrint_Admin_Menu::REFERRAL_POINTS_OPTION,
			__( 'Points per successful referral', 'yeffoprint-core' ),
			[ $this, 'render_referral_points_field' ],
			self::SETTINGS_PAGE,
			'yeffoprint_rewards_rates'
		);
	}

	/**
	 * All three rates are positive numbers, not free text — a negative
	 * or non-numeric value would let a customer earn negative points or
	 * redeem for an unbounded discount.
	 */
	public function sanitize_positive_number( $value ): float {
		return max( 0, (float) $value );
	}

	public function render_points_per_dollar_field(): void {
		$value = get_option( YeffoPrint_Admin_Menu::REWARDS_POINTS_PER_DOLLAR_OPTION, YeffoPrint_Admin_Menu::REWARDS_POINTS_PER_DOLLAR_DEFAULT );
		?>
		<input
			type="number"
			step="0.01"
			min="0"
			name="<?php echo esc_attr( YeffoPrint_Admin_Menu::REWARDS_POINTS_PER_DOLLAR_OPTION ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
		/>
		<p class="description"><?php esc_html_e( 'How many points a customer earns for every $1 of merchandise (shipping and tax excluded), awarded once an order is paid.', 'yeffoprint-core' ); ?></p>
		<?php
	}

	public function render_dollars_per_point_field(): void {
		$value = get_option( YeffoPrint_Admin_Menu::REWARDS_DOLLARS_PER_POINT_OPTION, YeffoPrint_Admin_Menu::REWARDS_DOLLARS_PER_POINT_DEFAULT );
		?>
		<input
			type="number"
			step="0.001"
			min="0"
			name="<?php echo esc_attr( YeffoPrint_Admin_Menu::REWARDS_DOLLARS_PER_POINT_OPTION ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
		/>
		<p class="description"><?php esc_html_e( 'Dollar discount each point is worth when a customer redeems their balance. Default 0.01 means 100 points = $1.', 'yeffoprint-core' ); ?></p>
		<?php
	}

	public function render_referral_points_field(): void {
		$value = get_option( YeffoPrint_Admin_Menu::REFERRAL_POINTS_OPTION, YeffoPrint_Admin_Menu::REFERRAL_POINTS_DEFAULT );
		?>
		<input
			type="number"
			step="1"
			min="0"
			name="<?php echo esc_attr( YeffoPrint_Admin_Menu::REFERRAL_POINTS_OPTION ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
		/>
		<p class="description"><?php esc_html_e( 'Awarded to the referring customer once the person they referred places their first paid order. 0 turns referral rewards off entirely.', 'yeffoprint-core' ); ?></p>
		<?php
	}

	public function render_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'yeffoprint-core' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Rewards', 'yeffoprint-core' ); ?></h1>

			<?php $this->render_notice(); ?>

			<h2><?php esc_html_e( 'Points & referral rates', 'yeffoprint-core' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
				<?php
				settings_fields( self::SETTINGS_GROUP );
				do_settings_sections( self::SETTINGS_PAGE );
				submit_button( __( 'Save Rates', 'yeffoprint-core' ) );
				?>
			</form>

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
					$user     = get_userdata( (int) ( $entry['user_id'] ?? 0 ) );
					$admin_id = (int) ( $entry['admin_id'] ?? 0 );
					$admin    = $admin_id ? get_userdata( $admin_id ) : null;
					$delta    = (int) ( $entry['delta'] ?? 0 );
					?>
					<tr>
						<td><?php echo esc_html( wp_date( 'Y-m-d H:i', strtotime( (string) ( $entry['date'] ?? '' ) ) ?: null ) ); ?></td>
						<td><?php echo esc_html( $user ? $user->user_email : __( '(deleted user)', 'yeffoprint-core' ) ); ?></td>
						<td style="color:<?php echo $delta >= 0 ? '#0078A4' : '#C2007A'; /* cyan-deep / magenta-deep — same positive/attention split as admin-shell.css's own notice colors */ ?>; font-weight:600;">
							<?php echo esc_html( ( $delta >= 0 ? '+' : '' ) . number_format_i18n( $delta ) ); ?>
						</td>
						<td><?php echo esc_html( (string) ( $entry['reason'] ?? '' ) ); ?></td>
						<td>
							<?php
							// admin_id 0 means a system-triggered adjustment (e.g.
							// class-referrals.php's referral bonus) — no staff
							// member behind it, distinct from a real user record
							// that's since been deleted.
							if ( $admin ) {
								echo esc_html( $admin->display_name );
							} elseif ( 0 === $admin_id ) {
								esc_html_e( 'System', 'yeffoprint-core' );
							} else {
								echo '—';
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
