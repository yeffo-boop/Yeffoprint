<?php namespace TierPricingTable\Blocks;

use TierPricingTable\PricingTable;
use WP_Post;

class GutenbergBlocks {

	public function __construct() {

		register_block_type( __DIR__ . '/build', array(
			'render_callback' => function ( $attributes, $content ) {

				$attributes = wp_parse_args( $attributes, array(
					'displayType'         => 'table',
					'activeTierColor'     => '#3858e9',
					'title'               => '',
					'showDiscountColumn'  => true,
					'quantityColumnTitle' => '',
					'discountColumnTitle' => '',
					'priceColumnTitle'    => '',
				) );

				global $post;

				if ( ! ( $post instanceof WP_Post ) ) {
					return '';
				}

				ob_start();
				PricingTable::getInstance()->renderPricingTable( $post->ID, null, array(
					'display_type'          => $attributes['displayType'],
					'active_tier_color'     => $attributes['activeTierColor'],
					'title'                 => $attributes['title'],
					'show_discount_column'  => $attributes['showDiscountColumn'],
					'discount_column_title' => $attributes['discountColumnTitle'],
					'price_column_title'    => $attributes['priceColumnTitle'],
					'quantity_column_title' => $attributes['quantityColumnTitle'],
				) );

				return ob_get_clean();
			},
		) );
	}
}


