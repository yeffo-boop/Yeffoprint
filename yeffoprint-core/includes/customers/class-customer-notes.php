<?php
/**
 * Internal staff notes on a customer — direct request: "I want to be
 * able to add notes to customers so when I print their future orders I
 * can refer to them." Keyed by billing email rather than a WordPress
 * user ID: this store allows both registered accounts and guest
 * checkout, and email is the one identifier every order already carries
 * regardless of which of those it came from — a note added from the
 * Customers screen (registered account) and a note added straight from
 * an order drawer (guest or registered) both land in the same place and
 * both surface on every future order from that same email address.
 *
 * A brand-new, plugin-owned table rather than post/user meta: there is
 * no single post or user every note could always attach to (a guest has
 * no user row at all), and a customer accumulates an open-ended list of
 * timestamped notes over time, not one value to overwrite — exactly the
 * shape a small dedicated table is for for. Created lazily via
 * maybe_install(), the same "check a stored version option, dbDelta if
 * it's behind" pattern WordPress core itself uses for its own upgrades
 * — safe to add on an already-active install, unlike register_activation_hook()
 * (which never re-fires for a plugin that's already active).
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Customer_Notes {

	private const DB_VERSION        = '1.0';
	private const DB_VERSION_OPTION = 'yeffoprint_customer_notes_db_version';

	public function __construct() {
		add_action( 'init', [ __CLASS__, 'maybe_install' ] );
	}

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'yeffoprint_customer_notes';
	}

	public static function maybe_install(): void {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// customer_email stored lowercased/trimmed (normalize_email())
		// so "Jane@Example.com" and "jane@example.com" — the same
		// person, just typed differently at checkout twice — always
		// land in the same note history instead of silently splitting
		// across two.
		dbDelta( "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_email VARCHAR(190) NOT NULL,
			note TEXT NOT NULL,
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY customer_email (customer_email)
		) {$charset_collate};" );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	public static function normalize_email( string $email ): string {
		return strtolower( trim( $email ) );
	}

	/** @return array{id:int,note:string,created_at:string,created_by:int,created_by_name:string}[] Newest first. */
	public static function get_notes( string $email ): array {
		global $wpdb;
		$email = self::normalize_email( $email );
		if ( '' === $email ) {
			return [];
		}

		$table = self::table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, note, created_by, created_at FROM {$table} WHERE customer_email = %s ORDER BY created_at DESC, id DESC", $email ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		return array_map( static function ( array $row ): array {
			$author = (int) $row['created_by'] ? get_userdata( (int) $row['created_by'] ) : false;
			return [
				'id'              => (int) $row['id'],
				'note'            => (string) $row['note'],
				'created_at'      => (string) $row['created_at'],
				'created_by'      => (int) $row['created_by'],
				'created_by_name' => $author ? $author->display_name : __( 'Unknown', 'yeffoprint-core' ),
			];
		}, $rows ?: [] );
	}

	/** @return int|\WP_Error The new note's id, or WP_Error on a blank email/note. */
	public static function add_note( string $email, string $note, int $created_by ) {
		global $wpdb;
		$email = self::normalize_email( $email );
		$note  = trim( $note );

		if ( '' === $email || ! is_email( $email ) ) {
			return new \WP_Error( 'yeffoprint_invalid_customer_email', __( 'A valid customer email is required.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}
		if ( '' === $note ) {
			return new \WP_Error( 'yeffoprint_empty_note', __( 'Enter a note before saving.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$wpdb->insert(
			self::table_name(),
			[
				'customer_email' => $email,
				'note'           => $note,
				'created_by'     => $created_by,
				'created_at'     => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%d', '%s' ]
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * No ownership check beyond the caller's own `admin_write` REST
	 * permission (manage_options) — every note in this table is already
	 * visible to, and was presumably left by, that same single trusted
	 * staff tier, so there is no "wrong customer" boundary to enforce
	 * the way there would be between two different end users.
	 */
	public static function delete_note( int $note_id ): bool {
		global $wpdb;
		return false !== $wpdb->delete( self::table_name(), [ 'id' => $note_id ], [ '%d' ] );
	}

	public static function count_notes( string $email ): int {
		global $wpdb;
		$email = self::normalize_email( $email );
		if ( '' === $email ) {
			return 0;
		}
		$table = self::table_name();
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE customer_email = %s", $email ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}
}
