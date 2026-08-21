<?php namespace TierPricingTable\Integrations\Plugins\Yoast;

use TierPricingTable\Settings\CustomOptions\TPTSwitchOption;
use TierPricingTable\Settings\Sections\SectionAbstract;

class Settings extends SectionAbstract {
	
	public function getName(): string {
		return 'Yoast SEO';
	}
	
	public function getSlug(): string {
		return 'yoast-seo';
	}
	
	public function getSettings(): array {
		return array(
			array(
				'title' => 'Yoast SEO',
				'id'    => \TierPricingTable\Settings\Settings::SETTINGS_PREFIX . '_subsection_' . $this->getSlug(),
				'desc'  => __( 'Configure the integration with Yoast SEO plugin to use tiered pricing variables in your SEO metadata.',
					'tier-pricing-table' ),
				'type'  => 'title',
			),
			array(
				'title'   => __( 'Enable custom variables', 'tier-pricing-table' ),
				'id'      => \TierPricingTable\Settings\Settings::SETTINGS_PREFIX . 'yoast_enable_variables',
				'type'    => TPTSwitchOption::FIELD_TYPE,
				'default' => 'yes',
				'extended_description'    => __( 'Enable you to use the %%lowest_price%% and %%price_range%% variables to display the lowest price and price range of products with tiered pricing in Yoast SEO metadata.',
					'tier-pricing-table' ),
			),
			array(
				'title'                => __( 'Enhance product schema with tiered pricing offers',
					'tier-pricing-table' ),
				'id'                   => \TierPricingTable\Settings\Settings::SETTINGS_PREFIX . 'yoast_enhance_schema',
				'type'                 => TPTSwitchOption::FIELD_TYPE,
				'default'              => 'no',
				'extended_description' => __( 'Enhance the product schema with tiered pricing offers. Adds an offer for each tier and the lowest price as the main offer.',
					'tier-pricing-table' ),
			),
			array(
				'type' => 'sectionend',
			),
		);
	}
	
	public function isIntegration(): bool {
		return true;
	}
}
