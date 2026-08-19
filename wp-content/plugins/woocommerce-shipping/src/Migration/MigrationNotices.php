<?php
/**
 * File containing the MigrationNotices class.
 *
 * @package Automattic\WCShipping\Migration
 */

namespace Automattic\WCShipping\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MigrationNotices
 */
class MigrationNotices {

	/**
	 * @var MigrationController $migration_controller
	 */
	private static $migration_controller;

	/**
	 * Constructor.
	 */
	public static function init( MigrationController $migration_controller ): void {
		self::$migration_controller = $migration_controller;
		add_action( 'admin_notices', array( __CLASS__, 'output_migration_notices' ) );
		add_action( 'admin_footer', array( __CLASS__, 'enqueue_migration_notice_dismiss_script' ) );
		add_action( 'wp_ajax_dismiss_admin_notice', array( __CLASS__, 'dismiss_migration_completed_notice' ) );
	}

	/**
	 * Output migration notices.
	 */
	public static function output_migration_notices(): void {
		// Only show notices if we have traces that WCS&T used to be activated on the website.
		if ( ! get_option( 'wc_connect_options', false ) ) {
			return;
		}

		if ( MigrationState::INSTALLATION_COMPLETED === MigrationState::get_state() && ! MigrationState::is_data_migration_required() && self::should_show_notice( 'installed' ) ) {
			self::installation_completed_notice();
		} elseif ( MigrationState::INSTALLATION_COMPLETED === MigrationState::get_state() && MigrationState::is_data_migration_required() ) {
			self::data_migration_required_notice();
		} elseif ( MigrationState::DATA_MIGRATION_STARTED === MigrationState::get_state() ) {
			self::data_migration_started_notice();
		} elseif ( MigrationState::DATA_MIGRATION_COMPLETED === MigrationState::get_state() && self::should_show_notice( 'migrated' ) ) {
			self::data_migration_completed_notice();
		}
	}

	/**
	 * Installation completed notice. No data migration is needed.
	 */
	public static function installation_completed_notice(): void {
		wp_admin_notice(
			sprintf(
				'<p>%s</p>',
				esc_html__( 'WooCommerce Shipping has successfully been installed and activated — enjoy the new WooCommerce Shipping experience.', 'woocommerce-shipping' )
			),
			array(
				'id'          => 'wcshipping_migration_installed_message',
				'type'        => 'success',
				'dismissible' => true,
			)
		);
	}

	/**
	 * Check if a notice should be shown.
	 *
	 * @param string $notice The notice to check.
	 * @return bool Wehther the notice should be shown.
	 */
	public static function should_show_notice( $notice ): bool {
		switch ( $notice ) {
			case 'installed':
				$option_name = 'wcshipping_installation_completed_shown';
				break;
			case 'migrated':
				$option_name = 'wcshipping_migration_completed_shown';
				break;
			default:
				return false;
		}

		return ! get_option( $option_name );
	}

	/**
	 * Data migration required notice.
	 */
	public static function data_migration_required_notice(): void {
		$migration_type = MigrationState::get_data_migration_required_type();
		switch ( $migration_type ) {
			case MigrationState::SETTINGS_TYPE:
				$migration_message = __( 'Next, transfer your WooCommerce Shipping & Tax settings to WooCommerce Shipping.', 'woocommerce-shipping' );
				break;
			case MigrationState::LABELS_TYPE:
				$migration_message = __( 'Next, transfer your WooCommerce Shipping & Tax shipping labels to WooCommerce Shipping.', 'woocommerce-shipping' );
				break;
			case MigrationState::ALL_TYPE:
				$migration_message = __( 'Next, transfer your WooCommerce Shipping & Tax settings and shipping labels to WooCommerce Shipping.', 'woocommerce-shipping' );
				break;
			default:
				return;
		}

		$message = sprintf(
			'<p>%s</p>
			<p>%s</p>
			<form method="post" action="">
				<p>
					<button type="submit" name="wcst_start_migration" class="action-button button button-primary">%s</button>
				</p>
			</form>',
			esc_html__( 'Congratulations! You have successfully installed and activated WooCommerce Shipping.', 'woocommerce-shipping' ),
			esc_html( $migration_message ),
			esc_html__( 'Click here to start the process', 'woocommerce-shipping' )
		);

		add_filter(
			'wp_kses_allowed_html',
			function ( $allowedtags ) {
				$allowedtags['form'] = array(
					'method' => true,
					'action' => true,
				);

				return $allowedtags;
			}
		);

		wp_admin_notice(
			$message,
			array(
				'type'           => 'warning',
				'paragraph_wrap' => false,
			)
		);
	}

	/**
	 * Data migration started notice.
	 */
	public static function data_migration_started_notice() {
		switch ( MigrationState::get_data_migration_required_type() ) {
			case MigrationState::SETTINGS_TYPE:
				$is_migrating_labels = false;
				$message             = __( 'WooCommerce Shipping & Tax settings are being migrated to WooCommerce Shipping.', 'woocommerce-shipping' );
				break;
			case MigrationState::LABELS_TYPE:
				$is_migrating_labels = true;
				$message             = __( 'WooCommerce Shipping & Tax labels are being migrated to WooCommerce Shipping.', 'woocommerce-shipping' );
				break;
			case MigrationState::ALL_TYPE:
				$is_migrating_labels = true;
				$message             = __( 'WooCommerce Shipping & Tax legacy settings and labels are being migrated to WooCommerce Shipping.', 'woocommerce-shipping' );
				break;
			default:
				return;
		}

		$admin_notice = sprintf(
			'<p>%s</p><p>%s</p>%s',
			$message,
			__( 'You may continue to use your website as usual. We will notify you once the migration process is complete.', 'woocommerce-shipping' ),
			$is_migrating_labels ? self::data_migration_progress() : ''
		);

		$allowed_tags = array_merge(
			wp_kses_allowed_html( 'post' ),
			array(
				'style'    => array(),
				'progress' => array(
					'id'    => true,
					'max'   => true,
					'value' => true,
				),
			)
		);

		echo wp_kses(
			wp_get_admin_notice(
				$admin_notice,
				array(
					'type'        => 'success',
					'dismissible' => true,
				)
			),
			$allowed_tags
		);
	}

	private static function data_migration_progress() {
		$progress = self::$migration_controller->get_labels_migration_progress();

		$progress_element = sprintf(
			'<label for="migration_progress_bar">%s</label>',
			__( 'Current progress:', 'woocommerce-shipping' )
		);
		$progress_bar     = sprintf(
			'<progress id="migration_progress_bar" max="100" value="%1$d">%1$d %%</progress> %1$d %%',
			(int) $progress
		);

		return sprintf(
			'<style>
				.migration_progress {
					display: flex;
					align-items: center;
					gap: 0.5rem;
				}

				.migration_progress progress {
					font-size: 1rem;
				}
			</style>
			<p class="migration_progress">%s %s</p>',
			$progress_element,
			$progress_bar
		);
	}

	/**
	 * Data migration completed notice.
	 */
	public static function data_migration_completed_notice() {
		wp_admin_notice(
			sprintf(
				'<p>%s</p>',
				esc_html__( 'Your shipping settings and label history have been successfully migrated — enjoy the new WooCommerce Shipping experience.', 'woocommerce-shipping' )
			),
			array(
				'id'          => 'wcshipping_migration_completed_message',
				'type'        => 'success',
				'dismissible' => true,
			)
		);
	}

	public static function dismiss_migration_completed_notice() {
		check_ajax_referer( 'wcshipping_migration_completed_dismiss_notice', 'nonce' );
		if ( isset( $_POST['notice'] ) && 'wcshipping_migration_installed_message' === $_POST['notice'] ) {
			update_option( 'wcshipping_installation_completed_shown', true );
		} elseif ( isset( $_POST['notice'] ) && 'wcshipping_migration_completed_message' === $_POST['notice'] ) {
			update_option( 'wcshipping_migration_completed_shown', true );
		}
	}

	public static function enqueue_migration_notice_dismiss_script() {
		$nonce = wp_create_nonce( 'wcshipping_migration_completed_dismiss_notice' );
		?>
		<script type="text/javascript">
		jQuery(document).ready(function($) {
			$('.notice.is-dismissible').on('click', '.notice-dismiss', function() {
				var notice = $(this).closest('.notice').attr('id');
				if (notice) {
					$.post(ajaxurl, {
						action: 'dismiss_admin_notice',
						notice: notice,
						nonce: '<?php echo esc_js( $nonce ); ?>'
					});
				}
			});
		});
		</script>
		<?php
	}
}
