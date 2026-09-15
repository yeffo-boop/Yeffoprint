<?php
/**
 * Every message the bot fell through to escalation on (Telegram or the
 * website widget — class-telegram-message-handler.php logs both
 * through this one table) is a real question the FAQ didn't cover, but
 * nothing kept a record of it for staff to review. class-telegram-
 * unanswered-digest.php reads this weekly and clears it out; a brand-
 * new, plugin-owned table rather than a transient — a week's worth of
 * messages is an open-ended list to accumulate, not one value to
 * overwrite, the same reasoning class-customer-notes.php already
 * documents for its own table. Same lazy-install convention as that
 * class: check a stored version option, dbDelta if it's behind.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Telegram_Unanswered_Log {

	private const DB_VERSION        = '1.0';
	private const DB_VERSION_OPTION = 'yeffoprint_telegram_unanswered_db_version';

	public function __construct() {
		add_action( 'init', [ __CLASS__, 'maybe_install' ] );
	}

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'yeffoprint_telegram_unanswered';
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
			chat_id BIGINT NOT NULL,
			source VARCHAR(20) NOT NULL DEFAULT 'telegram',
			message TEXT NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id)
		) {$charset_collate};" );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	public static function log( int $chat_id, string $message, string $source ): void {
		global $wpdb;
		$wpdb->insert(
			self::table_name(),
			[
				'chat_id'    => $chat_id,
				'source'     => $source,
				'message'    => $message,
				'created_at' => current_time( 'mysql' ),
			],
			[ '%d', '%s', '%s', '%s' ]
		);
	}

	/** @return array{id:int,message:string,source:string,created_at:string}[] Oldest first. */
	public static function get_all(): array {
		global $wpdb;
		$table = self::table_name();
		$rows  = $wpdb->get_results( "SELECT id, message, source, created_at FROM {$table} ORDER BY created_at ASC, id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map( static function ( array $row ): array {
			return [
				'id'         => (int) $row['id'],
				'message'    => (string) $row['message'],
				'source'     => (string) $row['source'],
				'created_at' => (string) $row['created_at'],
			];
		}, $rows ?: [] );
	}

	public static function count(): int {
		global $wpdb;
		$table = self::table_name();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Called after a digest ships — this table is always "since the last digest," never a permanent archive. */
	public static function clear_all(): void {
		global $wpdb;
		$table = self::table_name();
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
