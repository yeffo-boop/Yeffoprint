<?php
/**
 * Email orders. Direct request: "Some customers email me their orders.
 * Is there a way I could forward the email to a special email address
 * and have the site create the order based on the list in the email?"
 *
 * Every five minutes the site checks a mailbox folder over IMAP
 * (Settings › Integrations › Email orders). Follow-up: a second Gmail
 * mailbox costs a license, so the folder can also be a label in Jeff's
 * own mailbox: he tags a customer's email with it (or a Gmail filter
 * does, for mail sent to his free +orders address).
 * Each new email
 * forwarded from an allowed address is kept as a yp_email_order record
 * and read by Claude (class-email-order-reader.php) into the customer,
 * address and label lines. A phone push says it's ready; the admin app's
 * Email Orders screen opens it as a filled-in Create Order form, which
 * Jeff checks and submits like any other order (that's what sends the
 * customer a payment link). Nothing is ever charged or sent to the
 * customer from here.
 *
 * When the folder is the inbox, mail from anyone not on the allowed list
 * is marked read and ignored, so a stranger who learns the address can't
 * fill it. A label only holds what Jeff (or his own filter) put there,
 * so every email in it is read, including a customer's original email.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Email_Orders {

	public const POST_TYPE = 'yp_email_order';

	public const ENABLED_OPTION  = 'yeffoprint_email_orders_enabled';
	public const HOST_OPTION     = 'yeffoprint_email_orders_host';
	public const PORT_OPTION     = 'yeffoprint_email_orders_port';
	public const USER_OPTION     = 'yeffoprint_email_orders_username';
	public const PASSWORD_OPTION = 'yeffoprint_email_orders_password';
	public const SENDERS_OPTION  = 'yeffoprint_email_orders_senders';
	public const FOLDER_OPTION   = 'yeffoprint_email_orders_folder';
	private const STATUS_OPTION  = 'yeffoprint_email_orders_status';
	/** Label mode: { folder, validity, uid } of the newest message already looked at. */
	private const CURSOR_OPTION  = 'yeffoprint_email_orders_cursor';

	private const HOOK     = 'yeffoprint_email_orders_check';
	private const SCHEDULE = 'yeffoprint_five_minutes';
	private const LOCK     = 'yeffoprint_email_orders_lock';

	/** Emails downloaded per check, and how many of them Claude reads per check. */
	private const FETCH_PER_RUN = 10;

	/** How many label UIDs are grouped into conversations per run. */
	private const THREAD_LOOKAHEAD = 200;
	private const READ_PER_RUN  = 3;

	private const META_STATE      = '_yp_eo_state';
	private const META_FROM       = '_yp_eo_forwarded_by';
	private const META_FROM_NAME  = '_yp_eo_from_name';
	private const META_MESSAGE_ID = '_yp_eo_message_id';
	private const META_RESULT     = '_yp_eo_result';
	private const META_ERROR      = '_yp_eo_error';
	private const META_ORDER_ID   = '_yp_eo_order_id';
	private const META_EMAIL_DATE = '_yp_eo_email_date';
	private const META_EARLIER    = '_yp_eo_earlier';

	/** queued: waiting to be read. ready: read, waiting on Jeff. failed: couldn't be read. created / dismissed: done. */
	public const STATES = [ 'queued', 'ready', 'failed', 'created', 'dismissed' ];

	public function __construct() {
		add_action( 'init', [ $this, 'register_post_type' ] );
		add_filter( 'cron_schedules', [ YeffoPrint_Telegram_Express_Alerts::class, 'add_schedule' ] ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		add_action( self::HOOK, [ __CLASS__, 'check' ] );
		add_action( 'init', [ $this, 'ensure_scheduled' ] );
	}

	public function register_post_type(): void {
		register_post_type( self::POST_TYPE, [
			'label'           => __( 'Email orders', 'yeffoprint-core' ),
			'public'          => false,
			'show_ui'         => false,
			'show_in_rest'    => false,
			'rewrite'         => false,
			'query_var'       => false,
			'supports'        => [ 'title', 'editor' ],
			'capability_type' => 'post',
			'map_meta_cap'    => true,
		] );
	}

	public function ensure_scheduled(): void {
		$scheduled = wp_next_scheduled( self::HOOK );
		if ( self::is_enabled() && ! $scheduled ) {
			wp_schedule_event( time() + 60, self::SCHEDULE, self::HOOK );
		} elseif ( ! self::is_enabled() && $scheduled ) {
			wp_clear_scheduled_hook( self::HOOK );
		}
	}

	/* ---------- Settings ---------- */

	public static function is_enabled(): bool {
		return (bool) get_option( self::ENABLED_OPTION, false );
	}

	/** Lowercase addresses allowed to forward orders; the store's own addresses when none are saved. */
	public static function allowed_senders(): array {
		$saved = (string) get_option( self::SENDERS_OPTION, '' );
		$list  = '' !== trim( $saved ) ? preg_split( '/[\s,;]+/', $saved ) : self::default_senders();
		return array_values( array_unique( array_filter( array_map( static function ( $email ) {
			return strtolower( sanitize_email( (string) $email ) );
		}, $list ) ) ) );
	}

	public static function default_senders(): array {
		return array_values( array_unique( array_filter( [
			strtolower( (string) get_option( 'admin_email' ) ),
			strtolower( (string) get_option( 'woocommerce_email_from_address' ) ),
		] ) ) );
	}

	public static function settings(): array {
		$status = get_option( self::STATUS_OPTION, [] );
		return [
			'email_orders_enabled'       => self::is_enabled(),
			'email_orders_host'          => (string) get_option( self::HOST_OPTION, '' ),
			'email_orders_port'          => (int) get_option( self::PORT_OPTION, 993 ),
			'email_orders_username'      => (string) get_option( self::USER_OPTION, '' ),
			'email_orders_folder'        => self::folder(),
			// Secrets are never sent back to the browser, only whether one is saved.
			'email_orders_password_set'  => '' !== (string) get_option( self::PASSWORD_OPTION, '' ),
			'email_orders_senders'       => (string) get_option( self::SENDERS_OPTION, '' ),
			'email_orders_default_senders' => implode( ', ', self::default_senders() ),
			'claude_api_key_set'         => '' !== YeffoPrint_Email_Order_Reader::api_key(),
			'claude_api_key_in_config'   => defined( 'YEFFOPRINT_CLAUDE_API_KEY' ) && YEFFOPRINT_CLAUDE_API_KEY,
			'email_orders_status'        => is_array( $status ) ? (string) ( $status['message'] ?? '' ) : '',
			'email_orders_checked_at'    => is_array( $status ) && ! empty( $status['time'] ) ? gmdate( 'c', (int) $status['time'] ) : null,
		];
	}

	/** Each key only when sent, so an older copy of the app can't blank them. Blank secrets keep the saved one. */
	public static function save_settings( array $params ): void {
		if ( array_key_exists( 'email_orders_enabled', $params ) ) {
			update_option( self::ENABLED_OPTION, ! empty( $params['email_orders_enabled'] ) );
		}
		if ( array_key_exists( 'email_orders_host', $params ) ) {
			update_option( self::HOST_OPTION, preg_replace( '/[^a-z0-9.\-]/i', '', (string) $params['email_orders_host'] ) );
		}
		if ( array_key_exists( 'email_orders_port', $params ) ) {
			$port = (int) $params['email_orders_port'];
			update_option( self::PORT_OPTION, $port > 0 && $port < 65536 ? $port : 993 );
		}
		if ( array_key_exists( 'email_orders_folder', $params ) ) {
			update_option( self::FOLDER_OPTION, trim( str_replace( [ '"', '\\', "\r", "\n" ], '', sanitize_text_field( (string) $params['email_orders_folder'] ) ) ) );
		}
		if ( array_key_exists( 'email_orders_username', $params ) ) {
			update_option( self::USER_OPTION, sanitize_text_field( (string) $params['email_orders_username'] ) );
		}
		if ( ! empty( $params['email_orders_password'] ) ) {
			update_option( self::PASSWORD_OPTION, YeffoPrint_Secret_Box::encrypt( (string) $params['email_orders_password'] ), false );
		}
		if ( array_key_exists( 'email_orders_senders', $params ) ) {
			update_option( self::SENDERS_OPTION, sanitize_textarea_field( (string) $params['email_orders_senders'] ) );
		}
		if ( ! empty( $params['claude_api_key'] ) ) {
			update_option( YeffoPrint_Email_Order_Reader::KEY_OPTION, YeffoPrint_Secret_Box::encrypt( sanitize_text_field( (string) $params['claude_api_key'] ) ), false );
		}
	}

	/** The IMAP folder to read: INBOX, or a Gmail label. */
	public static function folder(): string {
		$folder = trim( (string) get_option( self::FOLDER_OPTION, '' ) );
		return '' !== $folder ? $folder : 'INBOX';
	}

	private static function reads_inbox(): bool {
		return 'INBOX' === strtoupper( self::folder() );
	}

	private static function set_status( string $message ): void {
		update_option( self::STATUS_OPTION, [ 'message' => $message, 'time' => time() ], false );
	}

	/* ---------- Checking the mailbox ---------- */

	/**
	 * Cron job, and the "Check now" buttons. Downloads new mail, then reads
	 * a few queued emails with Claude.
	 *
	 * @return array{ok:bool, message:string, added:int}
	 */
	public static function check(): array {
		if ( ! self::is_enabled() ) {
			return [ 'ok' => false, 'message' => 'Email orders are turned off.', 'added' => 0 ];
		}

		if ( get_transient( self::LOCK ) ) {
			return [ 'ok' => true, 'message' => 'Already checking the mailbox. Try again in a minute.', 'added' => 0 ];
		}
		set_transient( self::LOCK, 1, 5 * MINUTE_IN_SECONDS );

		try {
			$fetch = self::fetch_new_mail();
			$read  = self::read_queued( self::READ_PER_RUN );

			$parts = [ 0 === $fetch['added'] ? 'No new order emails.' : sprintf( _n( '%d new order email.', '%d new order emails.', $fetch['added'], 'yeffoprint-core' ), $fetch['added'] ) ];
			if ( $fetch['skipped'] ) {
				$parts[] = sprintf( _n( 'Ignored %d email from an address not on the allowed list.', 'Ignored %d emails from addresses not on the allowed list.', $fetch['skipped'], 'yeffoprint-core' ), $fetch['skipped'] );
			}
			if ( $read['failed'] ) {
				$parts[] = sprintf( _n( '%d couldn’t be read.', '%d couldn’t be read.', $read['failed'], 'yeffoprint-core' ), $read['failed'] );
			}
			$message = implode( ' ', $parts );
			self::set_status( $message );
			return [ 'ok' => true, 'message' => $message, 'added' => $fetch['added'] ];
		} catch ( \RuntimeException $e ) {
			self::set_status( $e->getMessage() );
			return [ 'ok' => false, 'message' => $e->getMessage(), 'added' => 0 ];
		} finally {
			delete_transient( self::LOCK );
		}
	}

	/** @return array{added:int, skipped:int} @throws \RuntimeException */
	private static function fetch_new_mail(): array {
		$host     = (string) get_option( self::HOST_OPTION, '' );
		$username = (string) get_option( self::USER_OPTION, '' );
		$password = YeffoPrint_Secret_Box::decrypt( (string) get_option( self::PASSWORD_OPTION, '' ) );

		if ( '' === $host || '' === $username || '' === $password ) {
			throw new \RuntimeException( 'Fill in the mailbox server, username and password in Settings › Integrations › Email orders.' );
		}

		$allowed = self::allowed_senders();
		$added   = 0;
		$skipped = 0;
		$imap    = new YeffoPrint_Imap_Client();

		try {
			$imap->connect( $host, (int) get_option( self::PORT_OPTION, 993 ), $username, $password, self::folder() );

			$inbox = self::reads_inbox();

			if ( $inbox ) {
				$uids = $imap->unread_uids();
			} else {
				// A label's emails are usually already read (Jeff opened them to tag them),
				// so go by position instead, and never change his mail.
				$cursor = get_option( self::CURSOR_OPTION, [] );
				$same   = is_array( $cursor ) && ( $cursor['folder'] ?? '' ) === self::folder() && (int) ( $cursor['validity'] ?? -1 ) === $imap->uid_validity();
				$uids   = $imap->uids_after( $same ? (int) $cursor['uid'] : 0 );
			}

			// A Gmail label holds every message of a tagged conversation. Only the
			// newest one is the order (the rest is history, quoted inside it anyway).
			$threads = $inbox ? [] : $imap->thread_ids( array_slice( $uids, 0, self::THREAD_LOOKAHEAD ) );
			$newest  = [];
			foreach ( $threads as $uid => $thread ) {
				$newest[ $thread ] = max( $uid, $newest[ $thread ] ?? 0 );
			}

			$fetched = 0;
			foreach ( array_slice( $uids, 0, self::THREAD_LOOKAHEAD ) as $uid ) {
				$thread = $threads[ $uid ] ?? '';
				if ( '' !== $thread && $newest[ $thread ] !== $uid ) {
					update_option( self::CURSOR_OPTION, [ 'folder' => self::folder(), 'validity' => $imap->uid_validity(), 'uid' => $uid ], false );
					continue;
				}
				if ( ++$fetched > self::FETCH_PER_RUN ) {
					break;
				}

				$email = YeffoPrint_Mime_Message::parse( $imap->fetch( $uid ) );

				if ( $inbox && ! in_array( $email['from_email'], $allowed, true ) ) {
					$skipped++;
				} elseif ( self::store( $email ) ) {
					$added++;
				}

				if ( $inbox ) {
					// Read either way: it's in the dashboard now (or ignored), and must not be picked up again.
					$imap->mark_read( $uid );
				} else {
					update_option( self::CURSOR_OPTION, [ 'folder' => self::folder(), 'validity' => $imap->uid_validity(), 'uid' => $uid ], false );
				}
			}
		} finally {
			$imap->close();
		}

		return [ 'added' => $added, 'skipped' => $skipped ];
	}

	/** @return int the new record, or 0 when this email was already stored. */
	private static function store( array $email ): int {
		if ( '' !== $email['message_id'] && self::find_by_message_id( $email['message_id'] ) ) {
			return 0;
		}

		$id = wp_insert_post( [
			'post_type'    => self::POST_TYPE,
			'post_status'  => 'private',
			'post_title'   => mb_substr( '' !== $email['subject'] ? $email['subject'] : '(no subject)', 0, 190 ),
			'post_content' => $email['text'],
		], true );

		if ( is_wp_error( $id ) ) {
			return 0;
		}

		update_post_meta( $id, self::META_STATE, 'queued' );
		update_post_meta( $id, self::META_FROM, $email['from_email'] );
		update_post_meta( $id, self::META_FROM_NAME, $email['from_name'] );
		update_post_meta( $id, self::META_MESSAGE_ID, $email['message_id'] );
		update_post_meta( $id, self::META_EMAIL_DATE, $email['date'] );
		if ( '' !== $email['earlier'] ) {
			update_post_meta( $id, self::META_EARLIER, wp_slash( $email['earlier'] ) );
		}
		return (int) $id;
	}

	private static function find_by_message_id( string $message_id ): int {
		$ids = get_posts( [
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => self::META_MESSAGE_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => $message_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		] );
		return $ids ? (int) $ids[0] : 0;
	}

	/** @return array{read:int, failed:int} */
	private static function read_queued( int $limit ): array {
		$ids = get_posts( [
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'private',
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'ASC',
			'meta_query'     => [ [ 'key' => self::META_STATE, 'value' => 'queued' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		] );

		$counts = [ 'read' => 0, 'failed' => 0 ];
		foreach ( $ids as $id ) {
			$counts[ self::read( (int) $id ) ? 'read' : 'failed' ]++;
		}
		return $counts;
	}

	/** Reads one stored email with Claude and alerts Jeff either way. */
	public static function read( int $id ): bool {
		$post = get_post( $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return false;
		}

		$text    = $post->post_content;
		$earlier = (string) get_post_meta( $id, self::META_EARLIER, true );
		if ( '' === $earlier ) {
			// Emails stored before quoted history was split off.
			[ $text, $earlier ] = YeffoPrint_Mime_Message::split_history( $text );
		}

		try {
			$result = YeffoPrint_Email_Order_Reader::read( [
				'from_email' => (string) get_post_meta( $id, self::META_FROM, true ),
				'from_name'  => (string) get_post_meta( $id, self::META_FROM_NAME, true ),
				'subject'    => $post->post_title,
				'date'       => (string) get_post_meta( $id, self::META_EMAIL_DATE, true ),
				'text'       => $text,
				'earlier'    => $earlier,
			] );
		} catch ( \RuntimeException $e ) {
			update_post_meta( $id, self::META_STATE, 'failed' );
			update_post_meta( $id, self::META_ERROR, $e->getMessage() );
			YeffoPrint_Telegram_Admin_Alerts::notify(
				"Couldn’t read an emailed order\n" . $post->post_title . "\n" . $e->getMessage(),
				[ 'section' => 'email-orders' ]
			);
			return false;
		}

		update_post_meta( $id, self::META_RESULT, wp_slash( wp_json_encode( $result ) ) );
		update_post_meta( $id, self::META_STATE, 'ready' );
		delete_post_meta( $id, self::META_ERROR );

		$labels  = count( array_filter( $result['items'], static function ( $item ) { return 'unclear' !== $item['kind']; } ) );
		$flagged = count( array_filter( $result['items'], static function ( $item ) { return $item['flags'] || 'unclear' === $item['kind']; } ) );

		YeffoPrint_Telegram_Admin_Alerts::notify(
			implode( "\n", array_filter( [
				'Emailed order ready to check',
				$result['customer']['name'] ?: ( $result['customer']['email'] ?: $post->post_title ),
				sprintf( _n( '%d label', '%d labels', $labels, 'yeffoprint-core' ), $labels ) . ( $flagged ? sprintf( ' · %d to check', $flagged ) : '' ),
			] ) ),
			[ 'section' => 'email-orders' ]
		);
		return true;
	}

	/* ---------- Records ---------- */

	/** @param string $filter open (queued, ready, failed) | done (created, dismissed) | all */
	public static function list( string $filter, int $page = 1 ): array {
		$args = [
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'private',
			'posts_per_page' => 30,
			'paged'          => max( 1, $page ),
			'orderby'        => 'date',
			'order'          => 'DESC',
		];

		if ( 'open' === $filter || 'done' === $filter ) {
			$args['meta_query'] = [ [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'key'     => self::META_STATE,
				'value'   => 'open' === $filter ? [ 'queued', 'ready', 'failed' ] : [ 'created', 'dismissed' ],
				'compare' => 'IN',
			] ];
		}

		$query = new \WP_Query( $args );

		return [
			'email_orders'  => array_map( [ self::class, 'format' ], $query->posts ),
			'open_count'    => self::count_open(),
			'max_num_pages' => (int) $query->max_num_pages,
			'page'          => max( 1, $page ),
			'enabled'       => self::is_enabled(),
		];
	}

	public static function count_open(): int {
		$query = new \WP_Query( [
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'private',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [ [ 'key' => self::META_STATE, 'value' => [ 'queued', 'ready', 'failed' ], 'compare' => 'IN' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		] );
		return (int) $query->found_posts;
	}

	public static function exists( int $id ): bool {
		$post = get_post( $id );
		return $post instanceof \WP_Post && self::POST_TYPE === $post->post_type;
	}

	public static function format( \WP_Post $post ): array {
		$state  = (string) get_post_meta( $post->ID, self::META_STATE, true );
		$result = json_decode( (string) get_post_meta( $post->ID, self::META_RESULT, true ), true );
		$result = is_array( $result ) ? $result : null;

		$order_id = (int) get_post_meta( $post->ID, self::META_ORDER_ID, true );
		$order    = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

		$customer = $result['customer'] ?? [];
		$user     = ! empty( $customer['email'] ) ? get_user_by( 'email', $customer['email'] ) : false;

		return [
			'id'            => $post->ID,
			'subject'       => $post->post_title,
			'forwarded_by'  => (string) get_post_meta( $post->ID, self::META_FROM, true ),
			'date'          => get_post_datetime( $post ) ? get_post_datetime( $post )->format( 'c' ) : null,
			'state'         => in_array( $state, self::STATES, true ) ? $state : 'queued',
			'error'         => (string) get_post_meta( $post->ID, self::META_ERROR, true ),
			'text'          => (string) $post->post_content,
			'earlier'       => (string) get_post_meta( $post->ID, self::META_EARLIER, true ),
			'result'        => $result,
			// An existing account with the customer's email, so the form can pick it instead of making a new one.
			'customer_user' => $user ? [ 'id' => $user->ID, 'display_name' => $user->display_name, 'email' => $user->user_email ] : null,
			'order_id'      => $order ? $order->get_id() : 0,
			'order_number'  => $order ? $order->get_order_number() : '',
		];
	}

	public static function set_state( int $id, string $state ): void {
		update_post_meta( $id, self::META_STATE, in_array( $state, self::STATES, true ) ? $state : 'ready' );
	}

	public static function mark_created( int $id, int $order_id ): void {
		update_post_meta( $id, self::META_ORDER_ID, $order_id );
		self::set_state( $id, 'created' );
	}

	/** Dismisses every open record. @return int how many. */
	public static function dismiss_open(): int {
		$ids = get_posts( [
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'private',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => [ [ 'key' => self::META_STATE, 'value' => [ 'queued', 'ready', 'failed' ], 'compare' => 'IN' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		] );
		foreach ( $ids as $id ) {
			self::set_state( (int) $id, 'dismissed' );
		}
		return count( $ids );
	}

	public static function requeue( int $id ): void {
		self::set_state( $id, 'queued' );
		delete_post_meta( $id, self::META_ERROR );
	}

	public static function delete( int $id ): void {
		wp_delete_post( $id, true );
	}
}
