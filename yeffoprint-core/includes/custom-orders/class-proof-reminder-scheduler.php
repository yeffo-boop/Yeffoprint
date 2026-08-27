<?php
/**
 * Automated proof-approval reminders (direct request: nudge a customer
 * who hasn't responded to a proof yet, at 24h then 48h).
 *
 * WP-Cron, not a per-order wp_schedule_single_event() — this plugin has
 * no existing scheduling infrastructure to extend (no WP-Cron/Action
 * Scheduler usage anywhere else), and a per-order single event would
 * mean scheduling/rescheduling/cancelling one on every status change
 * for every request. An hourly sweep over every CustomOrder currently
 * `awaiting_approval` is simpler, self-healing (a missed sweep just
 * catches up on the next run), and the up-to-~1h fuzziness against a
 * 24h/48h threshold is irrelevant in practice.
 *
 * Two reminders only, then it stops on its own: the sweep advances
 * `PROOF_REMINDER_STAGE` (0 → 1 → 2) and never re-sends a stage already
 * recorded, and it only ever looks at orders whose STATUS is still
 * 'awaiting_approval' — the moment a customer approves or requests
 * changes (class-proof-approval-controller.php), or staff otherwise
 * move status on, this order simply stops showing up in the query.
 * Nothing here needs to know that happened.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Proof_Reminder_Scheduler {

	private const HOOK = 'yeffoprint_proof_reminder_sweep';

	/** [ stage => seconds since AWAITING_APPROVAL_AT before that stage fires ]. */
	private const STAGE_THRESHOLDS = [
		1 => DAY_IN_SECONDS,
		2 => 2 * DAY_IN_SECONDS,
	];

	public function __construct() {
		add_action( self::HOOK, [ $this, 'sweep' ] );

		// Not solely register_activation_hook (below, via unschedule()'s
		// sibling in yeffoprint-core.php) — same reasoning this plugin
		// already applies to its rewrite-flush flag: a deploy that skips
		// WP's real activation hook (FTP/file-manager upload, git sync,
		// staging→production push) would otherwise never schedule this
		// at all. Cheap to check every request; wp_schedule_event() itself
		// is a no-op once an event's already on the cron table.
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
		$custom_order_ids = get_posts( [
			'post_type'      => 'yp_custom_order',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => [
				[
					'key'   => YeffoPrint_Custom_Order_Meta::STATUS,
					'value' => 'awaiting_approval',
				],
			],
		] );

		foreach ( $custom_order_ids as $custom_order_id ) {
			$this->maybe_remind( (int) $custom_order_id );
		}
	}

	private function maybe_remind( int $custom_order_id ): void {
		// No timestamp means this order entered 'awaiting_approval'
		// before this feature existed — nothing to compute elapsed time
		// from, and no reminder is owed for a proof that's been sitting
		// since before reminders were a thing.
		$awaiting_since = (int) get_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::AWAITING_APPROVAL_AT, true );
		if ( ! $awaiting_since ) {
			return;
		}

		if ( ! $this->has_active_linked_order( $custom_order_id ) ) {
			return;
		}

		$stage_sent = (int) get_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::PROOF_REMINDER_STAGE, true );
		$elapsed    = time() - $awaiting_since;

		foreach ( self::STAGE_THRESHOLDS as $stage => $threshold ) {
			if ( $stage_sent < $stage && $elapsed >= $threshold ) {
				$this->send_reminder( $custom_order_id, $stage );
				return; // One stage per sweep — the next stage, if already also due, catches up on the next hourly run.
			}
		}
	}

	/**
	 * Skips a reminder for an order whose linked WC order no longer
	 * warrants one — cancelled/refunded/failed since it was created.
	 * `awaiting_approval` orders are always linked to a real WC order by
	 * this point (that's what published them in the first place — see
	 * class-custom-order-payment.php), so a missing order here would be
	 * unexpected, not just "not yet paid."
	 */
	private function has_active_linked_order( int $custom_order_id ): bool {
		$wc_order_id = (int) get_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::WC_ORDER_ID, true );
		$order       = $wc_order_id ? wc_get_order( $wc_order_id ) : false;

		if ( ! $order instanceof \WC_Order ) {
			return false;
		}

		return ! $order->has_status( [ 'cancelled', 'refunded', 'failed', 'trash' ] );
	}

	private function send_reminder( int $custom_order_id, int $stage ): void {
		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::PROOF_REMINDER_STAGE, $stage );

		YeffoPrint_Proof_Meta::send_reminder_email( $custom_order_id, $stage );

		/**
		 * Lets the Telegram integration (class-telegram-order-
		 * notifications.php) nudge any chat already linked to this
		 * order — same "fired regardless of whether the email actually
		 * went out" reasoning as yeffoprint_proof_ready_for_review.
		 */
		do_action( 'yeffoprint_proof_reminder_due', $custom_order_id, $stage );
	}
}
