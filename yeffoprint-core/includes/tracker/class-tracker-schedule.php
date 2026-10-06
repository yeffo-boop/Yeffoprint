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
 *   cycle: { on: weeks, off: weeks } — repeating weeks on, then weeks off (both set to use it)
 *   steps: [{ week, dose }] — titration: from `week` weeks after start, the dose is `dose`
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

		// Cycle planner: N weeks on, M weeks off, repeating from the start date.
		$cycle = is_array( $protocol['cycle'] ?? null ) ? $protocol['cycle'] : [];
		$on_w  = (int) ( $cycle['on'] ?? 0 );
		$off_w = (int) ( $cycle['off'] ?? 0 );
		if ( $on_w > 0 && $off_w > 0 && ( $offset % ( ( $on_w + $off_w ) * 7 ) ) >= $on_w * 7 ) {
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

	/** The dose on a date, after any titration steps — tracker.js's doseOn(). */
	public static function dose_on( array $protocol, string $date ): float {
		$dose  = (float) ( $protocol['dose'] ?? 0 );
		$start = self::parse_date( (string) ( $protocol['start'] ?? '' ) );
		$day   = self::parse_date( $date );
		if ( ! $start || ! $day || ! is_array( $protocol['steps'] ?? null ) ) {
			return $dose;
		}
		$week = (int) floor( ( $day->getTimestamp() - $start->getTimestamp() ) / DAY_IN_SECONDS / 7 );
		$best = -1;
		foreach ( $protocol['steps'] as $step ) {
			$at = is_array( $step ) ? (int) ( $step['week'] ?? -1 ) : -1;
			if ( $at > 0 && $at <= $week && $at > $best && (float) ( $step['dose'] ?? 0 ) > 0 ) {
				$best = $at;
				$dose = (float) $step['dose'];
			}
		}
		return $dose;
	}

	/**
	 * The clock a customer's dose times follow — the PHP twin of
	 * effectiveOffset() in tracker.js (travel mode). Normally their home
	 * zone (`baseTz`, falling back to the device's `tz`). While they're
	 * easing into a new time zone it's a fixed offset that moves `step`
	 * minutes a day from `from` to `to`, counted in whole days since they
	 * chose it (`at`, unix seconds); once there, the new zone itself.
	 */
	public static function timezone( array $settings, int $now ): \DateTimeZone {
		$travel = is_array( $settings['travel'] ?? null ) ? $settings['travel'] : null;
		if ( $travel && isset( $travel['from'], $travel['to'], $travel['at'] ) ) {
			$diff  = (int) $travel['to'] - (int) $travel['from'];
			$step  = max( 15, (int) ( $travel['step'] ?? 120 ) );
			$days  = max( 0, (int) floor( ( $now - (int) $travel['at'] ) / DAY_IN_SECONDS ) );
			$moved = min( abs( $diff ), $step * $days );
			if ( $moved < abs( $diff ) ) {
				$offset = (int) $travel['from'] + ( $diff < 0 ? -$moved : $moved );
				return new \DateTimeZone( sprintf( '%s%02d:%02d', $offset < 0 ? '-' : '+', intdiv( abs( $offset ), 60 ), abs( $offset ) % 60 ) );
			}
			$name = (string) ( $travel['tz'] ?? '' );
		} else {
			$name = (string) ( ( $settings['baseTz'] ?? '' ) ?: ( $settings['tz'] ?? '' ) );
		}
		try {
			return new \DateTimeZone( $name ?: wp_timezone_string() );
		} catch ( \Exception $e ) {
			return wp_timezone();
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
