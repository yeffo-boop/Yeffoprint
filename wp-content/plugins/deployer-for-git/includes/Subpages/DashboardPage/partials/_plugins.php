<?php
use DeployerForGit\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<section class="dfg_package_section" aria-labelledby="dfg-installed-plugins-heading">
	<div class="dfg_package_section_header">
		<div>
			<h2 id="dfg-installed-plugins-heading">
				<span class="dashicons dashicons-admin-plugins" aria-hidden="true"></span>
				<?php echo esc_html__( 'Installed Plugins', 'deployer-for-git' ); ?>
				<span class="dfg_count_badge"><?php echo esc_html( count( $plugin_list ) ); ?></span>
			</h2>
			<p><?php echo esc_html__( 'Plugins linked to a Git repository and ready to deploy.', 'deployer-for-git' ); ?></p>
		</div>
	</div>

	<?php if ( empty( $plugin_list ) ) : ?>
		<div class="dfg_empty_state">
			<span class="dashicons dashicons-admin-plugins" aria-hidden="true"></span>
			<h3><?php echo esc_html__( 'No linked plugins yet', 'deployer-for-git' ); ?></h3>
			<p><?php echo esc_html__( 'Install a plugin from a Git repository to manage deployments here.', 'deployer-for-git' ); ?></p>
			<a href="<?php echo esc_url( Helper::install_plugin_url() ); ?>" class="button button-primary">
				<?php echo esc_html__( 'Install Plugin', 'deployer-for-git' ); ?>
			</a>
		</div>
	<?php else : ?>
		<div class="dfg_package_boxes">
			<?php foreach ( $plugin_list as $plugin_info ) : ?>
				<?php
				$package_info   = $plugin_info;
				$package_type   = 'plugin';
				$package_active = false;
				include __DIR__ . '/_package-card.php';
				?>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</section>
