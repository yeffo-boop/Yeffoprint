<?php
/**
 * Dose Tracker reminders — every 5 minutes (the same
 * `yeffoprint_five_minutes` schedule express alerts use; the server's
 * real cron hits wp-cron.php on that interval, docs/deploy-setup.md),
 * find each customer's doses whose time fell since their last sweep and
 * that aren't logged yet, and push one notification per time slot
 * ("BPC-157 250 mcg + Ipamorelin 200 mcg").
 *
 * Only customers who turned reminders on have a `push` record, so the
 * sweep never decrypts anyone else's data.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Tracker_Reminders {

	public const LAST_SWEEP_META = '_yeffoprint_tracker_last_sweep';

	private const HOOK     = 'yeffoprint_tracker_reminder_sweep';
	private const SCHEDULE = 'yeffoprint_five_minutes';

	/** A server that was down longer than this doesn't wake the customer with a pile of stale reminders. */
	private const MAX_LOOKBACK = 30 * MINUTE_IN_SECONDS;

	public function __construct() {
		add_filter( 'cron_schedules', [ YeffoPrint_Telegram_Express_Alerts::class, 'add_schedule' ] ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		add_action( self::HOOK, [ $this, 'sweep' ] );
		add_action( 'init', [ $this, 'ensure_scheduled' ] );
	}

	public function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), self::SCHEDULE, self::HOOK );
		}
	}

	public function sweep(): void {
		if ( ! YeffoPrint_Tracker_Crypto::is_ready() ) {
			return;
		}
		$now = time();
		foreach ( YeffoPrint_Tracker_Store::users_with_kind( 'push' ) as $user_id ) {
			self::sweep_user( $user_id, $now );
		}
	}

	public static function sweep_user( int $user_id, int $now ): void {
		$last = (int) get_user_meta( $user_id, self::LAST_SWEEP_META, true );
		update_user_meta( $user_id, self::LAST_SWEEP_META, $now );
		$since = max( $last, $now - self::MAX_LOOKBACK );

		$settings = YeffoPrint_Tracker_Store::get( $user_id, 'settings', 'me' ) ?? [];
		if ( isset( $settings['reminders'] ) && ! $settings['reminders'] ) {
			return;
		}

		$due = self::due_between( $user_id, $settings, $since, $now );
		if ( ! $due ) {
			return;
		}

		$subs = YeffoPrint_Tracker_Store::all( $user_id, 'push' );
		foreach ( $due as $slot ) {
			$message = [
				'title' => 1 === count( $slot['lines'] )
					? sprintf( /* translators: %s: compound */ __( 'Time for %s', 'yeffoprint-core' ), $slot['names'][0] )
					: __( 'Time for your doses', 'yeffoprint-core' ),
				'body'  => implode( ' + ', $slot['lines'] ),
				'tag'   => 'yp-dose-' . $slot['key'],
				'url'   => home_url( '/tracker/' ),
			];
			if ( ! self::show_names( $settings ) ) {
				$message['title'] = __( 'Dose reminder', 'yeffoprint-core' );
				$message['body']  = 1 === count( $slot['lines'] )
					? __( 'You have a dose due. Open your tracker to see it.', 'yeffoprint-core' )
					/* translators: %d: number of doses */
					: sprintf( __( 'You have %d doses due. Open your tracker to see them.', 'yeffoprint-core' ), count( $slot['lines'] ) );
			}
			foreach ( $subs as $sub_id => $sub ) {
				$status = YeffoPrint_Tracker_Push::send( $sub, $message );
				if ( 404 === $status || 410 === $status ) {
					YeffoPrint_Tracker_Store::delete( $user_id, 'push', $sub_id );
					unset( $subs[ $sub_id ] );
				}
			}
		}
	}

	/**
	 * Names on the lock screen are on by default (Jeff); the Me tab's
	 * "Show names in reminders" switch turns them off. Every reminder
	 * that names a medication checks this.
	 */
	public static function show_names( array $settings ): bool {
		return ! ( isset( $settings['reminderNames'] ) && false === $settings['reminderNames'] );
	}

	/** @return array<int,array{key:string,names:string[],lines:string[]}> One entry per local time slot with something still to take. */
	private static function due_between( int $user_id, array $settings, int $since, int $now ): array {
		try {
			$tz = new \DateTimeZone( (string) ( $settings['tz'] ?? '' ) ?: wp_timezone_string() );
		} catch ( \Exception $e ) {
			$tz = wp_timezone();
		}

		$protocols = YeffoPrint_Tracker_Store::all( $user_id, 'protocol' );
		if ( ! $protocols ) {
			return [];
		}

		$slots = [];
		// The window is at most 30 minutes, but can straddle midnight.
		$dates = array_unique( [
			( new \DateTimeImmutable( '@' . $since ) )->setTimezone( $tz )->format( 'Y-m-d' ),
			( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $tz )->format( 'Y-m-d' ),
		] );

		foreach ( $dates as $date ) {
			foreach ( $protocols as $protocol_id => $protocol ) {
				if ( ! YeffoPrint_Tracker_Schedule::is_due_on( $protocol, $date ) ) {
					continue;
				}
				foreach ( YeffoPrint_Tracker_Schedule::times( $protocol ) as $time ) {
					$at = ( new \DateTimeImmutable( $date . ' ' . $time, $tz ) )->getTimestamp();
					if ( $at <= $since || $at > $now ) {
						continue;
					}
					if ( null !== YeffoPrint_Tracker_Store::get( $user_id, 'dose', YeffoPrint_Tracker_Schedule::slot_id( (string) $protocol_id, $date, $time ) ) ) {
						continue; // Already taken or skipped early.
					}
					$key  = $date . 'T' . $time;
					$name = wp_strip_all_tags( (string) ( $protocol['compound'] ?? '' ) );
					$slots[ $key ]['key']     = str_replace( [ '-', ':' ], '', $key );
					$slots[ $key ]['names'][] = $name;
					$slots[ $key ]['lines'][] = trim( $name . ' ' . self::format_amount( $protocol ) );
				}
			}
		}

		ksort( $slots );
		return array_values( $slots );
	}

	private static function format_amount( array $protocol ): string {
		$dose = (float) ( $protocol['dose'] ?? 0 );
		if ( $dose <= 0 ) {
			return '';
		}
		$unit = sanitize_text_field( (string) ( $protocol['unit'] ?? '' ) );
		// Countable units read naturally: "2 tablets", "1 spray" (same words as the app's unitLabel()).
		$plural = [ 'tablet' => 'tablets', 'capsule' => 'capsules', 'spray' => 'sprays', 'drop' => 'drops', 'puff' => 'puffs', 'patch' => 'patches', 'pump' => 'pumps', 'application' => 'applications', 'suppository' => 'suppositories', 'dose' => 'doses' ];
		if ( 1.0 !== $dose && isset( $plural[ $unit ] ) ) {
			$unit = $plural[ $unit ];
		}
		// A blend dosed by one of its peptides: "250 mcg BPC-157".
		$of = sanitize_text_field( (string) ( $protocol['doseOf'] ?? '' ) );
		return rtrim( rtrim( number_format( $dose, 3, '.', '' ), '0' ), '.' ) . ' ' . $unit . ( '' !== $of ? ' ' . $of : '' );
	}
}
