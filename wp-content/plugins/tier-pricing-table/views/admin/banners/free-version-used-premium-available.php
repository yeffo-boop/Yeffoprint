<?php if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
	
	/**
	 * Available variables
	 *
	 * @var bool $is_product
	 */
	$is_product = isset( $is_product ) ? $is_product : false;
?>

<div class="tpt-alert <?php echo esc_attr( $is_product ? 'tpt-alert--compact' : '' ); ?>">
	<div class="tpt-alert__text">
		<div class="tpt-alert__inner">
			<span class="tpt-badge tpt-badge--free"><?php esc_html_e( 'Free Version', 'tier-pricing-table' ); ?></span>
			<strong style="margin-left: 6px;">
				<?php 
				esc_html_e( 'Premium License Available',
					'tier-pricing-table' ); 
				?>
			</strong>
			&mdash;
			<span style="font-weight: normal; font-size: 13px;">
				<?php 
				echo wp_kses_post( __( 'You are currently running the free version. Download the Pro version from your <a href="' . esc_url( tpt_fs()->get_account_url() ) . '">account</a> or purchase receipt.',
					'tier-pricing-table' ) ); 
				?>
			</span>
		</div>
	</div>
</div>
