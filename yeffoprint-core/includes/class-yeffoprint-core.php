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
		require_once YEFFOPRINT_CORE_PATH . 'includes/post-types/class-sticker-size-meta.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/schema/class-field-schema.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/lib/class-qr-code-gen.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/qr/class-qr-renderer.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/class-qr-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/query/class-template-query.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/search/class-template-search.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/pricing/class-pricing-rule.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/pricing/class-sticker-pricing.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/configurator/quantity-presets.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/api/template-api.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/class-template-schema-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/class-pricing-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/class-rest-security.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/class-nonce-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-cart-item-keys.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-linked-product.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-cart-pricing.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-card-surcharge.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-card-surcharge-blocks-integration.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-order-item-meta.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/class-cart-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-custom-design-fee-product.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-custom-order-labels-product.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-custom-sticker-product.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/uploads/class-secure-upload.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/custom-orders/class-custom-order-meta.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/custom-orders/class-proof-meta.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/custom-orders/class-custom-order-payment.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/class-custom-order-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/class-custom-sticker-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/class-proof-approval-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/accounts/class-account-endpoints.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rewards/class-rewards.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/class-order-item-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/class-saved-design-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-reorder.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-payment-webhook-secret.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/class-payment-webhook-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/admin/class-admin-menu.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/tracking-providers/class-tracking-exception.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/tracking-providers/interface-tracking-provider.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/tracking-providers/class-usps-tracking-provider.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/tracking-providers/class-ups-tracking-provider.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/tracking-providers/class-tracking-provider-registry.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-order-tracking.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/class-order-tracking-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/class-contact-controller.php';

		new YeffoPrint_Post_Type_Registry();
		new YeffoPrint_Template_Taxonomies();
		new YeffoPrint_Template_Meta();
		new YeffoPrint_Commerce_Record_Meta();
		new YeffoPrint_Sticker_Size_Meta();
		new YeffoPrint_Template_Query();
		new YeffoPrint_Template_Search();
		new YeffoPrint_Template_Schema_Controller();
		new YeffoPrint_Pricing_Controller();
		new YeffoPrint_Qr_Controller();
		new YeffoPrint_Linked_Product();
		new YeffoPrint_Cart_Pricing();
		new YeffoPrint_Card_Surcharge();
		new YeffoPrint_Card_Surcharge_Blocks_Integration();
		new YeffoPrint_Order_Item_Meta();
		new YeffoPrint_Cart_Controller();
		new YeffoPrint_Nonce_Controller();
		new YeffoPrint_Custom_Order_Meta();
		new YeffoPrint_Custom_Order_Payment();
		new YeffoPrint_Custom_Order_Controller();
		new YeffoPrint_Custom_Sticker_Controller();
		new YeffoPrint_Proof_Approval_Controller();
		new YeffoPrint_Account_Endpoints();
		new YeffoPrint_Rewards();
		new YeffoPrint_Order_Item_Controller();
		new YeffoPrint_Saved_Design_Controller();
		new YeffoPrint_Reorder();
		new YeffoPrint_Payment_Webhook_Controller();
		new YeffoPrint_Order_Tracking();
		new YeffoPrint_Order_Tracking_Controller();
		new YeffoPrint_Contact_Controller();

		// The gateway classes extend \WC_Payment_Gateway directly (a
		// class declaration, not a lazy reference inside a method body)
		// — unlike every other class in this plugin, that means the
		// *file itself* needs WooCommerce's class already resolvable
		// the moment it's require_once'd, not just by the time it's
		// used. Loading them here, inside the one filter WooCommerce
		// itself calls to ask "what gateways exist" (fired from deep
		// inside its own fully-booted payment-gateway registry), is the
		// standard safe pattern — it can never fire before WC_Payment_
		// Gateway exists, regardless of plugin load order edge cases
		// `Requires Plugins: woocommerce` (yeffoprint-core.php) doesn't
		// fully rule out on its own.
		add_filter( 'woocommerce_payment_gateways', static function ( array $gateways ): array {
			require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-manual-payment-gateway.php';
			require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-venmo-gateway.php';
			require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-zelle-gateway.php';

			$gateways[] = 'YeffoPrint_Venmo_Gateway';
			$gateways[] = 'YeffoPrint_Zelle_Gateway';
			return $gateways;
		} );

		// Separate registration for the Checkout *block* (Store API) —
		// found live: the classic WC_Payment_Gateway above worked fine
		// (enabled, saved, even present in the checkout page's initial
		// HTML) but never showed as a selectable option, because this
		// site's Checkout page uses the block, not the classic
		// [woocommerce_checkout] shortcode. Core WooCommerce gateways
		// ship this same registration built into WooCommerce itself,
		// which is why only the custom ones were missing. Same lazy-
		// require reasoning as the filter above — AbstractPaymentMethodType
		// is also a class *declaration* dependency, and this action only
		// ever fires from deep inside WooCommerce Blocks' own bootstrap.
		add_action( 'woocommerce_blocks_payment_method_type_registration', static function ( $registry ): void {
			require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-manual-payment-blocks-support.php';
			require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-venmo-blocks-support.php';
			require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-zelle-blocks-support.php';

			$registry->register( new YeffoPrint_Venmo_Blocks_Support() );
			$registry->register( new YeffoPrint_Zelle_Blocks_Support() );
		} );

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
			require_once YEFFOPRINT_CORE_PATH . 'includes/admin/class-field-preset-editor.php';
			require_once YEFFOPRINT_CORE_PATH . 'includes/admin/class-material-size-editor.php';
			require_once YEFFOPRINT_CORE_PATH . 'includes/admin/class-sticker-size-editor.php';
			require_once YEFFOPRINT_CORE_PATH . 'includes/admin/class-pricing-rule-editor.php';
			require_once YEFFOPRINT_CORE_PATH . 'includes/admin/class-custom-order-editor.php';
			require_once YEFFOPRINT_CORE_PATH . 'includes/admin/class-proof-editor.php';

			new YeffoPrint_Template_Editor();
			new YeffoPrint_Field_Preset_Editor();
			new YeffoPrint_Material_Size_Editor();
			new YeffoPrint_Sticker_Size_Editor();
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
