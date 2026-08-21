<?php

require_once './tests/inc/functions-wordpress.php';
require_once './tests/inc/functions-woocommerce.php';
require_once './vendor/autoload_packages.php';
require_once './src/class-wc-boxpack.php';
require_once './tests/class-box-packing-testcase.php';

function get_boxes_data( $name ) {
	return include './tests/data/' . $name . '.php';
}

/**
 * We need to define this WP function here as it's used when WC_Boxpack_Box::pack()
 * throws an Exception. It always return false as we don't want anything to be
 * outputted when running the test.
 *
 * @param $__ string Whatever. We don't use it.
 *
 * @return bool
 */
function current_user_can( $__ ) {
	return false;
}
