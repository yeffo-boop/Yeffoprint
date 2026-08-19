<?php

namespace WooCommerce\BoxPacker\UnitTests\Original;

use WooCommerce\BoxPacker\UnitTests\Box_Packing_TestCase;

class FedEx_Test extends Box_Packing_TestCase {

	protected $library         = 'original';
	protected $carrier         = 'fedex';
	protected $boxes_data_name = 'fedex-boxes';
	protected $data            = array(
		array(
			'case'        => 'one item of 10x5x5 should be packed into one FEDEX_LARGE_BOX:2',
			'type'        => false,
			'code_prefix' => false,
			'items'       => array(
				array(
					'length' => 10,
					'width'  => 5,
					'height' => 5,
					'weight' => 10,
					'qty'    => 1,
				),
			),
			'expect'      => array(
				array(
					'code'     => 'FEDEX_LARGE_BOX:2',
					'unpacked' => array(),
					'packed'   => array(
						array(
							'length' => 10,
							'width'  => 5,
							'height' => 5,
							'weight' => 10,
						),
					),
				),
			),
		),
		array(
			'case'        => 'two items of 10x5x5 should be packed into one FEDEX_LARGE_BOX:2',
			'type'        => false,
			'code_prefix' => false,
			'items'       => array(
				array(
					'length' => 10,
					'width'  => 5,
					'height' => 5,
					'weight' => 10,
					'qty'    => 1,
				),
				array(
					'length' => 10,
					'width'  => 5,
					'height' => 5,
					'weight' => 10,
					'qty'    => 1,
				),
			),
			'expect'      => array(
				array(
					'code'     => 'FEDEX_LARGE_BOX:2',
					'unpacked' => array(),
					'packed'   => array(
						array(
							'length' => 10,
							'width'  => 5,
							'height' => 5,
							'weight' => 10,
						),
						array(
							'length' => 10,
							'width'  => 5,
							'height' => 5,
							'weight' => 10,
						),
					),
				),
			),
		),
		array(
			'case'        => 'two items of 10x5x5 should be packed into one FEDEX_LARGE_BOX:2',
			'type'        => false,
			'code_prefix' => false,
			'items'       => array(
				array(
					'length' => 10,
					'width'  => 5,
					'height' => 5,
					'weight' => 10,
					'qty'    => 2,
				),
			),
			'expect'      => array(
				array(
					'code'     => 'FEDEX_LARGE_BOX:2',
					'unpacked' => array(),
					'packed'   => array(
						array(
							'length' => 10,
							'width'  => 5,
							'height' => 5,
							'weight' => 10,
						),
						array(
							'length' => 10,
							'width'  => 5,
							'height' => 5,
							'weight' => 10,
						),
					),
				),
			),
		),
		array(
			'case'        => 'one item of 12x2.5x2.5 should be packed into one FEDEX_PAK:4',
			'options'     => array( 'prefer_packets' => true ),
			'type'        => false,
			'code_prefix' => false,
			'items'       => array(
				array(
					'length' => 12,
					'width'  => 2.5,
					'height' => 2.5,
					'weight' => 0.6,
					'qty'    => 1,
				),
			),
			'expect'      => array(
				array(
					'code'     => 'FEDEX_PAK:4',
					'unpacked' => array(),
					'packed'   => array(
						array(
							'length' => 12,
							'width'  => 2.5,
							'height' => 2.5,
							'weight' => 0.6,
						),
					),
				),
			),
		),
	);

}
