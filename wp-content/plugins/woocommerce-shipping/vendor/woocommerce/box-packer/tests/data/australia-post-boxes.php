<?php
/**
 * Default Box Sizes
 *
 * @package WC_Shipping_Australia_Post
 */

return array(
	array(
		'name'         => 'Small Satchel',
		'id'           => 'AUS_PARCEL_REGULAR_SATCHEL_SMALL',
		'max_weight'   => 5, // In kg.
		'box_weight'   => 0,
		'outer_length' => 22, // In cm.
		'outer_width'  => 16, // In Cm.
		'outer_height' => 7, // In Cm.
		'inner_length' => 22, // In cm.
		'inner_width'  => 16, // In Cm.
		'inner_height' => 7, // In Cm.
		'type'         => 'packet',
	),
	array(
		'name'         => 'Medium Satchel',
		'id'           => 'AUS_PARCEL_EXPRESS_SATCHEL_MEDIUM',
		'max_weight'   => 5, // In kg.
		'box_weight'   => 0,
		'outer_length' => 24, // In cm.
		'outer_width'  => 19, // In Cm.
		'outer_height' => 12, // In Cm.
		'inner_length' => 24, // In cm.
		'inner_width'  => 19, // In Cm.
		'inner_height' => 12, // In Cm.
		'type'         => 'packet',
	),
	array(
		'name'         => 'Large Satchel',
		'id'           => 'AUS_PARCEL_EXPRESS_SATCHEL_LARGE',
		'max_weight'   => 5, // In kg.
		'box_weight'   => 0,
		'outer_length' => 39, // In cm.
		'outer_width'  => 28, // In Cm.
		'outer_height' => 14, // In Cm.
		'inner_length' => 39, // In cm.
		'inner_width'  => 28, // In Cm.
		'inner_height' => 14, // In Cm.
		'type'         => 'packet',
	),
	array(
		'name'         => 'Extra Large Satchel',
		'id'           => 'AUS_PARCEL_EXPRESS_SATCHEL_EXTRA_LARGE',
		'max_weight'   => 5, // In kg.
		'box_weight'   => 0,
		'outer_length' => 44, // In cm.
		'outer_width'  => 27.7, // In Cm.
		'outer_height' => 16.8, // In Cm.
		'inner_length' => 44, // In cm.
		'inner_width'  => 27.7, // In Cm.
		'inner_height' => 16.8, // In Cm.
		'type'         => 'packet',
	),
);