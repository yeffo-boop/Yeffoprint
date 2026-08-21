<?php
/**
 * Protected storage for export/import files.
 *
 * These files can contain password hashes, billing/shipping addresses,
 * order history, and whatever WooCommerce settings (including payment
 * gateway configuration) exist on the source site — real PII and
 * credentials, not something to leave sitting at a guessable URL under
 * the normal uploads directory. Stored instead under a dedicated
 * directory, blocked from direct web access by both a .htaccess rule
 * (Apache) and an index.php silence file (defense in depth — the
 * .htaccess rule alone does nothing on nginx), and only ever reachable
 * through a nonce-verified, capability-checked admin-post.php handler,
 * never a direct file URL.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Migrate_File_Store {

	private const SUBDIR = 'yeffoprint-migrate';

	public static function dir(): string {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . self::SUBDIR;
	}

	public static function protect_storage_dir(): void {
		$dir = self::dir();
		wp_mkdir_p( $dir );

		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Require all denied\nDeny from all\n" );
		}

		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * @return string|\WP_Error Absolute path to the new, empty file.
	 *   Actually creates that empty file (not just the path string) —
	 *   the batched export loop's very first call (class-ajax-
	 *   controller.php's export_batch()) calls this, then immediately
	 *   calls resolve() on the same path to get back a real, checked
	 *   path to append each batch's rows to. resolve() requires the
	 *   file to already exist ("was this migration file actually
	 *   found," not "is this a safe path to write one at") — without
	 *   creating it here first, that resolve() call always failed with
	 *   "That migration file was not found," on every single export,
	 *   before a single row was ever written.
	 */
	public static function new_export_path( string $kind, string $extension ) {
		self::protect_storage_dir();

		$dir = self::dir();
		if ( ! wp_is_writable( $dir ) ) {
			return new \WP_Error( 'yeffoprint_migrate_dir_unwritable', __( 'The migration storage directory is not writable.', 'yeffoprint-migrate' ) );
		}

		$filename = sprintf( 'yeffoprint-migrate-%s-%s.%s', $kind, gmdate( 'Ymd-His' ), $extension );
		$path     = $dir . '/' . $filename;

		if ( false === file_put_contents( $path, '' ) ) {
			return new \WP_Error( 'yeffoprint_migrate_file_create_failed', __( "Couldn't create the export file.", 'yeffoprint-migrate' ) );
		}

		return $path;
	}

	/** Validates that $filename (a bare filename, no path segments) exists inside the protected dir and returns its absolute path, or a WP_Error. */
	public static function resolve( string $filename ) {
		$filename = wp_basename( $filename ); // Strips any directory traversal attempt down to a bare filename.
		$path     = self::dir() . '/' . $filename;

		if ( ! file_exists( $path ) || ! is_file( $path ) ) {
			return new \WP_Error( 'yeffoprint_migrate_file_not_found', __( 'That migration file was not found.', 'yeffoprint-migrate' ) );
		}

		return $path;
	}

	/**
	 * Moves an admin-uploaded file into the protected directory. Only
	 * accepts .json/.ndjson — this plugin never needs to accept
	 * arbitrary uploads, so there's no reason to allow anything else.
	 *
	 * @return string|\WP_Error Bare filename (not full path) on success.
	 */
	public static function store_upload( array $file, string $kind ) {
		if ( ! isset( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new \WP_Error( 'yeffoprint_migrate_bad_upload', __( 'No file was uploaded.', 'yeffoprint-migrate' ) );
		}

		$original_ext = strtolower( pathinfo( $file['name'] ?? '', PATHINFO_EXTENSION ) );
		if ( ! in_array( $original_ext, [ 'json', 'ndjson' ], true ) ) {
			return new \WP_Error( 'yeffoprint_migrate_bad_filetype', __( 'Please upload the .json or .ndjson file this plugin exported.', 'yeffoprint-migrate' ) );
		}

		self::protect_storage_dir();

		$dest_filename = sprintf( 'yeffoprint-migrate-import-%s-%s.%s', $kind, gmdate( 'Ymd-His' ), $original_ext );
		$dest_path     = self::dir() . '/' . $dest_filename;

		if ( ! move_uploaded_file( $file['tmp_name'], $dest_path ) ) {
			return new \WP_Error( 'yeffoprint_migrate_upload_move_failed', __( "Couldn't save the uploaded file.", 'yeffoprint-migrate' ) );
		}

		return $dest_filename;
	}

	public static function delete( string $filename ): bool {
		$path = self::resolve( $filename );
		return ! is_wp_error( $path ) && wp_delete_file( $path ) !== false;
	}

	/** @return array<int, array{name:string, size:int, modified:int}> */
	public static function list_files(): array {
		self::protect_storage_dir();
		$dir = self::dir();

		$files = [];
		foreach ( glob( $dir . '/*.{json,ndjson}', GLOB_BRACE ) ?: [] as $path ) {
			$files[] = [
				'name'     => wp_basename( $path ),
				'size'     => filesize( $path ) ?: 0,
				'modified' => filemtime( $path ) ?: 0,
			];
		}

		usort( $files, static function ( $a, $b ) {
			return $b['modified'] <=> $a['modified'];
		} );

		return $files;
	}
}
