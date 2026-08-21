<?php

use DeployerForGit\Helper;
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Expected: $package_type is either 'plugin' or 'theme'
 * You can set this before including the template, e.g.:
 *   $package_type = 'plugin'; // or 'theme'
 */
$package_type = ( isset( $package_type ) && 'theme' === $package_type ? 'theme' : 'plugin' );
$is_plugin = 'plugin' === $package_type;
// Labels.
$label_singular = ( $is_plugin ? __( 'Plugin', 'deployer-for-git' ) : __( 'Theme', 'deployer-for-git' ) );
$success_message = ( $is_plugin ? __( 'Plugin was successfully installed', 'deployer-for-git' ) : __( 'Theme was successfully installed', 'deployer-for-git' ) );
$heading = ( $is_plugin ? __( 'Install Plugin', 'deployer-for-git' ) : __( 'Install Theme', 'deployer-for-git' ) );
// Repo examples.
$repo_example_suffix = ( $is_plugin ? 'wordpress-plugin-name' : 'wordpress-theme-name' );
// Overwrite note.
$overwrite_note = ( $is_plugin ? __( 'Note that if a plugin with specified slug is already installed, this action will overwrite the already existing plugin.', 'deployer-for-git' ) : __( 'Note that if a theme with specified slug is already installed, this action will overwrite the already existing theme.', 'deployer-for-git' ) );
?>

<?php 
if ( isset( $install_result ) ) {
    ?>
	<?php 
    if ( !is_wp_error( $install_result ) ) {
        ?>
		<div class="updated">
			<p>
				<?php 
        echo esc_attr( $success_message );
        ?>
			</p>
		</div>
	<?php 
    } else {
        ?>
		<div class="error">
			<p>
				<!-- <strong><?php 
        echo esc_attr( $install_result->get_error_code() );
        ?></strong> -->
				<?php 
        echo esc_attr( $install_result->get_error_message() );
        ?>
			</p>
		</div>
	<?php 
    }
}
?>

<div class="wrap">
	<h1><?php 
echo esc_attr( $heading );
?></h1>
	<form class="dfg_install_package_form" method="post" action="">
		<input type="hidden" name="<?php 
echo esc_attr( DFG_SLUG . '_install_package_submitted' );
?>" value="1">
		<input type="hidden" name="package_type" value="<?php 
echo esc_attr( $package_type );
?>">

		<?php 
wp_nonce_field( DFG_SLUG . '_install_package_form', DFG_SLUG . '_nonce' );
?>
		<table class="form-table">
			<tr valign="top">
				<th scope="row"><?php 
echo esc_attr__( 'Provider Type', 'deployer-for-git' );
?></th>
				<td>
					<select name='provider_type'>
						<option value="" selected disabled><?php 
echo esc_attr__( 'Choose a provider', 'deployer-for-git' );
?></option>
						<?php 
foreach ( Helper::available_providers() as $provider_id => $name ) {
    ?>
							<option value="<?php 
    echo esc_attr( $provider_id );
    ?>"><?php 
    echo esc_attr( $name );
    ?></option>
						<?php 
}
?>
					</select>
				</td>
			</tr>

			<tr valign="top" class="dfg_hidden dfg_help_link_row">
				<th scope="row"></th>
				<td>
					<a target="_blank" href="#" id="dfg_install_package_form_repo_help_link">
						<?php 
esc_attr_e( 'Click to here for more information about repo configuration', 'deployer-for-git' );
?>
					</a>
				</td>
			</tr>

			<tr valign="top">
				<th scope="row"><?php 
echo esc_attr__( 'Repository URL', 'deployer-for-git' );
?></th>
				<td>
					<input type="url" class="regular-text code" name="repository_url" value="" />
					<p class="description dfg_repo_url_description dfg_hidden" id="bitbucket-repo-url-description">
						<?php 
echo esc_attr( sprintf( 'Example: https://bitbucket.org/owner/%s', $repo_example_suffix ) );
?>
					</p>
					<p class="description dfg_repo_url_description dfg_hidden" id="github-repo-url-description">
						<?php 
echo esc_attr( sprintf( 'Example: https://github.com/owner/%s', $repo_example_suffix ) );
?>
					</p>
					<p class="description dfg_repo_url_description dfg_hidden" id="gitea-repo-url-description">
						<?php 
echo esc_attr( sprintf( 'Example: https://gitea.com/owner/%s', $repo_example_suffix ) );
?>
					</p>
					<p class="description dfg_repo_url_description dfg_hidden" id="gitlab-repo-url-description">
						<?php 
echo esc_attr( sprintf( 'Example: https://gitlab.com/owner/%s', $repo_example_suffix ) );
?>
					</p>
				</td>
			</tr>

			<tr valign="top">
				<th scope="row">
					<?php 
echo esc_attr__( 'Branch', 'deployer-for-git' );
?>
					<span class="dashicons dashicons-randomize"></span>
				</th>
				<td>
					<input type="text" class="" placeholder="master" name="repository_branch" value="" />
				</td>
			</tr>

			<?php 
$private_repository_row_class = 'free';
?>

			<tr valign="top" class="dfg_hidden dfg_is_private_repository_row <?php 
echo esc_attr( $private_repository_row_class );
?>">
				<th scope="row">
					<?php 
echo esc_attr__( 'Is Private Repository', 'deployer-for-git' );
?>

					<?php 
?>
						<br>
						<small><?php 
echo esc_attr__( '[Available in PRO version]', 'deployer-for-git' );
?></small>
					<?php 
?>

					<span class="dashicons dashicons-lock"></span>
				</th>
				<td><input type="checkbox" name="is_private_repository" value="1"></td>
			</tr>

			<?php 
?>
		</table>
		<div class="submit">
			<input
				type="submit"
				class="button-primary"
				value="<?php 
echo esc_attr( $heading );
?>"
			/>
			<br><br>
			<p class="description">
				<i><?php 
echo esc_attr( $overwrite_note );
?></i>
			</p>
		</div>
	</form>
</div>
