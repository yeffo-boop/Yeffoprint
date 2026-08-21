<?php

namespace WooCommerce\BoxPacker\UnitTests\Original;

use WooCommerce\BoxPacker\UnitTests\Box_Packing_TestCase;

class Australia_Post_Letter_Test extends Box_Packing_TestCase {

	protected $library         = 'original';
	protected $carrier         = 'australia-post-letters';
	protected $boxes_data_name = 'australia-post-letters';
	protected $data            = array(
		array(
			'case'        => '10 items of 6.7x6.7x.12 should be packed into one AUS_LETTER_SIZE_C4',
			'type'        => false,
			'code_prefix' => false,
			'items'       => array(
				array(
					'length' => 6.7,
					'width'  => 6.7,
					'height' => 0.12,
					'weight' => 0.06,
					'qty'    => 1,
				),
				array(
					'length' => 6.7,
					'width'  => 6.7,
					'height' => 0.12,
					'weight' => 0.06,
					'qty'    => 1,
				),
				array(
					'length' => 6.7,
					'width'  => 6.7,
					'height' => 0.12,
					'weight' => 0.06,
					'qty'    => 1,
				),
				array(
					'length' => 6.7,
					'width'  => 6.7,
					'height' => 0.12,
					'weight' => 0.06,
					'qty'    => 1,
				),
				array(
					'length' => 6.7,
					'width'  => 6.7,
					'height' => 0.12,
					'weight' => 0.06,
					'qty'    => 1,
				),
				array(
					'length' => 6.7,
					'width'  => 6.7,
					'height' => 0.12,
					'weight' => 0.06,
					'qty'    => 1,
				),
				array(
					'length' => 6.7,
					'width'  => 6.7,
					'height' => 0.12,
					'weight' => 0.06,
					'qty'    => 1,
				),
				array(
					'length' => 6.7,
					'width'  => 6.7,
					'height' => 0.12,
					'weight' => 0.06,
					'qty'    => 1,
				),
				array(
					'length' => 6.7,
					'width'  => 6.7,
					'height' => 0.12,
					'weight' => 0.06,
					'qty'    => 1,
				),
				array(
					'length' => 6.7,
					'width'  => 6.7,
					'height' => 0.12,
					'weight' => 0.06,
					'qty'    => 1,
				),
			),
			'expect'      => array(
				array(
					'code'     => 'AUS_LETTER_SIZE_C4',
					'unpacked' => array(),
					'packed'   => array(
						array(
							'length' => 6.7,
							'width'  => 6.7,
							'height' => 0.12,
							'weight' => 0.06,
						),
						array(
							'length' => 6.7,
							'width'  => 6.7,
							'height' => 0.12,
							'weight' => 0.06,
						),
						array(
							'length' => 6.7,
							'width'  => 6.7,
							'height' => 0.12,
							'weight' => 0.06,
						),
						array(
							'length' => 6.7,
							'width'  => 6.7,
							'height' => 0.12,
							'weight' => 0.06,
						),
						array(
							'length' => 6.7,
							'width'  => 6.7,
							'height' => 0.12,
							'weight' => 0.06,
						),
						array(
							'length' => 6.7,
							'width'  => 6.7,
							'height' => 0.12,
							'weight' => 0.06,
						),
						array(
							'length' => 6.7,
							'width'  => 6.7,
							'height' => 0.12,
							'weight' => 0.06,
						),
						array(
							'length' => 6.7,
							'width'  => 6.7,
							'height' => 0.12,
							'weight' => 0.06,
						),
						array(
							'length' => 6.7,
							'width'  => 6.7,
							'height' => 0.12,
							'weight' => 0.06,
						),
						array(
							'length' => 6.7,
							'width'  => 6.7,
							'height' => 0.12,
							'weight' => 0.06,
						),
					),
				),
			),
		),
		array(
			'case'        => '10 items of 6.7x6.7x.12 should be packed into one AUS_LETTER_SIZE_C4',
			'type'        => false,
			'code_prefix' => false,
			'items'       => array(
				array(
					'length' => 6.7,
					'width'  => 6.7,
					'height' => 0.12,
					'weight' => 0.06,
					'qty'    => 10,
				),
			),
			'expect'      => array(
				array(
					'code'     => 'AUS_LETTER_SIZE_C4',
					'unpacked' => array(),
					'packed'   => array(
						array(
							'length' => 6.7,
							'width'  => 6.7,
							'height' => 0.12,
							'weight' => 0.06,
						),
						array(
							'length' => 6.7,
							'width'  => 6.7,
							'height' => 0.12,
							'weight' => 0.06,
						),
						array(
							'length' => 6.7,
							'width'  => 6.7,
							'height' => 0.12,
							'weight' => 0.06,
						),
						array(
							'length' => 6.7,
							'width'  => 6.7,
							'height' => 0.12,
							'weight' => 0.06,
						),
						array(
							'length' => 6.7,
							'width'  => 6.7,
							'height' => 0.12,
							'weight' => 0.06,
						),
						array(
							'length' => 6.7,
							'width'  => 6.7,
							'height' => 0.12,
							'weight' => 0.06,
						),
						array(
							'length' => 6.7,
							'width'  => 6.7,
							'height' => 0.12,
							'weight' => 0.06,
						),
						array(
							'length' => 6.7,
							'width'  => 6.7,
							'height' => 0.12,
							'weight' => 0.06,
						),
						array(
							'length' => 6.7,
							'width'  => 6.7,
							'height' => 0.12,
							'weight' => 0.06,
						),
						array(
							'length' => 6.7,
							'width'  => 6.7,
							'height' => 0.12,
							'weight' => 0.06,
						),
					),
				),
			),
		),
	);

}
