<?php

namespace DeployerForGit\CLI;

use DeployerForGit\DataManager;
use DeployerForGit\Helper;
use DeployerForGit\Logger;
use DeployerForGit\Subpages\InstallPackage;

/**
 * Manage Deployer for Git packages from the command line.
 *
 * ## EXAMPLES
 *
 *     # List all tracked packages
 *     wp dfg list
 *
 *     # Update a registered plugin
 *     wp dfg update my-plugin --type=plugin
 *
 *     # Install a new theme from GitHub
 *     wp dfg install https://github.com/user/my-theme --provider=github --type=theme
 *
 * @package DeployerForGit\CLI
 */
class DfgCommand {

	/**
	 * List all packages managed by Deployer for Git.
	 *
	 * ## OPTIONS
	 *
	 * [--type=<type>]
	 * : Filter results by package type.
	 * ---
	 * options:
	 *   - plugin
	 *   - theme
	 * ---
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp dfg list
	 *     wp dfg list --type=plugin
	 *     wp dfg list --format=json
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function list_packages( $args, $assoc_args ) {
		$type   = \WP_CLI\Utils\get_flag_value( $assoc_args, 'type', 'all' );
		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		$data_manager = new DataManager();
		$items        = array();

		if ( 'all' === $type || 'plugin' === $type ) {
			foreach ( $data_manager->get_plugin_list() as $pkg ) {
				$items[] = $this->format_package_row( $pkg, 'plugin' );
			}
		}

		if ( 'all' === $type || 'theme' === $type ) {
			foreach ( $data_manager->get_theme_list() as $pkg ) {
				$items[] = $this->format_package_row( $pkg, 'theme' );
			}
		}

		if ( empty( $items ) ) {
			\WP_CLI::line( __( 'No packages found.', 'deployer-for-git' ) );
			return;
		}

		$fields = array( 'slug', 'type', 'provider', 'branch', 'private', 'repo_url' );
		\WP_CLI\Utils\format_items( $format, $items, $fields );
	}

	/**
	 * Update (redeploy) a registered package from its git repository.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : The slug of the package to update.
	 *
	 * [--type=<type>]
	 * : Package type.
	 * ---
	 * default: plugin
	 * options:
	 *   - plugin
	 *   - theme
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp dfg update my-plugin
	 *     wp dfg update my-theme --type=theme
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function update( $args, $assoc_args ) {
		$slug = isset( $args[0] ) ? $args[0] : '';

		if ( empty( $slug ) ) {
			\WP_CLI::error( __( 'Please provide a package slug.', 'deployer-for-git' ) );
		}

		$type = \WP_CLI\Utils\get_flag_value( $assoc_args, 'type', 'plugin' );
		$type = ( 'theme' === $type ) ? 'theme' : 'plugin';

		$data_manager = new DataManager();

		if ( 'theme' === $type ) {
			$package_details = $data_manager->get_theme( $slug );
		} else {
			$package_details = $data_manager->get_plugin( $slug );
		}

		if ( false === $package_details ) {
			\WP_CLI::error(
				sprintf(
					/* translators: 1: package slug, 2: package type */
					__( 'Package "%1$s" (%2$s) not found. Is it registered with Deployer for Git?', 'deployer-for-git' ),
					$slug,
					$type
				)
			);
		}

		\WP_CLI::line(
			sprintf(
				/* translators: 1: package type, 2: package slug, 3: repository URL */
				__( 'Updating %1$s "%2$s" from %3$s...', 'deployer-for-git' ),
				$type,
				$slug,
				$package_details['repo_url']
			)
		);

		$result = $this->run_install( $package_details, $type );
		$logger = new Logger();

		if ( is_wp_error( $result ) ) {
			$logger->log(
				sprintf(
					'Error updating %1$s "%2$s" via WP-CLI: %3$s',
					$type,
					$slug,
					$result->get_error_message()
				)
			);
			\WP_CLI::error( $result->get_error_message() );
		}

		$logger->log(
			sprintf( 'Package (%1$s) "%2$s" successfully updated via WP-CLI', $type, $slug )
		);

		\WP_CLI::success(
			sprintf(
				/* translators: %s: package slug */
				__( '"%s" updated successfully.', 'deployer-for-git' ),
				$slug
			)
		);
	}

	/**
	 * Install a package from a git repository and register it with Deployer for Git.
	 *
	 * ## OPTIONS
	 *
	 * <repo-url>
	 * : The full repository URL.
	 *
	 * --provider=<provider>
	 * : The git provider hosting the repository.
	 * ---
	 * options:
	 *   - github
	 *   - gitlab
	 *   - bitbucket
	 *   - gitea
	 * ---
	 *
	 * --type=<type>
	 * : The package type to install.
	 * ---
	 * options:
	 *   - plugin
	 *   - theme
	 * ---
	 *
	 * [--branch=<branch>]
	 * : The branch to deploy.
	 * ---
	 * default: master
	 * ---
	 *
	 * [--private]
	 * : Treat the repository as private. Requires PRO licence.
	 *
	 * [--token=<token>]
	 * : Access token for private GitHub, GitLab, or Gitea repositories. Requires PRO licence.
	 *
	 * [--username=<username>]
	 * : Username for private Bitbucket repositories. Requires PRO licence.
	 *
	 * [--password=<password>]
	 * : App password for private Bitbucket repositories. Requires PRO licence.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dfg install https://github.com/user/my-plugin --provider=github --type=plugin
	 *     wp dfg install https://github.com/user/my-theme --provider=github --type=theme --branch=main
	 *     wp dfg install https://github.com/user/private-plugin --provider=github --type=plugin --private --token=ghp_xxx
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function install( $args, $assoc_args ) {
		$repo_url   = isset( $args[0] ) ? $args[0] : '';
		$provider   = \WP_CLI\Utils\get_flag_value( $assoc_args, 'provider', '' );
		$type       = \WP_CLI\Utils\get_flag_value( $assoc_args, 'type', 'plugin' );
		$branch     = \WP_CLI\Utils\get_flag_value( $assoc_args, 'branch', 'master' );
		$is_private = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'private', false );
		$token      = \WP_CLI\Utils\get_flag_value( $assoc_args, 'token', '' );
		$username   = \WP_CLI\Utils\get_flag_value( $assoc_args, 'username', '' );
		$password   = \WP_CLI\Utils\get_flag_value( $assoc_args, 'password', '' );

		$type = ( 'theme' === $type ) ? 'theme' : 'plugin';

		if ( empty( $repo_url ) ) {
			\WP_CLI::error( __( 'Please provide a repository URL.', 'deployer-for-git' ) );
		}

		$valid_providers = array_keys( Helper::available_providers() );
		if ( ! in_array( $provider, $valid_providers, true ) ) {
			\WP_CLI::error(
				sprintf(
					/* translators: 1: invalid provider, 2: comma-separated list of valid providers */
					__( 'Invalid provider "%1$s". Valid options: %2$s', 'deployer-for-git' ),
					$provider,
					implode( ', ', $valid_providers )
				)
			);
		}

		$provider_class = Helper::get_provider_class( $provider );

		try {
			$provider_instance = new $provider_class( $repo_url );
			$package_slug      = $provider_instance->get_package_slug();
			$package_zip_url   = $provider_instance->get_zip_repo_url( $branch );

			// GitHub fine-grained PAT support.
			if ( $provider_instance instanceof \DeployerForGit\Providers\GithubProvider ) {
				$package_zip_url = $provider_instance->get_zip_repo_url( $branch, $token );
			}
		} catch ( \Exception $e ) {
			\WP_CLI::error( $e->getMessage() );
			return; // Unreachable in real WP-CLI (error() exits), but needed for testability.
		}

		$options = array( 'wp_json_request' => true );

		if ( $is_private ) {
			$options['is_private_repository'] = true;
			$options['username']              = $username;
			$options['password']              = $password;
			$options['access_token']          = $token;
		}

		\WP_CLI::line(
			sprintf(
				/* translators: 1: package type, 2: package slug, 3: repository URL, 4: branch */
				__( 'Installing %1$s "%2$s" from %3$s (branch: %4$s)...', 'deployer-for-git' ),
				$type,
				$package_slug,
				$repo_url,
				$branch
			)
		);

		$result = InstallPackage::install_package_from_zip_url(
			$package_zip_url,
			$package_slug,
			$type,
			$provider,
			$options
		);

		$logger = new Logger();

		if ( is_wp_error( $result ) ) {
			$logger->log(
				sprintf(
					'Error installing %1$s "%2$s" via WP-CLI: %3$s',
					$type,
					$package_slug,
					$result->get_error_message()
				)
			);
			\WP_CLI::error( $result->get_error_message() );
			return; // Unreachable in real WP-CLI, but needed for testability.
		}

		// Register the package so it appears on the dashboard and can be webhook-updated.
		$data_manager = new DataManager();
		$package_data = array(
			'slug'                  => $package_slug,
			'repo_url'              => $repo_url,
			'branch'                => $branch,
			'provider'              => $provider,
			'is_private_repository' => $is_private,
			'options'               => array(
				'username'     => $username,
				'password'     => $password,
				'access_token' => $token,
			),
		);
		$data_manager->store_package_details( $package_data, $type );

		$logger->log(
			sprintf( 'Package (%1$s) "%2$s" successfully installed via WP-CLI', $type, $package_slug )
		);

		\WP_CLI::success(
			sprintf(
				/* translators: %s: package slug */
				__( '"%s" installed and registered successfully.', 'deployer-for-git' ),
				$package_slug
			)
		);
	}

	/**
	 * Format a stored package array as a display row.
	 *
	 * @param array  $pkg  Raw package data from DataManager.
	 * @param string $type Package type: plugin or theme.
	 * @return array
	 */
	private function format_package_row( $pkg, $type ) {
		return array(
			'slug'     => isset( $pkg['slug'] ) ? $pkg['slug'] : '',
			'type'     => $type,
			'provider' => isset( $pkg['provider'] ) ? $pkg['provider'] : '',
			'branch'   => isset( $pkg['branch'] ) ? $pkg['branch'] : '',
			'private'  => ( isset( $pkg['is_private_repository'] ) && $pkg['is_private_repository'] ) ? 'yes' : 'no',
			'repo_url' => isset( $pkg['repo_url'] ) ? $pkg['repo_url'] : '',
		);
	}

	/**
	 * Run a package install/update from stored DataManager package details.
	 *
	 * Mirrors the logic in PackageUpdate::update_package_callback() so that
	 * private-repo auth, GitHub PAT handling, and the wp_json_request bypass
	 * all work consistently from the CLI.
	 *
	 * @param array  $package_details Stored package data from DataManager.
	 * @param string $type            Package type: plugin or theme.
	 * @return true|\WP_Error
	 */
	private function run_install( $package_details, $type ) {
		$provider_class_name = Helper::get_provider_class( $package_details['provider'] );
		$provider            = new $provider_class_name( $package_details['repo_url'] );

		$package_slug    = $provider->get_package_slug();
		$package_zip_url = $provider->get_zip_repo_url( $package_details['branch'] );

		if ( $provider instanceof \DeployerForGit\Providers\GithubProvider ) {
			$github_token    = isset( $package_details['options']['access_token'] ) ? $package_details['options']['access_token'] : '';
			$package_zip_url = $provider->get_zip_repo_url( $package_details['branch'], $github_token );
		}

		$options = array( 'wp_json_request' => true );

		if ( isset( $package_details['is_private_repository'] ) && true === $package_details['is_private_repository'] ) {
			$options['is_private_repository'] = true;
			$options['username']              = isset( $package_details['options']['username'] ) ? $package_details['options']['username'] : '';
			$options['password']              = isset( $package_details['options']['password'] ) ? $package_details['options']['password'] : '';
			$options['access_token']          = isset( $package_details['options']['access_token'] ) ? $package_details['options']['access_token'] : '';
		}

		return InstallPackage::install_package_from_zip_url(
			$package_zip_url,
			$package_slug,
			$type,
			$package_details['provider'],
			$options
		);
	}
}
