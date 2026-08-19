<?php
namespace DeployerForGit;

use DeployerForGit\Subpages\DashboardPage\Dashboard;
use DeployerForGit\Subpages\InstallThemePage\InstallTheme;
use DeployerForGit\Subpages\InstallPluginPage\InstallPlugin;
use DeployerForGit\Subpages\LogsPage\Logs;
use DeployerForGit\Subpages\MiscellaneousPage\Miscellaneous;
use DeployerForGit\Subpages\EditPackagePage\EditPackage;

/**
 * Class Admin
 *
 * @package DeployerForGit
 */
class Admin {
	/**
	 * Admin constructor.
	 */
	public function __construct() {
		$this->init_dashboard_page();
		$this->init_install_theme_page();
		$this->init_install_plugin_page();
		$this->init_logs_page();
		$this->init_miscellaneous_page();
		$this->init_edit_package_page();

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( is_multisite() ? 'network_admin_notices' : 'admin_notices', array( $this, 'display_alert_notification_box' ) );
		add_filter( 'admin_footer_text', array( $this, 'admin_footer' ), 1, 2 );
		add_action( 'wp_ajax_dfg_unlink_package', array( $this, 'handle_unlink_package' ) );

		// Add plugin action links.
		add_filter( 'plugin_action_links', array( $this, 'add_plugin_action_links' ), 10, 2 );
	}

	/**
	 * Initialize dashboard page
	 */
	private function init_dashboard_page() {
		new Dashboard();
	}

	/**
	 * Initialize install theme page
	 */
	public function init_install_theme_page() {
		new InstallTheme();
	}

	/**
	 * Initialize install plugin page
	 */
	private function init_install_plugin_page() {
		new InstallPlugin();
	}

	/**
	 * Initialize miscellaneous page
	 */
	private function init_miscellaneous_page() {
		new Miscellaneous();
	}

	/**
	 * Initialize logs page
	 */
	private function init_logs_page() {
		new Logs();
	}

	/**
	 * Initialize edit package page
	 */
	private function init_edit_package_page() {
		new EditPackage();
	}

	/**
	 * Display alert notification box
	 */
	public function display_alert_notification_box() {
		$data_manager               = new DataManager();
		$alert_notification_setting = $data_manager->get_alert_notification_setting();

		if ( $alert_notification_setting ) {
			$menu_slug     = Helper::menu_slug();
			$dashboard_url = is_multisite() ? network_admin_url( "admin.php?page={$menu_slug}" ) : menu_page_url( $menu_slug, false );

			// translators: %s = dashboard url.
			$html = wp_kses_post( __( '<strong>Warning:</strong> Please do not make any changes to the theme or plugin code directly because some themes or plugins are connected with Git.<br>Click <a href="%s">here</a> to learn more.', 'deployer-for-git' ) );
			$html = sprintf( $html, esc_url( $dashboard_url ) );

			$html = apply_filters( 'dfg_alert_notification_message', $html );

			$html = "<div class='dfg_alert_box' >{$html}</div>";

			echo wp_kses(
				$html,
				array(
					'a'      => array(
						'href'  => array(),
						'title' => array(),
					),
					'br'     => array(),
					'strong' => array(),
					'div'    => array(
						'class' => array(),
					),
				)
			);
		}
	}

	/**
	 * Enqueue scripts
	 */
	public function enqueue_scripts() {
		$plugin_data = get_plugin_data( DFG_FILE );
		$version     = $plugin_data['Version'];

		wp_enqueue_style( 'dfg-styles', DFG_URL . 'assets/css/deployer-for-git-main.css', array(), $version );

		wp_enqueue_script( 'dfg-script', DFG_URL . 'assets/js/deployer-for-git-main.js', array(), $version, true );

		wp_localize_script(
			'dfg-script',
			'dfg',
			array(
				'copy_url_label'         => __( 'Copy URL', 'deployer-for-git' ),
				'copied_url_label'       => __( 'Copied!', 'deployer-for-git' ),
				'copy_failed_label'      => __( 'Could not copy the URL.', 'deployer-for-git' ),
				'show_webhook_label'     => __( 'Show webhook URL', 'deployer-for-git' ),
				'hide_webhook_label'     => __( 'Hide webhook URL', 'deployer-for-git' ),
				'deploying_now_label'    => __( 'Deploying now...', 'deployer-for-git' ),
				'error_label'            => __( 'Something went wrong', 'deployer-for-git' ),
				'deploy_completed_label' => __( 'Deployed!', 'deployer-for-git' ),
				'deploy_theme_label'     => __( 'Deploy Theme', 'deployer-for-git' ),
				'deploy_plugin_label'    => __( 'Deploy Plugin', 'deployer-for-git' ),
				'unlinking_label'        => __( 'Unlinking package...', 'deployer-for-git' ),
				'ajax_url'               => admin_url( 'admin-ajax.php' ),
				'unlink_nonce'           => wp_create_nonce( DFG_SLUG . '_unlink_nonce' ),
				'unlink_confirm_label'   => __( 'Are you sure you want to unlink this package from Deployer for Git? The plugin/theme files will remain installed.', 'deployer-for-git' ),
			)
		);
	}

	/**
	 * Handle AJAX request to unlink a package from Deployer for Git.
	 */
	public function handle_unlink_package() {
		check_ajax_referer( DFG_SLUG . '_unlink_nonce', 'nonce' );

		$capability = is_multisite() ? 'manage_network_options' : 'manage_options';
		if ( ! current_user_can( $capability ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'deployer-for-git' ) ) );
		}

		$slug = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
		$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';

		if ( empty( $slug ) || ! in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'deployer-for-git' ) ) );
		}

		$data_manager = new DataManager();
		$result       = $data_manager->remove_package_details( $slug, $type );

		if ( $result ) {
			wp_send_json_success( array( 'message' => __( 'Package unlinked successfully.', 'deployer-for-git' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to unlink package.', 'deployer-for-git' ) ) );
		}
	}

	/**
	 * When user is on a plugin related admin page, display footer text.
	 *
	 * @param string $text Footer text.
	 *
	 * @return string
	 */
	public function admin_footer( $text ) {

		global $current_screen;

		if ( ! empty( $current_screen->id ) && strpos( $current_screen->id, 'deployer-for-git' ) !== false ) {
			$documentation_url = 'https://deployer-for-git.com/knowledge-base/';
			$review_url        = 'https://wordpress.org/support/plugin/deployer-for-git/reviews/?filter=5#new-post';
			$text              = sprintf(
				wp_kses( /* translators: $1$s - documentation link; $2$s - Deployer for Git plugin name; $3$s and $4$s - WP.org review link. */
					__( '<a href="%1$s" target="_blank" rel="noopener noreferrer">Documentation</a> &middot; Please rate %2$s <a href="%3$s" target="_blank" rel="noopener noreferrer">&#9733;&#9733;&#9733;&#9733;&#9733;</a> on <a href="%4$s" target="_blank" rel="noopener">WordPress.org</a> to support and promote our solution. Thank you very much!', 'deployer-for-git' ),
					array(
						'a' => array(
							'href'   => array(),
							'target' => array(),
							'rel'    => array(),
						),
					)
				),
				esc_url( $documentation_url ),
				'<strong>Deployer for Git</strong>',
				esc_url( $review_url ),
				esc_url( $review_url )
			);
		}

		return $text;
	}

	/**
	 * Add plugin action links.
	 *
	 * @param array  $actions An array of plugin action links.
	 * @param string $plugin_file Path to the plugin file.
	 *
	 * @return array
	 */
	public function add_plugin_action_links( $actions, $plugin_file ) {

		$data_manager = new DataManager();
		$plugin_list  = $data_manager->get_plugin_list();

		foreach ( $plugin_list as $plugin_data_item ) {
			$target_dir = $plugin_data_item['slug'];

			// Check if this plugin belongs to that directory.
			if ( strpos( $plugin_file, $target_dir . '/' ) === 0 ) {
					$actions['dfg_package_update_link'] = sprintf(
						'<a href="%s">%s</a> <span class="dashicons dashicons-update-alt"></span>',
						esc_url( Helper::dashboard_url() ),
						__( 'Update with Git', 'deployer-for-git' )
					);
			}
		}

		return $actions;
	}
}
