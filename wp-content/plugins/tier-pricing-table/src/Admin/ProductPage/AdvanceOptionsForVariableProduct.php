<?php namespace TierPricingTable\Admin\ProductPage;

use TierPricingTable\TierPricingTablePlugin;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * Class VariationProduct
 *
 * @package TierPricingTable\Admin\Product
 */
class AdvanceOptionsForVariableProduct {

	const PRODUCT_VARIATIONS_SEARCH_ACTION = 'woocommerce_json_search_tpt_product_variations';

	/**
	 * Register hooks
	 */
	public function __construct() {
		add_action( 'wp_ajax_' . self::PRODUCT_VARIATIONS_SEARCH_ACTION, array(
				$this,
				'productVariationsSearchHandler',
		) );

		// Saving
		add_action( 'woocommerce_process_product_meta', array( $this, 'saveOptions' ) );

		add_action( 'tiered_pricing_table/admin/advance_product_options', function ( $productId ) {

			$product = wc_get_product( $productId );

			if ( ! TierPricingTablePlugin::isVariableProductSupported( $product ) ) {
				return;
			}
			?>

			<p class="form-field _tiered_pricing_default_variation show_if_variable">
				<label for="_tiered_pricing_default_variation_id">
					<?php
						esc_html_e( 'Default variation', 'tier-pricing-table' );
					?>
				</label>

				<select class="wc-product-search" style="width: 50%;"
				        id="_tiered_pricing_default_variation_id"
				        data-allow_clear="true"
				        name="_tiered_pricing_default_variation_id"
				        data-include="<?php echo esc_attr( $productId ); ?>"
				        data-placeholder="<?php
							esc_attr_e( 'Search variations&hellip;', 'tier-pricing-table' );
						?>"
				        data-action="woocommerce_json_search_tpt_product_variations">

					<?php $default = self::getDefaultVariation( $productId, 'edit' ); ?>

					<?php if ( $default ) : ?>
						<option selected value="<?php echo esc_attr( $default->get_id() ); ?>">
							<?php echo esc_attr( $default->get_attribute_summary() ); ?>
						</option>
					<?php endif; ?>
				</select>
			</p>
			<div style="padding: 0 20px 0 162px;
    margin-bottom: 10px;
    color: #666;
    margin-top: -10px;
    font-size: 12px;">
				<?php
					esc_html_e( 'Display this variation\'s pricing before attributes are selected. The variation itself won\'t be pre-selected.',
							'tier-pricing-table' );
				?>
			</div>

			<?php
			woocommerce_wp_checkbox( array(
					'id'          => '_tiered_pricing_variable_product_same_prices',
					'value'       => wc_bool_to_string( self::isVariableProductSamePrices( $productId ) ),
					'label'       => __( 'Variations share pricing', 'tier-pricing-table' ),
					'description' => __( 'Use the default variation\'s pricing for all variations to prevent reloading the pricing table.',
							'tier-pricing-table' ),
			) );

			woocommerce_wp_select( array(
					'id'      => '_tiered_price_mix_and_match_minimum',
					'value'   => get_post_meta( $productId, '_tiered_price_mix_and_match_minimum', true ),
					'options' => array(
							''    => __( 'Global Settings', 'tier-pricing-table' ),
							'no'  => __( 'Individual variation', 'tier-pricing-table' ),
							'yes' => __( 'Variable product total (Mix & match)', 'tier-pricing-table' ),
					),
					'label'   => __( 'Calculate minimum quantity by', 'tier-pricing-table' ),
			) );
			?>
			<div style="padding: 0 20px 0 162px;
				margin-bottom: 10px;
				color: #666;
				margin-top: -10px;
				font-size: 12px;">
				<?php
					esc_html_e( 'Determine whether the minimum quantity requirement applies to each variation separately, or to the combined total of all variations in the cart.',
							'tier-pricing-table' );
				?>
			</div>
			<?php
		} );
	}

	/**
	 * Save advanced options related to the variable product
	 *
	 * @param  string|int  $productId
	 */
	public function saveOptions( $productId ) {

		if ( wp_verify_nonce( true, true ) ) {
			// as phpcs comments at Woo is not available, we have to do such a trash
			$woo = 'Woo, please add ignoring comments to your phpcs checker';
		}

		$data = $_POST;

		$defaultVariation = ! empty( $data['_tiered_pricing_default_variation_id'] ) ? intval( $data['_tiered_pricing_default_variation_id'] ) : null;
		$samePrices       = ! empty( $data['_tiered_pricing_variable_product_same_prices'] );
		$mixAndMatch      = isset( $data['_tiered_price_mix_and_match_minimum'] ) ? sanitize_text_field( $data['_tiered_price_mix_and_match_minimum'] ) : '';

		self::updateDefaultVariation( $productId, $defaultVariation );
		self::updateVariableProductSamePrices( $productId, $samePrices );
		self::updateMixAndMatchMinimum( $productId, $mixAndMatch );
	}

	public static function updateMixAndMatchMinimum( $productId, $mixAndMatch ) {
		if ( in_array( $mixAndMatch, array( 'yes', 'no' ) ) ) {
			update_post_meta( $productId, '_tiered_price_mix_and_match_minimum', $mixAndMatch );
		} else {
			delete_post_meta( $productId, '_tiered_price_mix_and_match_minimum' );
		}
	}

	public static function isVariableProductSamePrices( $productId ): bool {
		return 'yes' === get_post_meta( $productId, '_tiered_pricing_variable_product_same_prices', true );
	}

	public static function updateVariableProductSamePrices( $productId, bool $samePrices = false ) {
		if ( $samePrices ) {
			update_post_meta( $productId, '_tiered_pricing_variable_product_same_prices', 'yes' );
		} else {
			delete_post_meta( $productId, '_tiered_pricing_variable_product_same_prices' );
		}
	}

	public static function updateDefaultVariation( $variableProductId, $defaultVariationId = null ) {
		if ( $defaultVariationId ) {
			update_post_meta( $variableProductId, '_tiered_pricing_default_variation_id', $defaultVariationId );
		} else {
			delete_post_meta( $variableProductId, '_tiered_pricing_default_variation_id' );
		}
	}

	/**
	 * Get default variation
	 *
	 * @param  int  $productId
	 * @param  string  $context
	 *
	 * @return ?WC_Product_Variation
	 */
	public static function getDefaultVariation( $productId, $context = 'view' ) {
		$defaultVariationId = get_post_meta( $productId, '_tiered_pricing_default_variation_id', true );

		$defaultVariation = $defaultVariationId ? wc_get_product( $defaultVariationId ) : false;

		if ( ! ( $defaultVariation instanceof WC_Product_Variation ) ) {
			$defaultVariation = null;
		}

		if ( 'edit' !== $context ) {
			return apply_filters( 'tiered_pricing_table/product/default_variation', $defaultVariation, $productId );
		}

		return $defaultVariation;
	}

	public function productVariationsSearchHandler() {

		check_ajax_referer( 'search-products', 'security' );

		if ( empty( $term ) && isset( $_GET['term'] ) ) {
			$term = (string) wc_clean( wp_unslash( $_GET['term'] ) );
		}

		if ( empty( $term ) ) {
			wp_send_json( array() );
		}

		$limit   = 30;
		$include = ! empty( $_GET['include'] ) ? intval( $_GET['include'] ) : false;

		if ( ! $include ) {
			wp_send_json( array() );
		}

		$product = wc_get_product( $include );

		if ( ! TierPricingTablePlugin::isVariableProductSupported( $product ) ) {
			wp_send_json( array() );
		}

		$results = array();

		if ( $product instanceof WC_Product_Variable ) {
			$variationsObjects = $product->get_available_variations( 'objects' );
			$variations        = array();
			$rawVariations     = array();

			foreach ( $variationsObjects as $variation ) {
				$rawVariations[ '_' . $variation->get_id() ] = rawurldecode( wp_strip_all_tags( $variation->get_attribute_summary() ) );
				$variations                                  = $rawVariations;
			}

			if ( count( $rawVariations ) > $limit ) {
				$similarTextResults = array();

				foreach ( $variationsObjects as $variation ) {
					$similarTextResults[ '_' . $variation->get_id() ] = similar_text( strtolower( $variation->get_attribute_summary() ),
							strtolower( $term ) );
				}

				asort( $similarTextResults );
				$similarTextResults = array_reverse( $similarTextResults );
				$similarTextResults = array_slice( $similarTextResults, 0, $limit );

				$variations = array_intersect_key( $similarTextResults, $rawVariations );

			}

			foreach ( $variations as $key => $value ) {
				$results[ str_replace( '_', '', $key ) ] = $rawVariations[ $key ];
			}
		}

		wp_send_json( $results );
	}
}