<?php namespace TierPricingTable\Admin\Tips\Tips;

use TierPricingTable\Admin\Tips\Tip;

/**
 * Class RoleCustomerPricingTabTip
 *
 * @package TierPricingTable\Admin\Tips\Tips
 */
class RoleCustomerPricingTabTip extends Tip {
	
	public function getSlug(): string {
		return 'role_customer_pricing_tab_tip';
	}
	
	public function __construct() {
		parent::__construct();
		
		add_action( 'tiered_pricing_table/admin/role_customer_pricing_tab_end', array( $this, 'render' ), 10, 1 );
	}
	
	public function render() {
		
		if ( $this->isSeen() ) {
			return;
		}
		
		$toolsUrl = admin_url( 'admin.php?page=wc-settings&tab=tiered_pricing_table_settings&section=tools' );
		
		?>
		<div class="tiered-pricing-tip"
			 style="margin: 12px; padding: 10px; background: #fafafa; border: 1px solid #eeeeee; display: flex; gap: 10px; justify-content: space-between">
			<div style="display:flex; gap: 10px; ">
				<div style="color: #2272b1; margin: 0 5px;">
					<span class="dashicons dashicons-admin-post"></span>
				</div>
				<div>
					<strong>
						<?php esc_html_e( 'Tip', 'tier-pricing-table' ); ?>:
					</strong>
					
					<?php 
					esc_html_e( 'Need to add or modify user roles?', 'tier-pricing-table' ); 
					?>

					<div style="margin-top: 10px;">
						<?php 
						printf(
						// translators: %s: URL to the Role Manager.
							wp_kses_post( __( 'You can create, edit, or manage user roles directly from the <a href="%s" target="_blank">Role Manager</a>.', 'tier-pricing-table' ) ),
							esc_url( $toolsUrl )
						);
						?>
					</div>
					
				</div>
			</div>

			<div style="white-space: nowrap; ">
				<a role="button" href="<?php echo esc_attr( $this->getMarkAsSeenURL() ); ?>"
				   class="tiered-pricing-tip-close-button">
					&times; <?php esc_html_e( 'Hide this tip', 'tier-pricing-table' ); ?>
				</a>
			</div>
		</div>
		<?php
		
	}
}
