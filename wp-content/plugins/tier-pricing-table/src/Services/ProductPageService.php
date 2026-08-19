<?php namespace TierPricingTable\Services;

use Exception;
use TierPricingTable\Admin\ProductPage\TieredPricingTab;
use TierPricingTable\Core\ServiceContainerTrait;
use TierPricingTable\PriceManager;
use TierPricingTable\PricingTable;
use TierPricingTable\Settings\Sections\GeneralSection\Subsections\ProductPagePriceSubsection;
use TierPricingTable\TierPricingTablePlugin;
use WC_Product;
use WC_Product_Data_Store_CPT;
use WP_Post;

/**
 * Class ProductPageHandler
 *
 * @package TierPricingTable\Frontend
 */
class ProductPageService {

	use ServiceContainerTrait;

	public function __construct() {

		add_action( 'woocommerce_quantity_input_classes', function ( $classes, $product ) {
			if ( $product instanceof WC_Product ) {
				$classes[] = 'quantity-input-product-' . $product->get_id();
			}

			return $classes;
		}, 10, 2 );

		add_filter( 'woocommerce_available_variation', function ( $data, WC_Product $product ) {
			$data['parent_id'] = $product->get_id();

			return $data;
		}, 10, 2 );

		// Wrap price
		add_action( 'woocommerce_get_price_html', array( $this, 'wrapPrice' ), 101, 2 );

		// Render price table
		add_action( $this->getContainer()->getSettings()->get( 'position_hook',
				'woocommerce_before_add_to_cart_button' ), array(
				$this,
				'renderPricingTableOnProductPage',
		), - 999 );

		// Get table for variation
		add_action( 'wc_ajax_get_pricing_table', array( $this, 'getVariationPricingTable' ), 10, 1 );

		// Render tooltip if enabled
		add_filter( 'woocommerce_get_price_html', array( $this, 'renderTooltip' ), 999, 2 );

		add_filter( 'woocommerce_get_price_suffix', function ( $suffix, \WC_product $product, $price, $qty ) {

			// Allow 3rd-party to control this
			if ( ! apply_filters( 'tiered_pricing_table/frontend/modify_price_suffix', true, $suffix, $product, $price,
					$qty ) ) {
				return $suffix;
			}

			if ( empty( $suffix ) ) {
				return $suffix;
			}

			$html = '';

			$suffix = get_option( 'woocommerce_price_display_suffix' );

			if ( $suffix && wc_tax_enabled() && 'taxable' === $product->get_tax_status() ) {

				if ( '' === $price ) {
					$price = $product->get_price();
				}

				$replacements = array(
						'{price_including_tax}' => '<span class="tiered-pricing-dynamic-price__including_tax">' . wc_price( wc_get_price_including_tax( $product,
										array(
												'qty'   => $qty,
												'price' => $price,
										) ) ) . '</span>',
						'{price_excluding_tax}' => '<span class="tiered-pricing-dynamic-price__excluding_tax">' . wc_price( wc_get_price_excluding_tax( $product,
										array(
												'qty'   => $qty,
												'price' => $price,
										) ) ) . '</span>',
				);

				$html = str_replace( array_keys( $replacements ), array_values( $replacements ),
						' <small class="woocommerce-price-suffix">' . wp_kses_post( $suffix ) . '</small>' );
			}

			return $html;

		}, 10, 4 );
	}

	/**
	 * Wrap product price for managing it by JS
	 *
	 * @param  mixed  $priceHTML
	 * @param  WC_Product  $product
	 *
	 * @return string
	 */
	public function wrapPrice( $priceHTML, WC_Product $product ) {

		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return $priceHTML;
		}

		// Do not wrap price in quick edit and inline edit in admin
		if ( isset( $_POST['action'] ) && in_array( $_POST['action'], [
						'inline-save',
				], true ) ) {
			return $priceHTML;
		}

		// Allow 3rd-party to control this
		if ( ! apply_filters( 'tiered_pricing_table/frontend/wrap_price', true, $product, $priceHTML ) ) {
			return $priceHTML;
		}

		$parentProductID     = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		$variationID         = TierPricingTablePlugin::isVariationProductSupported( $product ) ? $product->get_id() : null;
		$productPagePrice    = get_queried_object_id() === $parentProductID && is_product();
		$priceDisplayContext = $productPagePrice ? 'product-page' : 'shop-loop';

		$priceType = apply_filters( 'tiered_pricing_table/frontend/default_price_behaviour_type', 'dynamic', $product,
				$priceHTML, $priceDisplayContext );

		if ( $productPagePrice && $product->get_type() !== 'variation' ) {
			// Do not format prices if tiered pricing formatting enabled on the product page.
			if ( 'same_as_catalog' === ProductPagePriceSubsection::getFormatPriceType() ) {
				$priceType = 'static';
			}
		}

		$isVariable = in_array( $product->get_type(), TierPricingTablePlugin::getSupportedVariableProductTypes() );

		$pricingRules = \TierPricingTable\PriceManager::getPricingRule( $product->get_id() );

		// Set to no-rules if there are no pricing rules and the main dynamic price setting is disabled
		$updatePrice = $this->getContainer()->getSettings()->get( 'update_price_on_product_page', 'yes' ) === 'yes';
		if ( ! $isVariable && empty( $pricingRules->getRules() ) && ! $updatePrice ) {
			$priceType = 'no-rules';
		}

		$supportedTypes = array_merge( TierPricingTablePlugin::getSupportedSimpleProductTypes(), array( 'variation' ) );

		$wrapVariableProductPrice = apply_filters( 'tiered_pricing_table/frontend/wrap_variable_price', true,
				$product );

		$hasRules = PricingTable::getInstance()->productHasPricingRules( $product );
		$showTotalPrice = $hasRules
			? $this->getContainer()->getSettings()->get( 'show_total_price', 'no' ) === 'yes'
			: $this->getContainer()->getSettings()->get( 'show_total_price_non_tiered', 'no' ) === 'yes';

		// Is "show total price" is enabled, we can wrap the variable product price, or it's forced by the hook
		if ( $wrapVariableProductPrice || $showTotalPrice ) {
			$supportedTypes = array_merge( $supportedTypes,
					TierPricingTablePlugin::getSupportedVariableProductTypes() );
		}

		if ( in_array( $product->get_type(), $supportedTypes ) ) {

			ob_start();

			?>
		<span class="tiered-pricing-dynamic-price-wrapper<?php echo $isVariable ? ' tiered-pricing-dynamic-price-wrapper--variable' : ''; ?>"
			  data-display-context="<?php echo esc_attr( $priceDisplayContext ); ?>"
			  data-price-type="<?php echo esc_attr( $priceType ); ?>"
			  data-product-id="<?php echo esc_attr( $product->get_id() ); ?>"
			  data-parent-id="<?php echo esc_attr( $product->get_parent_id() ? $product->get_parent_id() : $product->get_id() ); ?>">
			<?php
			return ob_get_clean() . $priceHTML . '</span>';
		}

		return $priceHTML;
	}

	/**
	 *  Render a pricing table on the product page
	 */
	public function renderPricingTableOnProductPage() {

		global $post;

		if ( ! $post || ! is_product() ) {
			return;
		}

		$variationId = $this->getVariationIdFromURL( $post->ID );

		PricingTable::getInstance()->renderPricingTable( $post->ID, $variationId );
	}

	public function getVariationIdFromURL( $productId ): ?int {

		$attributes       = array();
		$attributeWordLen = strlen( 'attribute_' );

		foreach ( $_REQUEST as $key => $value ) {

			if ( strlen( $key ) < $attributeWordLen ) {
				continue;
			}

			$string = substr( $key, 0, $attributeWordLen );

			if ( strcasecmp( $string, 'attribute_' ) === 0 ) {
				$attributes[ $key ] = $value;
			}
		}

		if ( empty( $attributes ) ) {
			return null;
		}

		$product = wc_get_product( $productId );

		if ( ! $product ) {
			return null;
		}

		if ( ! TierPricingTablePlugin::isVariableProductSupported( $product ) ) {
			return null;
		}

		return ( new WC_Product_Data_Store_CPT() )->find_matching_product_variation( $product, $attributes );
	}

	/**
	 * Render tooltip near product price if selected display type is "tooltip"
	 *
	 * @param  ?string  $price
	 * @param  WC_Product  $_product
	 *
	 * @return string
	 */
	public function renderTooltip( ?string $price, WC_Product $_product ): ?string {

		// Do not render if not display
		if ( 'yes' !== $this->getContainer()->getSettings()->get( 'display', 'yes' ) ) {
			return $price;
		}

		$displayType = TieredPricingTab::getProductTemplate( $_product->get_parent_id() ? $_product->get_parent_id() : $_product->get_id() );
		$displayType = 'default' === $displayType ? $this->getContainer()->getSettings()->get( 'display_type',
				'table' ) : $displayType;

		// Do not display if display type is not the tooltip
		if ( 'tooltip' !== $displayType ) {
			return $price;
		}

		if ( is_product() ) {
			$addTooltip      = false;
			$page_product_id = get_queried_object_id();

			if ( $_product->is_type( 'variation' ) && $_product->get_parent_id() === $page_product_id ) {
				$addTooltip = true;
			} elseif ( $_product->get_id() === $page_product_id && TierPricingTablePlugin::isSimpleProductSupported( $_product ) ) {
				$addTooltip = true;
			}

			if ( ! $addTooltip ) {
				return $price;
			}

			$pricingRule = PriceManager::getPricingRule( $_product->get_id() );

			if ( ! empty( $pricingRule->getRules() ) ) {

				return $price . $this->getContainer()->getFileManager()->renderTemplate( 'frontend/tooltip.php', array(
								'color' => $this->getContainer()->getSettings()->get( 'tooltip_color', '#3858e9' ),
								'size'  => $this->getContainer()->getSettings()->get( 'tooltip_size', 15 ) . 'px',
						) );
			}

		}

		return $price;
	}

	/**
	 * Fired when user chooses a variation. Renders tiered pricing table for the variation
	 *
	 * @throws Exception
	 * @global WP_Post $post .
	 */
	public function getVariationPricingTable() {

		$product_id     = isset( $_POST['variation_id'] ) ? sanitize_text_field( $_POST['variation_id'] ) : false;
		$nonce          = isset( $_POST['nonce'] ) ? sanitize_key( $_POST['nonce'] ) : false;
		$displayContext = isset( $_POST['display_context'] ) ? sanitize_text_field( $_POST['display_context'] ) : 'product-page';

		$renderSettings = apply_filters( 'tiered_pricing_table/frontend/variation_render_settings', array(),
				$product_id, $displayContext );

		// Some cache plugins may cache the nonce, so we need to leave the ability to disable nonce verification.
		// Checking nonce is not critical here.
		$verifyNonce = apply_filters( 'tiered_pricing_table/frontend/load_variation/verify_nonce', false, $nonce,
				'get_pricing_table' );

		if ( $verifyNonce && ! wp_verify_nonce( $nonce, 'get_pricing_table' ) ) {
			return;
		}

		$product = wc_get_product( $product_id );

		if ( $product ) {
			$parentProduct = wc_get_product( $product->get_parent_id() );

			if ( $parentProduct ) {
				PricingTable::getInstance()->renderPricingTableHTML( $parentProduct, $product, $renderSettings );
			}

		}

	}
}
