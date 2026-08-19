<?php
use DeployerForGit\ApiRequests\PackageUpdate;
use DeployerForGit\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$endpoint_url      = PackageUpdate::package_update_url( $package_info['slug'], $package_type );
$provider_names    = Helper::available_providers();
$provider_name     = isset( $provider_names[ $package_info['provider'] ] ) ? $provider_names[ $package_info['provider'] ] : ucfirst( $package_info['provider'] );
$provider_logo_url = plugin_dir_url( DFG_FILE ) . 'assets/img/' . $package_info['provider'] . '-logo.svg';
$is_private        = ! empty( $package_info['is_private_repository'] );
$github_token      = isset( $package_info['options']['access_token'] ) ? $package_info['options']['access_token'] : '';
$uses_fine_pat     = 'github' === $package_info['provider'] && Helper::is_github_pat_token( $github_token );
$webhook_id        = 'dfg-webhook-' . $package_type . '-' . sanitize_html_class( $package_info['slug'] );
$status_id         = 'dfg-status-' . $package_type . '-' . sanitize_html_class( $package_info['slug'] );
$package_label     = ( 'theme' === $package_type ) ? __( 'theme', 'deployer-for-git' ) : __( 'plugin', 'deployer-for-git' );
$deploy_label      = ( 'theme' === $package_type ) ? __( 'Deploy Theme', 'deployer-for-git' ) : __( 'Deploy Plugin', 'deployer-for-git' );
$edit_aria_label   = sprintf(
	/* translators: 1: Package type, 2: Package slug. */
	__( 'Edit %1$s %2$s', 'deployer-for-git' ),
	$package_label,
	$package_info['slug']
);
$unlink_aria_label = sprintf(
	/* translators: 1: Package type, 2: Package slug. */
	__( 'Unlink %1$s %2$s from Deployer for Git', 'deployer-for-git' ),
	$package_label,
	$package_info['slug']
);
?>

<article class="dfg_package_box">
	<div class="dfg_package_card">
		<header class="dfg_package_card_header">
			<img class="dfg_provider_logo" src="<?php echo esc_url( $provider_logo_url ); ?>" alt="" aria-hidden="true">
			<div class="dfg_package_identity">
				<h3><?php echo esc_html( $package_info['slug'] ); ?></h3>
				<div class="dfg_package_badges">
					<span class="dfg_badge"><?php echo esc_html( $provider_name ); ?></span>
					<span class="dfg_badge">
						<span class="dashicons <?php echo $is_private ? 'dashicons-lock' : 'dashicons-unlock'; ?>" aria-hidden="true"></span>
						<?php echo $is_private ? esc_html__( 'Private', 'deployer-for-git' ) : esc_html__( 'Public', 'deployer-for-git' ); ?>
					</span>
					<?php if ( $uses_fine_pat ) : ?>
						<span class="dfg_badge">
							<span class="dashicons dashicons-post-status" aria-hidden="true"></span>
							<?php echo esc_html__( 'Fine-grained token', 'deployer-for-git' ); ?>
						</span>
					<?php endif; ?>
					<?php if ( $package_active ) : ?>
						<span class="dfg_badge dfg_badge_success"><?php echo esc_html__( 'Active', 'deployer-for-git' ); ?></span>
					<?php endif; ?>
				</div>
			</div>
		</header>

		<dl class="dfg_package_metadata">
			<div>
				<dt><?php echo esc_html__( 'Branch', 'deployer-for-git' ); ?></dt>
				<dd><code class="dfg_package_box_branch"><?php echo esc_html( $package_info['branch'] ); ?></code></dd>
			</div>
			<div>
				<dt><?php echo esc_html__( 'Repository', 'deployer-for-git' ); ?></dt>
				<dd>
					<a class="dfg_repository_link" href="<?php echo esc_url( $package_info['repo_url'] ); ?>" target="_blank" rel="noopener noreferrer">
						<span><?php echo esc_html( $package_info['repo_url'] ); ?></span>
						<span class="dashicons dashicons-external" aria-hidden="true"></span>
						<span class="screen-reader-text"><?php echo esc_html__( '(opens in a new tab)', 'deployer-for-git' ); ?></span>
					</a>
				</dd>
			</div>
		</dl>

		<div class="dfg_webhook">
			<button type="button" class="dfg_webhook_toggle" data-show-ptd-btn aria-expanded="false" aria-controls="<?php echo esc_attr( $webhook_id ); ?>">
				<span class="dashicons dashicons-rest-api" aria-hidden="true"></span>
				<span class="text"><?php echo esc_html__( 'Show webhook URL', 'deployer-for-git' ); ?></span>
				<span class="dashicons dashicons-arrow-down-alt2 dfg_webhook_chevron" aria-hidden="true"></span>
			</button>
			<div id="<?php echo esc_attr( $webhook_id ); ?>" class="dfg_package_box_action" hidden>
				<label class="screen-reader-text" for="<?php echo esc_attr( $webhook_id ); ?>-url">
					<?php echo esc_html__( 'Push-to-deploy webhook URL', 'deployer-for-git' ); ?>
				</label>
				<input id="<?php echo esc_attr( $webhook_id ); ?>-url" type="url" readonly value="<?php echo esc_url( $endpoint_url ); ?>">
				<button type="button" data-copy-url-btn="<?php echo esc_url( $endpoint_url ); ?>" class="button button-small">
					<span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
					<span class="text"><?php echo esc_html__( 'Copy URL', 'deployer-for-git' ); ?></span>
				</button>
			</div>
		</div>

		<p id="<?php echo esc_attr( $status_id ); ?>" class="dfg_deploy_status" role="status" aria-live="polite"></p>

		<footer class="dfg_package_box_buttons">
			<div class="dfg_package_primary_actions">
				<button
					type="button"
					data-package-type="<?php echo esc_attr( $package_type ); ?>"
					data-trigger-ptd-btn="<?php echo esc_url( $endpoint_url ); ?>"
					aria-describedby="<?php echo esc_attr( $status_id ); ?>"
					class="button button-primary update-package-btn"
				>
					<span class="dashicons dashicons-update" aria-hidden="true"></span>
					<span class="text"><?php echo esc_html( $deploy_label ); ?></span>
				</button>
				<a
					href="<?php echo esc_url( Helper::edit_package_url( $package_info['slug'], $package_type ) ); ?>"
					aria-label="<?php echo esc_attr( $edit_aria_label ); ?>"
					class="button edit-package-btn"
				>
					<span class="dashicons dashicons-edit" aria-hidden="true"></span>
					<?php echo esc_html__( 'Edit', 'deployer-for-git' ); ?>
				</a>
			</div>

			<button
				type="button"
				data-unlink-btn
				data-unlink-slug="<?php echo esc_attr( $package_info['slug'] ); ?>"
				data-unlink-type="<?php echo esc_attr( $package_type ); ?>"
				aria-label="<?php echo esc_attr( $unlink_aria_label ); ?>"
				class="button dfg_unlink_button"
			>
				<span class="dashicons dashicons-editor-unlink" aria-hidden="true"></span>
				<?php echo esc_html__( 'Unlink', 'deployer-for-git' ); ?>
			</button>
		</footer>
	</div>
</article>
