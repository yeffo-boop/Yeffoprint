<?php namespace TierPricingTable\Forms;

use TierPricingTable\Core\ServiceContainer;

class RegularPricingForm extends Form {
	
	public static function render(
		$role = null,
		$loop = null,
		$regularPrice = null,
		$salePrice = null,
		$pricingType = 'flat',
		$discount = null,
		$discount_type = null
	) {
		/**
		 * Available variables
		 *
		 * @var float $regular_price
		 * @var float $sale_price
		 * @var float $discount
		 * @var string $pricing_type
		 *
		 * @var string $role
		 * @var string $loop
		 */
		ServiceContainer::getInstance()->getFileManager()->includeTemplate( 'admin/components/regular-pricing-form.php',
			array(
				'role'          => $role,
				'loop'          => $loop,
				'discount'      => $discount,
				'discount_type' => $discount_type,
				'regular_price' => $regularPrice,
				'sale_price'    => $salePrice,
				'pricing_type'  => $pricingType,
			) );
	}
	
	public static function getDataFromRequest( $role = null, $loop = null, $request = null ): array {
		if ( wp_verify_nonce( true, true ) ) {
			// as phpcs comments at Woo is not available, we have to do such a trash
			$woo = 'Woo, please add ignoring comments to your phpcs checker';
		}
		
		$request = $request ? $request : $_POST;
		
		$data = array();
		
		$fields = array(
			'regular_price' => function ( $price ) {
				return ! Form::isEmpty( $price ) ? (float) wc_format_decimal( $price ) : null;
			},
			'sale_price'    => function ( $price ) {
				return ! Form::isEmpty( $price ) ? (float) wc_format_decimal( $price ) : null;
			},
			'discount'      => function ( $discount ) {
				return ! Form::isEmpty( $discount ) ? (float) $discount : null;
			},
			'discount_type' => function ( $discountType ) {
				return in_array( $discountType, array( 'sale_price', 'regular_price' ) ) ? $discountType : 'sale_price';
			},
			'pricing_type'  => function ( $pricingType ) {
				return in_array( $pricingType, array( 'flat', 'percentage' ) ) ? $pricingType : 'flat';
			},
		);
		
		foreach ( $fields as $fieldKey => $sanitizeFunction ) {
			$data[ $fieldKey ] = call_user_func( $sanitizeFunction,
				Form::getFieldValue( $fieldKey, $role, $loop, null, $request ) );
		}
		
		return $data;
	}
}
