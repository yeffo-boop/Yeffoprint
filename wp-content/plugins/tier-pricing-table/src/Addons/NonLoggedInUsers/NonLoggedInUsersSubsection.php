<?php namespace TierPricingTable\Addons\NonLoggedInUsers;

use TierPricingTable\Settings\CustomOptions\TPTSwitchOption;
use TierPricingTable\Settings\CustomOptions\TPTTextTemplate;
use TierPricingTable\Settings\Sections\SubsectionAbstract;
use TierPricingTable\Settings\Settings;

class NonLoggedInUsersSubsection extends SubsectionAbstract {
	
	public function getTitle(): string {
		return __( 'Guest Users', 'tier-pricing-table' );
	}
	
	public function getDescription(): string {
		return __( 'Manage pricing visibility and purchasing rules for guest (non-logged-in) users.',
			'tier-pricing-table' );
	}
	
	public function getSlug(): string {
		return 'non-logged-in-users';
	}
	
	public function getSettings(): array {
		return array(
			array(
				'title'    => __( 'Require login to purchase', 'tier-pricing-table' ),
				'id'       => Settings::SETTINGS_PREFIX . 'non_logged_in_users_prevent_purchase',
				'type'     => TPTSwitchOption::FIELD_TYPE,
				'default'  => 'no',
				'desc_tip' => false,
			),
			
			array(
				'title'       => __( 'Guest add-to-cart label', 'tier-pricing-table' ),
				'id'          => Settings::SETTINGS_PREFIX . 'non_logged_in_users_add_to_cart_label',
				'type'        => 'text',
				'default'     => '',
				'desc'        => __( 'Change the default "Add to cart" button text for guest users.',
					'tier-pricing-table' ),
				'placeholder' => __( 'Leave empty to keep default', 'tier-pricing-table' ),
				'desc_tip'    => false,
			),
			
			array(
				'title'        => __( 'Guest purchase error message', 'tier-pricing-table' ),
				'id'           => Settings::SETTINGS_PREFIX . 'non_logged_in_users_purchase_message',
				'type'         => TPTTextTemplate::FIELD_TYPE,
				'placeholders' => array(),
				// translators: %s: login page url
				'default'      => sprintf( __( 'Please enter %s to make a purchase.', 'tier-pricing-table' ),
					sprintf( '<a href="%s">%s</a>', wc_get_account_endpoint_url( 'dashboard' ),
						__( 'your account', 'tier-pricing-table' ) ) ),
			),
			
			array(
				'title'    => __( 'Hide prices for guests', 'tier-pricing-table' ),
				'id'       => Settings::SETTINGS_PREFIX . 'non_logged_in_users_hide_prices',
				'type'     => TPTSwitchOption::FIELD_TYPE,
				'default'  => 'no',
				'desc_tip' => false,
			),
			array(
				'title'        => __( 'Guest price message', 'tier-pricing-table' ),
				'id'           => Settings::SETTINGS_PREFIX . 'non_logged_in_users_price_message',
				'type'         => TPTTextTemplate::FIELD_TYPE,
				'description'  => __( 'This message will be displayed instead of the price for non-logged-in users.',
					'tier-pricing-table' ),
				'placeholders' => array(),
				// translators: %s: login page url
				'default'      => sprintf( __( '%s see prices', 'tier-pricing-table' ),
					sprintf( '<a href="%s">%s</a>', wc_get_account_endpoint_url( 'dashboard' ),
						__( 'Login', 'tier-pricing-table' ) ) ),
			),
		);
	}
}
