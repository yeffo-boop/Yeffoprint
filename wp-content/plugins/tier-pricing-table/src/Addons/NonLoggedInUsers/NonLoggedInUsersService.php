<?php namespace TierPricingTable\Addons\NonLoggedInUsers;

use TierPricingTable\Core\ServiceContainerTrait;
use TierPricingTable\Settings\Settings;

/**
 * Class NonLoggedInUsersService
 *
 * Handle non-logged-in users prices and purchase ability.
 *
 * @package TierPricingTable\Addons\NonLoggedInUsers
 */
class NonLoggedInUsersService {
	
	use ServiceContainerTrait;
	
	/**
	 * NonLoggedInUsersService constructor.
	 */
	public function __construct() {
		
		if ( is_user_logged_in() ) {
			return;
		}
		
		if ( $this->isHidePrices() ) {
			
			add_filter( 'woocommerce_get_price_html', function () {
				return '<div class="tpt-hidden-price price"><span>' . $this->getPriceMessage() . '</span></div>';
			}, 9999 );
			
			add_filter( 'tiered_pricing_table/should_render_pricing_table', '__return_false' );
		}
		
		
		if ( $this->isPreventPurchase() ) {
			
			add_filter( 'woocommerce_add_to_cart_validation', function ( $valid ) {
				
				if ( $valid ) {
					wc_add_notice( $this->getPurchaseMessage(), 'error' );
				}
				
				return false;
			} );
		}
		
		$label = $this->getAddToCartLabel();
		
		if ( $label && $this->isPreventPurchase() ) {
			add_filter( 'woocommerce_product_single_add_to_cart_text', function () use ( $label ) {
				return $label;
			} );
			
			add_filter( 'woocommerce_product_add_to_cart_text', function () use ( $label ) {
				return $label;
			} );
		}
		
	}
	
	public function isPreventPurchase(): bool {
		return get_option( Settings::SETTINGS_PREFIX . 'non_logged_in_users_prevent_purchase', 'no' ) === 'yes';
	}
	
	public function getAddToCartLabel(): string {
		return get_option( Settings::SETTINGS_PREFIX . 'non_logged_in_users_add_to_cart_label', '' );
	}
	
	public function getPurchaseMessage(): string {
		// translators: %s: account page link
		$default = sprintf( __( 'Please enter %s to make a purchase', 'tier-pricing-table' ),
			sprintf( '<a href="%s">%s</a>', wc_get_account_endpoint_url( 'dashboard' ),
				__( 'your account', 'tier-pricing-table' ) ) );
		
		return get_option( Settings::SETTINGS_PREFIX . 'non_logged_in_users_purchase_message', $default );
	}
	
	public function isHidePrices(): bool {
		return get_option( Settings::SETTINGS_PREFIX . 'non_logged_in_users_hide_prices', 'no' ) === 'yes';
	}
	
	public function getPriceMessage(): string {
		// translators: %s: login page url
		$default = sprintf( __( '%s see prices', 'tier-pricing-table' ),
			sprintf( '<a href="%s">%s</a>', wc_get_account_endpoint_url( 'dashboard' ),
				__( 'Login', 'tier-pricing-table' ) ) );
		
		return get_option( Settings::SETTINGS_PREFIX . 'non_logged_in_users_price_message', $default );
	}
}
