<?php namespace TierPricingTable\Core;

/*
 * Class Cache
 *
 * @package TierPricingTable\Core
 */

use TierPricingTable\TierPricingTablePlugin;
use WC_Product;
use WC_Product_Variable;

class Cache {
	
	use ServiceContainerTrait;
	
	protected $variableProductsHashes = array();
	
	const PURGE_CACHE_ACTION = 'tpt_purge_cache';
	const PRODUCT_DATA_TRANSIENT_KEY = 'tpt_product_data';
	
	protected $isEnabled = true;
	
	public function __construct() {
		
		$this->isEnabled = $this->getContainer()->getSettings()->get( 'cache_enabled', 'yes' ) === 'yes';
		
		if ( ! $this->isEnabled ) {
			return;
		}
		
		// Store variable products price hashes
		add_filter( 'woocommerce_get_variation_prices_hash', function ( $hash, WC_Product_Variable $product ) {
			$pricesDisplayType = ServiceContainer::getInstance()->getSettings()->get( 'tiered_price_at_catalog_type',
				'range' );
			
			$hash[] = $product->get_date_modified();
			$hash[] = $product->get_id();
			
			if ( ! array_key_exists( $product->get_id(), $this->variableProductsHashes ) ) {
				$this->variableProductsHashes[ $product->get_id() ] = md5( wp_json_encode( $hash ) . $pricesDisplayType );
			}
			
			return $hash;
		}, 999999, 2 );
		
		add_action( 'admin_post_' . self::PURGE_CACHE_ACTION, function () {
			
			$nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( $_REQUEST['nonce'] ) : false;
			
			if ( current_user_can( 'manage_options' ) && wp_verify_nonce( $nonce, self::PURGE_CACHE_ACTION ) ) {
				
				ServiceContainer::getInstance()->getAdminNotifier()->flash( __( 'Cache has been purged successfully',
					'tier-pricing-table' ) );
				
				$this->purge();
			}
			
			return wp_safe_redirect( wp_get_referer() );
		} );
	}
	
	public function isEnabled(): bool {
		return $this->getContainer()->getSettings()->get( 'cache_enabled', 'yes' ) === 'yes';
	}
	
	public function getProductCacheKey( WC_Product $product ) {
		
		if ( TierPricingTablePlugin::isVariableProductSupported( $product ) ) {
			
			if ( ! empty( $this->variableProductsHashes[ $product->get_id() ] ) ) {
				return $this->variableProductsHashes[ $product->get_id() ];
			}
		}
		
		return md5( $product->get_date_modified() . implode( ',', TierPricingTablePlugin::getCurrentUserRoles() ) );
	}
	
	public function getProductData( WC_Product $product, $key = null ) {
		if ( ! $this->isEnabled ) {
			return false;
		}
		
		$productCacheKey = $this->getProductCacheKey( $product );
		
		$data = (array) get_transient( self::PRODUCT_DATA_TRANSIENT_KEY );
		
		if ( empty( $data[ $productCacheKey ] ) ) {
			return false;
		}
		
		if ( $key ) {
			return $data[ $productCacheKey ][ $key ] ?? false;
		}
		
		return $data;
	}
	
	public function setProductData( WC_Product $product, $key, $value ) {
		
		if ( ! $this->isEnabled ) {
			return;
		}
		
		$productCacheKey = $this->getProductCacheKey( $product );
		
		$data = (array) get_transient( self::PRODUCT_DATA_TRANSIENT_KEY );
		
		if ( $key ) {
			$data[ $productCacheKey ][ $key ] = $value;
		} else {
			$data[ $productCacheKey ] = $value;
		}
		
		set_transient( self::PRODUCT_DATA_TRANSIENT_KEY, $data, DAY_IN_SECONDS * 10 + rand( 10, DAY_IN_SECONDS ) );
	}
	
	public function purge( $product = null ) {
		
		if ( $product ) {
			$this->setProductData( $product, null, null );
		} else {
			delete_transient( self::PRODUCT_DATA_TRANSIENT_KEY );
		}
	}
	
	public function getPurgeURL(): string {
		return add_query_arg( array(
			'action' => self::PURGE_CACHE_ACTION,
			'nonce'  => wp_create_nonce( self::PURGE_CACHE_ACTION ),
		), admin_url( 'admin-post.php' ) );
	}
}
