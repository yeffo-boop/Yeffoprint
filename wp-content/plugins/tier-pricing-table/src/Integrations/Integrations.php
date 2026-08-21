<?php namespace TierPricingTable\Integrations;

use TierPricingTable\Integrations\Plugins\AddifyRequestAQuote;
use TierPricingTable\Integrations\Plugins\AeliaMulticurrency;
use TierPricingTable\Integrations\Plugins\Curcy;
use TierPricingTable\Integrations\Plugins\DiscountRulesForWooCommerce;
use TierPricingTable\Integrations\Plugins\Elementor\ElementorIntegration;
use TierPricingTable\Integrations\Plugins\MixMatch;
use TierPricingTable\Integrations\Plugins\ProductBundles;
use TierPricingTable\Integrations\Plugins\RankMath\RankMath;
use TierPricingTable\Integrations\Plugins\SEOPress\SEOPress;
use TierPricingTable\Integrations\Plugins\WCCS;
use TierPricingTable\Integrations\Plugins\WCPA;
use TierPricingTable\Integrations\Plugins\WCPProductBundles;
use TierPricingTable\Integrations\Plugins\WooPaymentsMulticurrency;
use TierPricingTable\Integrations\Plugins\WombatProductAddons;
use TierPricingTable\Integrations\Plugins\WooCommerceDeposits;
use TierPricingTable\Integrations\Plugins\WooCommerceProductAddons;
use TierPricingTable\Integrations\Plugins\WooCommerceSubscriptions;
use TierPricingTable\Integrations\Plugins\WOOCS;
use TierPricingTable\Integrations\Plugins\WPAllImport;
use TierPricingTable\Integrations\Plugins\WPMLMulticurrency;
use TierPricingTable\Integrations\Plugins\WPMLMultilingual;
use TierPricingTable\Integrations\Plugins\YithMulticurrency;
use TierPricingTable\Integrations\Plugins\YithRequestAQuote;
use TierPricingTable\Integrations\Plugins\Yoast\Yoast;
use TierPricingTable\Integrations\Themes\Astra;
use TierPricingTable\Integrations\Themes\Avada;
use TierPricingTable\Integrations\Themes\Divi;
use TierPricingTable\Integrations\Themes\Flatsome;
use TierPricingTable\Integrations\Themes\Merchandiser;
use TierPricingTable\Integrations\Themes\OceanWp;
use TierPricingTable\Integrations\Themes\Shopkeeper;
use TierPricingTable\Integrations\Themes\TheRetailer;
use TierPricingTable\Integrations\Themes\Zakra;

class Integrations {
	
	public function __construct() {
		$this->init();
	}
	
	public function init() {
		$themes = apply_filters( 'tiered_pricing_table/integrations/themes', array(
			'avada'        => Avada::class,
			'astra'        => Astra::class,
			'divi'         => Divi::class,
			'oceanWP'      => OceanWp::class,
			'flatsome'     => Flatsome::class,
			'shopkeeper'   => Shopkeeper::class,
			'the retailer' => TheRetailer::class,
			'merchandiser' => Merchandiser::class,
			'zakra'        => Zakra::class,
		) );
		
		$plugins = apply_filters( 'tiered_pricing_table/integrations/plugins', array(
			ElementorIntegration::class,
			WPAllImport::class,
			WooCommerceProductAddons::class,
			WooCommerceSubscriptions::class,
			ProductBundles::class,
			WooCommerceDeposits::class,
			WOOCS::class,
			WCPA::class,
			AeliaMulticurrency::class,
			MixMatch::class,
			
			WCCS::class,
			WPMLMulticurrency::class,
			WPMLMultilingual::class,
			WooPaymentsMulticurrency::class,
			YithMulticurrency::class,
			YithRequestAQuote::class,
			AddifyRequestAQuote::class,
			
			WombatProductAddons::class,
			
			DiscountRulesForWooCommerce::class,
			Curcy::class,
			
			WCPProductBundles::class,
			
			Yoast::class,
			RankMath::class,
			SEOPress::class,
		) );
		
		foreach ( $themes as $themeName => $theme ) {
			if ( strpos( strtolower( wp_get_theme()->name ),
					$themeName ) !== false || ( ! empty( wp_get_theme()->template ) && strpos( strtolower( wp_get_theme()->template ),
						$themeName ) !== false ) ) {
				new $theme();
			}
		}
		
		foreach ( $plugins as $plugin ) {
			new $plugin();
		}
	}
}
