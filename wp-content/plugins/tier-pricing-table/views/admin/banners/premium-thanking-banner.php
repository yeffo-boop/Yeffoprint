<?php if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
	
	/**
	 * Available variables
	 *
	 * @var string $accountUrl
	 * @var string $contactUsUrl
	 * @var string $documentationUrl
	 */
?>
<div class="tpt-alert">
	
	<div class="tpt-alert__text">
		<div class="tpt-alert__inner">
			<span style="margin-left: 6px;">
				<?php esc_html_e( 'Tiered Pricing Table', 'tier-pricing-table' ); ?>
			</span>
			<span class="tpt-badge tpt-badge--premium"><?php esc_html_e( 'Premium', 'tier-pricing-table' ); ?></span>
			<?php
			if ( ! tpt_fs()->can_use_premium_code() ) {
				?>
					<br>
					<small style="color:red;"><?php esc_html_e( 'License is invalid or expired. Please check your account.', 'tier-pricing-table' ); ?></small>
					<?php
			}
			?>
		</div>
	</div>
	
	<div class="tpt-alert__buttons">
		<div class="tpt-alert__inner">
			<a class="tpt-button tpt-button--accent" style="display: inline-flex; align-items: center;" href="<?php echo esc_attr( $accountUrl ); ?>">
				<svg style="width: 16px; height: 16px; stroke: currentColor; fill: none; margin-right: 6px;" xmlns="http://www.w3.org/2000/svg" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
				<?php esc_html_e( 'My Account', 'tier-pricing-table' ); ?>
			</a>
			<a class="tpt-button tpt-button--default" style="display: inline-flex; align-items: center;" href="<?php echo esc_attr( $contactUsUrl ); ?>">
				<svg style="width: 16px; height: 16px; stroke: currentColor; fill: none; margin-right: 6px;" xmlns="http://www.w3.org/2000/svg" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
				<?php esc_html_e( 'Contact Support', 'tier-pricing-table' ); ?>
			</a>
			<a class="tpt-button tpt-button--default" target="_blank" style="display: inline-flex; align-items: center;" href="<?php echo esc_attr( $documentationUrl ); ?>">
				<svg style="width: 16px; height: 16px; stroke: currentColor; fill: none; margin-right: 6px;" xmlns="http://www.w3.org/2000/svg" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
				<?php esc_html_e( 'Documentation', 'tier-pricing-table' ); ?>
			</a>
		</div>
	</div>
</div>