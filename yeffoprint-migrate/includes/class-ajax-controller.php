<?php
/**
 * AJAX endpoints driving the admin page's batch export/import loops.
 *
 * "Admin page only" (direct choice, no WP-CLI) means every export/
 * import has to run as a sequence of short HTTP requests small enough
 * to never approach a hosting timeout, rather than one long-running
 * process — assets/admin.js drives that loop client-side, calling
 * back into these handlers repeatedly until each one reports done.
 *
 * Progress between calls is kept in options (not transients) —
 * autoload disabled, but *not* subject to a persistent object cache's
 * own eviction policy the way a transient would be, which matters
 * more here than the usual "fine to lose a transient" tradeoff: a
 * multi-minute migration spanning dozens of requests losing its place
 * partway through would be a real problem, not just a cache miss.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Migrate_Ajax_Controller {

	private const BATCH_SIZE = 50;
	private const OPTION_PREFIX = 'yeffoprint_migrate_progress_';

	public function __construct() {
		add_action( 'wp_ajax_yeffoprint_migrate_export_settings', [ $this, 'export_settings' ] );
		add_action( 'wp_ajax_yeffoprint_migrate_import_settings', [ $this, 'import_settings' ] );
		add_action( 'wp_ajax_yeffoprint_migrate_export_batch', [ $this, 'export_batch' ] );
		add_action( 'wp_ajax_yeffoprint_migrate_import_batch', [ $this, 'import_batch' ] );
	}

	private function check_request(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Not allowed.', 'yeffoprint-migrate' ) ], 403 );
		}
		check_ajax_referer( 'yeffoprint_migrate', 'nonce' );
	}

	/* ---------- Settings (one-shot, no batching needed) ---------- */

	public function export_settings(): void {
		$this->check_request();

		$path = YeffoPrint_Migrate_File_Store::new_export_path( 'settings', 'json' );
		if ( is_wp_error( $path ) ) {
			wp_send_json_error( [ 'message' => $path->get_error_message() ] );
		}

		$data = ( new YeffoPrint_Migrate_Settings_Migrator() )->export();
		$written = file_put_contents( $path, wp_json_encode( $data, JSON_PRETTY_PRINT ) );

		if ( false === $written ) {
			wp_send_json_error( [ 'message' => __( "Couldn't write the settings export file.", 'yeffoprint-migrate' ) ] );
		}

		wp_send_json_success( [
			'file'    => wp_basename( $path ),
			'options' => count( $data['options'] ),
			'zones'   => count( $data['shipping_zones']['zones'] ?? [] ),
			'rates'   => count( $data['tax_rates']['rates'] ?? [] ),
		] );
	}

	public function import_settings(): void {
		$this->check_request();

		$filename = sanitize_file_name( (string) ( $_POST['file'] ?? '' ) );
		$path     = YeffoPrint_Migrate_File_Store::resolve( $filename );
		if ( is_wp_error( $path ) ) {
			wp_send_json_error( [ 'message' => $path->get_error_message() ] );
		}

		$decoded = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $decoded ) ) {
			wp_send_json_error( [ 'message' => __( 'That file is not a valid settings export.', 'yeffoprint-migrate' ) ] );
		}

		$result = ( new YeffoPrint_Migrate_Settings_Migrator() )->import( $decoded );
		wp_send_json_success( $result );
	}

	/* ---------- Users / Orders export (batched) ---------- */

	public function export_batch(): void {
		$this->check_request();

		$type = $this->validated_type();
		$migrator = $this->migrator_for( $type );

		$progress_key = self::OPTION_PREFIX . 'export_' . $type;
		$reset        = ! empty( $_POST['reset'] );
		$progress     = $reset ? null : get_option( $progress_key );

		if ( ! is_array( $progress ) ) {
			$path = YeffoPrint_Migrate_File_Store::new_export_path( $type, 'ndjson' );
			if ( is_wp_error( $path ) ) {
				wp_send_json_error( [ 'message' => $path->get_error_message() ] );
			}
			$progress = [
				'file'      => wp_basename( $path ),
				'offset'    => 0,
				'total'     => $migrator->count_total(),
				'processed' => 0,
				'done'      => false,
			];
		}

		if ( $progress['done'] ) {
			wp_send_json_success( $progress );
		}

		$path = YeffoPrint_Migrate_File_Store::resolve( $progress['file'] );
		if ( is_wp_error( $path ) ) {
			wp_send_json_error( [ 'message' => $path->get_error_message() ] );
		}

		$rows = $migrator->export_batch( $progress['offset'], self::BATCH_SIZE );

		if ( $rows ) {
			$lines = implode( '', array_map( static function ( $row ) {
				return wp_json_encode( $row ) . "\n";
			}, $rows ) );
			file_put_contents( $path, $lines, FILE_APPEND );
		}

		$progress['offset']   += self::BATCH_SIZE;
		$progress['processed'] += count( $rows );
		$progress['done']      = count( $rows ) < self::BATCH_SIZE;

		update_option( $progress_key, $progress, false );

		if ( $progress['done'] ) {
			delete_option( $progress_key );
		}

		wp_send_json_success( $progress );
	}

	/* ---------- Users / Orders import (batched) ---------- */

	public function import_batch(): void {
		$this->check_request();

		$type     = $this->validated_type();
		$migrator = $this->migrator_for( $type );

		$progress_key = self::OPTION_PREFIX . 'import_' . $type;
		$reset        = ! empty( $_POST['reset'] );
		$progress     = $reset ? null : get_option( $progress_key );

		if ( ! is_array( $progress ) ) {
			$filename = sanitize_file_name( (string) ( $_POST['file'] ?? '' ) );
			$path     = YeffoPrint_Migrate_File_Store::resolve( $filename );
			if ( is_wp_error( $path ) ) {
				wp_send_json_error( [ 'message' => $path->get_error_message() ] );
			}

			$progress = [
				'file'        => $filename,
				'byte_offset' => 0,
				'total_bytes' => filesize( $path ) ?: 0,
				'created'     => 0,
				'matched'     => 0, // Users only — orders has no equivalent "already exists" match, only skip-by-old-id.
				'skipped'     => 0, // Orders only.
				'errors'      => [],
				'id_map'      => 'users' === $type ? $this->existing_user_id_map() : [],
				'done'        => false,
			];
		}

		if ( $progress['done'] ) {
			wp_send_json_success( $progress );
		}

		$path = YeffoPrint_Migrate_File_Store::resolve( $progress['file'] );
		if ( is_wp_error( $path ) ) {
			wp_send_json_error( [ 'message' => $path->get_error_message() ] );
		}

		[ $rows, $next_offset, $reached_end ] = $this->read_ndjson_batch( $path, (int) $progress['byte_offset'], self::BATCH_SIZE );

		if ( 'users' === $type ) {
			$result = $migrator->import_batch( $rows, $progress['id_map'] );
			$progress['id_map']  = $result['id_map'];
			$progress['created'] += $result['created'];
			$progress['matched'] += $result['matched'];
		} else {
			// Orders need the *completed* users import's id map, not an
			// in-progress one — always read fresh from where the users
			// import left it (yeffoprint_migrate_user_id_map, saved once
			// that batch loop finishes; see maybe_persist_user_id_map()).
			$result = $migrator->import_batch( $rows, get_option( 'yeffoprint_migrate_user_id_map', [] ) );
			$progress['created'] += $result['created'];
			$progress['skipped'] += $result['skipped'];
		}

		$progress['errors']      = array_slice( array_merge( $progress['errors'], $result['errors'] ), -50 ); // Cap so this option can't grow unbounded on a run with pervasive errors.
		$progress['byte_offset'] = $next_offset;
		$progress['done']        = $reached_end;

		if ( $progress['done'] ) {
			if ( 'users' === $type ) {
				update_option( 'yeffoprint_migrate_user_id_map', $progress['id_map'], false );
			}
			delete_option( $progress_key );
		} else {
			update_option( $progress_key, $progress, false );
		}

		wp_send_json_success( $progress );
	}

	/**
	 * Seeds the id map with every already-migrated user found by the
	 * `_yp_migrate` marker this class itself never actually sets on
	 * users directly — instead this reads back whatever the last
	 * completed users import left behind, so re-running (or running
	 * orders import in a later browser session) still has it. See
	 * import_batch()'s 'users' branch, which is what actually persists
	 * yeffoprint_migrate_user_id_map when its loop finishes.
	 */
	private function existing_user_id_map(): array {
		return (array) get_option( 'yeffoprint_migrate_user_id_map', [] );
	}

	/**
	 * Reads up to $limit NDJSON lines starting at $byte_offset.
	 *
	 * @return array{0: array<int,array>, 1: int, 2: bool} [decoded rows, new byte offset, whether EOF was reached]
	 */
	private function read_ndjson_batch( string $path, int $byte_offset, int $limit ): array {
		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) {
			return [ [], $byte_offset, true ];
		}

		fseek( $handle, $byte_offset );

		$rows = [];
		$count = 0;
		while ( $count < $limit && ! feof( $handle ) ) {
			$line = fgets( $handle );
			if ( false === $line ) {
				break;
			}
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$decoded = json_decode( $line, true );
			if ( is_array( $decoded ) ) {
				$rows[] = $decoded;
			}
			$count++;
		}

		$new_offset = ftell( $handle );
		$reached_end = feof( $handle );
		fclose( $handle );

		return [ $rows, (int) $new_offset, $reached_end ];
	}

	private function validated_type(): string {
		$type = sanitize_key( (string) ( $_POST['type'] ?? '' ) );
		if ( ! in_array( $type, [ 'users', 'orders' ], true ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown migration type.', 'yeffoprint-migrate' ) ] );
		}
		return $type;
	}

	/** @return YeffoPrint_Migrate_Users_Migrator|YeffoPrint_Migrate_Orders_Migrator */
	private function migrator_for( string $type ) {
		return 'users' === $type ? new YeffoPrint_Migrate_Users_Migrator() : new YeffoPrint_Migrate_Orders_Migrator();
	}
}
