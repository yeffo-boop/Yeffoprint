<?php namespace TierPricingTable\Addons\Coupons;

use TierPricingTable\Addons\AbstractAddon;
use WC_Cart;

class CouponsAddon extends AbstractAddon {
	
	public function getName(): string {
		return __( 'Coupons management', 'tier-pricing-table' );
	}
	
	public function run() {
		add_action( 'woocommerce_coupon_options', array( $this, 'addTieredPricingOption' ), 10, 2 );
		add_action( 'woocommerce_coupon_options_save', array( $this, 'saveTieredPricingOption' ), 10, 2 );
		
		add_action( 'tiered_pricing_table/cart/need_price_recalculation', array(
			$this,
			'checkAppliedCoupons',
		), 999, 3 );
		
		add_action( 'tiered_pricing_table/cart/need_price_recalculation/item', array(
			$this,
			'checkAppliedCoupons',
		), 999, 3 );
	}
	
	public function checkAppliedCoupons( $recalculate, $item, $cart = null ) {
		
		$cart = $cart instanceof WC_Cart ? $cart : wc()->cart;
		
		if ( ! $cart ) {
			return $recalculate;
		}
		
		$coupons = $cart->get_coupons();
		
		if ( empty( $coupons ) ) {
			return $recalculate;
		}
		
		foreach ( $coupons as $coupon ) {
			
			if ( ! $coupon instanceof \WC_Coupon ) {
				continue;
			}
			
			if ( ! $coupon->is_valid_for_product( $item['data'] ) ) {
				continue;
			}
			
			if ( get_post_meta( $coupon->get_id(), '_disable_tiered_pricing', true ) === 'yes' ) {
				return false;
			}
		}
		
		return $recalculate;
	}
	
	public function saveTieredPricingOption( $couponId ) {
		update_post_meta( $couponId, '_disable_tiered_pricing',
			isset( $_REQUEST['_disable_tiered_pricing'] ) ? 'yes' : 'no' );
	}
	
	public function addTieredPricingOption( $couponId, $coupon ) {
		
		woocommerce_wp_checkbox( array(
			'id'          => '_disable_tiered_pricing',
			'label'       => __( 'Disable tiered pricing when the coupon is applied', 'woocommerce' ),
			'description' => __( 'Check this option to don\'t  apply tiered pricing in the cart when users have applied this coupon.',
				'tier-pricing-table' ),
			'value'       => get_post_meta( $couponId, '_disable_tiered_pricing', true ),
		) );
	}
	
	public function getDescription(): string {
		return __( 'Disable tiered pricing for specific coupons.', 'tier-pricing-table' );
	}
	
	public function getIcon(): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M21.41 11.58l-9-9C12.05 2.22 11.55 2 11 2H4c-1.1 0-2 .9-2 2v7c0 .55.22 1.05.59 1.42l9 9c.36.36.86.58 1.41.58.55 0 1.05-.22 1.41-.59l7-7c.37-.36.59-.86.59-1.41 0-.55-.23-1.06-.59-1.42zM5.5 7C4.67 7 4 6.33 4 5.5S4.67 4 5.5 4 7 4.67 7 5.5 6.33 7 5.5 7z"/></svg>';
	}
	
	public function getSlug(): string {
		return 'coupons';
	}
}
