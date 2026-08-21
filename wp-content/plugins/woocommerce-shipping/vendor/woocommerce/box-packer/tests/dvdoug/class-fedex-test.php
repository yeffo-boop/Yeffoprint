<?php

namespace WooCommerce\BoxPacker\UnitTests\DVDoug;

use WooCommerce\BoxPacker\UnitTests\Box_Packing_TestCase;

class FedEx_Test extends Box_Packing_TestCase {

	protected $library         = 'dvdoug';
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
			'case'        => 'two items of 10x5x5 should be packed into one FEDEX_EXTRA_LARGE_BOX:2',
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
					'code'     => 'FEDEX_EXTRA_LARGE_BOX:2',
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
			'case'        => 'two items of 10x5x5 should be packed into one FEDEX_EXTRA_LARGE_BOX:2',
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
					'code'     => 'FEDEX_EXTRA_LARGE_BOX:2',
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
			'case'        => 'three items of 8.2x4.6x0.15748 should be packed into one FEDEX_PAK:2',
			'type'        => false,
			'code_prefix' => false,
			'items'       => array(
				array(
					'length' => 8.2,
					'width'  => 4.6,
					'height' => 0.14,
					'weight' => 1,
					'qty'    => 1,
				),
				array(
					'length' => 8.2,
					'width'  => 4.6,
					'height' => 0.14,
					'weight' => 1,
					'qty'    => 1,
				),
				array(
					'length' => 8.2,
					'width'  => 4.6,
					'height' => 0.14,
					'weight' => 1,
					'qty'    => 1,
				),
			),
			'expect'      => array(
				array(
					'code'     => 'FEDEX_PAK:2',
					'unpacked' => array(),
					'packed'   => array(
						array(
							'length' => 8.2,
							'width'  => 4.6,
							'height' => 0.14,
							'weight' => 1,
						),
						array(
							'length' => 8.2,
							'width'  => 4.6,
							'height' => 0.14,
							'weight' => 1,
						),
						array(
							'length' => 8.2,
							'width'  => 4.6,
							'height' => 0.14,
							'weight' => 1,
						),
					),
				),
			),
		),
		array(
			'case'        => 'three items of 8.2x4.6x0.15748 should be packed into one FEDEX_PAK:2',
			'type'        => false,
			'code_prefix' => false,
			'items'       => array(
				array(
					'length' => 8.2,
					'width'  => 4.6,
					'height' => 0.14,
					'weight' => 1,
					'qty'    => 3,
				),
			),
			'expect'      => array(
				array(
					'code'     => 'FEDEX_PAK:2',
					'unpacked' => array(),
					'packed'   => array(
						array(
							'length' => 8.2,
							'width'  => 4.6,
							'height' => 0.14,
							'weight' => 1,
						),
						array(
							'length' => 8.2,
							'width'  => 4.6,
							'height' => 0.14,
							'weight' => 1,
						),
						array(
							'length' => 8.2,
							'width'  => 4.6,
							'height' => 0.14,
							'weight' => 1,
						),
					),
				),
			),
		),
		array(
			'case'        => 'one item of 12x2.5x2.5 should be packed into one FEDEX_LARGE_BOX',
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
					'code'     => 'FEDEX_LARGE_BOX',
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
