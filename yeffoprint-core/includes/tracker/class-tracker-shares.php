<?php
/**
 * Shared protocols — direct request (Jeff): a customer sends a link to a
 * protocol (compound, dose, schedule) and whoever opens it adds it to
 * their own tracker in one tap.
 *
 * A share is a copy of just the fields the customer ticked on the Share
 * sheet: no name, history, vials or anything else from their tracker.
 * Anyone with the link can read it (that's the point), so it can't use
 * the customer's own key; it's sealed with the master key instead
 * (YeffoPrint_Tracker_Crypto::seal), bound to its code, so a database
 * copy alone doesn't reveal it. The row keeps the sharer's user id only
 * so "Delete my data" and account deletion remove their links too.
 *
 *   /tracker/p/{code}   the page someone opens (class-tracker-app.php)
 *   POST tracker/shares (class-tracker-controller.php) creates one
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Tracker_Shares {

	/** Links one customer can create — plenty for sharing with friends, stops a script filling the table. */
	public const MAX_PER_USER = 200;

	private const DB_VERSION        = '1.0';
	private const DB_VERSION_OPTION = 'yeffoprint_tracker_shares_db_version';
	private const CODE_CHARS        = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

	public function __construct() {
		add_action( 'init', [ __CLASS__, 'maybe_install' ] );
	}

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'yeffoprint_tracker_shares';
	}

	public static function maybe_install(): void {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		dbDelta( "CREATE TABLE {$table} (
			code VARCHAR(16) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			payload TEXT NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (code),
			KEY user_id (user_id)
		) {$charset_collate};" );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	public static function valid_code( string $code ): bool {
		return (bool) preg_match( '/^[A-Za-z0-9]{10}$/', $code );
	}

	public static function url( string $code ): string {
		return home_url( '/' . YeffoPrint_Tracker_App::SLUG . '/p/' . $code );
	}

	/** @return string|\WP_Error The new link's code. */
	public static function create( int $user_id, array $protocol ) {
		global $wpdb;
		$table = self::table_name();

		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $count >= self::MAX_PER_USER ) {
			return new \WP_Error( 'yeffoprint_tracker_share_full', __( 'You’ve shared a lot of links already. Contact us if you need more.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		for ( $try = 0; $try < 5; $try++ ) {
			$code = self::new_code();
			$sealed = YeffoPrint_Tracker_Crypto::seal( (string) wp_json_encode( $protocol ), self::context( $code ) );
			if ( '' === $sealed ) {
				return new \WP_Error( 'yeffoprint_tracker_crypto', __( 'The tracker isn’t set up on this site yet.', 'yeffoprint-core' ), [ 'status' => 503 ] );
			}
			$ok = $wpdb->insert( $table, [
				'code'       => $code,
				'user_id'    => $user_id,
				'payload'    => $sealed,
				'created_at' => current_time( 'mysql', true ),
			], [ '%s', '%d', '%s', '%s' ] );
			if ( $ok ) {
				return $code;
			}
		}

		return new \WP_Error( 'yeffoprint_tracker_share_failed', __( 'That link couldn’t be created. Please try again.', 'yeffoprint-core' ), [ 'status' => 500 ] );
	}

	public static function get( string $code ): ?array {
		if ( ! self::valid_code( $code ) ) {
			return null;
		}
		global $wpdb;
		$table   = self::table_name();
		$payload = $wpdb->get_var( $wpdb->prepare( "SELECT payload FROM {$table} WHERE code = %s", $code ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( null === $payload ) {
			return null;
		}
		$data = json_decode( YeffoPrint_Tracker_Crypto::open( (string) $payload, self::context( $code ) ), true );
		return is_array( $data ) ? self::sanitize( $data ) : null;
	}

	public static function delete_for_user( int $user_id ): void {
		global $wpdb;
		$wpdb->delete( self::table_name(), [ 'user_id' => $user_id ], [ '%d' ] );
	}

	/**
	 * Only the fields a protocol card shows, typed and capped — used both
	 * when a link is made and when it's read back, so the page never
	 * prints anything else. @return array|null null when there's no name or dose.
	 */
	public static function sanitize( array $in ): ?array {
		$text = static function ( $v, int $max ): string {
			return mb_substr( trim( wp_strip_all_tags( is_scalar( $v ) ? (string) $v : '' ) ), 0, $max );
		};
		$num = static function ( $v ): float {
			$n = is_numeric( $v ) ? (float) $v : 0.0;
			return is_finite( $n ) && $n > 0 ? round( $n, 4 ) : 0.0;
		};

		$compound = $text( $in['compound'] ?? '', 80 );
		$dose     = $num( $in['dose'] ?? 0 );
		if ( '' === $compound || $dose <= 0 ) {
			return null;
		}

		$sched = is_array( $in['schedule'] ?? null ) ? $in['schedule'] : [];
		$type  = in_array( $sched['type'] ?? '', [ 'daily', 'weekdays', 'interval', 'cycle' ], true ) ? $sched['type'] : 'daily';
		$days  = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $sched['days'] ?? [] ) ), static function ( $d ) {
			return $d >= 0 && $d <= 6;
		} ) ) );

		$times = array_values( array_slice( array_filter( (array) ( $in['times'] ?? [] ), static function ( $t ) {
			return is_string( $t ) && preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $t );
		} ), 0, 6 ) );

		$out = [
			'compound' => $compound,
			'dose'     => $dose,
			'unit'     => $text( $in['unit'] ?? '', 16 ),
			'route'    => $text( $in['route'] ?? '', 40 ),
			'device'   => in_array( $in['device'] ?? '', [ 'syringe', 'pen', 'single' ], true ) ? $in['device'] : '',
			'doseOf'   => $text( $in['doseOf'] ?? '', 80 ),
			'schedule' => [
				'type'  => $type,
				'days'  => $days,
				'every' => max( 1, min( 60, (int) ( $sched['every'] ?? 2 ) ) ),
				'on'    => max( 1, min( 90, (int) ( $sched['on'] ?? 5 ) ) ),
				'off'   => max( 0, min( 90, (int) ( $sched['off'] ?? 0 ) ) ),
			],
			'times'    => $times ?: [ '09:00' ],
			'weeks'    => max( 0, min( 520, (int) ( $in['weeks'] ?? 0 ) ) ),
			'notes'    => $text( $in['notes'] ?? '', 500 ),
		];

		// How to mix it (optional): the vial the sharer mixes, never their vial record itself.
		$mix = is_array( $in['mix'] ?? null ) ? $in['mix'] : null;
		if ( $mix ) {
			$mode  = in_array( $mix['mode'] ?? '', [ 'mg', 'iu', 'conc', 'blend' ], true ) ? $mix['mode'] : 'mg';
			$clean = [
				'kind'   => 'pen' === ( $mix['kind'] ?? '' ) ? 'pen' : 'vial',
				'mode'   => $mode,
				'amount' => $num( $mix['amount'] ?? 0 ),
				'water'  => $num( $mix['water'] ?? 0 ),
				'conc'   => $num( $mix['conc'] ?? 0 ),
				'volume' => $num( $mix['volume'] ?? 0 ),
			];
			if ( 'blend' === $mode ) {
				$clean['parts'] = [];
				foreach ( array_slice( (array) ( $mix['parts'] ?? [] ), 0, 5 ) as $part ) {
					$name = $text( is_array( $part ) ? ( $part['name'] ?? '' ) : '', 80 );
					$amt  = $num( is_array( $part ) ? ( $part['amount'] ?? 0 ) : 0 );
					if ( '' !== $name && $amt > 0 ) {
						$clean['parts'][] = [ 'name' => $name, 'amount' => $amt ];
					}
				}
				if ( count( $clean['parts'] ) < 2 ) {
					$clean = null;
				}
			}
			if ( $clean && ( 'conc' === $mode ? $clean['conc'] > 0 && $clean['volume'] > 0 : $clean['water'] > 0 && ( 'blend' === $mode || $clean['amount'] > 0 ) ) ) {
				$out['mix'] = $clean;
			}
		}

		return $out;
	}

	private static function context( string $code ): string {
		return 'yeffoprint-tracker-share:' . $code;
	}

	private static function new_code(): string {
		$chars = self::CODE_CHARS;
		$code  = '';
		for ( $i = 0; $i < 10; $i++ ) {
			$code .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
		}
		return $code;
	}
}
