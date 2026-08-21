<?php

namespace WooCommerce\BoxPacker\UnitTests;

use PHPUnit\Framework\TestCase;
use WooCommerce\BoxPacker\Abstract_Packer;
use WooCommerce\BoxPacker\WC_Boxpack;

class Box_Packing_TestCase extends TestCase {

	/**
	 * File name of boxes data without `.php`.
	 *
	 * @var string
	 */
	protected $boxes_data_name;
	/**
	 * Input test cases and expected outputs.
	 *
	 * Each array is a test case consisting type of box (priority
	 * or express), code prefix, items to pack, and expected output.
	 *
	 * If targetting domestic only, set code_prefix to 'd'. Or 'i' for international,
	 * let it blank for targetting both.
	 *
	 * Dimension in items is in inch. Weight in lbs.
	 *
	 * @var array
	 */
	protected $data;
	protected $library;
	protected $carrier;

	/**
	 * Test packing.
	 */
	public function test_packing() {
		foreach ( $this->data as $data ) {
			$options = ! empty( $data['options'] ) ? $data['options'] : array();
			$boxpack = ( new WC_Boxpack( 'in', 'lbs', $this->library, $options ) )->get_packer();
			$this->_set_boxes( $boxpack, $data );
			$this->_pack_items( $boxpack, $data );

			$actual = $boxpack->get_packages();

			$this->assertEquals( count( $data['expect'] ), count( $actual ), sprintf( 'expecting %d package(s), but got %d package(s) for case: "%s"', count( $data['expect'] ), count( $actual ), $data['case'] ) );

			foreach ( $data['expect'] as $i => $expected ) {
				$this->assertEquals( $expected['code'], $actual[ $i ]->id, sprintf( 'expecting box #%d to be "%s", but got %s', $i + 1, $expected['code'], $actual[ $i ]->id ) );
				$this->assertEquals( $expected['unpacked'], $actual[ $i ]->unpacked, sprintf( 'expecting box #%d "%s" unpacked %s, but got %s', $i + 1, $expected['code'], print_r( $expected['unpacked'], true ), print_r( $actual[ $i ]->unpacked, true ) ) );

				$this->assertEquals( count( $expected['packed'] ), count( $actual[ $i ]->packed ), sprintf( 'expecting %d item(s) in box #%d "%s", but got %d item(s) for case: "%s"', count( $expected['packed'] ), $i + 1, $expected['code'], count( $actual[ $i ]->packed ), $data['case'] ) );

				foreach ( $expected['packed'] as $j => $item ) {
					$extras = array(
						'value' => 10.00,
						'meta'  => array( 'key' => 'value' ),
					);

					$length = $item['length'];
					$width  = $item['width'];
					$height = $item['height'];
					$weight = $item['weight'];
					$value  = $extras['value'];
					$meta   = $extras['meta'];

					$this->assertEquals( $length, $actual[ $i ]->packed[ $j ]->get_length(), sprintf( 'expecting item #%d in box #%d "%s" has length %s, but got %s', $j + 1, $i + 1, $expected['code'], $length, $actual[ $i ]->packed[ $j ]->get_length() ) );
					$this->assertEquals( $width, $actual[ $i ]->packed[ $j ]->get_width(), sprintf( 'expecting item #%d in box #%d "%s" has width %s, but got %s', $j + 1, $i + 1, $expected['code'], $width, $actual[ $i ]->packed[ $j ]->get_width() ) );
					$this->assertEquals( $height, $actual[ $i ]->packed[ $j ]->get_height(), sprintf( 'expecting item #%d in box #%d "%s" has height %s, but got %s', $j + 1, $i + 1, $expected['code'], $height, $actual[ $i ]->packed[ $j ]->get_height() ) );
					$this->assertEquals( $weight, $actual[ $i ]->packed[ $j ]->get_weight(), sprintf( 'expecting item #%d in box #%d "%s" has weight %s, but got %s', $j + 1, $i + 1, $expected['code'], $weight, $actual[ $i ]->packed[ $j ]->get_weight() ) );
					$this->assertEquals( $value, $actual[ $i ]->packed[ $j ]->get_value(), sprintf( 'expecting item #%d in box #%d "%s" has value %s, but got %s', $j + 1, $i + 1, $expected['code'], $value, $actual[ $i ]->packed[ $j ]->get_value() ) );
					$this->assertEquals( $meta, $actual[ $i ]->packed[ $j ]->get_meta(), sprintf( 'expecting item #%d in box #%d "%s" has meta %s, but got %s', $j + 1, $i + 1, $expected['code'], print_r( $meta, true ), print_r( $actual[ $i ]->packed[ $j ]->get_meta(), true ) ) );
					$this->assertEquals( 'value', $actual[ $i ]->packed[ $j ]->get_meta( 'key' ), sprintf( 'expecting item #%d in box #%d "%s" has meta %s, but got %s', $j + 1, $i + 1, $expected['code'], 'value', $actual[ $i ]->packed[ $j ]->get_meta( 'key' ) ) );
					$this->assertEquals( null, $actual[ $i ]->packed[ $j ]->get_meta( 'key2' ), sprintf( 'expecting item #%d in box #%d "%s" has meta %s, but got %s', $j + 1, $i + 1, $expected['code'], null, $actual[ $i ]->packed[ $j ]->get_meta( 'key2' ) ) );
				}
			}
		}
	}

	/**
	 * Set flat rate boxes.
	 *
	 * @param Abstract_Packer $boxpack Abstract_Packer instance
	 * @param array           $input Input case
	 */
	protected function _set_boxes( Abstract_Packer $boxpack, array $input ) {
		foreach ( get_boxes_data( $this->boxes_data_name ) as $code => $box ) {

			if ( ! empty( $box['box_type'] ) && $box['box_type'] !== $input['type'] ) {
				continue;
			}

			if ( ! empty( $input['code_prefix'] ) && substr( $code, 0, 1 ) !== $input['code_prefix'] ) {
				continue;
			}

			$length = $box['length'] ?? 0;
			$width  = $box['width'] ?? 0;
			$height = $box['height'] ?? 0;

			switch ( $this->carrier ) {
				case 'fedex':
					$max_weight = $box['max_weight'];
					$code       = $box['id'];
					break;
				case 'usps':
				case 'ups':
					$max_weight = $box['weight'];
					break;
				case 'australia-post-letters':
					$length      = wc_get_dimension( $box['length'], 'in', 'mm' );
					$width       = wc_get_dimension( $box['width'], 'in', 'mm' );
					$height      = ! empty( $box['thickness'] ) ? wc_get_dimension( $box['thickness'], 'in', 'mm' ) : 0.8;
					$box['type'] = 'envelope';
					$max_weight  = 1.1;
					break;
				case 'australia-post-boxes':
					$length     = wc_get_dimension( $box['inner_length'], 'in', 'cm' );
					$width      = wc_get_dimension( $box['inner_width'], 'in', 'cm' );
					$height     = wc_get_dimension( $box['inner_height'], 'in', 'cm' );
					$max_weight = wc_get_weight( $box['max_weight'], 'lbs', 'kg' );
					$code       = $box['id'];
					break;
				default:
					$max_weight = 0;
			}

			$newbox = $boxpack->add_box( $length, $width, $height );

			if ( ! empty( $max_weight ) ) {
				$newbox->set_max_weight( $max_weight );
			}

			$newbox->set_id( $code );

			if ( isset( $box['volume'] ) ) {
				$newbox->set_volume( $box['volume'] );
			}

			if ( isset( $box['type'] ) ) {
				$newbox->set_type( $box['type'] );
			}
		}
	}

	/**
	 * Pack items from input.
	 *
	 * @param Abstract_Packer $boxpack Abstract_Packer instance
	 * @param array           $input Input case
	 */
	protected function _pack_items( Abstract_Packer $boxpack, array $input ) {
		foreach ( $input['items'] as $item ) {
			$length = $item['length'];
			$width  = $item['width'];
			$height = $item['height'];
			$weight = $item['weight'];
			$qty    = $item['qty'];

			$boxpack->add_item( $length, $width, $height, $weight, 10.00, array( 'key' => 'value' ), $qty );
		}
		$boxpack->pack();
	}

}
