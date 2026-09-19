<?php
/**
 * Deletes a submitted go-live server password 30 days after the
 * customer submitted it (YeffoPrint_Web_Design_Project_Meta::
 * CREDENTIAL_TTL) — once a site has gone live, or 30 days have simply
 * passed, there's no legitimate reason to keep holding a client's
 * server password. Same WP-Cron hourly-sweep shape as
 * class-proof-reminder-scheduler.php (this plugin's only other
 * scheduled job): self-healing, no per-order event to scheduler/
 * reschedule/cancel, an up-to-~1h fuzziness against a 30-day threshold
 * is irrelevant in practice.
 *
 * "Mark site as live" (class-admin-web-design-controller.php's
 * mark_live()) already purges immediately on that action — this sweep
 * only ever catches the credentials of a project that was never marked
 * live within the window.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Web_Design_Credential_Purge {

	private const HOOK = 'yeffoprint_web_design_credential_purge_sweep';

	public function __construct() {
		add_action( self::HOOK, [ $this, 'sweep' ] );
		add_action( 'init', [ $this, 'ensure_scheduled' ] );
	}

	public function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::HOOK );
		}
	}

	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	public function sweep(): void {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}

		$orders = wc_get_orders( [
			'limit'      => -1,
			'return'     => 'ids',
			'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small, infrequent (hourly) sweep; no indexed alternative for "expiry has passed" over order meta.
				[
					'key'     => YeffoPrint_Web_Design_Project_Meta::GOLIVE_EXPIRES_AT,
					'value'   => current_time( 'mysql' ),
					'compare' => '<=',
					'type'    => 'DATETIME',
				],
				[
					'key'     => YeffoPrint_Web_Design_Project_Meta::GOLIVE_PURGED,
					'compare' => 'NOT EXISTS',
				],
			],
		] );

		foreach ( $orders as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof \WC_Order ) {
				YeffoPrint_Web_Design_Project_Meta::purge_golive_secret( $order, __( '30-day retention window elapsed', 'yeffoprint-core' ) );
			}
		}
	}
}
