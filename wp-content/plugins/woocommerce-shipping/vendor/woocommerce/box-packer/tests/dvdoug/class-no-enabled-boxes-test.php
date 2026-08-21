<?php

namespace WooCommerce\BoxPacker\UnitTests\DVDoug;

use WooCommerce\BoxPacker\UnitTests\Box_Packing_TestCase;

class No_Enabled_Boxes_Test extends Box_Packing_TestCase {

	protected $library         = 'dvdoug';
	protected $carrier         = 'usps';
	protected $boxes_data_name = 'no-enabled-boxes';
	protected $data            = array(
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
	);

}
