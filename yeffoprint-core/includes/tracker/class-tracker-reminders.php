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
 *
 * Snooze: a dose reminder carries a "Remind me in 30 min" button (Android
 * and desktop; iPhone notifications have no buttons, so Today offers the
 * same thing in the app). Either way a `snooze` record holds only the
 * slot ids and when to remind again; the sweep rebuilds the message then,
 * and drops it if the dose was logged in the meantime.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Tracker_Reminders {

	public const LAST_SWEEP_META = '_yeffoprint_tracker_last_sweep';

	private const HOOK     = 'yeffoprint_tracker_reminder_sweep';
	private const SCHEDULE = 'yeffoprint_five_minutes';

	/** A server that was down longer than this doesn't wake the customer with a pile of stale reminders. */
	private const MAX_LOOKBACK = 30 * MINUTE_IN_SECONDS;

	public const SNOOZE_MINUTES = 30;

	/** How long a notification's snooze button keeps working. */
	private const SNOOZE_TOKEN_TTL = 12 * HOUR_IN_SECONDS;

	/** Snoozing the same dose again and again stops here. */
	private const MAX_SNOOZES = 6;

	/** Open snoozes per customer, so a stuck client can't pile them up. */
	private const MAX_OPEN_SNOOZES = 20;

	private const SLOT_PATTERN = '/^d-([A-Za-z0-9_-]+)-(\d{8})-(\d{4})$/';

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

		$tz       = YeffoPrint_Tracker_Schedule::timezone( $settings, $now );
		$messages = [];
		foreach ( self::due_between( $user_id, $tz, $since, $now ) as $slot ) {
			$messages[] = self::dose_message( $user_id, $slot, $settings, $now, 0 );
		}
		foreach ( self::snoozes_due( $user_id, $now ) as $snooze ) {
			$messages[] = self::dose_message( $user_id, $snooze['slot'], $settings, $now, $snooze['n'] );
		}
		foreach ( self::supply_alerts_between( $user_id, $tz, $since, $now, self::show_names( $settings ) ) as $alert ) {
			$messages[] = $alert;
		}
		if ( ! $messages ) {
			return;
		}

		$subs = YeffoPrint_Tracker_Store::all( $user_id, 'push' );
		foreach ( $messages as $message ) {
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
	 * One dose reminder ("Time for BPC-157" / "250 mcg BPC-157 + …"),
	 * with the snooze button. `$snoozed` counts how many times this one
	 * was put off already; the last allowed one has no button.
	 *
	 * @param array{key:string,names:string[],lines:string[],ids:string[]} $slot
	 */
	private static function dose_message( int $user_id, array $slot, array $settings, int $now, int $snoozed ): array {
		$one     = 1 === count( $slot['lines'] );
		$message = [
			'title' => $one
				? sprintf( /* translators: %s: compound */ __( 'Time for %s', 'yeffoprint-core' ), $slot['names'][0] )
				: __( 'Time for your doses', 'yeffoprint-core' ),
			'body'  => implode( ' + ', $slot['lines'] ),
			'tag'   => 'yp-dose-' . $slot['key'],
			'url'   => home_url( '/tracker/' ),
		];
		if ( ! self::show_names( $settings ) ) {
			$message['title'] = __( 'Dose reminder', 'yeffoprint-core' );
			$message['body']  = $one
				? __( 'You have a dose due. Open your tracker to see it.', 'yeffoprint-core' )
				/* translators: %d: number of doses */
				: sprintf( __( 'You have %d doses due. Open your tracker to see them.', 'yeffoprint-core' ), count( $slot['lines'] ) );
		}
		if ( $snoozed < self::MAX_SNOOZES ) {
			$message['actions'] = [ [
				'action' => 'snooze',
				/* translators: %d: minutes */
				'title'  => sprintf( __( 'Remind me in %d min', 'yeffoprint-core' ), self::SNOOZE_MINUTES ),
			] ];
			$message['snooze'] = self::snooze_token( $user_id, $slot['ids'], $snoozed, $now );
		}
		return $message;
	}

	/**
	 * Signed so the notification's button works without the app open
	 * (the service worker has no nonce): it can only ever snooze these
	 * slots for this customer, and only for a few hours.
	 *
	 * @param string[] $ids
	 */
	public static function snooze_token( int $user_id, array $ids, int $snoozed, int $now ): string {
		$payload = YeffoPrint_Tracker_Push::b64url( (string) wp_json_encode( [ 'u' => $user_id, 's' => array_values( $ids ), 'n' => $snoozed, 'e' => $now + self::SNOOZE_TOKEN_TTL ] ) );
		return $payload . '.' . YeffoPrint_Tracker_Push::b64url( hash_hmac( 'sha256', $payload, self::token_key(), true ) );
	}

	/** @return array{u:int,s:string[],n:int}|null */
	public static function read_snooze_token( string $token ): ?array {
		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) || strlen( $token ) > 2048 ) {
			return null;
		}
		$expected = YeffoPrint_Tracker_Push::b64url( hash_hmac( 'sha256', $parts[0], self::token_key(), true ) );
		if ( ! hash_equals( $expected, $parts[1] ) ) {
			return null;
		}
		$data = json_decode( YeffoPrint_Tracker_Push::b64url_decode( $parts[0] ), true );
		if ( ! is_array( $data ) || (int) ( $data['e'] ?? 0 ) < time() || (int) ( $data['u'] ?? 0 ) <= 0 || ! is_array( $data['s'] ?? null ) ) {
			return null;
		}
		return [ 'u' => (int) $data['u'], 's' => array_map( 'strval', $data['s'] ), 'n' => (int) ( $data['n'] ?? 0 ) ];
	}

	private static function token_key(): string {
		return wp_salt( 'auth' ) . '|yp-tracker-snooze';
	}

	/**
	 * Remind again in $minutes about these dose slots. Returns when, or
	 * null if none of the ids is a dose slot.
	 *
	 * @param string[] $ids
	 */
	public static function snooze( int $user_id, array $ids, int $minutes, int $snoozed = 0 ): ?int {
		$ids = array_values( array_unique( array_filter( array_map( 'strval', $ids ), static function ( $id ) {
			return 1 === preg_match( self::SLOT_PATTERN, $id );
		} ) ) );
		$ids = array_slice( $ids, 0, 12 );
		if ( ! $ids || $snoozed >= self::MAX_SNOOZES ) {
			return null;
		}
		sort( $ids );
		$record = 'sz-' . substr( hash( 'sha256', implode( '|', $ids ) ), 0, 24 );
		if ( ! YeffoPrint_Tracker_Store::exists( $user_id, 'snooze', $record ) && YeffoPrint_Tracker_Store::count( $user_id, 'snooze' ) >= self::MAX_OPEN_SNOOZES ) {
			return null;
		}
		$at = time() + max( 5, min( 240, $minutes ) ) * MINUTE_IN_SECONDS;
		YeffoPrint_Tracker_Store::put( $user_id, 'snooze', $record, [ 'at' => $at, 'slots' => $ids, 'n' => $snoozed + 1 ] );
		return $at;
	}

	/** @return array<int,array{slots:string[],at:int}> Open snoozes, for Today's "Reminder at 9:45 PM". */
	public static function snoozes_for( int $user_id ): array {
		$out = [];
		foreach ( YeffoPrint_Tracker_Store::all( $user_id, 'snooze' ) as $snooze ) {
			$out[] = [ 'slots' => array_values( (array) ( $snooze['slots'] ?? [] ) ), 'at' => (int) ( $snooze['at'] ?? 0 ) ];
		}
		return $out;
	}

	/**
	 * Snoozes whose time came, as reminder slots. Each fires once (it's
	 * deleted here); slots logged since, or whose protocol is gone, drop out.
	 *
	 * @return array<int,array{slot:array,n:int}>
	 */
	private static function snoozes_due( int $user_id, int $now ): array {
		$out = [];
		foreach ( YeffoPrint_Tracker_Store::all( $user_id, 'snooze' ) as $record_id => $snooze ) {
			$at = (int) ( $snooze['at'] ?? 0 );
			if ( $at > $now ) {
				continue;
			}
			YeffoPrint_Tracker_Store::delete( $user_id, 'snooze', (string) $record_id );
			if ( $now - $at > 2 * HOUR_IN_SECONDS ) {
				continue; // The server was down; a reminder hours late helps no one.
			}
			$slot = [ 'key' => '', 'names' => [], 'lines' => [], 'ids' => [] ];
			foreach ( (array) ( $snooze['slots'] ?? [] ) as $id ) {
				if ( ! preg_match( self::SLOT_PATTERN, (string) $id, $m ) || null !== YeffoPrint_Tracker_Store::get( $user_id, 'dose', (string) $id ) ) {
					continue;
				}
				$protocol = YeffoPrint_Tracker_Store::get( $user_id, 'protocol', $m[1] );
				if ( ! $protocol ) {
					continue;
				}
				$date            = substr( $m[2], 0, 4 ) . '-' . substr( $m[2], 4, 2 ) . '-' . substr( $m[2], 6, 2 );
				$name            = wp_strip_all_tags( (string) ( $protocol['compound'] ?? '' ) );
				$slot['key']     = '' !== $slot['key'] ? $slot['key'] : $m[2] . 'T' . $m[3];
				$slot['names'][] = $name;
				$slot['lines'][] = trim( $name . ' ' . self::format_amount( $protocol, $date ) );
				$slot['ids'][]   = (string) $id;
			}
			if ( $slot['ids'] ) {
				$out[] = [ 'slot' => $slot, 'n' => (int) ( $snooze['n'] ?? 1 ) ];
			}
		}
		return $out;
	}

	/**
	 * Names on the lock screen are on by default (Jeff); the Me tab's
	 * "Show names in reminders" switch turns them off. Every reminder
	 * that names a medication checks this.
	 */
	public static function show_names( array $settings ): bool {
		return ! ( isset( $settings['reminderNames'] ) && false === $settings['reminderNames'] );
	}

	/**
	 * Running-low, mix-day and vial-expiry notifications. The app works these out
	 * (it has the vial math) and saves the upcoming ones as the `alerts`
	 * settings record: [{ id, date, time, title, body }] in the customer's
	 * own clock. Each one goes out once, as its time passes.
	 *
	 * @return array<int,array{title:string,body:string,tag:string,url:string}>
	 */
	private static function supply_alerts_between( int $user_id, \DateTimeZone $tz, int $since, int $now, bool $show_names = true ): array {
		$record = YeffoPrint_Tracker_Store::get( $user_id, 'settings', 'alerts' );
		$out    = [];
		foreach ( array_slice( (array) ( $record['list'] ?? [] ), 0, 40 ) as $alert ) {
			if ( ! is_array( $alert ) ) {
				continue;
			}
			$date = (string) ( $alert['date'] ?? '' );
			$time = (string) ( $alert['time'] ?? '' );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
				continue;
			}
			$at = ( new \DateTimeImmutable( $date . ' ' . $time, $tz ) )->getTimestamp();
			if ( $at <= $since || $at > $now ) {
				continue;
			}
			$id = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $alert['id'] ?? '' ) );
			if ( ! $show_names ) {
				// Titles, bodies and ids (low-bpc157-…) all carry the name, so
				// hidden-names alerts use fixed wording and a hashed tag.
				$mix   = 0 === strpos( $id, 'mix-' );
				$exp   = 0 === strpos( $id, 'exp-' );
				$out[] = [
					'title' => $exp ? __( 'A vial is about to expire', 'yeffoprint-core' ) : ( $mix ? __( 'Time to mix a new vial', 'yeffoprint-core' ) : __( 'Your supply is running low', 'yeffoprint-core' ) ),
					'body'  => $exp ? __( 'Open the tracker to see which one.', 'yeffoprint-core' ) : ( $mix ? __( 'Your next dose needs a new vial or pen.', 'yeffoprint-core' ) : __( 'Open the tracker to see what to reorder.', 'yeffoprint-core' ) ),
					'tag'   => 'yp-supply-' . substr( md5( $id ), 0, 12 ),
					'url'   => home_url( '/tracker/' ),
				];
				continue;
			}
			$out[] = [
				'title' => wp_strip_all_tags( (string) ( $alert['title'] ?? '' ) ),
				'body'  => wp_strip_all_tags( (string) ( $alert['body'] ?? '' ) ),
				'tag'   => 'yp-supply-' . $id,
				'url'   => home_url( '/tracker/' ),
			];
		}
		return $out;
	}

	/** @return array<int,array{key:string,names:string[],lines:string[],ids:string[]}> One entry per local time slot with something still to take. */
	private static function due_between( int $user_id, \DateTimeZone $tz, int $since, int $now ): array {
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
					$slot_id = YeffoPrint_Tracker_Schedule::slot_id( (string) $protocol_id, $date, $time );
					if ( null !== YeffoPrint_Tracker_Store::get( $user_id, 'dose', $slot_id ) ) {
						continue; // Already taken or skipped early.
					}
					$key  = $date . 'T' . $time;
					$name = wp_strip_all_tags( (string) ( $protocol['compound'] ?? '' ) );
					$slots[ $key ]['key']     = str_replace( [ '-', ':' ], '', $key );
					$slots[ $key ]['names'][] = $name;
					$slots[ $key ]['lines'][] = trim( $name . ' ' . self::format_amount( $protocol, $date ) );
					$slots[ $key ]['ids'][]   = $slot_id;
				}
			}
		}

		ksort( $slots );
		return array_values( $slots );
	}

	private static function format_amount( array $protocol, string $date ): string {
		// Titration: the dose that day, not the starting one.
		$dose = YeffoPrint_Tracker_Schedule::dose_on( $protocol, $date );
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
		// A tablet / capsule's dosage on the bottle: "2 tablets (500 mg each)".
		$strength = (float) ( $protocol['strength'] ?? 0 );
		$s_unit   = (string) ( $protocol['strengthUnit'] ?? '' );
		$per      = '';
		if ( $strength > 0 && in_array( $protocol['unit'] ?? '', [ 'tablet', 'capsule' ], true ) && in_array( $s_unit, [ 'mg', 'mcg', 'g', 'IU' ], true ) ) {
			$per = ' (' . rtrim( rtrim( number_format( $strength, 3, '.', '' ), '0' ), '.' ) . ' ' . $s_unit . ( 1.0 === $dose ? ')' : ' each)' );
		}
		return rtrim( rtrim( number_format( $dose, 3, '.', '' ), '0' ), '.' ) . ' ' . $unit . $per . ( '' !== $of ? ' ' . $of : '' );
	}
}
