<?php

namespace WooCommerce\BoxPacker\UnitTests\Original;

use WooCommerce\BoxPacker\UnitTests\Box_Packing_TestCase;

class USPS_Flat_Rate_Boxes_Test extends Box_Packing_TestCase {

	protected $library         = 'original';
	protected $carrier         = 'usps';
	protected $boxes_data_name = 'usps-flat-rate-boxes';
	protected $data            = array(
		array(
			'case'        => 'zero items should return zero packages',
			'type'        => 'priority',
			'code_prefix' => 'd',
			'items'       => array(),
			'expect'      => array(),
		),
		array(
			'case'        => 'one item of 30x30x30 should be left unpacked',
			'type'        => 'priority',
			'code_prefix' => 'd',
			'items'       => array(
				array(
					'length' => 30,
					'width'  => 30,
					'height' => 30,
					'weight' => 100,
					'qty'    => 1,
				),
			),
			'expect'      => array(
				array(
					'code'     => '',
					'unpacked' => true,
					'packed'   => array(),
				),
			),
		),
		array(
			'case'        => 'one item of 10x5x5 should be packed into one d17b',
			'type'        => 'priority',
			'code_prefix' => 'd',
			'items'       => array(
				array(
					'length' => 10,
					'width'  => 5,
					'height' => 5,
					'weight' => 20,
					'qty'    => 1,
				),
			),
			'expect'      => array(
				array(
					'code'     => 'd17b',
					'unpacked' => array(),
					'packed'   => array(
						array(
							'length' => 10,
							'width'  => 5,
							'height' => 5,
							'weight' => 20,
							'qty'    => 1,
						),
					),
				),
			),
		),
		array(
			'case'        => 'two items of 10x5x5 and 9x5x5 should be packed into one d17b',
			'type'        => 'priority',
			'code_prefix' => 'd',
			'items'       => array(
				array(
					'length' => 10,
					'width'  => 5,
					'height' => 5,
					'weight' => 19,
					'qty'    => 1,
				),
				array(
					'length' => 9,
					'width'  => 5,
					'height' => 5,
					'weight' => 20,
					'qty'    => 1,
				),
			),
			'expect'      => array(
				array(
					'code'     => 'd17b',
					'unpacked' => array(),
					'packed'   => array(
						array(
							'length' => 10,
							'width'  => 5,
							'height' => 5,
							'weight' => 19,
							'qty'    => 1,
						),
						array(
							'length' => 9,
							'width'  => 5,
							'height' => 5,
							'weight' => 20,
							'qty'    => 1,
						),
					),
				),
			),
		),
		array(
			'case'        => 'two items of 10x5x5 should be packed into one d17b',
			'type'        => 'priority',
			'code_prefix' => 'd',
			'items'       => array(
				array(
					'length' => 10,
					'width'  => 5,
					'height' => 5,
					'weight' => 19,
					'qty'    => 1,
				),
				array(
					'length' => 10,
					'width'  => 5,
					'height' => 5,
					'weight' => 20,
					'qty'    => 1,
				),
			),
			'expect'      => array(
				array(
					'code'     => 'd17b',
					'unpacked' => array(),
					'packed'   => array(
						array(
							'length' => 10,
							'width'  => 5,
							'height' => 5,
							'weight' => 20,
							'qty'    => 1,
						),
						array(
							'length' => 10,
							'width'  => 5,
							'height' => 5,
							'weight' => 19,
							'qty'    => 1,
						),
					),
				),
			),
		),
		array(
			'case'        => '3 items of 8.5x5.5x.75 should be packed into one d17b',
			'type'        => 'priority',
			'code_prefix' => 'd',
			'items'       => array(
				array(
					'length' => 8.5,
					'width'  => 5.5,
					'height' => 0.75,
					'weight' => 20,
					'qty'    => 1,
				),
				array(
					'length' => 8.5,
					'width'  => 5.5,
					'height' => 0.75,
					'weight' => 20,
					'qty'    => 1,
				),
				array(
					'length' => 8.5,
					'width'  => 5.5,
					'height' => 0.75,
					'weight' => 20,
					'qty'    => 1,
				),
			),
			'expect'      => array(
				array(
					'code'     => 'd17b',
					'unpacked' => array(),
					'packed'   => array(
						array(
							'length' => 8.5,
							'width'  => 5.5,
							'height' => 0.75,
							'weight' => 20,
						),
						array(
							'length' => 8.5,
							'width'  => 5.5,
							'height' => 0.75,
							'weight' => 20,
						),
						array(
							'length' => 8.5,
							'width'  => 5.5,
							'height' => 0.75,
							'weight' => 20,
						),
					),
				),
			),
		),
		array(
			'case'        => '1 item of 8.5x5.5x1.1 should be packed into one d17b',
			'type'        => 'priority',
			'code_prefix' => 'd',
			'items'       => array(
				array(
					'length' => 8.5,
					'width'  => 5.5,
					'height' => 1.1,
					'weight' => 20,
					'qty'    => 1,
				),
			),
			'expect'      => array(
				array(
					'code'     => 'd17b',
					'unpacked' => array(),
					'packed'   => array(
						array(
							'length' => 8.5,
							'width'  => 5.5,
							'height' => 1.1,
							'weight' => 20,
							'qty'    => 1,
						),
					),
				),
			),
		),
		array(
			'case'        => '12 items of 1.25x1.25x3.25 should be packed into one d28',
			'type'        => 'priority',
			'code_prefix' => 'd',
			'items'       => array(
				array(
					'length' => 3.25,
					'height' => 1.25,
					'width'  => 1.25,
					'weight' => 0.15625,
					'qty'    => 1,
				),
				array(
					'length' => 3.25,
					'height' => 1.25,
					'width'  => 1.25,
					'weight' => 0.15625,
					'qty'    => 1,
				),
				array(
					'length' => 3.25,
					'height' => 1.25,
					'width'  => 1.25,
					'weight' => 0.15625,
					'qty'    => 1,
				),
				array(
					'length' => 3.25,
					'height' => 1.25,
					'width'  => 1.25,
					'weight' => 0.15625,
					'qty'    => 1,
				),
				array(
					'length' => 3.25,
					'height' => 1.25,
					'width'  => 1.25,
					'weight' => 0.15625,
					'qty'    => 1,
				),
				array(
					'length' => 3.25,
					'height' => 1.25,
					'width'  => 1.25,
					'weight' => 0.15625,
					'qty'    => 1,
				),
				array(
					'length' => 3.25,
					'height' => 1.25,
					'width'  => 1.25,
					'weight' => 0.15625,
					'qty'    => 1,
				),
				array(
					'length' => 3.25,
					'height' => 1.25,
					'width'  => 1.25,
					'weight' => 0.15625,
					'qty'    => 1,
				),
				array(
					'length' => 3.25,
					'height' => 1.25,
					'width'  => 1.25,
					'weight' => 0.15625,
					'qty'    => 1,
				),
				array(
					'length' => 3.25,
					'height' => 1.25,
					'width'  => 1.25,
					'weight' => 0.15625,
					'qty'    => 1,
				),
				array(
					'length' => 3.25,
					'height' => 1.25,
					'width'  => 1.25,
					'weight' => 0.15625,
					'qty'    => 1,
				),
				array(
					'length' => 3.25,
					'height' => 1.25,
					'width'  => 1.25,
					'weight' => 0.15625,
					'qty'    => 1,
				),
			),
			'expect'      => array(
				array(
					'code'     => 'd28',
					'unpacked' => array(),
					'packed'   => array(
						array(
							'length' => 3.25,
							'height' => 1.25,
							'width'  => 1.25,
							'weight' => 0.15625,
						),
						array(
							'length' => 3.25,
							'height' => 1.25,
							'width'  => 1.25,
							'weight' => 0.15625,
						),
						array(
							'length' => 3.25,
							'height' => 1.25,
							'width'  => 1.25,
							'weight' => 0.15625,
						),
						array(
							'length' => 3.25,
							'height' => 1.25,
							'width'  => 1.25,
							'weight' => 0.15625,
						),
						array(
							'length' => 3.25,
							'height' => 1.25,
							'width'  => 1.25,
							'weight' => 0.15625,
						),
						array(
							'length' => 3.25,
							'height' => 1.25,
							'width'  => 1.25,
							'weight' => 0.15625,
						),
						array(
							'length' => 3.25,
							'height' => 1.25,
							'width'  => 1.25,
							'weight' => 0.15625,
						),
						array(
							'length' => 3.25,
							'height' => 1.25,
							'width'  => 1.25,
							'weight' => 0.15625,
						),
						array(
							'length' => 3.25,
							'height' => 1.25,
							'width'  => 1.25,
							'weight' => 0.15625,
						),
						array(
							'length' => 3.25,
							'height' => 1.25,
							'width'  => 1.25,
							'weight' => 0.15625,
						),
						array(
							'length' => 3.25,
							'height' => 1.25,
							'width'  => 1.25,
							'weight' => 0.15625,
						),
						array(
							'length' => 3.25,
							'height' => 1.25,
							'width'  => 1.25,
							'weight' => 0.15625,
						),
					),
				),
			),
		),
		array(
			'case'        => '12 items of 1.25x1.25x3.25 should be packed into one d28',
			'type'        => 'priority',
			'code_prefix' => 'd',
			'items'       => array(
				array(
					'length' => 3.25,
					'height' => 1.25,
					'width'  => 1.25,
					'weight' => 0.15625,
					'qty'    => 12,
				),
			),
			'expect'      => array(
				array(
					'code'     => 'd28',
					'unpacked' => array(),
					'packed'   => array(
						array(
							'length' => 3.25,
							'height' => 1.25,
							'width'  => 1.25,
							'weight' => 0.15625,
						),
						array(
							'length' => 3.25,
							'height' => 1.25,
							'width'  => 1.25,
							'weight' => 0.15625,
						),
						array(
							'length' => 3.25,
							'height' => 1.25,
							'width'  => 1.25,
							'weight' => 0.15625,
						),
						array(
							'length' => 3.25,
							'height' => 1.25,
							'width'  => 1.25,
							'weight' => 0.15625,
						),
						array(
							'length' => 3.25,
							'height' => 1.25,
							'width'  => 1.25,
							'weight' => 0.15625,
						),
						array(
							'length' => 3.25,
							'height' => 1.25,
							'width'  => 1.25,
							'weight' => 0.15625,
						),
						array(
							'length' => 3.25,
							'height' => 1.25,
							'width'  => 1.25,
							'weight' => 0.15625,
						),
						array(
							'length' => 3.25,
							'height' => 1.25,
							'width'  => 1.25,
							'weight' => 0.15625,
						),
						array(
							'length' => 3.25,
							'height' => 1.25,
							'width'  => 1.25,
							'weight' => 0.15625,
						),
						array(
							'length' => 3.25,
							'height' => 1.25,
							'width'  => 1.25,
							'weight' => 0.15625,
						),
						array(
							'length' => 3.25,
							'height' => 1.25,
							'width'  => 1.25,
							'weight' => 0.15625,
						),
						array(
							'length' => 3.25,
							'height' => 1.25,
							'width'  => 1.25,
							'weight' => 0.15625,
						),
					),
				),
			),
		),
	);

}
