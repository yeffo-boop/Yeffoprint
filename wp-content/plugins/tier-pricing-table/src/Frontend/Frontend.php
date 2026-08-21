<?php namespace TierPricingTable\Frontend;

use TierPricingTable\Core\ServiceContainerTrait;
use TierPricingTable\TierPricingTablePlugin;
use WP_Post;

/**
 * Class Frontend
 *
 * @package TierPricingTable\Frontend
 */
class Frontend {
	
	use ServiceContainerTrait;
	
	public function __construct() {
		
		new PricingTableShortcode();
		
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueueAssets' ), 10, 1 );
	}
	
	public function enqueueAssets() {
		wp_enqueue_script( 'tiered-pricing-table-front-js',
			$this->getContainer()->getFileManager()->locateJSAsset( 'frontend/product-tiered-pricing-table' ),
			array( 'jquery' ), TierPricingTablePlugin::VERSION );
		
		wp_enqueue_style( 'tiered-pricing-table-front-css',
			$this->getContainer()->getFileManager()->locateCSSAsset( 'frontend/main.css' ), null,
			TierPricingTablePlugin::VERSION );
		
		wp_localize_script( 'tiered-pricing-table-front-js', 'tieredPricingGlobalData', [
			'version'                         => TierPricingTablePlugin::VERSION,
			'loadVariationTieredPricingNonce' => wp_create_nonce( 'get_pricing_table' ),
			'isPremium'                       => ! tpt_fs()->can_use_premium_code() ? 'no' : 'yes',
			'currencyOptions'                 => [
				'currency_symbol'    => get_woocommerce_currency_symbol(),
				'decimal_separator'  => wc_get_price_decimal_separator(),
				'thousand_separator' => wc_get_price_thousand_separator(),
				'decimals'           => wc_get_price_decimals(),
				'price_format'       => get_woocommerce_price_format(),
				'trim_zeros'         => apply_filters( 'woocommerce_price_trim_zeros', false ),
			],
			'supportedVariableProductTypes'   => TierPricingTablePlugin::getSupportedVariableProductTypes(),
			'supportedSimpleProductTypes'     => TierPricingTablePlugin::getSupportedSimpleProductTypes(),
		] );
	}
}
