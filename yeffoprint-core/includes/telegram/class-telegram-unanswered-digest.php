<?php
/**
 * Weekly "what the bot couldn't answer" digest to the store owner's own
 * Telegram chat — direct pitch: every fallback-to-escalation message is
 * a real customer question the FAQ didn't cover, but nothing showed the
 * owner which ones without manually digging through chat logs. Same
 * WP-Cron shape as class-proof-reminder-scheduler.php (ensure_scheduled()
 * on 'init', a deactivation-hook unschedule() sibling in
 * yeffoprint-core.php), just weekly instead of hourly and with nothing
 * per-item to track — one sweep reads everything class-telegram-
 * unanswered-log.php has collected since the last digest and clears it,
 * so there's no "already reported" flag to maintain.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Telegram_Unanswered_Digest {

	private const HOOK = 'yeffoprint_telegram_unanswered_digest';

	/** Plenty to skim in a Telegram message without it turning into a wall of text; the total count above the list still says how many there really were. */
	private const MAX_LISTED = 15;

	public function __construct() {
		add_filter( 'cron_schedules', [ __CLASS__, 'add_weekly_schedule' ] ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		add_action( self::HOOK, [ $this, 'send' ] );
		add_action( 'init', [ $this, 'ensure_scheduled' ] );
	}

	/** Neither WordPress core nor WooCommerce registers a "weekly" interval (WC's own cron_schedules() adds only "monthly"/"fifteendays") — this adds the one this digest actually needs. */
	public static function add_weekly_schedule( array $schedules ): array {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = [
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once Weekly', 'yeffoprint-core' ),
			];
		}

		return $schedules;
	}

	public function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), 'weekly', self::HOOK );
		}
	}

	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	public function send(): void {
		$entries = YeffoPrint_Telegram_Unanswered_Log::get_all();

		if ( ! $entries ) {
			return; // Nothing unanswered this week — no need to say so.
		}

		$lines   = [];
		$lines[] = sprintf(
			/* translators: %d: number of questions the bot couldn't answer this week */
			_n(
				"This week the bot couldn't answer %d question — here it is, in case it's worth adding to the FAQ:",
				"This week the bot couldn't answer %d questions — here are the most recent, in case any are worth adding to the FAQ:",
				count( $entries ),
				'yeffoprint-core'
			),
			count( $entries )
		);
		$lines[] = '';

		foreach ( array_slice( $entries, -self::MAX_LISTED ) as $entry ) {
			$icon    = 'web' === $entry['source'] ? '🌐' : '✈️';
			$lines[] = sprintf( '%1$s "%2$s"', $icon, $entry['message'] );
		}

		if ( count( $entries ) > self::MAX_LISTED ) {
			$lines[] = '';
			$lines[] = sprintf(
				/* translators: %d: how many older questions were left out of this list */
				__( '(+%d more not shown)', 'yeffoprint-core' ),
				count( $entries ) - self::MAX_LISTED
			);
		}

		YeffoPrint_Telegram_Admin_Alerts::notify( implode( "\n", $lines ) );

		YeffoPrint_Telegram_Unanswered_Log::clear_all();
	}
}
