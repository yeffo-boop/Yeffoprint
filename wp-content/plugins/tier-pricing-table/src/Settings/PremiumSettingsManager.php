<?php namespace TierPricingTable\Settings;

use TierPricingTable\Core\ServiceContainerTrait;

/**
 * Class PremiumSettingsManager
 *
 * @package TierPricingTable\Settings
 */
class PremiumSettingsManager {
	
	use ServiceContainerTrait;
	
	public $premiumSubsections = array(
		'tier_pricing_table__subsection_catalog_prices-description',
		'tier_pricing_table__subsection_summary-description',
		'tier_pricing_table__subsection_cart-description',
		'tier_pricing_table__subsection_cart-upsells-description',
		'tier_pricing_table__subsection_non-logged-in-users-description',
		'tier_pricing_table__subsection_rank_math-description',
		'tier_pricing_table__subsection_yoast-seo-description',
		'tier_pricing_table__subsection_seopress-description',
	);
	
	public function __construct() {
		add_action( 'woocommerce_settings_' . Settings::SETTINGS_PAGE, array( $this, 'scripts' ) );
	}
	
	protected function getConfig(): array {
		return array(
			'premiumSubsections' => $this->premiumSubsections,
		);
	}
	
	public function scripts() {
		?>
		<script>
			jQuery(document).ready(function ($) {

				const config = JSON.parse('<?php echo json_encode( $this->getConfig() ); ?>');

				$.each($('[data-tiered-pricing-premium-option]'), function (i, el) {
					const row = $(el).closest('tr');

					if (!row) {
						return;
					}
					const $premiumLabel = jQuery('<span>');
					$premiumLabel.addClass('tpt_premium_option_label');
					$premiumLabel.text('Available in premium version');

					row.find('th').append($premiumLabel);
					row.find('td').addClass('tpt_premium_option');

				});

				config.premiumSubsections.forEach(function (subsectionId) {

					const $subsection = $('#' + subsectionId);
					const $title = $subsection.prev('h2');

					const $premiumLabel = jQuery('<span>');
					$premiumLabel.addClass('tpt_premium_subsection_label');
					$premiumLabel.text('Available in premium version');

					$title.append($premiumLabel);

					$subsection.next('table').addClass('tpt_premium_subsection');
				});

			});
		</script>
		<?php
	}
}