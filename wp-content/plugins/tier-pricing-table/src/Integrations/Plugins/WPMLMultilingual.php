<?php namespace TierPricingTable\Integrations\Plugins;

class WPMLMultilingual extends PluginIntegrationAbstract {

	/**
	 * Suffixes of product meta keys whose full name embeds a role slug or user ID and therefore cannot
	 * be declared statically in wpml-config.xml (e.g. "_wholesale_fixed_price_rules",
	 * "_user_42_tiered_price_discount"). Matched against every meta key stored on the product.
	 *
	 * The base (role-less) equivalents — "_fixed_price_rules" etc. — are handled by wpml-config.xml and
	 * are intentionally excluded by the pattern in getDynamicPricingMetaKeys().
	 */
	const DYNAMIC_KEY_SUFFIXES = array(
		'fixed_price_rules',
		'percentage_price_rules',
		'tiered_price_rules_type',
		'tiered_price_minimum_qty',
		'tiered_price_regular_price',
		'tiered_price_sale_price',
		'tiered_price_discount',
		'tiered_price_discount_type',
		'tiered_price_pricing_type',
		'tiered_price_tax_status',
		'tiered_price_tax_class',
	);

	/**
	 * Re-entrancy guard for the WPML sync path.
	 *
	 * @var bool
	 */
	protected $syncing = false;

	public function run() {

		// WPML: push the original's dynamic pricing meta to its translations whenever a product or one
		// of its translations is saved, or a translation job completes.
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			add_action( 'wpml_after_save_post', array( $this, 'syncDynamicPricingMeta' ), 20 );
			add_action( 'wpml_pro_translation_completed', array( $this, 'syncDynamicPricingMeta' ), 20 );
		}

		// Polylang: declare the dynamic keys as "copy" fields so they travel to translations. Polylang
		// already reads wpml-config.xml for the static keys, but has no wildcard support for these.
		if ( defined( 'POLYLANG_VERSION' ) || function_exists( 'pll_get_post_translations' ) ) {
			add_filter( 'pll_copy_post_metas', array( $this, 'addPolylangCopyKeys' ), 10, 4 );
		}
	}

	/**
	 * Copy role-based and user-based tiered pricing meta from the original product to its translations.
	 *
	 * Without this, role/user tier prices configured on the original product never reach its
	 * translations, so a customer viewing a translated product sees no role/user pricing.
	 *
	 * @param  int  $postId  A product ID in any language (the original or one of its translations).
	 *
	 * @return void
	 */
	public function syncDynamicPricingMeta( $postId ) {

		if ( $this->syncing || 'product' !== get_post_type( $postId ) ) {
			return;
		}

		// Always sync from the original (source of truth), regardless of which language triggered the save.
		$originalId = apply_filters( 'wpml_original_element_id', null, $postId, 'post_product' );

		if ( ! $originalId ) {
			$originalId = $postId;
		}

		$metaKeys = $this->getDynamicPricingMetaKeys( (int) $originalId );

		if ( empty( $metaKeys ) ) {
			return;
		}

		// sync_to_translations() writes meta on the translations; guard against re-entering this handler.
		$this->syncing = true;

		foreach ( $metaKeys as $metaKey ) {
			// WPML resolves the translations of $originalId and copies the value to each of them.
			do_action( 'wpml_sync_custom_field', (int) $originalId, $metaKey );
		}

		$this->syncing = false;
	}

	/**
	 * Append the source product's dynamic pricing keys to Polylang's list of meta to copy.
	 *
	 * @param  string[]  $keys  Meta keys Polylang will copy to the translation.
	 * @param  bool      $sync  Whether this is an ongoing sync (vs. an initial copy).
	 * @param  int       $from  Source (original) post ID.
	 * @param  int       $to    Target (translation) post ID.
	 *
	 * @return string[]
	 */
	public function addPolylangCopyKeys( $keys, $sync, $from, $to ) {

		if ( ! $from || 'product' !== get_post_type( $from ) ) {
			return $keys;
		}

		return array_values( array_unique( array_merge( (array) $keys,
			$this->getDynamicPricingMetaKeys( (int) $from ) ) ) );
	}

	/**
	 * All role-based / user-based tiered pricing meta keys actually present on a product.
	 *
	 * @param  int  $productId
	 *
	 * @return string[]
	 */
	protected function getDynamicPricingMetaKeys( int $productId ): array {

		$allKeys = array_keys( get_post_meta( $productId ) );

		// _{role}_<suffix> (role may contain underscores, e.g. shop_manager) or _user_{id}_<suffix>.
		$pattern = '/^_(?:user_\d+|[a-z0-9\-]+(?:_[a-z0-9\-]+)*)_(?:' . implode( '|',
				self::DYNAMIC_KEY_SUFFIXES ) . ')$/';

		return array_values( array_filter( $allKeys, function ( $key ) use ( $pattern ) {
			return (bool) preg_match( $pattern, $key );
		} ) );
	}

	public function getTitle(): string {
		return 'WPML / Polylang Multilingual';
	}

	public function getIconURL(): string {
		return $this->getContainer()->getFileManager()->locateAsset( 'admin/integrations/wpml-multicurrency-icon.png' );
	}

	public function getAuthorURL(): string {
		return 'https://wpml.org/documentation/related-projects/woocommerce-multilingual/';
	}

	public function getDescription(): string {
		return __( 'Copy role-based and user-based tiered pricing to product translations when using WPML or Polylang.', 'tier-pricing-table' );
	}

	public function getSlug(): string {
		return 'wpml-multilingual';
	}

	public function getIntegrationCategory(): string {
		return 'other';
	}
}
