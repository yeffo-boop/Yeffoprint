<?php namespace TierPricingTable\Addons\GlobalTieredPricing\CPT\Columns;

use TierPricingTable\Addons\GlobalTieredPricing\Formatter;
use TierPricingTable\Addons\GlobalTieredPricing\GlobalPricingRule;
use WC_Product;
use WP_Term;

class AppliedProducts {
	
	public function getName(): string {
		return __( 'Products', 'tier-pricing-table' );
	}
	
	public function render( GlobalPricingRule $rule ) {
		$hasProducts   = $this->showProducts( $rule->getIncludedProducts() );
		$hasCategories = $this->showTerms( $rule->getIncludedProductCategories(), __( 'Categories', 'tier-pricing-table' ) );
		$hasTags       = $this->showTerms( $rule->getIncludedProductTags(), __( 'Tags', 'tier-pricing-table' ) );
		$hasBrands     = $this->showTerms( $rule->getIncludedProductBrands(), __( 'Brands', 'tier-pricing-table' ) );
		
		$excludedProducts   = $rule->getExcludedProducts();
		$excludedCategories = $rule->getExcludedProductCategories();
		$excludedTags       = $rule->getExcludedProductTags();
		$excludedBrands     = $rule->getExcludedProductBrands();
		$hasExceptions      = ! empty( $excludedProducts ) || ! empty( $excludedCategories ) || ! empty( $excludedTags ) || ! empty( $excludedBrands );
		
		if ( ! $hasProducts && ! $hasCategories && ! $hasTags && ! $hasBrands ) {
			$badgeText = $hasExceptions ? __( 'Applied to every product', 'tier-pricing-table' ) : __( 'Applied to every product', 'tier-pricing-table' );
			?>
			<span style="display: inline-block; background: #e0f0fa; color: #0070bc; border: 1px solid #bae0ff; padding: 4px 10px; border-radius: 4px; font-size: 13px; font-weight: 500; margin-bottom: 12px; line-height: 1.4;">
				<?php echo esc_html( $badgeText ); ?>
			</span>
			<?php
		}

		$this->showProducts( $excludedProducts, false );
		$this->showTerms( $excludedCategories, __( 'Excluded Categories', 'tier-pricing-table' ) );
		$this->showTerms( $excludedTags, __( 'Excluded Tags', 'tier-pricing-table' ) );
		$this->showTerms( $excludedBrands, __( 'Excluded Brands', 'tier-pricing-table' ) );
	}
	
	public function showProducts( array $productsIds, $included = true ): bool {
		
		$moreThanCanBeShown = count( $productsIds ) > 10;
		
		$productsIds = array_slice( $productsIds, 0, 5 );
		
		$products = array_filter( array_map( function ( $productId ) {
			return wc_get_product( $productId );
		}, $productsIds ) );
		
		if ( ! empty( $products ) ) {
			$title = $included ? __( 'Products', 'tier-pricing-table' ) : __( 'Excluded Products', 'tier-pricing-table' );
			
			echo '<div style="margin-bottom: 12px;">';
			echo sprintf('<strong style="display: block; margin-bottom: 4px;">%s:</strong>', esc_html($title));
			echo '<div style="display: flex; flex-wrap: wrap; gap: 4px;">';
			
			foreach ($products as $product) {
				$link = get_edit_post_link( $product->get_parent_id() ? $product->get_parent_id() : $product->get_id() );
				echo sprintf(
					'<a href="%s" target="_blank" style="display: inline-block; padding: 2px 8px; background: #f0f0f1; border-radius: 3px; text-decoration: none; font-size: 12px; color: #3c434a; border: 1px solid #dcdcdc; line-height: 1.4;">%s</a>',
					esc_url( $link ),
					esc_html( $product->get_name() )
				);
			}
			
			if ( $moreThanCanBeShown ) {
				echo '<span style="display: inline-block; padding: 2px 8px; background: #f0f0f1; border-radius: 3px; font-size: 12px; color: #8c8f94; border: 1px solid #dcdcdc; line-height: 1.4;">...</span>';
			}
			
			echo '</div></div>';
			
			return true;
		}
		
		return false;
	}
	
	public function showTerms( array $termsIds, string $title ): bool {
		$moreThanCanBeShown = count( $termsIds ) > 10;
		$termsIds           = array_slice( $termsIds, 0, 10 );
		
		$terms = array_filter( array_map( function ( $termId ) {
			return get_term( $termId );
		}, $termsIds ) );
		
		$terms = array_filter( $terms, function ( $term ) {
			return $term instanceof WP_Term;
		} );
		
		if ( ! empty( $terms ) ) {
			
			echo '<div style="margin-bottom: 12px;">';
			echo sprintf('<strong style="display: block; margin-bottom: 4px;">%s:</strong>', esc_html($title));
			echo '<div style="display: flex; flex-wrap: wrap; gap: 4px;">';
			
			foreach ($terms as $term) {
				$link = get_edit_term_link( $term->term_id );
				$linkHtml = $link ? esc_url( $link ) : '#';
				echo sprintf(
					'<a href="%s" target="_blank" style="display: inline-block; padding: 2px 8px; background: #f0f0f1; border-radius: 3px; text-decoration: none; font-size: 12px; color: #3c434a; border: 1px solid #dcdcdc; line-height: 1.4;">%s</a>',
					$linkHtml,
					esc_html( $term->name )
				);
			}
			
			if ( $moreThanCanBeShown ) {
				echo '<span style="display: inline-block; padding: 2px 8px; background: #f0f0f1; border-radius: 3px; font-size: 12px; color: #8c8f94; border: 1px solid #dcdcdc; line-height: 1.4;">...</span>';
			}
			
			echo '</div></div>';
			
			return true;
		}
		
		return false;
	}
}
