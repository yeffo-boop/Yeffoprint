<?php
use DeployerForGit\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<?php
if ( isset( $edit_error ) && is_wp_error( $edit_error ) ) :
	?>
	<div class="wrap">
		<h1><?php echo esc_attr__( 'Edit Package', 'deployer-for-git' ); ?></h1>
		<div class="error">
			<p><?php echo esc_attr( $edit_error->get_error_message() ); ?></p>
		</div>
		<p>
			<a href="<?php echo esc_url( Helper::dashboard_url() ); ?>" class="button">
				<?php echo esc_attr__( '&larr; Back to Dashboard', 'deployer-for-git' ); ?>
			</a>
		</p>
	</div>
	<?php
	return;
endif;
?>

<?php if ( isset( $edit_result ) ) : ?>
	<?php if ( $edit_result === true ) : ?>
		<div class="updated">
			<p><?php echo esc_attr__( 'Package updated successfully.', 'deployer-for-git' ); ?></p>
		</div>
	<?php elseif ( is_wp_error( $edit_result ) ) : ?>
		<div class="error">
			<p><?php echo esc_attr( $edit_result->get_error_message() ); ?></p>
		</div>
	<?php endif; ?>
<?php endif; ?>

<div class="wrap">
	<h1><?php echo esc_attr__( 'Edit Package', 'deployer-for-git' ); ?></h1>

	<p>
		<a href="<?php echo esc_url( Helper::dashboard_url() ); ?>">
			<?php echo esc_attr__( '&larr; Back to Dashboard', 'deployer-for-git' ); ?>
		</a>
	</p>

	<table class="form-table">
		<tr valign="top">
			<th scope="row"><?php echo esc_attr__( 'Package Slug', 'deployer-for-git' ); ?></th>
			<td><code><?php echo esc_attr( $package_data['slug'] ); ?></code></td>
		</tr>
		<tr valign="top">
			<th scope="row"><?php echo esc_attr__( 'Type', 'deployer-for-git' ); ?></th>
			<td><code><?php echo esc_attr( $package_type ); ?></code></td>
		</tr>
		<tr valign="top">
			<th scope="row"><?php echo esc_attr__( 'Provider', 'deployer-for-git' ); ?></th>
			<td><code><?php echo esc_attr( Helper::available_providers()[ $package_data['provider'] ] ); ?></code></td>
		</tr>
		<tr valign="top">
			<th scope="row"><?php echo esc_attr__( 'Repository URL', 'deployer-for-git' ); ?></th>
			<td><code><?php echo esc_attr( $package_data['repo_url'] ); ?></code></td>
		</tr>
	</table>

	<hr>

	<?php
	$existing_options      = isset( $package_data['options'] ) ? $package_data['options'] : array();
	$existing_username     = isset( $existing_options['username'] ) ? $existing_options['username'] : '';
	$existing_password     = isset( $existing_options['password'] ) ? $existing_options['password'] : '';
	$existing_access_token = isset( $existing_options['access_token'] ) ? $existing_options['access_token'] : '';
	$provider              = $package_data['provider'];
	?>

	<form method="post" action="">
		<input type="hidden" name="<?php echo esc_attr( DFG_SLUG . '_edit_package_submitted' ); ?>" value="1">
		<?php wp_nonce_field( DFG_SLUG . '_edit_package_form', DFG_SLUG . '_nonce' ); ?>

		<table class="form-table">
			<tr valign="top">
				<th scope="row">
					<label for="repository_branch"><?php echo esc_attr__( 'Branch', 'deployer-for-git' ); ?></label>
				</th>
				<td>
					<input
						id="repository_branch"
						class="regular-text code"
						type="text"
						name="repository_branch"
						value="<?php echo esc_attr( $package_data['branch'] ); ?>"
						aria-describedby="repository_branch_description"
						required
					>
					<p id="repository_branch_description" class="description">
						<?php echo esc_html__( 'Choose the repository branch to use for future package updates and push-to-deploy requests (for example, main or develop).', 'deployer-for-git' ); ?>
					</p>
				</td>
			</tr>

			<?php if ( $package_data['is_private_repository'] ) : ?>
				<?php if ( $provider === 'bitbucket' ) : ?>
					<tr valign="top">
						<th scope="row"><?php echo esc_attr__( 'Email address', 'deployer-for-git' ); ?></th>
						<td><input type="text" name="username" value="<?php echo esc_attr( $existing_username ); ?>"></td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php echo esc_attr__( 'API Token', 'deployer-for-git' ); ?></th>
						<td><input type="text" name="password" value="<?php echo esc_attr( $existing_password ); ?>"></td>
					</tr>
				<?php else : ?>
					<input type="hidden" name="username" value="">
					<input type="hidden" name="password" value="">
				<?php endif; ?>

				<?php if ( in_array( $provider, array( 'github', 'gitlab', 'gitea' ), true ) ) : ?>
					<tr valign="top">
						<th scope="row"><?php echo esc_attr__( 'Access Token', 'deployer-for-git' ); ?></th>
						<td>
							<input class="regular-text code" type="text" name="access_token" value="<?php echo esc_attr( $existing_access_token ); ?>">
							<?php if ( $provider === 'gitea' ) : ?>
								<p class="description">
									<?php echo esc_attr__( 'You can generate Gitea access token here:', 'deployer-for-git' ); ?>
									<a href="https://gitea.com/user/settings/applications" target="_blank" rel="nofollow"><?php echo esc_attr__( 'Link', 'deployer-for-git' ); ?></a>
								</p>
							<?php elseif ( $provider === 'github' ) : ?>
								<p class="description">
									<?php echo esc_attr__( 'You can generate Github access token here:', 'deployer-for-git' ); ?>
									<a href="https://github.com/settings/tokens" target="_blank" rel="nofollow"><?php echo esc_attr__( 'Link', 'deployer-for-git' ); ?></a>
								</p>
							<?php elseif ( $provider === 'gitlab' ) : ?>
								<p class="description">
									<?php echo esc_attr__( 'You can generate GitLab access token here ("api" scope required):', 'deployer-for-git' ); ?>
									<a href="https://gitlab.com/-/user_settings/personal_access_tokens" target="_blank" rel="nofollow"><?php echo esc_attr__( 'Link', 'deployer-for-git' ); ?></a>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				<?php else : ?>
					<input type="hidden" name="access_token" value="">
				<?php endif; ?>
			<?php endif; ?>
		</table>
		<div class="submit">
			<input
				type="submit"
				class="button-primary"
				value="<?php echo esc_attr__( 'Update Package', 'deployer-for-git' ); ?>"
			/>
		</div>
	</form>
</div>
