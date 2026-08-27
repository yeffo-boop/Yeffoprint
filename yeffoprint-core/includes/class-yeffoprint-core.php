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
		require_once YEFFOPRINT_CORE_PATH . 'includes/post-types/class-web-design-package-meta.php';
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
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/admin/class-admin-ping-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/admin/class-admin-pricing-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/admin/class-admin-template-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/admin/class-admin-field-preset-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/admin/class-admin-custom-order-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/admin/class-admin-proof-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/admin/class-admin-order-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/admin/class-admin-dashboard-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/admin/class-admin-settings-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/admin/class-admin-surcharge-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/admin/class-admin-rewards-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/admin/class-admin-manual-order-controller.php';
		// Moved out of the is_admin()-only block below — YeffoPrint_Admin_Dashboard_Controller
		// calls YeffoPrint_Dashboard_Widgets::due_date_days() on every
		// /admin/dashboard-summary REST request, which isn't an
		// is_admin() context; the class itself is still only ever
		// instantiated from class-admin-menu.php's render_dashboard().
		require_once YEFFOPRINT_CORE_PATH . 'includes/admin/class-dashboard-widgets.php';
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
		require_once YEFFOPRINT_CORE_PATH . 'includes/custom-orders/class-proof-reminder-scheduler.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/custom-orders/class-custom-order-payment.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/custom-orders/class-manual-order-creator.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/class-custom-order-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/class-custom-sticker-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/class-proof-approval-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/accounts/class-account-endpoints.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/accounts/class-social-login.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rewards/class-rewards.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rewards/class-referrals.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/class-order-item-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/class-saved-design-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-reorder.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-payment-webhook-secret.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/class-payment-webhook-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/maintenance/class-maintenance-sub-meta.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/maintenance/class-stripe-webhook-secret.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/maintenance/class-stripe-webhook-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/promotions/class-promo-themes.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/admin/class-admin-menu.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/tracking-providers/class-tracking-exception.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/tracking-providers/interface-tracking-provider.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/tracking-providers/class-usps-tracking-provider.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/tracking-providers/class-ups-tracking-provider.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/tracking-providers/class-tracking-provider-registry.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-order-tracking.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-order-production-status.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-order-shipment-status.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-order-number-format.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/class-order-tracking-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/class-contact-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/class-web-design-quote-controller.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/access/class-web-design-page-gate.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/telegram/class-telegram-webhook-secret.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/telegram/class-telegram-settings.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/telegram/class-telegram-client.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/telegram/class-telegram-webhook-sync.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/telegram/class-telegram-faq.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/telegram/class-telegram-order-lookup.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/telegram/class-telegram-escalation.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/telegram/class-telegram-message-handler.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/telegram/class-telegram-admin-alerts.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/telegram/class-telegram-order-notifications.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/telegram/class-telegram-account-link.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/telegram/class-telegram-callback-handler.php';
		require_once YEFFOPRINT_CORE_PATH . 'includes/rest/class-telegram-webhook-controller.php';

		new YeffoPrint_Post_Type_Registry();
		new YeffoPrint_Template_Taxonomies();
		new YeffoPrint_Template_Meta();
		new YeffoPrint_Commerce_Record_Meta();
		new YeffoPrint_Web_Design_Package_Meta();
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
		new YeffoPrint_Admin_Ping_Controller();
		new YeffoPrint_Admin_Pricing_Controller();
		new YeffoPrint_Admin_Template_Controller();
		new YeffoPrint_Admin_Field_Preset_Controller();
		new YeffoPrint_Admin_Custom_Order_Controller();
		new YeffoPrint_Admin_Proof_Controller();
		new YeffoPrint_Admin_Order_Controller();
		new YeffoPrint_Admin_Dashboard_Controller();
		new YeffoPrint_Admin_Settings_Controller();
		new YeffoPrint_Admin_Surcharge_Controller();
		new YeffoPrint_Admin_Rewards_Controller();
		new YeffoPrint_Admin_Manual_Order_Controller();
		new YeffoPrint_Custom_Order_Meta();
		new YeffoPrint_Custom_Order_Payment();
		new YeffoPrint_Custom_Order_Controller();
		new YeffoPrint_Custom_Sticker_Controller();
		new YeffoPrint_Proof_Approval_Controller();
		new YeffoPrint_Account_Endpoints();
		new YeffoPrint_Social_Login();
		new YeffoPrint_Rewards();
		new YeffoPrint_Referrals();
		new YeffoPrint_Order_Item_Controller();
		new YeffoPrint_Saved_Design_Controller();
		new YeffoPrint_Reorder();
		new YeffoPrint_Payment_Webhook_Controller();
		new YeffoPrint_Maintenance_Sub_Meta();
		new YeffoPrint_Stripe_Webhook_Controller();
		new YeffoPrint_Order_Tracking();
		new YeffoPrint_Order_Production_Status();
		new YeffoPrint_Order_Shipment_Status();
		new YeffoPrint_Order_Number_Format();
		new YeffoPrint_Order_Tracking_Controller();
		new YeffoPrint_Web_Design_Page_Gate();
		new YeffoPrint_Contact_Controller();
		new YeffoPrint_Web_Design_Quote_Controller();
		new YeffoPrint_Telegram_Webhook_Sync();
		new YeffoPrint_Telegram_Webhook_Controller();
		new YeffoPrint_Telegram_Admin_Alerts();
		new YeffoPrint_Telegram_Order_Notifications();
		new YeffoPrint_Proof_Reminder_Scheduler();

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
			require_once YEFFOPRINT_CORE_PATH . 'includes/admin-app/class-admin-token-bridge.php';
			require_once YEFFOPRINT_CORE_PATH . 'includes/admin-app/class-admin-app.php';
			new YeffoPrint_Admin_App();

			new YeffoPrint_Admin_Menu();

			require_once YEFFOPRINT_CORE_PATH . 'includes/admin/class-admin-shell.php';
			require_once YEFFOPRINT_CORE_PATH . 'includes/admin/class-template-editor.php';
			require_once YEFFOPRINT_CORE_PATH . 'includes/admin/class-field-preset-editor.php';
			require_once YEFFOPRINT_CORE_PATH . 'includes/admin/class-material-size-editor.php';
			require_once YEFFOPRINT_CORE_PATH . 'includes/admin/class-sticker-size-editor.php';
			require_once YEFFOPRINT_CORE_PATH . 'includes/admin/class-pricing-rule-editor.php';
			require_once YEFFOPRINT_CORE_PATH . 'includes/admin/class-custom-order-editor.php';
			require_once YEFFOPRINT_CORE_PATH . 'includes/admin/class-proof-editor.php';
			require_once YEFFOPRINT_CORE_PATH . 'includes/admin/class-rewards-admin.php';
			require_once YEFFOPRINT_CORE_PATH . 'includes/admin/class-rewards-order-box.php';
			require_once YEFFOPRINT_CORE_PATH . 'includes/admin/class-design-setup-menu.php';
			require_once YEFFOPRINT_CORE_PATH . 'includes/admin/class-surcharge-admin.php';
			require_once YEFFOPRINT_CORE_PATH . 'includes/admin/class-web-design-package-editor.php';

			new YeffoPrint_Admin_Shell();
			new YeffoPrint_Template_Editor();
			new YeffoPrint_Field_Preset_Editor();
			new YeffoPrint_Material_Size_Editor();
			new YeffoPrint_Sticker_Size_Editor();
			new YeffoPrint_Pricing_Rule_Editor();
			new YeffoPrint_Custom_Order_Editor();
			new YeffoPrint_Proof_Editor();
			new YeffoPrint_Rewards_Admin();
			new YeffoPrint_Rewards_Order_Box();
			new YeffoPrint_Design_Setup_Menu();
			new YeffoPrint_Surcharge_Admin();
			new YeffoPrint_Web_Design_Package_Editor();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once YEFFOPRINT_CORE_PATH . 'includes/cli/class-seed-command.php';
			require_once YEFFOPRINT_CORE_PATH . 'includes/cli/class-shipping-setup-command.php';
			require_once YEFFOPRINT_CORE_PATH . 'includes/cli/class-pages-setup-command.php';
			require_once YEFFOPRINT_CORE_PATH . 'includes/cli/class-web-design-packages-setup-command.php';
			require_once YEFFOPRINT_CORE_PATH . 'includes/cli/class-telegram-setup-command.php';
			( new YeffoPrint_Seed_Command() )->register();
			( new YeffoPrint_Shipping_Setup_Command() )->register();
			( new YeffoPrint_Pages_Setup_Command() )->register();
			( new YeffoPrint_Web_Design_Packages_Setup_Command() )->register();
			( new YeffoPrint_Telegram_Setup_Command() )->register();
		}
	}
}
