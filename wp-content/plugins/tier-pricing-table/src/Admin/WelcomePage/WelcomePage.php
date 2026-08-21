<?php namespace TierPricingTable\Admin\WelcomePage;

use TierPricingTable\Core\ServiceContainerTrait;

class WelcomePage {
	
	use ServiceContainerTrait;
	
	const PAGE_SLUG = 'tiered-pricing-table-welcome';
	
	public function __construct() {
		
		add_action( 'admin_menu', function () {
			add_submenu_page( '__none__', __( 'Welcome to Tiered Pricing for WooCommerce!', 'tier-pricing-table' ),
				__( 'Hidden Admin Page', 'tier-pricing-table' ), 'manage_options', self::PAGE_SLUG,
				array( $this, 'render' ), 99 );
		} );
	}
	
	public function render() {
		$this->getContainer()->getFileManager()->includeTemplate( 'admin/welcome-page/welcome-page.php' );
	}
	
	public static function getURL(): string {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}
}
