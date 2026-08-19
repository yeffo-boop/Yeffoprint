<?php namespace TierPricingTable\Settings\Sections\GeneralSection\Subsections;

use TierPricingTable\Settings\Sections\SubsectionAbstract;
use TierPricingTable\Settings\Settings;

class HiddenOptionsSubsection extends SubsectionAbstract {
	
	public function getTitle(): string {
		return '';
	}
	
	public function getDescription(): string {
		return 'hidden_options';
	}
	
	public function getSlug(): string {
		return 'hidden_options';
	}
	
	protected function getCustomAttributes() {
		return array(
			'tpt_hidden_option' => 'tpt_hidden_option',
		);
	}
	
	public function getSettings(): array {
		return array(
			// Hidden fields
			array(
				'id'        => Settings::SETTINGS_PREFIX . 'head_quantity_text',
				'row_class' => 'tpt_hidden_option',
				'type'      => 'text',
			),
			array(
				'id'        => Settings::SETTINGS_PREFIX . 'head_price_text',
				'row_class' => 'tpt_hidden_option',
				'type'      => 'text',
			),
			array(
				'id'        => Settings::SETTINGS_PREFIX . 'head_discount_text',
				'row_class' => 'tpt_hidden_option',
				'type'      => 'text',
			),
		);
	}
}
