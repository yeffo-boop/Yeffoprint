<?php namespace TierPricingTable\Settings\Sections\Advanced;

use TierPricingTable\Core\ServiceContainer;
use TierPricingTable\Settings\CustomOptions\TPTLinkButton;
use TierPricingTable\Settings\CustomOptions\TPTSwitchOption;
use TierPricingTable\Settings\Sections\SectionAbstract;
use TierPricingTable\Settings\Settings;

class AdvancedSection extends SectionAbstract {
	
	public function getSettings() {
		
		$settings = array();
		$advanced = apply_filters( 'tiered_pricing_table/settings/advanced_settings', array() );
		
		$sectionTitle = array(
			'title' => __( 'Modules', 'tier-pricing-table' ),
			'desc'  => __( 'You can disable or enable specific plugin features.', 'tier-pricing-table' ),
			'id'    => Settings::SETTINGS_PREFIX . 'advanced',
			'type'  => 'title',
		);
		
		$sectionEnd = array(
			'type' => 'sectionend',
			'id'   => Settings::SETTINGS_PREFIX . 'advanced',
		);
		
		$settings[] = $sectionTitle;
		$settings   = array_merge( $settings, $advanced );
		$settings[] = $sectionEnd;
		
		$settings = array_merge( $settings, $this->getCacheSettings() );
		$settings = array_merge( $settings, $this->getDebuggerSettings() );
		
		return $settings;
	}
	
	public function getSlug(): string {
		return 'advanced';
	}
	
	public function getName(): string {
		return __( 'Modules', 'tier-pricing-table' );
	}
	
	public function getSectionCSS(): string {
		return '.form-table:first-of-type tbody { display: flex; flex-wrap: wrap; margin: 10px 0 20px 0; }
		.form-table:first-of-type tr { display: block; border-bottom: none; padding-bottom: 0; }
		.form-table:first-of-type th { display: none; }
		.form-table:first-of-type td { padding: 0 !important; width: 100%; }';
	}
	
	public static function deleteOptions() {
		delete_option( Settings::SETTINGS_PREFIX . 'advanced' );
		delete_option( Settings::SETTINGS_PREFIX . '_addon_category-tiered-pricing' );
		delete_option( Settings::SETTINGS_PREFIX . '_addon_manual-orders' );
		delete_option( Settings::SETTINGS_PREFIX . '_addon_role-based-rules' );
		delete_option( Settings::SETTINGS_PREFIX . '_addon_global-tier-pricing' );
		delete_option( Settings::SETTINGS_PREFIX . '_addon_minimum-quantity' );
		delete_option( Settings::SETTINGS_PREFIX . 'advanced' );
	}
	
	protected function getCacheSettings(): array {
		return array(
			array(
				'title' => __( 'Cache', 'tier-pricing-table' ),
				'desc'  => __( 'Cache improves performance making the plugin not calculate data on each request. Disable to debug issues.',
					'tier-pricing-table' ),
				'type'  => 'title',
			),
			array(
				'title'   => __( 'Enabled', 'tier-pricing-table' ),
				'id'      => Settings::SETTINGS_PREFIX . 'cache_enabled',
				'type'    => TPTSwitchOption::FIELD_TYPE,
				'default' => 'yes',
			),
			array(
				'title'        => __( 'Purge', 'tier-pricing-table' ),
				'button_text'  => __( 'Purge cache', 'tier-pricing-table' ),
				'button_class' => 'button button-large',
				'button_link'  => ServiceContainer::getInstance()->getCache()->getPurgeURL(),
				'type'         => TPTLinkButton::FIELD_TYPE,
			),
			array(
				'type' => 'sectionend',
			),
		);
	}
	
	protected function getDebuggerSettings(): array {
		return array(
			array(
				'title' => __( 'Debug', 'tier-pricing-table' ),
				'desc'  => __( 'Debug mode is useful when you need to track what pricing rule is applying for a cart item.',
					'tier-pricing-table' ),
				'type'  => 'title',
			),
			array(
				'title'   => __( 'Enabled', 'tier-pricing-table' ),
				'id'      => Settings::SETTINGS_PREFIX . 'debug_enabled',
				'type'    => TPTSwitchOption::FIELD_TYPE,
				'default' => 'no',
			),
			array(
				'type' => 'sectionend',
			),
		);
	}
}
