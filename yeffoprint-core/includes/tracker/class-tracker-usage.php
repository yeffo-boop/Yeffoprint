<?php
/**
 * Dose Tracker usage counts for the admin Dashboard — direct request:
 * "total users that have used it and daily uses."
 *
 * One tiny plaintext table, one row per customer per day they opened
 * or used the tracker, plus how many new doses they logged that day.
 * It holds no compound, dose, time or note — nothing that isn't
 * already plaintext in the records table (user id + a date) — and the
 * admin endpoint only ever returns totals, never a per-customer row.
 * Nothing here touches YeffoPrint_Tracker_Crypto. "Delete my data",
 * account deletion and the privacy eraser all clear a customer's rows
 * here too (via YeffoPrint_Tracker_Store::delete_all()).
 *
 * Days are the site's own timezone. The first install backfills from
 * the records table's `updated_at`, so the chart isn't empty on day one;
 * that history only reflects each record's latest change, so days before
 * this shipped are a floor, not an exact count.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Tracker_Usage {

	/** Days shown on the dashboard chart. */
	public const CHART_DAYS = 30;

	private const DB_VERSION        = '1.0';
	private const DB_VERSION_OPTION = 'yeffoprint_tracker_usage_db_version';

	public function __construct() {
		add_action( 'init', [ __CLASS__, 'maybe_install' ] );
	}

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'yeffoprint_tracker_usage';
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
			day DATE NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			doses INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (day,user_id),
			KEY user_id (user_id)
		) {$charset_collate};" );

		self::backfill();

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/** Seeds usage from the records table's last-changed dates. */
	private static function backfill(): void {
		global $wpdb;
		$usage   = self::table_name();
		$records = YeffoPrint_Tracker_Store::table_name();

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $records ) ) !== $records ) {
			return;
		}

		// updated_at is UTC; shift into the site's timezone for the day bucket.
		$offset = (int) round( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$usage} (day, user_id, doses)
				SELECT DATE(DATE_ADD(updated_at, INTERVAL %d SECOND)) AS d, user_id, SUM(kind = 'dose')
				FROM {$records}
				WHERE kind <> 'push'
				GROUP BY d, user_id",
				$offset
			)
		);
		// phpcs:enable
	}

	/** Marks today as a day this customer used the tracker, adding any newly logged doses. */
	public static function record( int $user_id, int $new_doses = 0 ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		global $wpdb;
		$table = self::table_name();
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (day, user_id, doses) VALUES (%s, %d, %d) ON DUPLICATE KEY UPDATE doses = doses + VALUES(doses)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				current_time( 'Y-m-d' ),
				$user_id,
				max( 0, $new_doses )
			)
		);
	}

	public static function forget_user( int $user_id ): void {
		global $wpdb;
		$wpdb->delete( self::table_name(), [ 'user_id' => $user_id ], [ '%d' ] );
	}

	/**
	 * Totals only, for the admin Dashboard.
	 *
	 * @return array{total_users:int,active_today:int,active_7_days:int,active_30_days:int,doses_today:int,days:array<int,array{date:string,users:int,doses:int}>}
	 */
	public static function summary(): array {
		global $wpdb;
		$table = self::table_name();
		$today = current_time( 'Y-m-d' );
		$ts    = strtotime( $today . ' 00:00:00 UTC' );
		$since = gmdate( 'Y-m-d', $ts - ( self::CHART_DAYS - 1 ) * DAY_IN_SECONDS );
		$week  = gmdate( 'Y-m-d', $ts - 6 * DAY_IN_SECONDS );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM {$table}" );
		$week_users  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$table} WHERE day >= %s", $week ) );
		$month_users = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$table} WHERE day >= %s", $since ) );
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT day, COUNT(*) AS users, SUM(doses) AS doses FROM {$table} WHERE day >= %s GROUP BY day", $since ),
			ARRAY_A
		);
		// phpcs:enable

		$by_day = [];
		foreach ( $rows as $row ) {
			$by_day[ $row['day'] ] = [ 'users' => (int) $row['users'], 'doses' => (int) $row['doses'] ];
		}

		$days = [];
		for ( $i = self::CHART_DAYS - 1; $i >= 0; $i-- ) {
			$date   = gmdate( 'Y-m-d', $ts - $i * DAY_IN_SECONDS );
			$days[] = [
				'date'  => $date,
				'users' => $by_day[ $date ]['users'] ?? 0,
				'doses' => $by_day[ $date ]['doses'] ?? 0,
			];
		}

		return [
			'total_users'    => $total,
			'active_today'   => $by_day[ $today ]['users'] ?? 0,
			'active_7_days'  => $week_users,
			'active_30_days' => $month_users,
			'doses_today'    => $by_day[ $today ]['doses'] ?? 0,
			'days'           => $days,
		];
	}
}
