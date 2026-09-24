<?php
/**
 * Draft orders — direct request: "can I get the ability to see draft
 * orders somehow?" WooCommerce's block Checkout saves an order as
 * "Draft" (checkout-draft) the moment a customer presses Place order,
 * and only moves it on once payment goes through — so a draft is a
 * checkout that got as far as paying and then stopped (card declined,
 * Coinbase window closed, tab closed). The admin app's Order History
 * shows them under its Drafts tab.
 *
 * WooCommerce's own nightly cleanup
 * (Blocks\Domain\Services\DraftOrders::delete_expired_draft_orders())
 * deletes any draft untouched for a single day, which would empty that
 * tab before there was a chance to follow up. Its one-day cutoff is
 * hard-coded with no filter, so this swaps its callback on the same
 * scheduled action for one that keeps drafts for RETENTION_DAYS
 * instead — same batch size and same force-delete.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Draft_Order_Retention {

	const RETENTION_DAYS = 30;

	private const CLEANUP_HOOK = 'woocommerce_cleanup_draft_orders';
	private const BATCH_SIZE   = 20;

	public function __construct() {
		add_action( 'init', [ $this, 'replace_cleanup' ], 1 );
	}

	public function replace_cleanup(): void {
		if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Package' ) || ! class_exists( '\Automattic\WooCommerce\Blocks\Domain\Services\DraftOrders' ) ) {
			return;
		}

		try {
			$draft_orders = \Automattic\WooCommerce\Blocks\Package::container()->get( \Automattic\WooCommerce\Blocks\Domain\Services\DraftOrders::class );
		} catch ( \Throwable $e ) {
			return; // Leave WooCommerce's own cleanup in place rather than risk none at all.
		}

		remove_action( self::CLEANUP_HOOK, [ $draft_orders, 'delete_expired_draft_orders' ] );
		add_action( self::CLEANUP_HOOK, [ $this, 'delete_expired' ] );
	}

	public function delete_expired(): void {
		$orders = wc_get_orders( [
			'date_modified' => '<=' . strtotime( '-' . self::RETENTION_DAYS . ' days' ),
			'limit'         => self::BATCH_SIZE,
			'status'        => 'checkout-draft',
			'type'          => 'shop_order',
		] );

		foreach ( $orders as $order ) {
			// Belt and braces, same check WooCommerce's own cleanup makes
			// before deleting: never touch anything that isn't still a draft.
			if ( $order instanceof \WC_Order && $order->has_status( 'checkout-draft' ) ) {
				$order->delete( true );
			}
		}
	}
}
