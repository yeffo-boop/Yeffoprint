<?php namespace TierPricingTable\Core;

trait ServiceContainerTrait {

	public function getContainer() {
		return ServiceContainer::getInstance();
	}

}
