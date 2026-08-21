<?php

namespace WooCommerce\BoxPacker\UnitTests\Original;

use WooCommerce\BoxPacker\UnitTests\Box_Packing_TestCase;

class Australia_Post_Box_Test extends Box_Packing_TestCase {

	protected $library         = 'original';
	protected $carrier         = 'australia-post-boxes';
	protected $boxes_data_name = 'australia-post-boxes';
	protected $data            = array(
		array(
			'case'        => '2 items of 8.5x6x1.25 should be packed into one AUS_PARCEL_REGULAR_SATCHEL_SMALL',
			'type'        => false,
			'code_prefix' => false,
			'items'       => array(
				array(
					'length' => 8.5,
					'width'  => 6,
					'height' => 1.25,
					'weight' => 1,
					'qty'    => 1,
				),
				array(
					'length' => 8.5,
					'width'  => 6,
					'height' => 1.25,
					'weight' => 1,
					'qty'    => 1,
				),
			),
			'expect'      => array(
				array(
					'code'     => 'AUS_PARCEL_REGULAR_SATCHEL_SMALL',
					'unpacked' => array(),
					'packed'   => array(
						array(
							'length' => 8.5,
							'width'  => 6,
							'height' => 1.25,
							'weight' => 1,
						),
						array(
							'length' => 8.5,
							'width'  => 6,
							'height' => 1.25,
							'weight' => 1,
						),
					),
				),
			),
		),
		array(
			'case'        => '2 items of 8.5x6x1.25 should be packed into one AUS_PARCEL_REGULAR_SATCHEL_SMALL',
			'type'        => false,
			'code_prefix' => false,
			'items'       => array(
				array(
					'length' => 8.5,
					'width'  => 6,
					'height' => 1.25,
					'weight' => 1,
					'qty'    => 2,
				),
			),
			'expect'      => array(
				array(
					'code'     => 'AUS_PARCEL_REGULAR_SATCHEL_SMALL',
					'unpacked' => array(),
					'packed'   => array(
						array(
							'length' => 8.5,
							'width'  => 6,
							'height' => 1.25,
							'weight' => 1,
						),
						array(
							'length' => 8.5,
							'width'  => 6,
							'height' => 1.25,
							'weight' => 1,
						),
					),
				),
			),
		),
		array(
			'case'        => '2 items of 8.5x6x1.45 should be packed into one AUS_PARCEL_REGULAR_SATCHEL_SMALL',
			'type'        => false,
			'code_prefix' => false,
			'items'       => array(
				array(
					'length' => 8.5,
					'width'  => 6,
					'height' => 1.45,
					'weight' => 1,
					'qty'    => 1,
				),
				array(
					'length' => 8.5,
					'width'  => 6,
					'height' => 1.45,
					'weight' => 1,
					'qty'    => 1,
				),
			),
			'expect'      => array(
				array(
					'code'     => 'AUS_PARCEL_REGULAR_SATCHEL_SMALL',
					'unpacked' => array(),
					'packed'   => array(
						array(
							'length' => 8.5,
							'width'  => 6,
							'height' => 1.45,
							'weight' => 1,
						),
						array(
							'length' => 8.5,
							'width'  => 6,
							'height' => 1.45,
							'weight' => 1,
						),
					),
				),
			),
		),
		array(
			'case'        => '2 items of 8.5x6x1.45 should be packed into one AUS_PARCEL_REGULAR_SATCHEL_SMALL',
			'type'        => false,
			'code_prefix' => false,
			'items'       => array(
				array(
					'length' => 8.5,
					'width'  => 6,
					'height' => 1.45,
					'weight' => 1,
					'qty'    => 2,
				),
			),
			'expect'      => array(
				array(
					'code'     => 'AUS_PARCEL_REGULAR_SATCHEL_SMALL',
					'unpacked' => array(),
					'packed'   => array(
						array(
							'length' => 8.5,
							'width'  => 6,
							'height' => 1.45,
							'weight' => 1,
						),
						array(
							'length' => 8.5,
							'width'  => 6,
							'height' => 1.45,
							'weight' => 1,
						),
					),
				),
			),
		),
	);
}
