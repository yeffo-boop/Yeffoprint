<?php
	
	use TierPricingTable\Addons\GlobalTieredPricing\CPT\GlobalTieredPricingCPT;
	use TierPricingTable\Admin\Notifications\Notifications\ActivationNotification;
	use TierPricingTable\Core\ServiceContainer;
	use TierPricingTable\TierPricingTablePlugin;
	
if ( ! defined( 'WPINC' ) ) {
	die;
}
	
	ActivationNotification::setInactive();
?>

<div class="updated notice is-dismissible" style="border: 1px solid #c3c4c7; padding: 15px 20px">
	<div style="display: flex; gap: 10px; align-items: center">
		<div>
			<img height="100px"
				 src="<?php echo esc_attr( ServiceContainer::getInstance()->getFileManager()->locateAsset( 'admin/pricing-logo.png' ) ); ?>"
				 alt="">
		</div>
		<div>
			<p>
				<span style="font-size: 1.3em">
				<?php esc_html_e( 'Thanks for installing the', 'tier-pricing-table' ); ?>
				<b><?php esc_html_e( 'Tiered Pricing Table', 'tier-pricing-table' ); ?></b>!
			</span>
			</p>
			<p>
				<span>
					<?php
						$productsURL = add_query_arg( array(
							'post_type' => 'product',
						), admin_url( 'edit.php' ) );
						
						$globalRulesURL = add_query_arg( array(
							'post_type' => GlobalTieredPricingCPT::SLUG,
						), admin_url( 'edit.php' ) );
						
						$productsLink = sprintf( '<b><a target="_blank" href="%s">%s</a></b>', $productsURL,
							__( 'products', 'tier-pricing-table' ) );
						
						$globalPricingRulesLink = sprintf( '<a target="_blank" href="%s"><b>%s</b></a>',
							$globalRulesURL, __( 'a global pricing rule', 'tier-pricing-table' ) );
						
						// translators: %1$s: product links, %2$s: categories
						echo wp_kses_post( sprintf( __( 'Add quantity-based pricing rules directly in the %1$s or create %2$s that will work for product categories and user roles.',
							'tier-pricing-table' ), $productsLink, $globalPricingRulesLink ) );
						?>
				</span>
			</p>
			<p style="margin-top: 10px">
				<span>
					<a href="<?php echo esc_attr( ServiceContainer::getInstance()->getSettings()->getLink() ); ?>"
					   class="button button-primary">
					<?php esc_html_e( 'Settings', 'tier-pricing-table' ); ?>
					</a>
				<a href="<?php echo esc_attr( TierPricingTablePlugin::getDocumentationURL() ); ?>"
				   target="_blank" class="button">
					<?php esc_html_e( 'Documentation', 'tier-pricing-table' ); ?>
				</a>
				</span>
			</p>
		</div>
	</div>
</div>
