<?php

namespace WooCommerce\BoxPacker\UnitTests\Original;

use WooCommerce\BoxPacker\UnitTests\Box_Packing_TestCase;

class UPS_Test extends Box_Packing_TestCase {

	protected $library         = 'original';
	protected $carrier         = 'ups';
	protected $boxes_data_name = 'ups-boxes';
	protected $data            = array(
		array(
			'case'        => '1 item of 16x13x10 should be packed into one 10KG Box',
			'type'        => false,
			'code_prefix' => false,
			'items'       => array(
				array(
					'length' => 16.5,
					'width'  => 13.25,
					'height' => 10.75,
					'weight' => 22.0462,
					'qty'    => 1,
				),
			),
			'expect'      => array(
				array(
					'code'     => '25',
					'unpacked' => array(),
					'packed'   => array(
						array(
							'length' => 16.5,
							'width'  => 13.25,
							'height' => 10.75,
							'weight' => 22.0462,
						),
					),
				),
			),
		),
		array(
			'case'        => '2 items of 11.7x10x10 should be packed into one 10KG Box',
			'type'        => false,
			'code_prefix' => false,
			'items'       => array(
				array(
					'length' => 11.7,
					'width'  => 10,
					'height' => 10,
					'weight' => 10,
					'qty'    => 1,
				),
				array(
					'length' => 11.7,
					'width'  => 10,
					'height' => 10,
					'weight' => 10,
					'qty'    => 1,
				),
			),
			'expect'      => array(
				array(
					'code'     => '25',
					'unpacked' => array(),
					'packed'   => array(
						array(
							'length' => 11.7,
							'width'  => 10,
							'height' => 10,
							'weight' => 10,
						),
						array(
							'length' => 11.7,
							'width'  => 10,
							'height' => 10,
							'weight' => 10,
						),
					),
				),
			),
		),
		array(
			'case'        => '2 items of 11.7x10x10 should be packed into one 10KG Box',
			'type'        => false,
			'code_prefix' => false,
			'items'       => array(
				array(
					'length' => 11.7,
					'width'  => 10,
					'height' => 10,
					'weight' => 10,
					'qty'    => 2,
				),
			),
			'expect'      => array(
				array(
					'code'     => '25',
					'unpacked' => array(),
					'packed'   => array(
						array(
							'length' => 11.7,
							'width'  => 10,
							'height' => 10,
							'weight' => 10,
						),
						array(
							'length' => 11.7,
							'width'  => 10,
							'height' => 10,
							'weight' => 10,
						),
					),
				),
			),
		),
	);

}
