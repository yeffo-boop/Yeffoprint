<?php
namespace DeployerForGit\Subpages\EditPackagePage;

use DeployerForGit\DataManager;
use DeployerForGit\Logger;
use DeployerForGit\Helper;

/**
 * Class EditPackage
 *
 * @package DeployerForGit\Subpages\EditPackagePage
 */
class EditPackage {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( is_multisite() ? 'network_admin_menu' : 'admin_menu', array( $this, 'init_menu' ) );
	}

	/**
	 * Initialize menu (hidden page — null parent)
	 *
	 * @return void
	 */
	public function init_menu() {
		$menu_slug  = Helper::menu_slug();
		$capability = is_multisite() ? 'manage_network_options' : 'manage_options';

		add_submenu_page(
			null,
			esc_attr__( 'Edit Package', 'deployer-for-git' ),
			'',
			$capability,
			"{$menu_slug}-edit-package",
			array( $this, 'init_page' )
		);
	}

	/**
	 * Initialize page — handle form submission or display form
	 *
	 * @return void
	 */
	public function init_page() {
		$data_manager = new DataManager();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$package_slug = isset( $_GET['package_slug'] ) ? sanitize_text_field( wp_unslash( $_GET['package_slug'] ) ) : '';
		$package_type = isset( $_GET['package_type'] ) ? sanitize_text_field( wp_unslash( $_GET['package_type'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$package_type = ( $package_type === 'theme' ) ? 'theme' : 'plugin';

		// Load existing package data.
		if ( $package_type === 'theme' ) {
			$package_data = $data_manager->get_theme( $package_slug );
		} else {
			$package_data = $data_manager->get_plugin( $package_slug );
		}

		if ( ! $package_data ) {
			$edit_error = new \WP_Error( 'not_found', __( 'Package not found.', 'deployer-for-git' ) );
			include_once __DIR__ . '/template.php';
			return;
		}

		// Handle form submission.
		$edit_result = $this->handle_edit_form( $package_data, $package_type, $data_manager );

		// Re-read package data after a successful save so the template shows updated values.
		if ( $edit_result === true ) {
			if ( $package_type === 'theme' ) {
				$package_data = $data_manager->get_theme( $package_slug );
			} else {
				$package_data = $data_manager->get_plugin( $package_slug );
			}
		}

		include_once __DIR__ . '/template.php';
	}

	/**
	 * Handle the edit package form submission.
	 *
	 * @param array       $package_data Existing package data.
	 * @param string      $package_type Package type (plugin or theme).
	 * @param DataManager $data_manager DataManager instance.
	 * @return \WP_Error|bool|null WP_Error on failure, true on success, null if not submitted.
	 */
	private function handle_edit_form( $package_data, $package_type, $data_manager ) {
		if ( ! isset( $_POST[ DFG_SLUG . '_edit_package_submitted' ] ) ) {
			return null;
		}

		// Verify nonce.
		$nonce = isset( $_POST[ DFG_SLUG . '_nonce' ] ) ? sanitize_text_field( wp_unslash( $_POST[ DFG_SLUG . '_nonce' ] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, DFG_SLUG . '_edit_package_form' ) ) {
			return new \WP_Error( 'invalid', __( 'Invalid nonce.', 'deployer-for-git' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$branch       = isset( $_POST['repository_branch'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['repository_branch'] ) ) ) : '';
		$username     = isset( $_POST['username'] ) ? sanitize_text_field( wp_unslash( $_POST['username'] ) ) : '';
		$password     = isset( $_POST['password'] ) ? sanitize_text_field( wp_unslash( $_POST['password'] ) ) : '';
		$access_token = isset( $_POST['access_token'] ) ? sanitize_text_field( wp_unslash( $_POST['access_token'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $branch ) {
			return new \WP_Error( 'invalid_branch', __( 'Branch is required.', 'deployer-for-git' ) );
		}

		$package_data['branch'] = $branch;

		// Public packages do not show credential fields, so preserve their options.
		if ( $package_data['is_private_repository'] ) {
			$package_data['options'] = array(
				'username'     => $username,
				'password'     => $password,
				'access_token' => $access_token,
			);
		}

		$data_manager->store_package_details( $package_data, $package_type );

		$logger = new Logger();
		$logger->log( "Package settings updated for {$package_type} \"{$package_data['slug']}\" via wp-admin" );
		return true;
	}
}
