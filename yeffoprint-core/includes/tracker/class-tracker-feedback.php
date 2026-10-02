<?php
/**
 * Dose Tracker Help & feedback — direct request (Jeff): customers send a
 * help question, a problem report or an idea from Me > Help & feedback.
 *
 * A note is what the customer typed, up to 3 screenshots, a reply-to
 * email and a short device line (phone, browser, app version). None of
 * their encrypted tracker records come with it unless they turn on
 * "Include my tracker setup": then the app adds a plain list of their
 * medications/schedules and reminder settings (never dose history,
 * notes, vials, stock or injection spots). That list is sealed with the
 * master key (YeffoPrint_Tracker_Crypto::seal) and erased when the note
 * is marked done, as the sheet promises.
 *
 * Screenshots are saved under uploads/yeffoprint-feedback/ with random
 * names and a deny-all .htaccess; the admin page reads them through
 * GET admin/tracker-feedback/{id}/screenshot/{n} and Telegram gets them
 * uploaded (YeffoPrint_Telegram_Client::send_photo_file), never by URL.
 *
 *   POST tracker/feedback                     the app's sheet (class-tracker-controller.php)
 *   admin/tracker-feedback*                   the admin list (class-admin-tracker-feedback-controller.php)
 *   Telegram "✅ Mark done"                    fb_done:{id} (class-telegram-callback-handler.php)
 *
 * "Delete my data" and account deletion remove the customer's notes too
 * (YeffoPrint_Tracker_Store::delete_all).
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Tracker_Feedback {

	public const TYPES = [
		'help'    => 'Help',
		'problem' => 'Problem',
		'idea'    => 'Idea',
	];

	/** Notes one customer can send per day — plenty for real use, stops a script flooding Telegram. */
	public const DAILY_LIMIT = 10;

	public const MAX_SCREENSHOTS = 3;

	private const MAX_SHOT_BYTES    = 4 * MB_IN_BYTES;
	private const SHOT_MIMES        = [ 'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ];
	private const DB_VERSION        = '1.0';
	private const DB_VERSION_OPTION = 'yeffoprint_tracker_feedback_db_version';
	private const DIR               = 'yeffoprint-feedback';

	public function __construct() {
		add_action( 'init', [ __CLASS__, 'maybe_install' ] );
	}

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'yeffoprint_tracker_feedback';
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
			type VARCHAR(10) NOT NULL,
			message TEXT NOT NULL,
			email VARCHAR(190) NOT NULL,
			device VARCHAR(200) NOT NULL DEFAULT '',
			setup TEXT NOT NULL,
			screenshots VARCHAR(255) NOT NULL DEFAULT '',
			status VARCHAR(10) NOT NULL DEFAULT 'new',
			created_at DATETIME NOT NULL,
			done_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY status (status)
		) {$charset_collate};" );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * @param array $input type, message, email, device, setup (string[]), screenshots (data: URLs).
	 * @return int|\WP_Error The new note's id.
	 */
	public static function submit( int $user_id, array $input ) {
		global $wpdb;
		$table = self::table_name();

		$type    = isset( self::TYPES[ $input['type'] ?? '' ] ) ? (string) $input['type'] : 'help';
		$message = trim( mb_substr( sanitize_textarea_field( (string) ( $input['message'] ?? '' ) ), 0, 4000 ) );
		$email   = sanitize_email( (string) ( $input['email'] ?? '' ) );
		$device  = mb_substr( sanitize_text_field( (string) ( $input['device'] ?? '' ) ), 0, 200 );

		if ( '' === $message ) {
			return new \WP_Error( 'yeffoprint_feedback_empty', __( 'Write a message first.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'yeffoprint_feedback_email', __( 'Enter an email address we can reply to.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$recent = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND created_at > %s", $user_id, $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $recent >= self::DAILY_LIMIT ) {
			return new \WP_Error( 'yeffoprint_feedback_limit', __( 'You’ve sent a lot of notes today. Please try again tomorrow, or email us.', 'yeffoprint-core' ), [ 'status' => 429 ] );
		}

		$setup = self::clean_setup( $input['setup'] ?? null );
		$sealed = '';
		if ( $setup ) {
			$sealed = YeffoPrint_Tracker_Crypto::seal( (string) wp_json_encode( $setup ), self::context( $user_id ) );
		}

		$shots = [];
		foreach ( array_slice( (array) ( $input['screenshots'] ?? [] ), 0, self::MAX_SCREENSHOTS ) as $data_url ) {
			$name = self::store_screenshot( (string) $data_url );
			if ( is_wp_error( $name ) ) {
				self::delete_files( $shots );
				return $name;
			}
			$shots[] = $name;
		}

		$ok = $wpdb->insert( $table, [
			'user_id'     => $user_id,
			'type'        => $type,
			'message'     => $message,
			'email'       => $email,
			'device'      => $device,
			'setup'       => $sealed,
			'screenshots' => implode( ',', $shots ),
			'status'      => 'new',
			'created_at'  => current_time( 'mysql', true ),
		], [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ] );

		if ( ! $ok ) {
			self::delete_files( $shots );
			return new \WP_Error( 'yeffoprint_feedback_failed', __( 'Your note couldn’t be sent. Please try again.', 'yeffoprint-core' ), [ 'status' => 500 ] );
		}

		$id = (int) $wpdb->insert_id;
		self::alert_owner( $id );
		return $id;
	}

	/** Up to 40 short plain lines the app built from the customer's own setup. @return string[] */
	private static function clean_setup( $setup ): array {
		if ( ! is_array( $setup ) ) {
			return [];
		}
		$out = [];
		foreach ( array_slice( $setup, 0, 40 ) as $line ) {
			if ( is_string( $line ) && '' !== trim( $line ) ) {
				$out[] = mb_substr( sanitize_text_field( $line ), 0, 200 );
			}
		}
		return $out;
	}

	private static function context( int $user_id ): string {
		return 'tracker-feedback:' . $user_id;
	}

	/** @return string|\WP_Error The saved file's name. */
	private static function store_screenshot( string $data_url ) {
		$bad = new \WP_Error( 'yeffoprint_feedback_upload', __( 'A screenshot couldn’t be read. Try a JPG or PNG image.', 'yeffoprint-core' ), [ 'status' => 400 ] );

		if ( ! preg_match( '#^data:image/(?:jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$#', $data_url, $m ) ) {
			return $bad;
		}
		$bytes = base64_decode( $m[1], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $bytes || '' === $bytes ) {
			return $bad;
		}
		if ( strlen( $bytes ) > self::MAX_SHOT_BYTES ) {
			/* translators: %s: max size, e.g. "4 MB" */
			return new \WP_Error( 'yeffoprint_feedback_upload', sprintf( __( 'Screenshots must be %s or smaller.', 'yeffoprint-core' ), size_format( self::MAX_SHOT_BYTES ) ), [ 'status' => 400 ] );
		}

		// Must really be an image, whatever the data URL says.
		$info = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$ext  = is_array( $info ) ? array_search( $info['mime'] ?? '', self::SHOT_MIMES, true ) : false;
		if ( ! $ext ) {
			return $bad;
		}

		$dir = self::dir();
		if ( '' === $dir ) {
			return new \WP_Error( 'yeffoprint_feedback_upload', __( 'Screenshots can’t be saved right now. Send your note without one.', 'yeffoprint-core' ), [ 'status' => 500 ] );
		}

		$name = bin2hex( random_bytes( 16 ) ) . '.' . $ext;
		if ( false === file_put_contents( $dir . '/' . $name, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return new \WP_Error( 'yeffoprint_feedback_upload', __( 'Screenshots can’t be saved right now. Send your note without one.', 'yeffoprint-core' ), [ 'status' => 500 ] );
		}
		return $name;
	}

	/** The private screenshots folder, created with a deny-all .htaccess and a blank index the first time. '' when uploads aren't writable. */
	private static function dir(): string {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			return '';
		}
		$dir = trailingslashit( $uploads['basedir'] ) . self::DIR;
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return $dir;
	}

	/** Full path of a stored screenshot, or '' if the name isn't one of ours. */
	public static function screenshot_path( string $name ): string {
		if ( ! preg_match( '/^[a-f0-9]{32}\.(jpg|png|webp)$/', $name ) ) {
			return '';
		}
		$dir = self::dir();
		return '' !== $dir && is_file( $dir . '/' . $name ) ? $dir . '/' . $name : '';
	}

	private static function delete_files( array $names ): void {
		foreach ( $names as $name ) {
			$path = self::screenshot_path( (string) $name );
			if ( '' !== $path ) {
				wp_delete_file( $path );
			}
		}
	}

	public static function get_row( int $id ): ?array {
		global $wpdb;
		$table = self::table_name();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? $row : null;
	}

	/** One note shaped for the admin list. */
	public static function format( array $row ): array {
		$user  = get_userdata( (int) $row['user_id'] );
		$name  = $user ? trim( $user->first_name . ' ' . ( $user->last_name ? mb_substr( $user->last_name, 0, 1 ) . '.' : '' ) ) : '';
		$setup = [];
		if ( '' !== (string) $row['setup'] ) {
			$decoded = json_decode( YeffoPrint_Tracker_Crypto::open( (string) $row['setup'], self::context( (int) $row['user_id'] ) ), true );
			$setup   = is_array( $decoded ) ? array_values( array_filter( $decoded, 'is_string' ) ) : [];
		}
		$shots = array_values( array_filter( explode( ',', (string) $row['screenshots'] ) ) );

		return [
			'id'          => (int) $row['id'],
			'type'        => (string) $row['type'],
			'name'        => '' !== $name ? $name : ( $user ? $user->display_name : __( 'Deleted account', 'yeffoprint-core' ) ),
			'email'       => (string) $row['email'],
			'message'     => (string) $row['message'],
			'device'      => (string) $row['device'],
			'setup'       => $setup,
			'screenshots' => count( $shots ),
			'status'      => (string) $row['status'],
			'created'     => mysql2date( 'M j, g:i A', get_date_from_gmt( (string) $row['created_at'] ) ),
		];
	}

	/** @param string $filter new, done, help, problem, idea or all. @return array[] */
	public static function list( string $filter ): array {
		global $wpdb;
		$table = self::table_name();

		if ( in_array( $filter, [ 'new', 'done' ], true ) ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY created_at DESC, id DESC LIMIT 200", $filter ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} elseif ( isset( self::TYPES[ $filter ] ) ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE type = %s ORDER BY created_at DESC, id DESC LIMIT 200", $filter ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT 200", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return array_map( [ __CLASS__, 'format' ], (array) $rows );
	}

	/** @return array{new: int, done: int, avg_hours: float|null} */
	public static function stats(): array {
		global $wpdb;
		$table = self::table_name();
		$new   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'new'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$done  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'done'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$times = $wpdb->get_results( "SELECT created_at, done_at FROM {$table} WHERE status = 'done' AND done_at IS NOT NULL ORDER BY done_at DESC LIMIT 200", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$hours = array_map( static function ( $row ) {
			return max( 0, strtotime( $row['done_at'] . ' UTC' ) - strtotime( $row['created_at'] . ' UTC' ) ) / HOUR_IN_SECONDS;
		}, (array) $times );

		return [
			'new'       => $new,
			'done'      => $done,
			'avg_hours' => $hours ? round( array_sum( $hours ) / count( $hours ), 2 ) : null,
		];
	}

	/** Marks a note done and erases the tracker setup it carried. @return bool false when it doesn't exist or was already done. */
	public static function mark_done( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->update(
			self::table_name(),
			[ 'status' => 'done', 'setup' => '', 'done_at' => current_time( 'mysql', true ) ],
			[ 'id' => $id, 'status' => 'new' ],
			[ '%s', '%s', '%s' ],
			[ '%d', '%s' ]
		);
	}

	public static function reopen( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->update( self::table_name(), [ 'status' => 'new', 'done_at' => null ], [ 'id' => $id ], [ '%s', null ], [ '%d' ] );
	}

	public static function delete( int $id ): bool {
		$row = self::get_row( $id );
		if ( ! $row ) {
			return false;
		}
		self::delete_files( explode( ',', (string) $row['screenshots'] ) );
		global $wpdb;
		return (bool) $wpdb->delete( self::table_name(), [ 'id' => $id ], [ '%d' ] );
	}

	/** "Delete my data" and account deletion. */
	public static function delete_for_user( int $user_id ): void {
		global $wpdb;
		$table = self::table_name();
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE user_id = %d", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $ids as $id ) {
			self::delete( (int) $id );
		}
	}

	public static function admin_url(): string {
		return admin_url( 'admin.php?page=yeffoprint#/tracker-feedback' );
	}

	/** Telegram to the owner's chat, with the first screenshot uploaded alongside when there is one. */
	private static function alert_owner( int $id ): void {
		if ( ! class_exists( 'YeffoPrint_Telegram_Settings' ) ) {
			return;
		}
		$chat_id = (int) get_option( YeffoPrint_Admin_Menu::TELEGRAM_ADMIN_CHAT_ID_OPTION, 0 );
		$token   = YeffoPrint_Telegram_Settings::get_bot_token();
		$row     = self::get_row( $id );
		if ( ! $chat_id || '' === $token || ! YeffoPrint_Telegram_Settings::is_enabled() || ! $row ) {
			return;
		}

		$note  = self::format( $row );
		$icons = [ 'help' => '❓', 'problem' => '🛠', 'idea' => '💡' ];
		$shots = array_values( array_filter( explode( ',', (string) $row['screenshots'] ) ) );

		$lines = [
			( $icons[ $note['type'] ] ?? '💬' ) . ' ' . sprintf(
				/* translators: %s: Help, Problem or Idea */
				__( 'Tracker feedback · %s', 'yeffoprint-core' ),
				self::TYPES[ $note['type'] ] ?? ''
			),
			sprintf( '%1$s · %2$s', $note['name'], $note['email'] ),
			'',
			'“' . mb_strimwidth( $note['message'], 0, 600, '…' ) . '”',
			'',
		];
		if ( '' !== $note['device'] ) {
			$lines[] = $note['device'];
		}
		$lines[] = $note['setup'] ? __( 'Tracker setup included (see admin)', 'yeffoprint-core' ) : __( 'Setup not included', 'yeffoprint-core' );
		if ( count( $shots ) > 1 ) {
			/* translators: %d: number of screenshots */
			$lines[] = '📷 ' . sprintf( __( '%d screenshots (all in admin)', 'yeffoprint-core' ), count( $shots ) );
		}
		$text = implode( "\n", $lines );

		$buttons = [ [ 'text' => __( '✅ Mark done', 'yeffoprint-core' ), 'callback_data' => 'fb_done:' . $id ] ];
		// Telegram rejects the whole message over a non-https URL button.
		if ( 0 === strpos( self::admin_url(), 'https://' ) ) {
			array_unshift( $buttons, [ 'text' => __( 'Open in admin', 'yeffoprint-core' ), 'url' => self::admin_url() ] );
		}
		$keyboard = [ $buttons ];

		$client = new YeffoPrint_Telegram_Client( $token );
		$path   = $shots ? self::screenshot_path( $shots[0] ) : '';
		if ( '' !== $path && $client->send_photo_file( $chat_id, $path, mb_strimwidth( $text, 0, 1000, '…' ), $keyboard ) ) {
			return;
		}
		$client->send_message( $chat_id, $text, $keyboard );
	}
}
