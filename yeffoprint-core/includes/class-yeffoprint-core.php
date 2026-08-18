<?php
/**
 * Plugin bootstrap singleton.
 */

defined( 'ABSPATH' ) || exit;

final class YeffoPrint_Core {

	private static ?YeffoPrint_Core $instance = null;

	public static function instance(): YeffoPrint_Core {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->includes();
	}

	/**
	 * Load post-type registrations and (in later phases) the schema
	 * engine, pricing engine, REST endpoints, and admin UI.
	 */
	private function includes(): void {
		require_once YEFFOPRINT_CORE_PATH . 'includes/post-types/class-post-type-registry.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/post-types/class-template-taxonomies.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/post-types/class-template-meta.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/post-types/class-commerce-record-meta.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/schema/class-field-schema.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/query/class-template-query.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/search/class-template-search.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/pricing/class-pricing-rule.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/configurator/quantity-presets.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/api/template-api.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/class-template-schema-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/class-pricing-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-cart-item-keys.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-linked-product.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-cart-pricing.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-order-item-meta.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/class-cart-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/admin/class-admin-menu.php';

		new YeffoPrint_Post_Type_Registry();
		new YeffoPrint_Template_Taxonomies();
		new YeffoPrint_Template_Meta();
		new YeffoPrint_Commerce_Record_Meta();
		new YeffoPrint_Template_Query();
		new YeffoPrint_Template_Search();
		new YeffoPrint_Template_Schema_Controller();
		new YeffoPrint_Pricing_Controller();
		new YeffoPrint_Linked_Product();
		new YeffoPrint_Cart_Pricing();
		new YeffoPrint_Order_Item_Meta();
		new YeffoPrint_Cart_Controller();

		if ( is_admin() ) {
			new YeffoPrint_Admin_Menu();

			require_once YEFFOPRINT_CORE_PATH . 'includes/admin/class-template-editor.php';
			require_once YEFFOPRINT_CORE_PATH . 'includes/admin/class-material-size-editor.php';
			require_once YEFFOPRINT_CORE_PATH . 'includes/admin/class-pricing-rule-editor.php';

			new YeffoPrint_Template_Editor();
			new YeffoPrint_Material_Size_Editor();
			new YeffoPrint_Pricing_Rule_Editor();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once YEFFOPRINT_CORE_PATH . 'includes/cli/class-seed-command.php';
			require_once YEFFOPRINT_CORE_PATH . 'includes/cli/class-shipping-setup-command.php';
			( new YeffoPrint_Seed_Command() )->register();
			( new YeffoPrint_Shipping_Setup_Command() )->register();
		}
	}
}
