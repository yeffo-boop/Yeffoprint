<?php namespace TierPricingTable\Forms;

use TierPricingTable\Core\ServiceContainer;

class MinimumOrderQuantityForm {

	public static function render(
		$role = null,
		$loop = null,
		$minimumOrderQuantity = null
	) {
		ServiceContainer::getInstance()->getFileManager()->includeTemplate( 'admin/components/minimum-order-quantity-form.php',
			array(
				'role'                   => $role,
				'loop'                   => $loop,
				'minimum_order_quantity' => $minimumOrderQuantity,
			) );
	}

	public static function getDataFromRequest( $role = null, $loop = null, $request = null ) {
		
		$data = array();

		$fields = array(
			'minimum_order_quantity' => function ( $quantity ) {
				return ! is_null( $quantity ) && '' !== $quantity ? max( 1, intval( $quantity ) ) : null;
			},
		);

		foreach ( $fields as $fieldKey => $sanitizeFunction ) {
			$data[ $fieldKey ] = call_user_func( $sanitizeFunction,
				Form::getFieldValue( $fieldKey, $role, $loop, null, $request ) );

		}

		return $data;
	}
}
