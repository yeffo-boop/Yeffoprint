<?php if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
	
	/**
	 * Available variables
	 *
	 * @var string $upgradeUrl
	 * @var string $contactUsUrl
	 * @var string $documentationUrl
	 */
	
	$accountURL = tpt_fs()->get_account_url();
?>
<div class="tpt-alert">
	
	<div class="tpt-alert__text">
		<div class="tpt-alert__inner">
			<span class="tpt-badge tpt-badge--free"><?php esc_html_e( 'Free Version', 'tier-pricing-table' ); ?></span>
			<strong style="margin-left: 6px;">
				<?php esc_html_e( 'Unlock advanced features with Premium 🚀', 'tier-pricing-table' ); ?>
			</strong>
			<?php if ( tpt_fs()->is_activation_mode() ) : ?>
				<br>
				<br>
				<?php
				$activationURL = tpt_fs()->get_activation_url();
				$linkText      = esc_html__( 'finish plugin activation', 'tier-pricing-table' );
				
				$upgradeLink = '<a target="_blank" href="' . $activationURL . '">' . $linkText . '</a>';
				?>
				<small style="font-size: .8em">
					<?php
						// translators: %s: activation link
						echo wp_kses_post( sprintf( esc_html__( 'Please %s to upgrade.', 'tier-pricing-table' ),
							$upgradeLink ) );
					?>
				</small>
			<?php endif; ?>
		</div>
	</div>
	
	<div class="tpt-alert__buttons">
		<div class="tpt-alert__inner">
			
			<a class="tpt-button tpt-button--accent tpt-button--bounce" target="_blank" style="display: inline-flex; align-items: center;"
			   href="<?php echo esc_attr( $upgradeUrl ); ?>">
			   <svg style="width: 16px; height: 16px; fill: currentColor; margin-right: 6px;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>
				<?php esc_html_e( 'Upgrade to Premium', 'tier-pricing-table' ); ?>
			</a>
   
			<a target="_blank" class="tpt-button tpt-button--default" style="display: inline-flex; align-items: center;" href="<?php echo esc_attr( $contactUsUrl ); ?>">
				<svg style="width: 16px; height: 16px; stroke: currentColor; fill: none; margin-right: 6px;" xmlns="http://www.w3.org/2000/svg" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
				<?php esc_html_e( 'Contact Support', 'tier-pricing-table' ); ?>
			</a>

			<a target="_blank" class="tpt-button tpt-button--default" style="display: inline-flex; align-items: center;" href="<?php echo esc_attr( $documentationUrl ); ?>">
				<svg style="width: 16px; height: 16px; stroke: currentColor; fill: none; margin-right: 6px;" xmlns="http://www.w3.org/2000/svg" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
				<?php esc_html_e( 'Documentation', 'tier-pricing-table' ); ?>
			</a>
			
			<?php if ( tpt_fs()->is_activation_mode() ) : ?>
				<a target="_blank" class="tpt-button tpt-button--default"
				   href="<?php echo esc_attr( tpt_fs()->get_activation_url() ); ?>">
					<?php esc_html_e( 'Opt-in', 'tier-pricing-table' ); ?>
				</a>
			<?php elseif ( tpt_fs()->get_user() ) : ?>
				<a target="_blank" class="tpt-button tpt-button--default" style="display: inline-flex; align-items: center;"
				   href="<?php echo esc_attr( admin_url( 'admin.php?page=tiered-pricing-table-account' ) ); ?>">
				    <svg style="width: 16px; height: 16px; stroke: currentColor; fill: none; margin-right: 6px;" xmlns="http://www.w3.org/2000/svg" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
					<?php esc_html_e( 'My Account', 'tier-pricing-table' ); ?>
				</a>
			<?php endif; ?>
		</div>
	</div>
</div>