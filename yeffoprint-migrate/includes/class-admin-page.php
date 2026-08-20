<?php
/**
 * The plugin's entire UI: one Tools submenu page with three sections
 * (Settings / Users / Orders), each offering Export and Import. File
 * upload and download/delete go through admin-post.php handlers (not
 * AJAX) since file transfer needs a real HTTP request/response, not a
 * JSON round-trip — the batch processing *of* an already-uploaded file
 * is what class-ajax-controller.php drives.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Migrate_Admin_Page {

	private const CAP = 'manage_options';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_post_yeffoprint_migrate_upload', [ $this, 'handle_upload' ] );
		add_action( 'admin_post_yeffoprint_migrate_download', [ $this, 'handle_download' ] );
		add_action( 'admin_post_yeffoprint_migrate_delete', [ $this, 'handle_delete' ] );
	}

	public function register_menu(): void {
		add_management_page(
			__( 'YeffoPrint Migrate', 'yeffoprint-migrate' ),
			__( 'YeffoPrint Migrate', 'yeffoprint-migrate' ),
			self::CAP,
			'yeffoprint-migrate',
			[ $this, 'render_page' ]
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( 'tools_page_yeffoprint-migrate' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'yeffoprint-migrate-admin', YEFFOPRINT_MIGRATE_URL . 'assets/admin.css', [], YEFFOPRINT_MIGRATE_VERSION );

		wp_enqueue_script( 'yeffoprint-migrate-admin', YEFFOPRINT_MIGRATE_URL . 'assets/admin.js', [], YEFFOPRINT_MIGRATE_VERSION, true );
		wp_localize_script( 'yeffoprint-migrate-admin', 'yeffoprintMigrate', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'yeffoprint_migrate' ),
			'i18n'    => [
				'exporting'     => __( 'Exporting…', 'yeffoprint-migrate' ),
				'importing'     => __( 'Importing…', 'yeffoprint-migrate' ),
				'done'          => __( 'Done.', 'yeffoprint-migrate' ),
				'error'         => __( 'Something went wrong — see details below.', 'yeffoprint-migrate' ),
				'confirmImport' => __( 'This will overwrite matching settings on this site and cannot be undone. Continue?', 'yeffoprint-migrate' ),
			],
		] );
	}

	public function render_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'yeffoprint-migrate' ) );
		}

		$files = YeffoPrint_Migrate_File_Store::list_files();
		?>
		<div class="wrap yeffoprint-migrate">
			<h1><?php esc_html_e( 'YeffoPrint Migrate', 'yeffoprint-migrate' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Move WooCommerce settings, order history, and user accounts from an old YeffoPrint site to this one. Nothing else — no products, no theme/site content.', 'yeffoprint-migrate' ); ?>
			</p>
			<div class="notice notice-warning inline">
				<p>
					<?php esc_html_e( 'Exported files can contain password hashes, customer addresses, and order details — real sensitive data. Run exports/imports over HTTPS, and delete files from the list below once a migration is complete.', 'yeffoprint-migrate' ); ?>
				</p>
			</div>

			<?php $this->render_section_settings(); ?>
			<?php $this->render_section_batch( 'users', __( 'Users', 'yeffoprint-migrate' ), __( 'Accounts matched by email are left untouched — only a new email creates a new account.', 'yeffoprint-migrate' ) ); ?>
			<?php $this->render_section_batch( 'orders', __( 'Orders', 'yeffoprint-migrate' ), __( 'Import Users first — orders link to the accounts that import creates/matches. Re-running a completed import skips orders already migrated.', 'yeffoprint-migrate' ) ); ?>
			<?php $this->render_files_table( $files ); ?>
		</div>
		<?php
	}

	private function render_section_settings(): void {
		?>
		<div class="yeffoprint-migrate-section" data-section="settings">
			<h2><?php esc_html_e( 'WooCommerce Settings', 'yeffoprint-migrate' ); ?></h2>
			<p class="description"><?php esc_html_e( 'General/tax/shipping/checkout/account/email settings, payment gateway configuration, shipping zones, and tax rates. Import overwrites matching settings on this site.', 'yeffoprint-migrate' ); ?></p>

			<p>
				<button type="button" class="button button-secondary" data-action="export-settings"><?php esc_html_e( 'Export Settings', 'yeffoprint-migrate' ); ?></button>
			</p>

			<?php $this->render_upload_form( 'settings' ); ?>
			<p>
				<button type="button" class="button button-primary" data-action="import-settings" data-confirm="1" disabled><?php esc_html_e( 'Import Uploaded Settings', 'yeffoprint-migrate' ); ?></button>
			</p>

			<div class="yeffoprint-migrate-progress" hidden></div>
			<div class="yeffoprint-migrate-result" hidden></div>
		</div>
		<?php
	}

	private function render_section_batch( string $type, string $label, string $note ): void {
		?>
		<div class="yeffoprint-migrate-section" data-section="<?php echo esc_attr( $type ); ?>">
			<h2><?php echo esc_html( $label ); ?></h2>
			<p class="description"><?php echo esc_html( $note ); ?></p>

			<p>
				<button type="button" class="button button-secondary" data-action="export-batch" data-type="<?php echo esc_attr( $type ); ?>"><?php esc_html_e( 'Export', 'yeffoprint-migrate' ); ?></button>
			</p>

			<?php $this->render_upload_form( $type ); ?>
			<p>
				<button type="button" class="button button-primary" data-action="import-batch" data-type="<?php echo esc_attr( $type ); ?>" disabled><?php esc_html_e( 'Start Import', 'yeffoprint-migrate' ); ?></button>
			</p>

			<div class="yeffoprint-migrate-progress" hidden>
				<progress max="100" value="0"></progress>
				<span class="yeffoprint-migrate-progress-label"></span>
			</div>
			<div class="yeffoprint-migrate-result" hidden></div>
		</div>
		<?php
	}

	private function render_upload_form( string $type ): void {
		?>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="yeffoprint-migrate-upload-form">
			<input type="hidden" name="action" value="yeffoprint_migrate_upload" />
			<input type="hidden" name="type" value="<?php echo esc_attr( $type ); ?>" />
			<?php wp_nonce_field( 'yeffoprint_migrate_upload_' . $type ); ?>
			<input type="file" name="import_file" accept=".json,.ndjson" required />
			<button type="submit" class="button"><?php esc_html_e( 'Upload', 'yeffoprint-migrate' ); ?></button>
		</form>
		<?php
		$uploaded = $this->uploaded_file_notice( $type );
		if ( $uploaded ) {
			printf(
				'<p class="yeffoprint-migrate-uploaded" data-type="%s" data-file="%s">%s <code>%s</code></p>',
				esc_attr( $type ),
				esc_attr( $uploaded ),
				esc_html__( 'Uploaded, ready to import:', 'yeffoprint-migrate' ),
				esc_html( $uploaded )
			);
		}
	}

	private function uploaded_file_notice( string $type ): string {
		if ( ! isset( $_GET['yeffoprint_migrate_uploaded'], $_GET['type'] ) || $type !== $_GET['type'] ) {
			return '';
		}
		return sanitize_file_name( wp_unslash( (string) $_GET['yeffoprint_migrate_uploaded'] ) );
	}

	private function render_files_table( array $files ): void {
		?>
		<div class="yeffoprint-migrate-section" data-section="files">
			<h2><?php esc_html_e( 'Files', 'yeffoprint-migrate' ); ?></h2>
			<?php if ( ! $files ) : ?>
				<p class="description"><?php esc_html_e( 'No export/import files yet.', 'yeffoprint-migrate' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'File', 'yeffoprint-migrate' ); ?></th>
							<th><?php esc_html_e( 'Size', 'yeffoprint-migrate' ); ?></th>
							<th><?php esc_html_e( 'Created', 'yeffoprint-migrate' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'yeffoprint-migrate' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $files as $file ) : ?>
							<tr>
								<td><code><?php echo esc_html( $file['name'] ); ?></code></td>
								<td><?php echo esc_html( size_format( $file['size'] ) ); ?></td>
								<td><?php echo esc_html( wp_date( 'Y-m-d H:i', $file['modified'] ) ); ?></td>
								<td>
									<a class="button button-small" href="<?php echo esc_url( $this->action_url( 'yeffoprint_migrate_download', $file['name'] ) ); ?>"><?php esc_html_e( 'Download', 'yeffoprint-migrate' ); ?></a>
									<a class="button button-small" href="<?php echo esc_url( $this->action_url( 'yeffoprint_migrate_delete', $file['name'] ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this file? This cannot be undone.', 'yeffoprint-migrate' ) ); ?>');"><?php esc_html_e( 'Delete', 'yeffoprint-migrate' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private function action_url( string $action, string $filename ): string {
		return wp_nonce_url(
			add_query_arg( [ 'action' => $action, 'file' => rawurlencode( $filename ) ], admin_url( 'admin-post.php' ) ),
			$action . '_' . $filename
		);
	}

	public function handle_upload(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'yeffoprint-migrate' ) );
		}

		$type = sanitize_key( (string) ( $_POST['type'] ?? '' ) );
		check_admin_referer( 'yeffoprint_migrate_upload_' . $type );

		if ( ! in_array( $type, [ 'settings', 'users', 'orders' ], true ) ) {
			wp_die( esc_html__( 'Unknown migration type.', 'yeffoprint-migrate' ) );
		}

		$file = $_FILES['import_file'] ?? null;
		$result = $file ? YeffoPrint_Migrate_File_Store::store_upload( $file, $type ) : new \WP_Error( 'yeffoprint_migrate_no_file', __( 'No file was uploaded.', 'yeffoprint-migrate' ) );

		$redirect = admin_url( 'tools.php?page=yeffoprint-migrate' );
		if ( is_wp_error( $result ) ) {
			$redirect = add_query_arg( 'yeffoprint_migrate_error', rawurlencode( $result->get_error_message() ), $redirect );
		} else {
			$redirect = add_query_arg( [ 'yeffoprint_migrate_uploaded' => rawurlencode( $result ), 'type' => $type ], $redirect );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	public function handle_download(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'yeffoprint-migrate' ) );
		}

		$filename = sanitize_file_name( wp_unslash( (string) ( $_GET['file'] ?? '' ) ) );
		check_admin_referer( 'yeffoprint_migrate_download_' . $filename );

		$path = YeffoPrint_Migrate_File_Store::resolve( $filename );
		if ( is_wp_error( $path ) ) {
			wp_die( esc_html( $path->get_error_message() ) );
		}

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile -- streaming a large export file; reading it into memory first would defeat the point.
		exit;
	}

	public function handle_delete(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'yeffoprint-migrate' ) );
		}

		$filename = sanitize_file_name( wp_unslash( (string) ( $_GET['file'] ?? '' ) ) );
		check_admin_referer( 'yeffoprint_migrate_delete_' . $filename );

		YeffoPrint_Migrate_File_Store::delete( $filename );

		wp_safe_redirect( admin_url( 'tools.php?page=yeffoprint-migrate' ) );
		exit;
	}
}
