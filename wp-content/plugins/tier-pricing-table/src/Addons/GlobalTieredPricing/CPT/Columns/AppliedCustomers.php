<?php namespace TierPricingTable\Addons\GlobalTieredPricing\CPT\Columns;

use Exception;
use TierPricingTable\Addons\GlobalTieredPricing\GlobalPricingRule;
use TierPricingTable\Addons\GlobalTieredPricing\Formatter;
use WC_Customer;

class AppliedCustomers {
	
	public function getName(): string {
		return __( 'Users & Roles', 'tier-pricing-table' );
	}
	
	public function render( GlobalPricingRule $rule ) {
		
		$hasCustomers = $this->showCustomers( $rule->getIncludedUsers() );
		$hasRoles     = $this->showUserRoles( $rule->getIncludedUserRoles() );
		
		$excludedUsers = $rule->getExcludedUsers();
		$excludedRoles = $rule->getExcludedUserRoles();
		$hasExceptions = ! empty( $excludedUsers ) || ! empty( $excludedRoles );
		
		if ( ! $hasRoles && ! $hasCustomers ) {
			$badgeText = $hasExceptions ? __( 'Applied to every user', 'tier-pricing-table' ) : __( 'Applied to every user', 'tier-pricing-table' );
			?>
			<span style="display: inline-block; background: #e0f0fa; color: #0070bc; border: 1px solid #bae0ff; padding: 4px 10px; border-radius: 4px; font-size: 13px; font-weight: 500; margin-bottom: 12px; line-height: 1.4;">
				<?php echo esc_html( $badgeText ); ?>
			</span>
			<?php
		}
	
		$this->showCustomers( $excludedUsers, false );
		$this->showUserRoles( $excludedRoles, false );
	}
	
	public function showCustomers( array $customersIds, $included = true ): bool {
		$customersMoreThanCanBeShown = count( $customersIds ) > 10;
		
		$customersIds = array_slice( $customersIds, 0, 5 );
		
		$customers = array_filter( array_map( function ( $customerId ) {
			try {
				return new WC_Customer( $customerId );
			} catch ( Exception $e ) {
				return false;
			}
		}, $customersIds ) );
		
		if ( ! empty( $customers ) ) {
			$title = $included ? __( 'Customers', 'tier-pricing-table' ) : __( 'Excluded Customers', 'tier-pricing-table' );
			
			echo '<div style="margin-bottom: 12px;">';
			echo sprintf('<strong style="display: block; margin-bottom: 4px;">%s:</strong>', esc_html($title));
			echo '<div style="display: flex; flex-wrap: wrap; gap: 4px;">';
			
			foreach ($customers as $customer) {
				echo sprintf(
					'<span style="display: inline-block; padding: 2px 8px; background: #f0f0f1; border-radius: 3px; font-size: 12px; color: #3c434a; border: 1px solid #dcdcdc; line-height: 1.4;">%s</span>',
					Formatter::formatCustomerString( $customer, true )
				);
			}
			
			if ( $customersMoreThanCanBeShown ) {
				echo '<span style="display: inline-block; padding: 2px 8px; background: #f0f0f1; border-radius: 3px; font-size: 12px; color: #8c8f94; border: 1px solid #dcdcdc; line-height: 1.4;">...</span>';
			}
			
			echo '</div></div>';
			
			return true;
		}
		
		return false;
	}
	
	public function showUserRoles( array $roles, $included = true ): bool {
		
		if ( ! empty( $roles ) ) {
			$title = $included ? __( 'Roles', 'tier-pricing-table' ) : __( 'Excluded Roles', 'tier-pricing-table' );
			
			echo '<div style="margin-bottom: 12px;">';
			echo sprintf('<strong style="display: block; margin-bottom: 4px;">%s:</strong>', esc_html($title));
			echo '<div style="display: flex; flex-wrap: wrap; gap: 4px;">';
			
			foreach ($roles as $role) {
				echo sprintf(
					'<span style="display: inline-block; padding: 2px 8px; background: #f0f0f1; border-radius: 3px; font-size: 12px; color: #3c434a; border: 1px solid #dcdcdc; line-height: 1.4;">%s</span>',
					Formatter::formatRoleString( $role )
				);
			}
			
			echo '</div></div>';
			
			return true;
		}
		
		return false;
	}
}
