<?php
/**
 * BulkLabelsBanner class.
 *
 * @package Automattic\WCShipping\Banners
 */

namespace Automattic\WCShipping\Banners;

use Automattic\WCShipping\Connect\WC_Connect_Functions;
use Automattic\WCShipping\Connect\WC_Connect_Service_Settings_Store;
use Automattic\WCShipping\LabelPurchase\OrdersShippingContextRESTController;
use Automattic\WCShipping\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the bulk-labels selection banner on the order-list page.
 */
class BulkLabelsBanner {

	const BULK_ACTION_CREATE_SHIPPING_LABELS = 'wcshipping_create_shipping_labels';

	/**
	 * Service settings store used to read the saved paper-size preference.
	 *
	 * @var WC_Connect_Service_Settings_Store
	 */
	private $settings_store;

	/**
	 * Constructor.
	 *
	 * @param WC_Connect_Service_Settings_Store $settings_store Used to read the merchant's saved paper-size preference.
	 */
	public function __construct( WC_Connect_Service_Settings_Store $settings_store ) {
		$this->settings_store = $settings_store;
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'register_bulk_action' ) );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'register_bulk_action' ) );
	}

	/**
	 * Enqueue the bulk labels banner script on order list pages.
	 */
	public function enqueue_scripts() {
		if ( ! $this->should_render() ) {
			return;
		}

		/**
		 * Fires when a WCShipping entry-point script should be enqueued.
		 *
		 * @since 2.4.0
		 */
		do_action(
			'wcshipping_enqueue_script',
			'woocommerce-shipping-bulk-labels-banner',
			array(
				// Surface the batch cap so the JS gates can match the
				// downstream batch endpoints without re-declaring
				// BATCH_SIZE_CAP on the client.
				'bulk_labels_max_orders' => OrdersShippingContextRESTController::BATCH_SIZE_CAP,
				// Full purchase settings, hydrated on the orders list page so
				// consumers of WCShipping_Config can read the saved paper-size
				// preference without a round-trip, and so any client-side
				// updater (e.g. `persistPaperSize`) can POST the complete
				// current row back to the account-settings endpoint. The
				// endpoint's `update_account_settings()` defaults any missing
				// boolean to `false`, so a paper-size-only POST would silently
				// flip `enabled`, `email_receipts`, etc. and disable the
				// label-purchase metabox account-wide.
				'accountSettings'        => array(
					'purchaseSettings' => $this->settings_store->get_account_settings(),
				),
			)
		);
	}

	/**
	 * Add the bulk labels action to the order list bulk actions menu.
	 *
	 * @param array $actions Bulk actions keyed by action ID.
	 * @return array
	 */
	public function register_bulk_action( array $actions ): array {
		if ( ! $this->should_render() ) {
			return $actions;
		}

		$action = array(
			self::BULK_ACTION_CREATE_SHIPPING_LABELS => __( 'Fulfill with labels', 'woocommerce-shipping' ),
		);

		if ( isset( $actions['trash'] ) ) {
			return $this->insert_action_after( $actions, 'trash', $action );
		}

		return array_merge( $actions, $action );
	}

	/**
	 * Whether the banner should render on the current screen.
	 *
	 * @return bool
	 */
	private function should_render(): bool {
		if ( ! Utils::is_orders_screen() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check of a GET param.
		$action = isset( $_GET['action'] ) ? wc_clean( wp_unslash( $_GET['action'] ) ) : '';

		if ( 'edit' === $action ) {
			return false;
		}

		return WC_Connect_Functions::user_can_manage_labels() !== false;
	}

	/**
	 * Insert a bulk action after an existing action.
	 *
	 * @param array  $actions Bulk actions keyed by action ID.
	 * @param string $after_action Action ID to insert after.
	 * @param array  $new_action Action to insert.
	 * @return array
	 */
	private function insert_action_after( array $actions, string $after_action, array $new_action ): array {
		$updated_actions = array();

		foreach ( $actions as $action_id => $label ) {
			$updated_actions[ $action_id ] = $label;

			if ( $after_action === $action_id ) {
				$updated_actions = array_merge( $updated_actions, $new_action );
			}
		}

		return $updated_actions;
	}
}
