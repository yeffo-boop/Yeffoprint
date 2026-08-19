<?php namespace TierPricingTable\Frontend;

use TierPricingTable\Core\ServiceContainerTrait;
use TierPricingTable\PricingTable;

class PricingTableShortcode {

	use ServiceContainerTrait;

	const TAG = 'tiered-pricing-table';

	public function __construct() {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	public function render( $args ) {

		$args = wp_parse_args( $args, array(
			'product_id' => null,
			'display'    => true,
		) );

		if ( $args['product_id'] ) {
			$productID = intval( $args['product_id'] );
		} else {
			global $post;

			if ( ! $post ) {
				return '';
			}

			$productID = $post->ID;
		}

		$product = wc_get_product( $productID );

		ob_start();

		if ( $product ) {
			PricingTable::getInstance()->renderPricingTable( $product->get_id(), null, $args );
		}

		return ob_get_clean();
	}
}
