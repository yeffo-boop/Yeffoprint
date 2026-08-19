<?php
use DeployerForGit\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<section class="dfg_package_section" aria-labelledby="dfg-installed-themes-heading">
	<div class="dfg_package_section_header">
		<div>
			<h2 id="dfg-installed-themes-heading">
				<span class="dashicons dashicons-admin-appearance" aria-hidden="true"></span>
				<?php echo esc_html__( 'Installed Themes', 'deployer-for-git' ); ?>
				<span class="dfg_count_badge"><?php echo esc_html( count( $theme_list ) ); ?></span>
			</h2>
			<p><?php echo esc_html__( 'Themes linked to a Git repository and ready to deploy.', 'deployer-for-git' ); ?></p>
		</div>
	</div>

	<?php if ( empty( $theme_list ) ) : ?>
		<div class="dfg_empty_state">
			<span class="dashicons dashicons-admin-appearance" aria-hidden="true"></span>
			<h3><?php echo esc_html__( 'No linked themes yet', 'deployer-for-git' ); ?></h3>
			<p><?php echo esc_html__( 'Install a theme from a Git repository to manage deployments here.', 'deployer-for-git' ); ?></p>
			<a href="<?php echo esc_url( Helper::install_theme_url() ); ?>" class="button button-primary">
				<?php echo esc_html__( 'Install Theme', 'deployer-for-git' ); ?>
			</a>
		</div>
	<?php else : ?>
		<?php $current_theme = wp_get_theme(); ?>
		<div class="dfg_package_boxes">
			<?php foreach ( $theme_list as $theme_info ) : ?>
				<?php
				$package_info   = $theme_info;
				$package_type   = 'theme';
				$package_active = $current_theme->get_template() === $theme_info['slug'];
				include __DIR__ . '/_package-card.php';
				?>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</section>
