<?php
/**
 * Which days a Dose Tracker protocol is due — the PHP twin of
 * isDueOn() in assets/tracker/tracker.js (the reminder sweep needs the
 * same answer the app shows). Keep the two in step.
 *
 * A protocol's schedule, all in the customer's own local dates/times:
 *   type: daily | weekdays (days: 0=Sun..6=Sat) | interval (every N days) | cycle (on N days, off M days)
 *   times: ["08:00", "21:00"]
 *   start: "2026-09-27", weeks: optional length, paused: bool
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Tracker_Schedule {

	public static function is_due_on( array $protocol, string $date ): bool {
		if ( ! empty( $protocol['paused'] ) ) {
			return false;
		}

		$start = self::parse_date( (string) ( $protocol['start'] ?? '' ) );
		$day   = self::parse_date( $date );
		if ( ! $start || ! $day ) {
			return false;
		}

		$offset = (int) floor( ( $day->getTimestamp() - $start->getTimestamp() ) / DAY_IN_SECONDS );
		if ( $offset < 0 ) {
			return false;
		}

		$weeks = (int) ( $protocol['weeks'] ?? 0 );
		if ( $weeks > 0 && $offset >= $weeks * 7 ) {
			return false;
		}

		$schedule = is_array( $protocol['schedule'] ?? null ) ? $protocol['schedule'] : [];
		switch ( $schedule['type'] ?? 'daily' ) {
			case 'weekdays':
				$days = array_map( 'intval', (array) ( $schedule['days'] ?? [] ) );
				return in_array( (int) $day->format( 'w' ), $days, true );
			case 'interval':
				$every = max( 1, (int) ( $schedule['every'] ?? 1 ) );
				return 0 === $offset % $every;
			case 'cycle':
				$on  = max( 1, (int) ( $schedule['on'] ?? 5 ) );
				$off = max( 0, (int) ( $schedule['off'] ?? 2 ) );
				return ( $offset % ( $on + $off ) ) < $on;
			default:
				return true;
		}
	}

	/** @return string[] "HH:MM" times, sorted; defaults to 09:00 so a protocol always has a slot. */
	public static function times( array $protocol ): array {
		$times = array_values( array_filter( (array) ( $protocol['times'] ?? [] ), static function ( $t ) {
			return is_string( $t ) && preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $t );
		} ) );
		sort( $times );
		return $times ?: [ '09:00' ];
	}

	/** The deterministic dose-record id for one scheduled slot — tracker.js's slotId(). */
	public static function slot_id( string $protocol_id, string $date, string $time ): string {
		return 'd-' . $protocol_id . '-' . str_replace( '-', '', $date ) . '-' . str_replace( ':', '', $time );
	}

	private static function parse_date( string $date ): ?\DateTimeImmutable {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return null;
		}
		$d = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, new \DateTimeZone( 'UTC' ) );
		return $d ?: null;
	}
}
