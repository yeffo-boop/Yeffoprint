<?php namespace TierPricingTable\Addons\GlobalTieredPricing\CPT\Form\Tabs;

use TierPricingTable\Addons\GlobalTieredPricing\CPT\Form\FormTab;
use TierPricingTable\Addons\GlobalTieredPricing\Formatter;
use TierPricingTable\Addons\GlobalTieredPricing\GlobalPricingRule;
use TierPricingTable\Core\ServiceContainer;

class UsersAndRoles extends FormTab {

	public function getId(): string {
		return 'user-and-roles';
	}

	public function getTitle(): string {
		return __( 'Users & Roles', 'tier-pricing-table' );
	}

	public function getDescription(): string {
		return __( 'Select the users or roles this rule applies to', 'tier-pricing-table' );
	}

	public function render( GlobalPricingRule $pricingRule ) {

		$this->renderSectionTitle( __( 'Applies to users', 'tier-pricing-table' ) );

		if ( empty( $pricingRule->getIncludedUserRoles() ) && empty( $pricingRule->getIncludedUsers() ) ) {
			$this->renderHint( __( 'The rule will apply to all users if you do not specify user roles or specific customers (excluding those selected in the exclusions section).',
					'tier-pricing-table' ) );
		}

		$this->renderSelect2( array(
				'id'            => 'tpt_included_user_roles',
				'label'         => __( 'User roles', 'tier-pricing-table' ),
				'options'       => ( function () {
					return array_map( function ( $WPRole ) {
						return $WPRole['name'];
					}, wp_roles()->roles );
				} )(),
				'value'         => $pricingRule->getIncludedUserRoles(),
				'placeholder'   => __( 'Select for a user role', 'tier-pricing-table' ),
				'search_action' => 'woocommerce_json_search_tpt_user_roles',
				'css_class'     => 'tpt-select-woo',
				'desc_tip'      => false,
				'description'   => function () {
					$settingsLink = ServiceContainer::getInstance()->getSettings()->getLink();

					$settingsLink = add_query_arg( [
							'section' => 'tools',
					], $settingsLink );

					$settingsLink .= '#roles';

					?>
					<a href="<?php echo esc_url( $settingsLink ); ?>"
					   target="_blank"><?php esc_html_e( 'Manage user roles', 'tier-pricing-table' ); ?></a>
					<?php
				},
		) );


		$this->renderSelect2( array(
				'id'            => 'tpt_included_users',
				'label'         => __( 'Customers', 'tier-pricing-table' ),
				'options'       => ( function () use ( $pricingRule ) {
					$users = [];
					foreach ( $pricingRule->getIncludedUsers() as $userId ) {
						$customer = new \WC_Customer( $userId );

						if ( $customer->get_id() ) {
							$users[ $userId ] = Formatter::formatCustomerString( $customer );
						}
					}

					return $users;
				} )(),
				'value'         => $pricingRule->getIncludedUsers(),
				'placeholder'   => __( 'Select for a customer', 'tier-pricing-table' ),
				'search_action' => 'woocommerce_json_search_tpt_customers',
				'css_class'     => 'rbp-select-woo wc-product-search',
		) );

		$hasExclusions = ! empty( $pricingRule->getExcludedUserRoles() ) ||
		                 ! empty( $pricingRule->getExcludedUsers() );
		?>
		<details class="tpt-exclusions-accordion" <?php echo $hasExclusions ? 'open' : ''; ?>>
			<summary>
				<?php echo esc_html__( 'Exclusions', 'tier-pricing-table' ); ?>
				<span class="dashicons dashicons-arrow-down-alt2 tpt-accordion-icon"></span>
			</summary>
			<div class="tpt-exclusions-accordion-content">
		<?php

		$this->renderSelect2( array(
				'id'            => 'tpt_excluded_user_roles',
				'label'         => __( 'User roles', 'tier-pricing-table' ),
				'options'       => ( function () {
					$roles = [];
					foreach ( wp_roles()->roles as $key => $WPRole ) {
						$roles[ $key ] = $WPRole['name'];
					}

					return $roles;
				} )(),
				'value'         => $pricingRule->getExcludedUserRoles(),
				'placeholder'   => __( 'Select for a user role', 'tier-pricing-table' ),
				'search_action' => 'woocommerce_json_search_tpt_user_roles',
				'css_class'     => 'tpt-select-woo',
		) );

		$this->renderSelect2( array(
				'id'            => 'tpt_excluded_users',
				'label'         => __( 'Customers', 'tier-pricing-table' ),
				'options'       => ( function () use ( $pricingRule ) {
					$users = [];
					foreach ( $pricingRule->getExcludedUsers() as $userId ) {
						$customer = new \WC_Customer( $userId );

						if ( $customer->get_id() ) {
							$users[ $userId ] = Formatter::formatCustomerString( $customer );
						}
					}

					return $users;
				} )(),
				'value'         => $pricingRule->getExcludedUsers(),
				'placeholder'   => __( 'Select for a customer', 'tier-pricing-table' ),
				'search_action' => 'woocommerce_json_search_tpt_customers',
				'css_class'     => 'rbp-select-woo wc-product-search',
		) );

		?>
			</div>
		</details>
		<?php
	}

	public function getIcon(): string {
		return 'dashicons-admin-users';
	}
}
