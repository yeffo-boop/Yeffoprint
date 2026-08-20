<?php
/**
 * User accounts: core wp_users fields, roles, and usermeta (billing/
 * shipping addresses, order-related WooCommerce customer meta,
 * yeffoprint-core's own per-user data like rewards points — anything
 * else stored against the user, copied through as opaque key/value
 * pairs with no need to know what any of it means).
 *
 * Import matches by email first (WooCommerce's own convention for
 * "is this the same customer"): an existing account is mapped by ID
 * only and never touched — no overwrite, no merge — since deciding
 * how to reconcile two different values for the same field is a
 * judgment call this tool has no business making silently. Only a
 * genuinely new email creates a new account, with the *original*
 * password hash carried over so the customer's existing password
 * keeps working after migration (never re-hashed — see import_batch()
 * for why that has to bypass wp_insert_user()'s own hashing).
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Migrate_Users_Migrator {

	/**
	 * Excluded from the raw usermeta copy — not "user data" in the
	 * sense meant here, and actively wrong to carry across sites:
	 * session_tokens are this WordPress install's own login sessions,
	 * meaningless (and a needless residual credential) on a different
	 * install; the user-settings pair is just wp-admin screen-option UI
	 * state. Roles/capabilities are handled separately (export()/
	 * import_batch() below use WP_User::$roles and set_role()/
	 * add_role() rather than copying the raw `{prefix}capabilities`
	 * meta key verbatim), since that key's name is table-prefix-
	 * dependent — copying it as-is would silently fail to grant any
	 * role at all on a target site with a different table prefix.
	 */
	private const EXCLUDED_META_KEYS = [ 'session_tokens', 'wp_user-settings', 'wp_user-settings-time' ];

	public function count_total(): int {
		$query = new \WP_User_Query( [ 'fields' => 'ID', 'number' => 1 ] );
		return (int) $query->get_total();
	}

	/** @return array<int, array> */
	public function export_batch( int $offset, int $limit ): array {
		global $wpdb;

		$query = new \WP_User_Query( [
			'number'  => $limit,
			'offset'  => $offset,
			'orderby' => 'ID',
			'order'   => 'ASC',
			'fields'  => 'all',
		] );

		$capabilities_key = $wpdb->prefix . 'capabilities';
		$user_level_key    = $wpdb->prefix . 'user_level';

		$rows = [];
		foreach ( $query->get_results() as $user ) {
			/** @var \WP_User $user */
			$raw_meta = get_user_meta( $user->ID ); // [key => [value, ...]] — a key can legitimately repeat.

			$meta = [];
			foreach ( $raw_meta as $key => $values ) {
				if ( in_array( $key, self::EXCLUDED_META_KEYS, true ) || $key === $capabilities_key || $key === $user_level_key ) {
					continue;
				}
				$meta[ $key ] = array_map( 'maybe_unserialize', $values );
			}

			$rows[] = [
				'old_id'          => $user->ID,
				'user_login'      => $user->user_login,
				'user_pass'       => $user->user_pass, // The already-hashed value from wp_users — never re-hashed on import, see import_batch().
				'user_email'      => $user->user_email,
				'user_url'        => $user->user_url,
				'user_registered' => $user->user_registered,
				'display_name'    => $user->display_name,
				'user_nicename'   => $user->user_nicename,
				'roles'           => $user->roles,
				'meta'            => $meta,
			];
		}

		return $rows;
	}

	/**
	 * @param array $rows One export_batch() page, decoded.
	 * @param array $id_map Old-ID => new-ID, accumulated across calls by the caller (persisted between AJAX batches).
	 * @return array{id_map:array, created:int, matched:int, errors:array<int,string>}
	 */
	public function import_batch( array $rows, array $id_map ): array {
		global $wpdb;

		$created = 0;
		$matched = 0;
		$errors  = [];

		foreach ( $rows as $row ) {
			$old_id = (int) ( $row['old_id'] ?? 0 );
			$email  = sanitize_email( (string) ( $row['user_email'] ?? '' ) );

			if ( ! $old_id || ! is_email( $email ) ) {
				$errors[] = sprintf( __( 'Skipped a row with an invalid id/email (old id %d).', 'yeffoprint-migrate' ), $old_id );
				continue;
			}

			$existing_id = email_exists( $email );
			if ( $existing_id ) {
				$id_map[ $old_id ] = (int) $existing_id;
				$matched++;
				continue;
			}

			$login = sanitize_user( (string) ( $row['user_login'] ?? '' ), true );
			if ( '' === $login || username_exists( $login ) ) {
				// A login collision with a *different* email is real but
				// rare (e.g. the same username reused by two unrelated
				// people across the two sites) — fall back to a
				// guaranteed-unique login derived from the email rather
				// than failing the whole row over a cosmetic field.
				$login = sanitize_user( current( explode( '@', $email ) ) . '-' . $old_id, true );
			}

			$new_id = wp_insert_user( [
				'user_login'      => $login,
				'user_email'      => $email,
				'user_pass'       => wp_generate_password( 32, true, true ), // Placeholder — overwritten with the real hash directly below, bypassing wp_insert_user()'s own re-hashing.
				'user_url'        => esc_url_raw( (string) ( $row['user_url'] ?? '' ) ),
				'user_registered' => (string) ( $row['user_registered'] ?? current_time( 'mysql' ) ),
				'display_name'    => sanitize_text_field( (string) ( $row['display_name'] ?? '' ) ),
				'nickname'        => sanitize_text_field( (string) ( $row['display_name'] ?? $login ) ),
				'role'            => '', // No default role — roles are set explicitly below from the export's own list, which may be empty, singular, or (rarely) multiple.
			] );

			if ( is_wp_error( $new_id ) ) {
				$errors[] = sprintf( '%s: %s', $email, $new_id->get_error_message() );
				continue;
			}

			$hash = (string) ( $row['user_pass'] ?? '' );
			if ( '' !== $hash ) {
				// wp_insert_user()/wp_update_user() always run whatever
				// they're given through wp_hash_password() — there is no
				// "trust me, this is already hashed" option in WordPress
				// core, so the only way to land an existing hash intact
				// is a direct, targeted column update after the account
				// already exists.
				$wpdb->update( $wpdb->users, [ 'user_pass' => $hash ], [ 'ID' => $new_id ], [ '%s' ], [ '%d' ] );
				clean_user_cache( $new_id );
			}

			$new_user = new \WP_User( $new_id );
			$roles    = array_filter( array_map( 'sanitize_key', (array) ( $row['roles'] ?? [] ) ) );
			foreach ( $roles as $role ) {
				if ( wp_roles()->is_role( $role ) ) {
					$new_user->add_role( $role );
				}
			}
			if ( ! $roles ) {
				$new_user->add_role( 'customer' ); // Every export row without a recognizable role still needs *some* WooCommerce-usable role rather than none.
			}

			foreach ( (array) ( $row['meta'] ?? [] ) as $key => $values ) {
				foreach ( (array) $values as $value ) {
					add_user_meta( $new_id, (string) $key, $value );
				}
			}

			$id_map[ $old_id ] = (int) $new_id;
			$created++;
		}

		return [ 'id_map' => $id_map, 'created' => $created, 'matched' => $matched, 'errors' => $errors ];
	}
}
