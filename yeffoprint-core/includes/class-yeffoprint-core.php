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
		require_once YEFFOPRINT_CORE_PATH . 'includes/post-types/class-saved-design-meta.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/schema/class-field-schema.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/query/class-template-query.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/search/class-template-search.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/pricing/class-pricing-rule.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/configurator/quantity-presets.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/api/template-api.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/class-template-schema-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/class-pricing-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/class-rest-security.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-cart-item-keys.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-linked-product.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-cart-pricing.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-order-item-meta.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/class-cart-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-custom-design-fee-product.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-custom-order-labels-product.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/uploads/class-secure-upload.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/custom-orders/class-custom-order-meta.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/custom-orders/class-proof-meta.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/custom-orders/class-custom-order-payment.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/class-custom-order-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/accounts/class-account-endpoints.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/class-order-item-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/class-saved-design-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-reorder.php';
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
		new YeffoPrint_Custom_Order_Meta();
		new YeffoPrint_Custom_Order_Payment();
		new YeffoPrint_Custom_Order_Controller();
		new YeffoPrint_Account_Endpoints();
		new YeffoPrint_Order_Item_Controller();
		new YeffoPrint_Saved_Design_Controller();
		new YeffoPrint_Reorder();

		// Flush once whenever needed — not just on activation. The
		// activation-hook flag (yeffoprint-core.php) covers a normal
		// install; the version check covers every other real-world
		// deployment path that skips activation entirely (uploading
		// updated plugin files over FTP/hosting file manager, syncing
		// from git, staging→production pushes) — those never fire
		// register_activation_hook, so without this, /shop-labels/ and
		// every custom rewrite rule silently 404 until someone happens
		// to open Settings → Permalinks and hits Save. Runs at priority
		// 20, after every CPT/endpoint has registered its rewrite rules
		// above at the default priority 10, in the same request.
		add_action( 'init', function () {
			$needs_flush = get_option( 'yeffoprint_core_flush_rewrite_rules' )
				|| get_option( 'yeffoprint_core_rewrite_version' ) !== YEFFOPRINT_CORE_VERSION;

			if ( $needs_flush ) {
				flush_rewrite_rules();
				delete_option( 'yeffoprint_core_flush_rewrite_rules' );
				update_option( 'yeffoprint_core_rewrite_version', YEFFOPRINT_CORE_VERSION );
			}
		}, 20 );

		if ( is_admin() ) {
			new YeffoPrint_Admin_Menu();

			require_once YEFFOPRINT_CORE_PATH . 'includes/admin/class-template-editor.php';
			require_once YEFFOPRINT_CORE_PATH . 'includes/admin/class-material-size-editor.php';
			require_once YEFFOPRINT_CORE_PATH . 'includes/admin/class-pricing-rule-editor.php';
			require_once YEFFOPRINT_CORE_PATH . 'includes/admin/class-custom-order-editor.php';
			require_once YEFFOPRINT_CORE_PATH . 'includes/admin/class-proof-editor.php';

			new YeffoPrint_Template_Editor();
			new YeffoPrint_Material_Size_Editor();
			new YeffoPrint_Pricing_Rule_Editor();
			new YeffoPrint_Custom_Order_Editor();
			new YeffoPrint_Proof_Editor();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once YEFFOPRINT_CORE_PATH . 'includes/cli/class-seed-command.php';
			require_once YEFFOPRINT_CORE_PATH . 'includes/cli/class-shipping-setup-command.php';
			require_once YEFFOPRINT_CORE_PATH . 'includes/cli/class-pages-setup-command.php';
			( new YeffoPrint_Seed_Command() )->register();
			( new YeffoPrint_Shipping_Setup_Command() )->register();
			( new YeffoPrint_Pages_Setup_Command() )->register();
		}
	}
}
