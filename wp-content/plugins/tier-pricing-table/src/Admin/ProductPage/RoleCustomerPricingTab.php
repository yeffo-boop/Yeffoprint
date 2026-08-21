<?php namespace TierPricingTable\Admin\ProductPage;

use TierPricingTable\Core\ServiceContainerTrait;
use TierPricingTable\TierPricingTablePlugin;

class RoleCustomerPricingTab {

	use ServiceContainerTrait;

	public function __construct() {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'register' ), 99, 1 );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render' ) );
	}

	public function register( $productTabs ): array {
		if ( ! apply_filters( 'tiered_pricing_table/admin/role_customer_pricing_tab_active', false ) ) {
			return $productTabs;
		}

		$productTabs['role-customer-pricing-tab'] = array(
			'label'  => __( 'Role & Customer Prices', 'tier-pricing-table' ),
			'target' => 'role-customer-pricing-data',
			'class'  => ( function () {
				$types = array_merge( TierPricingTablePlugin::getSupportedSimpleProductTypes(),
					TierPricingTablePlugin::getSupportedVariableProductTypes() );

				$classes = array_map( function ( $type ) {
					return 'show_if_' . $type;
				}, $types );

				return $classes;
			} )(),
		);

		return $productTabs;
	}

	public function render() {
		if ( ! apply_filters( 'tiered_pricing_table/admin/role_customer_pricing_tab_active', false ) ) {
			return;
		}

		global $post;
		?>
		<div id="role-customer-pricing-data" class="panel woocommerce_options_panel">

			<?php
			if ( ! tpt_fs()->can_use_premium_code() ) {
				$this->renderUpgradeNotice();
			}

			if ( tpt_fs()->can_use_premium_code() && ! tpt_fs()->is_premium() ) {
				$this->getContainer()->getFileManager()->includeTemplate( 'admin/banners/free-version-used-premium-available.php',
					array( 'is_product' => true, ) );
			}

			do_action( 'tiered_pricing_table/admin/role_customer_pricing_tab_begin', $post->ID );
			?>

			<div class="options_group">
				<?php do_action( 'tiered_pricing_table/admin/role_customer_pricing_tab_content', $post->ID ); ?>
			</div>

			<?php do_action( 'tiered_pricing_table/admin/role_customer_pricing_tab_end', $post->ID ); ?>
		</div>
		<?php
	}

	protected function renderUpgradeNotice() {
		?>
		<div style="display: flex; align-items: center; border-bottom: 1px solid #eee; justify-content: space-between; background: #f5f5f5; padding: 15px 20px; margin: 0 0 15px;">
			<div style="display: flex; align-items: center;">
				<span style="animation: pulse-animation 2s infinite; background: var(--wp-admin-theme-color); color: #fff; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-right: 15px; display: inline-flex; align-items: center; gap: 4px;">
					<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
						<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
					</svg>
					<?php esc_html_e( 'Premium', 'tier-pricing-table' ); ?>
				</span>
				<span style="color: #3c434a; font-weight: 500;">
					<?php esc_html_e( 'Unlock Role & Customer pricing rules by upgrading to Premium.', 'tier-pricing-table' ); ?>
				</span>
			</div>
			<div>
				<a target="_blank" class="" href="<?php echo esc_attr( tpt_fs()->get_upgrade_url() ); ?>">
					<?php esc_html_e( 'Upgrade Now', 'tier-pricing-table' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

}
