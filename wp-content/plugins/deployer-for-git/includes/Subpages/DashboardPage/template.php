<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$theme_list    = $data_manager->get_theme_list();
$plugin_list   = $data_manager->get_plugin_list();
$package_count = count( $theme_list ) + count( $plugin_list );
?>

<div class="wrap dfg_dashboard">
	<div class="dfg_dashboard_header">
		<div>
			<h1><?php echo esc_html__( 'Deployer for Git', 'deployer-for-git' ); ?></h1>
			<p class="dfg_dashboard_intro">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: Number of tracked packages. */
						_n(
							'Manage and deploy %d package from your Git repositories.',
							'Manage and deploy %d packages from your Git repositories.',
							$package_count,
							'deployer-for-git'
						),
						$package_count
					)
				);
				?>
			</p>
		</div>
		<div class="dfg_dashboard_header_actions">
			<a href="<?php echo esc_url( \DeployerForGit\Helper::install_plugin_url() ); ?>" class="button">
				<span class="dashicons dashicons-admin-plugins" aria-hidden="true"></span>
				<?php echo esc_html__( 'Install Plugin', 'deployer-for-git' ); ?>
			</a>
			<a href="<?php echo esc_url( \DeployerForGit\Helper::install_theme_url() ); ?>" class="button">
				<span class="dashicons dashicons-admin-appearance" aria-hidden="true"></span>
				<?php echo esc_html__( 'Install Theme', 'deployer-for-git' ); ?>
			</a>
		</div>
	</div>

	<?php require_once 'partials/_themes.php'; ?>
	<?php require_once 'partials/_plugins.php'; ?>
</div>
