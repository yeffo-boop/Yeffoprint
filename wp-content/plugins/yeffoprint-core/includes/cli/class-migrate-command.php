<?php
/**
 * Total site backup/restore for a server-to-server migration (direct
 * request: "a total backup of the entire site and restore of the
 * entire site, media and all").
 *
 * Scoped to database + `wp-content/uploads` only — the plugin/theme
 * code itself is already handled by the project's own git-based deploy
 * (docs/deploy-setup.md), so re-syncing that is a separate, manual step
 * the owner takes care of directly; duplicating code into this backup
 * would just be dead weight riding along with the real data.
 *
 * WP-CLI only, matching class-seed-command.php's own reasoning (never
 * runs automatically, no HTTP/admin-UI trigger) — confirmed the target
 * environment has shell access on both the old and new server, which
 * also means this can shell out to `mysqldump`/`mysql`/`tar` directly
 * rather than buffering a multi-gigabyte export through PHP memory or
 * an HTTP upload/download round trip (the real risk with an admin-UI
 * "download backup" button once a site's media library is more than a
 * few dozen megabytes).
 *
 * `export` writes a fresh timestamped directory *outside* the WordPress
 * install root by default (`dirname(ABSPATH) . '/yp-migrations/'`) —
 * deliberately not under docroot, and deliberately not anywhere inside
 * the git working tree this project's deploy script manages. That tree
 * gets `git reset --hard`'d on every deploy cycle (never `git clean`'d,
 * per docs/deploy-setup.md, so an untracked file surviving there isn't
 * actually the risk) but there's no reason to invite the question, and
 * a backup containing every customer's name/address/order history has
 * no business sitting anywhere web-servable. `.htaccess`/`index.php`
 * hardening is added to the output directory regardless, as
 * defense-in-depth in case the chosen --output ever does end up
 * somewhere web-accessible.
 *
 * `import` is the reverse: restores a database dump and/or a media
 * archive from a directory `export` produced. Same domain, different
 * server was confirmed as the actual migration shape here, so this
 * intentionally does *not* attempt a serialization-safe search-replace
 * of the site URL — WP-CLI already ships that itself (`wp
 * search-replace`) for the day this ever changes, so hand-rolling a
 * second implementation of it here would be pure duplication.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Migrate_Command {

	public function register(): void {
		\WP_CLI::add_command( 'yeffoprint migrate', $this );
	}

	/**
	 * Exports the database and the uploads directory to a fresh backup directory.
	 *
	 * ## OPTIONS
	 *
	 * [--output=<path>]
	 * : Directory to write the backup into. A new timestamped
	 * subdirectory is created inside it. Defaults to a `yp-migrations`
	 * directory one level above the WordPress install root — outside
	 * both the web-servable docroot and this project's git working
	 * tree.
	 *
	 * [--skip-db]
	 * : Skip the database export.
	 *
	 * [--skip-uploads]
	 * : Skip the uploads export.
	 *
	 * ## EXAMPLES
	 *
	 *     wp yeffoprint migrate export
	 *     wp yeffoprint migrate export --output=/home/user/backups
	 */
	public function export( array $args, array $assoc_args ): void {
		$this->require_binaries( [ 'gzip' ] );

		$base_dir = $assoc_args['output'] ?? ( dirname( ABSPATH ) . '/yp-migrations' );
		$run_dir  = rtrim( $base_dir, '/' ) . '/' . gmdate( 'Y-m-d-His' );

		if ( ! wp_mkdir_p( $run_dir ) ) {
			\WP_CLI::error( "Couldn't create output directory: {$run_dir}" );
		}
		$this->harden_directory( $base_dir );
		$this->harden_directory( $run_dir );

		$manifest = [
			'site_url'          => site_url(),
			'home_url'          => home_url(),
			'wp_version'        => get_bloginfo( 'version' ),
			'yeffoprint_core'   => YEFFOPRINT_CORE_VERSION,
			'exported_at'       => gmdate( 'c' ),
			'includes_database' => empty( $assoc_args['skip-db'] ),
			'includes_uploads'  => empty( $assoc_args['skip-uploads'] ),
		];

		if ( empty( $assoc_args['skip-db'] ) ) {
			$this->export_database( $run_dir . '/database.sql.gz' );
		}

		if ( empty( $assoc_args['skip-uploads'] ) ) {
			$this->export_uploads( $run_dir . '/uploads.tar.gz' );
		}

		file_put_contents( $run_dir . '/manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );

		\WP_CLI::success( "Backup written to {$run_dir}" );
		\WP_CLI::log( "Copy the whole directory to the new server (e.g. rsync -av {$run_dir}/ user@newhost:/path/) then run:" );
		\WP_CLI::log( "    wp yeffoprint migrate import {$run_dir}" );
		\WP_CLI::log( '(from the new server, once the files have landed there).' );
	}

	/**
	 * Restores the database and/or uploads directory from a directory `export` produced.
	 *
	 * ## OPTIONS
	 *
	 * <path>
	 * : The backup directory (as produced by `wp yeffoprint migrate export`).
	 *
	 * [--skip-db]
	 * : Don't restore the database, even if database.sql.gz is present.
	 *
	 * [--skip-uploads]
	 * : Don't restore uploads, even if uploads.tar.gz is present.
	 *
	 * [--clean-uploads]
	 * : Empty the destination wp-content/uploads directory before
	 * extracting, so the result exactly matches the backup rather than
	 * a merge of old and new files. Off by default since a fresh server
	 * has nothing there to conflict with anyway.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt. This overwrites the current
	 * database and/or media — only pass this once you're sure.
	 *
	 * ## EXAMPLES
	 *
	 *     wp yeffoprint migrate import /home/user/yp-migrations/2026-08-26-120000
	 *     wp yeffoprint migrate import /home/user/backups/latest --skip-uploads
	 */
	public function import( array $args, array $assoc_args ): void {
		$run_dir = rtrim( $args[0] ?? '', '/' );
		if ( '' === $run_dir || ! is_dir( $run_dir ) ) {
			\WP_CLI::error( 'Not a directory: ' . ( $args[0] ?? '(none given)' ) );
		}

		$db_archive      = $run_dir . '/database.sql.gz';
		$uploads_archive = $run_dir . '/uploads.tar.gz';
		$restore_db      = empty( $assoc_args['skip-db'] ) && file_exists( $db_archive );
		$restore_uploads = empty( $assoc_args['skip-uploads'] ) && file_exists( $uploads_archive );

		if ( ! $restore_db && ! $restore_uploads ) {
			\WP_CLI::error( 'Nothing to restore — no database.sql.gz or uploads.tar.gz found in that directory (or both were skipped).' );
		}

		$manifest_path = $run_dir . '/manifest.json';
		if ( file_exists( $manifest_path ) ) {
			$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
			if ( is_array( $manifest ) && ! empty( $manifest['site_url'] ) && $manifest['site_url'] !== site_url() ) {
				\WP_CLI::warning( "This backup was exported from {$manifest['site_url']}, but this site's URL is " . site_url() . '. Continuing restores the data as-is — run `wp search-replace` afterward if the domain actually needs to change.' );
			}
		}

		\WP_CLI::confirm(
			sprintf(
				'This will overwrite %s on this site with the contents of %s. Continue?',
				implode( ' and ', array_filter( [ $restore_db ? 'the database' : '', $restore_uploads ? 'wp-content/uploads' : '' ] ) ),
				$run_dir
			),
			$assoc_args
		);

		if ( $restore_db ) {
			$this->require_binaries( [ 'gzip' ] );
			$this->import_database( $db_archive );
		}

		if ( $restore_uploads ) {
			$this->import_uploads( $uploads_archive, ! empty( $assoc_args['clean-uploads'] ) );
		}

		if ( $restore_db ) {
			flush_rewrite_rules();
			wp_cache_flush();
		}

		\WP_CLI::success( 'Restore complete. Verify the site, then re-point/re-sync your git deploy on this server separately — this command never touched plugin/theme code.' );
	}

	private function export_database( string $dest_gz ): void {
		$this->require_binaries( [ 'mysqldump' ] );
		$defaults_file = $this->write_mysql_defaults_file();
		$host          = $this->parse_db_host();

		$cmd = sprintf(
			'mysqldump --defaults-extra-file=%s %s --single-transaction --quick --routines --triggers %s | gzip > %s',
			escapeshellarg( $defaults_file ),
			$host['args'],
			escapeshellarg( DB_NAME ),
			escapeshellarg( $dest_gz )
		);

		\WP_CLI::log( 'Exporting database…' );
		exec( $cmd . ' 2>&1', $output, $exit_code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- shell access confirmed available; mysqldump has no PHP-native equivalent for a full-database dump.
		unlink( $defaults_file );

		if ( 0 !== $exit_code || ! file_exists( $dest_gz ) || 0 === filesize( $dest_gz ) ) {
			\WP_CLI::error( "Database export failed:\n" . implode( "\n", $output ) );
		}

		\WP_CLI::log( 'Database exported (' . size_format( filesize( $dest_gz ) ) . ').' );
	}

	private function import_database( string $src_gz ): void {
		$defaults_file = $this->write_mysql_defaults_file();
		$host          = $this->parse_db_host();

		$cmd = sprintf(
			'gunzip -c %s | mysql --defaults-extra-file=%s %s %s',
			escapeshellarg( $src_gz ),
			escapeshellarg( $defaults_file ),
			$host['args'],
			escapeshellarg( DB_NAME )
		);

		\WP_CLI::log( 'Importing database…' );
		exec( $cmd . ' 2>&1', $output, $exit_code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- see export_database().
		unlink( $defaults_file );

		if ( 0 !== $exit_code ) {
			\WP_CLI::error( "Database import failed:\n" . implode( "\n", $output ) );
		}

		\WP_CLI::log( 'Database imported.' );
	}

	private function export_uploads( string $dest_tar_gz ): void {
		$this->require_binaries( [ 'tar' ] );
		$uploads   = wp_get_upload_dir();
		$base_path = $uploads['basedir'];

		if ( ! is_dir( $base_path ) ) {
			\WP_CLI::warning( "No uploads directory found at {$base_path} — skipping media export." );
			return;
		}

		$cmd = sprintf(
			'tar -czf %s -C %s %s',
			escapeshellarg( $dest_tar_gz ),
			escapeshellarg( dirname( $base_path ) ),
			escapeshellarg( basename( $base_path ) )
		);

		\WP_CLI::log( 'Archiving uploads (this can take a while for a few GB of media)…' );
		exec( $cmd . ' 2>&1', $output, $exit_code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- see export_database().

		if ( 0 !== $exit_code || ! file_exists( $dest_tar_gz ) ) {
			\WP_CLI::error( "Uploads export failed:\n" . implode( "\n", $output ) );
		}

		\WP_CLI::log( 'Uploads archived (' . size_format( filesize( $dest_tar_gz ) ) . ').' );
	}

	private function import_uploads( string $src_tar_gz, bool $clean_first ): void {
		$this->require_binaries( [ 'tar' ] );
		$uploads   = wp_get_upload_dir();
		$base_path = $uploads['basedir'];

		if ( $clean_first && is_dir( $base_path ) ) {
			\WP_CLI::log( 'Clearing existing uploads directory…' );
			$this->recursive_delete_contents( $base_path );
		}

		if ( ! wp_mkdir_p( $base_path ) ) {
			\WP_CLI::error( "Couldn't create uploads directory: {$base_path}" );
		}

		$cmd = sprintf(
			'tar -xzf %s -C %s --strip-components=1',
			escapeshellarg( $src_tar_gz ),
			escapeshellarg( $base_path )
		);

		\WP_CLI::log( 'Extracting uploads…' );
		exec( $cmd . ' 2>&1', $output, $exit_code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- see export_database().

		if ( 0 !== $exit_code ) {
			\WP_CLI::error( "Uploads import failed:\n" . implode( "\n", $output ) );
		}

		\WP_CLI::log( 'Uploads restored.' );
	}

	/** Every mysqldump/mysql invocation above shells out with credentials read from a 0600 --defaults-extra-file instead of a command-line flag — a bare --password=... would sit in plain sight in `ps aux` for any other user on the box for as long as the command runs. Plain PHP tempnam() rather than wp_tempnam() — the latter lives in wp-admin/includes/file.php, not loaded by default outside wp-admin/a WP-CLI bootstrap that happens to include it. */
	private function write_mysql_defaults_file(): string {
		$path = tempnam( sys_get_temp_dir(), 'yp-migrate-my-cnf' );
		file_put_contents( $path, "[client]\nuser={$this->escape_ini_value( DB_USER )}\npassword={$this->escape_ini_value( DB_PASSWORD )}\n" );
		chmod( $path, 0600 );
		return $path;
	}

	private function escape_ini_value( string $value ): string {
		return '"' . str_replace( '"', '\\"', $value ) . '"';
	}

	/**
	 * DB_HOST supports plain "host", "host:port", "host:/path/to/socket",
	 * and an optional leading "p:" (persistent connection) prefix WP's
	 * own wpdb strips before connecting — mirrored here so a socket-based
	 * local DB host doesn't silently get treated as a TCP hostname.
	 *
	 * @return array{args:string} A ready-to-use --host/--port or --socket flag string for the mysql/mysqldump command line.
	 */
	private function parse_db_host(): array {
		$host = preg_replace( '/^p:/', '', DB_HOST );

		if ( false !== strpos( $host, ':' ) ) {
			[ $hostname, $port_or_socket ] = explode( ':', $host, 2 );
			if ( str_starts_with( $port_or_socket, '/' ) ) {
				return [ 'args' => '--socket=' . escapeshellarg( $port_or_socket ) ];
			}
			return [ 'args' => '--host=' . escapeshellarg( $hostname ) . ' --port=' . escapeshellarg( $port_or_socket ) ];
		}

		return [ 'args' => '--host=' . escapeshellarg( $host ) ];
	}

	private function require_binaries( array $binaries ): void {
		foreach ( $binaries as $binary ) {
			exec( 'command -v ' . escapeshellarg( $binary ), $output, $exit_code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- a pure availability check, no user input involved.
			if ( 0 !== $exit_code ) {
				\WP_CLI::error( "`{$binary}` isn't available on this server's PATH — this command needs it and has no pure-PHP fallback (see this class's own docblock for why)." );
			}
		}
	}

	private function recursive_delete_contents( string $dir ): void {
		$items = scandir( $dir );
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->recursive_delete_contents( $path );
				rmdir( $path );
			} else {
				unlink( $path );
			}
		}
	}

	/** Belt-and-suspenders — this directory should already be outside the docroot, but a backup full of customer data costs nothing to also lock down directly in case --output was pointed somewhere web-servable. */
	private function harden_directory( string $dir ): void {
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Require all denied\nDeny from all\n" );
		}
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}
}
