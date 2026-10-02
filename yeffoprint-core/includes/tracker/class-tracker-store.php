<?php
/**
 * Dose Tracker storage — one plugin-owned table of encrypted records
 * (YeffoPrint_Tracker_Crypto). The only plaintext columns are what the
 * app needs to find a customer's rows: their user id, the record kind
 * and id, and when it last changed. Every compound, dose, time, note and
 * vial lives inside `payload`.
 *
 * Kinds:
 *   protocol — a peptide the customer takes on a schedule
 *   dose     — one taken/skipped dose (id is deterministic per scheduled slot, so an offline retry never double-logs)
 *   vial     — a mixed/opened vial, for units-to-draw and doses-left
 *   stock    — unmixed vials, pens or pills on hand (the Supply tab)
 *   settings — the "me" record (timezone, reminders, travel) and "alerts"
 *              (upcoming running-low / mix-day notifications the app works out)
 *   push     — this customer's browser push subscriptions
 *
 * Created lazily with the same stored-version + dbDelta pattern as
 * class-customer-notes.php.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Tracker_Store {

	public const KINDS = [ 'protocol', 'dose', 'vial', 'stock', 'settings', 'push' ];

	/** Per-record plaintext ceiling — a dose note or protocol is a few hundred bytes; this only stops abuse. */
	public const MAX_RECORD_BYTES = 8192;

	/** Per-customer ceiling: years of several daily doses fits comfortably. */
	public const MAX_RECORDS = 25000;

	private const DB_VERSION        = '1.0';
	private const DB_VERSION_OPTION = 'yeffoprint_tracker_db_version';

	public function __construct() {
		add_action( 'init', [ __CLASS__, 'maybe_install' ] );
		// A deleted WordPress account takes its tracker data with it.
		add_action( 'delete_user', [ __CLASS__, 'delete_all' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'register_eraser' ] );
	}

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'yeffoprint_tracker_records';
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
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			kind VARCHAR(16) NOT NULL,
			record_id VARCHAR(64) NOT NULL,
			payload MEDIUMTEXT NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_record (user_id,kind,record_id),
			KEY user_kind (user_id,kind)
		) {$charset_collate};" );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	public static function valid_id( string $record_id ): bool {
		return (bool) preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $record_id );
	}

	/** @return array<string,array> record_id => data, for one kind. Rows that won't decrypt are skipped, never surfaced as blanks. */
	public static function all( int $user_id, string $kind ): array {
		global $wpdb;
		$table = self::table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT record_id, payload FROM {$table} WHERE user_id = %d AND kind = %s", $user_id, $kind ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$out = [];
		foreach ( (array) $rows as $row ) {
			$data = YeffoPrint_Tracker_Crypto::decrypt_record( $user_id, $kind, $row['record_id'], $row['payload'] );
			if ( null !== $data ) {
				$out[ $row['record_id'] ] = $data;
			}
		}
		return $out;
	}

	public static function get( int $user_id, string $kind, string $record_id ): ?array {
		global $wpdb;
		$table   = self::table_name();
		$payload = $wpdb->get_var(
			$wpdb->prepare( "SELECT payload FROM {$table} WHERE user_id = %d AND kind = %s AND record_id = %s", $user_id, $kind, $record_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		return null === $payload ? null : YeffoPrint_Tracker_Crypto::decrypt_record( $user_id, $kind, $record_id, (string) $payload );
	}

	public static function exists( int $user_id, string $kind, string $record_id ): bool {
		global $wpdb;
		$table = self::table_name();
		return (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT 1 FROM {$table} WHERE user_id = %d AND kind = %s AND record_id = %s", $user_id, $kind, $record_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/** @return true|\WP_Error */
	public static function put( int $user_id, string $kind, string $record_id, array $data ) {
		global $wpdb;
		$table = self::table_name();

		$exists = self::exists( $user_id, $kind, $record_id );
		if ( ! $exists && self::count( $user_id ) >= self::MAX_RECORDS ) {
			return new \WP_Error( 'yeffoprint_tracker_full', __( 'Your tracker is full. Delete some old entries and try again.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$payload = YeffoPrint_Tracker_Crypto::encrypt_record( $user_id, $kind, $record_id, $data );
		if ( '' === $payload ) {
			return new \WP_Error( 'yeffoprint_tracker_crypto', __( 'The tracker isn’t set up on this site yet.', 'yeffoprint-core' ), [ 'status' => 503 ] );
		}

		$now = current_time( 'mysql', true );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (user_id, kind, record_id, payload, updated_at) VALUES (%d, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE payload = VALUES(payload), updated_at = VALUES(updated_at)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id,
				$kind,
				$record_id,
				$payload,
				$now
			)
		);

		return true;
	}

	public static function delete( int $user_id, string $kind, string $record_id ): void {
		global $wpdb;
		$wpdb->delete( self::table_name(), [ 'user_id' => $user_id, 'kind' => $kind, 'record_id' => $record_id ], [ '%d', '%s', '%s' ] );
	}

	public static function delete_all( int $user_id ): void {
		global $wpdb;
		$wpdb->delete( self::table_name(), [ 'user_id' => $user_id ], [ '%d' ] );
		YeffoPrint_Tracker_Crypto::forget_user( $user_id );
		YeffoPrint_Tracker_Usage::forget_user( $user_id );
		YeffoPrint_Tracker_Shares::delete_for_user( $user_id );
		YeffoPrint_Tracker_Feedback::delete_for_user( $user_id );
		delete_user_meta( $user_id, YeffoPrint_Tracker_Reminders::LAST_SWEEP_META );
	}

	public static function count( int $user_id ): int {
		global $wpdb;
		$table = self::table_name();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @return int[] Users with at least one saved push subscription — the reminder sweep's worklist. */
	public static function users_with_kind( string $kind ): array {
		global $wpdb;
		$table = self::table_name();
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM {$table} WHERE kind = %s", $kind ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** WordPress's Tools > Erase Personal Data covers the tracker too. */
	public function register_eraser( array $erasers ): array {
		$erasers['yeffoprint-dose-tracker'] = [
			'eraser_friendly_name' => __( 'Dose Tracker', 'yeffoprint-core' ),
			'callback'             => static function ( string $email ): array {
				$user    = get_user_by( 'email', $email );
				$removed = false;
				if ( $user && self::count( $user->ID ) > 0 ) {
					self::delete_all( $user->ID );
					$removed = true;
				}
				return [ 'items_removed' => $removed, 'items_retained' => false, 'messages' => [], 'done' => true ];
			},
		];
		return $erasers;
	}
}
