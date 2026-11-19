<?php
/**
 * Orders from the owner's own Telegram chats. Direct request: "My
 * telegram bot has access to my personal telegram chats. Is there a way
 * a customer I'm chatting with can send over a list of labels needed and
 * the bot can create the order on the site? It would need to ask the
 * email of the customer if they don't have the telegram bot tied to
 * their account... ask all the questions if things like shipping
 * address, etc are not in their account. Then it can provide the
 * payment link at the end." Jeff then chose, on a decision card, that
 * the bot sends the pay link itself once the customer confirms, with no
 * approval step from him.
 *
 * How the bot sees those chats: Telegram Business. Jeff adds the bot
 * under Telegram › Settings › Telegram Business › Chatbots (needs
 * Premium, and Business Mode turned on for the bot in BotFather).
 * Telegram then sends the webhook a `business_connection` update, and a
 * `business_message` for every message in the chats he picked, his own
 * included. The bot can reply in those chats as him (sendMessage with
 * business_connection_id).
 *
 * The bot never speaks in a chat on its own. Everything starts when Jeff
 * types /order in a customer's chat:
 *   1. Claude reads the recent chat (YeffoPrint_Email_Order_Reader, the
 *      same reader and catalog checks as Email Orders).
 *   2. The bot asks, in one message, only for what's missing: an email
 *      when their Telegram isn't linked to an account, a shipping
 *      address when neither the chat nor their account has one, the
 *      brand, and any size, material or quantity it couldn't tell. Each
 *      answer re-reads the chat (a few seconds after their last message,
 *      so an address typed over three messages is read once).
 *   3. With nothing missing it sends a summary and asks them to reply
 *      YES. YES creates the order (YeffoPrint_Manual_Order_Creator, the
 *      same as Create Order) and sends the pay link, where they can still
 *      edit the address and pick shipping. Anything else is read as a
 *      change and goes back to step 1.
 *   4. Jeff gets a push alert for each order. When something can't be
 *      done automatically (a second template design with a different
 *      size, a sticker, the bot ran out of questions, sales are paused),
 *      the draft waits in Orders › Email Orders instead and the customer
 *      is told Jeff will send the link. Pressing Create Order on that
 *      draft sends the pay link into the chat.
 *
 * Jeff types /cancel in the chat to stop the bot there.
 *
 * Only text is kept, only for chats where /order is possible (the
 * feature switched on), for three days, so the reader has the list the
 * customer sent before Jeff typed /order.
 *
 * Follow-up requests: "use buttons or selectors for the vinyl type and
 * sizes so they don't enter something in wrong", and "can this be setup to
 * work when they message the bot directly?"
 *   - Size and material are asked with tap buttons (inline keyboards,
 *     callback data `tgco:s:ID` / `tgco:m:ID`), and the summary ends with
 *     "Looks good" / "Change something" buttons. A tapped choice is kept
 *     in the session ('picks') and fills every label still missing it,
 *     even after later re-reads. Typing sizes per label still works.
 *   - In the bot's own chat a customer starts with /neworder (or "new
 *     order"), then sends their list. Same questions, same summary, same
 *     order and pay link. While that order is open, their messages go here
 *     instead of to the FAQ bot.
 *
 * "Session id" ($chat_id below): the Telegram chat id for one of Jeff's
 * business chats, or the NEGATIVE chat id for the bot's own chat with that
 * customer, so the two never share a session or log (both chats have the
 * customer's user id as their id).
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Telegram_Chat_Orders {

	public const ENABLED_OPTION     = 'yeffoprint_telegram_chat_orders_enabled';
	private const CONNECTIONS_OPTION = 'yeffoprint_telegram_business_connections';

	private const LOG_PREFIX     = 'yp_tgco_log_';
	private const SESSION_PREFIX = 'yp_tgco_s_';
	private const LOCK_PREFIX    = 'yp_tgco_lock_';
	private const LOG_TTL        = 3 * DAY_IN_SECONDS;
	private const LOG_MAX        = 80;

	/** Seconds after the customer's last message before the chat is read again. */
	private const DEBOUNCE = 8;

	/** Rounds of questions before the bot hands the draft to Jeff instead. */
	private const MAX_ROUNDS = 4;

	private const CRON_HOOK = 'yeffoprint_telegram_chat_order_process';

	public const META_PAY_LINK_SENT = '_yp_eo_tg_pay_link_sent';

	public function __construct() {
		add_action( self::CRON_HOOK, [ self::class, 'process' ], 10, 1 );
		add_action( 'yeffoprint_email_order_created', [ self::class, 'on_draft_created' ], 10, 2 );
	}

	public static function is_enabled(): bool {
		return (bool) get_option( self::ENABLED_OPTION, false );
	}

	/* ---------- Settings (Settings › Integrations) ---------- */

	public static function settings(): array {
		$connections = (array) get_option( self::CONNECTIONS_OPTION, [] );
		$connection  = $connections ? end( $connections ) : null;
		return [
			'telegram_chat_orders_enabled' => self::is_enabled(),
			'telegram_business_connection' => is_array( $connection ) ? [
				'enabled'   => ! empty( $connection['enabled'] ),
				'can_reply' => ! empty( $connection['can_reply'] ),
				'name'      => (string) ( $connection['name'] ?? '' ),
				'since'     => ! empty( $connection['date'] ) ? gmdate( 'c', (int) $connection['date'] ) : null,
			] : null,
		];
	}

	public static function save_settings( array $params ): void {
		if ( array_key_exists( 'telegram_chat_orders_enabled', $params ) ) {
			update_option( self::ENABLED_OPTION, ! empty( $params['telegram_chat_orders_enabled'] ) );
		}
	}

	/* ---------- Webhook ---------- */

	/** A `business_connection` update: Jeff added, changed or removed the bot under Telegram Business › Chatbots. */
	public static function handle_connection( array $connection ): void {
		$id = (string) ( $connection['id'] ?? '' );
		if ( '' === $id ) {
			return;
		}
		$connections = (array) get_option( self::CONNECTIONS_OPTION, [] );
		$user        = is_array( $connection['user'] ?? null ) ? $connection['user'] : [];
		// Bot API 9 moved can_reply into rights; older updates still carry it at the top.
		$can_reply = isset( $connection['rights'] ) ? ! empty( $connection['rights']['can_reply'] ) : ! empty( $connection['can_reply'] );

		$connections[ $id ] = [
			'user_id'   => (int) ( $user['id'] ?? 0 ),
			'name'      => trim( (string) ( $user['first_name'] ?? '' ) . ' ' . (string) ( $user['last_name'] ?? '' ) ),
			'can_reply' => $can_reply,
			'enabled'   => ! empty( $connection['is_enabled'] ),
			'date'      => (int) ( $connection['date'] ?? time() ),
		];
		update_option( self::CONNECTIONS_OPTION, $connections, false );
	}

	/** The owner's Telegram user id for a connection, fetching it once if it was made before this code was deployed. */
	private static function connection( string $id ): ?array {
		$connections = (array) get_option( self::CONNECTIONS_OPTION, [] );
		if ( ! isset( $connections[ $id ] ) ) {
			$response = self::client()->get_business_connection( $id );
			if ( is_wp_error( $response ) || ! is_array( $response['result'] ?? null ) ) {
				return null;
			}
			self::handle_connection( $response['result'] );
			$connections = (array) get_option( self::CONNECTIONS_OPTION, [] );
		}
		return $connections[ $id ] ?? null;
	}

	/** A `business_message` (or its edit) in one of Jeff's chats. */
	public static function handle_message( array $message, bool $edited = false ): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		$connection_id = (string) ( $message['business_connection_id'] ?? '' );
		$chat_id       = (int) ( $message['chat']['id'] ?? 0 );
		// Private chats only: a business chat's id is the customer's own user id.
		if ( '' === $connection_id || ! $chat_id || 'private' !== ( $message['chat']['type'] ?? 'private' ) ) {
			return;
		}

		// The bot's own replies come back as updates too. They're logged when sent.
		if ( ! empty( $message['sender_business_bot'] ) ) {
			return;
		}

		$connection = self::connection( $connection_id );
		if ( ! $connection || empty( $connection['enabled'] ) ) {
			return;
		}

		$from     = is_array( $message['from'] ?? null ) ? $message['from'] : [];
		$is_owner = (int) ( $from['id'] ?? 0 ) === (int) $connection['user_id'];
		$text     = trim( (string) ( $message['text'] ?? $message['caption'] ?? '' ) );
		if ( '' === $text ) {
			$text = isset( $message['photo'] ) ? '[photo]' : ( isset( $message['document'] ) ? '[file]' : '' );
		} elseif ( isset( $message['photo'] ) || isset( $message['document'] ) ) {
			$text = '[' . ( isset( $message['photo'] ) ? 'photo' : 'file' ) . '] ' . $text;
		}
		if ( '' === $text ) {
			return;
		}

		$command = $is_owner ? strtolower( strtok( $text, " \n@" ) ?: '' ) : '';

		if ( '/order' === $command ) {
			self::start( $chat_id, $connection_id, (int) ( $message['message_id'] ?? 0 ) );
			return;
		}
		if ( '/cancel' === $command ) {
			delete_transient( self::SESSION_PREFIX . $chat_id );
			self::delete_command( $connection_id, $chat_id, (int) ( $message['message_id'] ?? 0 ) );
			return;
		}

		self::log( $chat_id, [
			'id'   => (int) ( $message['message_id'] ?? 0 ),
			'who'  => $is_owner ? 'owner' : 'customer',
			'name' => $is_owner ? '' : trim( (string) ( $from['first_name'] ?? '' ) . ' ' . (string) ( $from['last_name'] ?? '' ) ),
			'user' => $is_owner ? '' : (string) ( $from['username'] ?? '' ),
			'time' => (int) ( $message['edit_date'] ?? $message['date'] ?? time() ),
			'text' => mb_substr( $text, 0, 4000 ),
		], $edited );

		$session = self::session( $chat_id );
		if ( ! $session || $is_owner ) {
			return; // Only the customer's answers move an open order along.
		}
		self::customer_said( $chat_id, $session, $text );
	}

	/** The customer answered (in either kind of chat): YES confirms, anything else is read a few seconds later. */
	private static function customer_said( int $chat_id, array $session, string $text ): void {
		if ( 'confirming' === $session['stage'] && self::is_yes( $text ) ) {
			$session['stage'] = 'creating';
			self::save_session( $chat_id, $session );
			self::run_soon( $chat_id, 0 );
			return;
		}

		if ( in_array( $session['stage'], [ 'listing', 'collecting', 'confirming' ], true ) ) {
			$session['stage'] = 'collecting';
			self::save_session( $chat_id, $session );
			self::run_soon( $chat_id, self::DEBOUNCE );
		}
	}

	/* ---------- The bot's own chat ---------- */

	/**
	 * A plain message to the bot (class-telegram-webhook-controller.php asks
	 * here before the FAQ bot). Starts an order on /neworder, and takes the
	 * customer's messages while one is open.
	 *
	 * @return bool true when handled here, so the FAQ bot stays quiet.
	 */
	public static function handle_direct( array $message ): bool {
		$chat = (int) ( $message['chat']['id'] ?? 0 );
		if ( ! self::is_enabled() || $chat <= 0 || 'private' !== ( $message['chat']['type'] ?? 'private' ) ) {
			return false;
		}
		$text = trim( (string) ( $message['text'] ?? $message['caption'] ?? '' ) );
		if ( '' === $text ) {
			return false;
		}

		$sid     = -$chat;
		$command = strtolower( strtok( $text, " \n@" ) ?: '' );
		$starts  = '/neworder' === $command
			|| ( '/start' === $command && 'neworder' === strtolower( trim( substr( $text, 6 ) ) ) )
			|| (bool) preg_match( '/^(new order|order labels|place an order|start an order|i want to order)\b/i', $text );

		if ( $starts ) {
			if ( '' === YeffoPrint_Email_Order_Reader::api_key() ) {
				return false;
			}
			$old = self::session( $sid );
			delete_transient( self::LOG_PREFIX . $sid );
			self::save_session( $sid, [
				'stage'         => 'listing',
				'connection_id' => '',
				'rounds'        => 0,
				'record_id'     => $old['record_id'] ?? 0,
				'started'       => time(),
				'token'         => '',
				'picks'         => [],
			] );
			self::send( $sid, __( "Great, let's start your label order!\n\nSend me your list: what each label says (like “BPC-157 10mg”) and how many of each. One message or a few is fine.", 'yeffoprint-core' ) );
			return true;
		}

		$session = self::session( $sid );
		if ( ! $session || ! in_array( $session['stage'], [ 'listing', 'collecting', 'confirming', 'creating' ], true ) ) {
			return false;
		}
		if ( '/cancel' === $command ) {
			delete_transient( self::SESSION_PREFIX . $sid );
			self::send( $sid, __( 'Okay, I cancelled that order. Send /neworder any time to start again.', 'yeffoprint-core' ) );
			return true;
		}
		if ( '/' === $text[0] ) {
			return false; // Other commands (/help, /orders…) still work mid-order.
		}

		$from = is_array( $message['from'] ?? null ) ? $message['from'] : [];
		self::log( $sid, [
			'id'   => (int) ( $message['message_id'] ?? 0 ),
			'who'  => 'customer',
			'name' => trim( (string) ( $from['first_name'] ?? '' ) . ' ' . (string) ( $from['last_name'] ?? '' ) ),
			'user' => (string) ( $from['username'] ?? '' ),
			'time' => (int) ( $message['date'] ?? time() ),
			'text' => mb_substr( $text, 0, 4000 ),
		] );
		self::customer_said( $sid, $session, $text );
		return true;
	}

	/* ---------- Buttons ---------- */

	/** A tapped size, material, "Looks good" or "Change something" button (callback data tgco:…). */
	public static function handle_callback( array $callback_query ): void {
		$client   = self::client();
		$query_id = (string) ( $callback_query['id'] ?? '' );
		$message  = is_array( $callback_query['message'] ?? null ) ? $callback_query['message'] : [];
		$chat     = (int) ( $message['chat']['id'] ?? 0 );
		$business = (string) ( $message['business_connection_id'] ?? '' );
		$sid      = '' !== $business ? $chat : -$chat;
		$session  = $chat ? self::session( $sid ) : null;
		$parts    = explode( ':', (string) ( $callback_query['data'] ?? '' ) );
		$from_id  = (int) ( $callback_query['from']['id'] ?? 0 );

		$connection = '' !== $business ? self::connection( $business ) : null;
		$allowed    = $from_id === $chat || ( $connection && $from_id === (int) $connection['user_id'] );

		if ( ! $session || ! $allowed || ! in_array( $session['stage'], [ 'collecting', 'confirming' ], true ) ) {
			$client->answer_callback_query( $query_id, __( 'This order was already sent or cancelled.', 'yeffoprint-core' ) );
			return;
		}

		$catalog = YeffoPrint_Email_Order_Reader::catalog();
		$action  = $parts[1] ?? '';
		$id      = absint( $parts[2] ?? 0 );

		if ( 's' === $action || 'm' === $action ) {
			$names = 's' === $action ? array_column( $catalog['sizes'], 'name', 'id' ) : array_column( $catalog['materials'], 'name', 'id' );
			if ( ! isset( $names[ $id ] ) || 'collecting' !== $session['stage'] ) {
				$client->answer_callback_query( $query_id );
				return;
			}
			$session['picks'][ 's' === $action ? 'size' : 'material' ] = $id;
			unset( $session[ 's' === $action ? 'asked_size' : 'asked_material' ] );
			$client->answer_callback_query( $query_id, $names[ $id ] );
			$client->edit_message_text( $chat, (int) ( $message['message_id'] ?? 0 ), ( 's' === $action ? __( 'Size', 'yeffoprint-core' ) : __( 'Material', 'yeffoprint-core' ) ) . ': ' . $names[ $id ] . ' ✓', $business );
			self::log( $sid, [ 'id' => 0, 'who' => 'customer', 'name' => '', 'user' => '', 'time' => time(), 'text' => '(tapped ' . ( 's' === $action ? 'size' : 'material' ) . ': ' . $names[ $id ] . ')' ] );
			self::save_session( $sid, $session );
			self::advance( $sid, $session, false );
			return;
		}

		if ( 'ok' === $action && 'confirming' === $session['stage'] ) {
			$client->answer_callback_query( $query_id, __( 'Creating your order…', 'yeffoprint-core' ) );
			$client->edit_message_text( $chat, (int) ( $message['message_id'] ?? 0 ), (string) ( $message['text'] ?? '' ) . "\n\n✅ " . __( 'Confirmed', 'yeffoprint-core' ), $business );
			$session['stage'] = 'creating';
			self::save_session( $sid, $session );
			self::run_soon( $sid, 0 );
			return;
		}

		if ( 'edit' === $action && 'confirming' === $session['stage'] ) {
			$client->answer_callback_query( $query_id );
			$client->edit_message_text( $chat, (int) ( $message['message_id'] ?? 0 ), (string) ( $message['text'] ?? '' ), $business );
			$session['stage'] = 'collecting';
			$session['picks'] = [];
			self::save_session( $sid, $session );
			self::send( $sid, __( 'Sure! What would you like to change?', 'yeffoprint-core' ) );
			return;
		}

		$client->answer_callback_query( $query_id );
	}

	/** Fills labels still missing a size or material with what the customer tapped (when it fits that label). */
	private static function apply_picks( array $result, array $picks ): array {
		if ( ! $picks ) {
			return $result;
		}
		$templates = array_column( YeffoPrint_Email_Order_Reader::catalog()['templates'], null, 'id' );
		foreach ( $result['items'] as &$item ) {
			if ( 'unclear' === $item['kind'] ) {
				continue;
			}
			$template = 'template' === $item['kind'] ? ( $templates[ $item['template_id'] ] ?? null ) : null;
			if ( ! $item['size_id'] && ! empty( $picks['size'] ) && ( ! $template || in_array( $picks['size'], $template['size_ids'], true ) ) ) {
				$item['size_id'] = (int) $picks['size'];
				$item['flags']   = array_values( array_diff( $item['flags'], [ 'Pick a size' ] ) );
			}
			if ( ! $item['material_id'] && ! empty( $picks['material'] ) && ( ! $template || in_array( $picks['material'], $template['material_ids'], true ) ) ) {
				$item['material_id'] = (int) $picks['material'];
				$item['flags']       = array_values( array_diff( $item['flags'], [ 'Pick a material' ] ) );
			}
		}
		unset( $item );
		return $result;
	}

	/** Two buttons per row. @param array<int,string> $options id => name */
	private static function keyboard( string $prefix, array $options ): array {
		$buttons = [];
		foreach ( $options as $id => $name ) {
			$buttons[] = [ 'text' => $name, 'callback_data' => 'tgco:' . $prefix . ':' . $id ];
		}
		return array_chunk( $buttons, 2 );
	}

	/**
	 * After a read ($after_read) or a tapped button: ask the next thing, or
	 * show the summary once nothing is missing. Typed questions count
	 * toward MAX_ROUNDS; buttons don't, since a tap can't go wrong.
	 */
	private static function advance( int $chat_id, array $session, bool $after_read ): void {
		$context = self::customer_context( $chat_id );
		$result  = self::apply_picks( (array) $session['result'], (array) ( $session['picks'] ?? [] ) );
		$missing = self::missing( $result, $context );
		$session['result'] = $result;

		if ( $missing['text'] && $after_read ) {
			if ( $session['rounds'] >= self::MAX_ROUNDS ) {
				self::hand_to_owner( $chat_id, $session, $context, __( 'The customer didn’t give everything the order needs.', 'yeffoprint-core' ) );
				return;
			}
			$session['rounds']++;
			self::send( $chat_id, self::question_text( $missing['text'], $session['rounds'] ) );
		}

		if ( $missing['size'] ) {
			if ( empty( $session['asked_size'] ) ) {
				$session['asked_size'] = true;
				self::send( $chat_id, __( 'Tap a label size', 'yeffoprint-core' ) . $missing['size_for'] . ':', null, self::keyboard( 's', $missing['size'] ) );
			}
		} elseif ( $missing['material'] ) {
			if ( empty( $session['asked_material'] ) ) {
				$session['asked_material'] = true;
				self::send( $chat_id, __( 'Tap a material', 'yeffoprint-core' ) . $missing['material_for'] . ':', null, self::keyboard( 'm', $missing['material'] ) );
			}
		} elseif ( ! $missing['text'] ) {
			$blocker = self::blocker( $result );
			if ( '' !== $blocker ) {
				self::hand_to_owner( $chat_id, $session, $context, $blocker );
				return;
			}
			$session['stage'] = 'confirming';
			self::save_session( $chat_id, $session );
			self::send( $chat_id, self::summary_text( $result, $context ), null, [ [
				[ 'text' => __( '✅ Looks good, send my payment link', 'yeffoprint-core' ), 'callback_data' => 'tgco:ok' ],
			], [
				[ 'text' => __( '✏️ Change something', 'yeffoprint-core' ), 'callback_data' => 'tgco:edit' ],
			] ] );
			return;
		}

		self::save_session( $chat_id, $session );
		if ( ! empty( $session['record_id'] ) ) {
			self::save_record( $chat_id, $session, $result, 'ready' );
		}
	}

	/** Jeff typed /order: (re)start the order for this chat. */
	private static function start( int $chat_id, string $connection_id, int $command_message_id ): void {
		self::delete_command( $connection_id, $chat_id, $command_message_id );

		if ( '' === YeffoPrint_Email_Order_Reader::api_key() ) {
			YeffoPrint_Telegram_Admin_Alerts::notify( "Telegram /order needs a Claude API key.\nAdd one in Settings › Integrations › Email orders.", [ 'section' => 'settings' ] );
			return;
		}

		$old = self::session( $chat_id );
		self::save_session( $chat_id, [
			'stage'         => 'collecting',
			'connection_id' => $connection_id,
			'rounds'        => 0,
			'record_id'     => $old['record_id'] ?? 0,
			'started'       => time(),
			'token'         => '',
			'picks'         => [],
		] );
		self::send( $chat_id, __( 'Thanks! Give me a moment to put your order together.', 'yeffoprint-core' ) );
		self::run_soon( $chat_id, 0 );
	}

	/* ---------- Running the read after the webhook has answered ---------- */

	/**
	 * Telegram waits for the webhook's answer, and a Claude read takes
	 * longer than it should have to. So the work runs after the response
	 * is sent (PHP-FPM's fastcgi_finish_request), a few seconds later, and
	 * only if no newer customer message came in meanwhile. A cron event is
	 * the backup for servers without FPM; process() skips work already done.
	 */
	private static function run_soon( int $chat_id, int $delay ): void {
		$session = self::session( $chat_id );
		if ( ! $session ) {
			return;
		}
		$token            = wp_generate_password( 12, false );
		$session['token']   = $token;
		$session['due']     = time() + $delay;
		$session['pending'] = true;
		self::save_session( $chat_id, $session );

		wp_clear_scheduled_hook( self::CRON_HOOK, [ $chat_id ] );
		wp_schedule_single_event( time() + $delay + 30, self::CRON_HOOK, [ $chat_id ] );

		add_action( 'shutdown', static function () use ( $chat_id, $token, $delay ) {
			if ( ! function_exists( 'fastcgi_finish_request' ) ) {
				return; // The cron event above does it instead.
			}
			fastcgi_finish_request();
			ignore_user_abort( true );
			if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( 300 );
			}
			if ( $delay > 0 ) {
				sleep( $delay );
			}
			$current = self::session( $chat_id );
			if ( $current && $token === ( $current['token'] ?? '' ) ) {
				self::process( $chat_id );
			}
		}, 1000 );
	}

	/** Reads the chat, asks what's missing, or creates the order — whatever the chat's stage calls for. */
	public static function process( int $chat_id ): void {
		$session = self::session( $chat_id );
		if ( ! $session || empty( $session['pending'] ) || ! in_array( $session['stage'], [ 'collecting', 'creating' ], true ) || (int) ( $session['due'] ?? 0 ) > time() ) {
			return;
		}

		$lock = self::LOCK_PREFIX . $chat_id;
		if ( get_transient( $lock ) ) {
			return;
		}
		set_transient( $lock, 1, 5 * MINUTE_IN_SECONDS );

		// Claimed: the backup cron event (or the other path) finds nothing to do.
		$session['pending'] = false;
		self::save_session( $chat_id, $session );

		try {
			if ( 'creating' === $session['stage'] ) {
				self::create_order( $chat_id, $session );
			} else {
				self::read_and_ask( $chat_id, $session );
			}
		} finally {
			delete_transient( $lock );
		}
	}

	private static function read_and_ask( int $chat_id, array $session ): void {
		$token   = (string) ( $session['token'] ?? '' );
		$context = self::customer_context( $chat_id );

		try {
			$result = YeffoPrint_Email_Order_Reader::read( [
				'from_email' => $context['email'],
				'from_name'  => $context['name'],
				'subject'    => '',
				'date'       => '',
				'text'       => self::transcript( $chat_id ),
				'earlier'    => '',
			], 'telegram' );
		} catch ( \RuntimeException $e ) {
			$session['record_id'] = self::save_record( $chat_id, $session, null, 'failed', $e->getMessage() );
			$session['stage']     = 'waiting_owner';
			self::save_session( $chat_id, $session );
			self::send( $chat_id, __( "Thanks! I'll double-check your order and send your payment link here shortly.", 'yeffoprint-core' ) );
			YeffoPrint_Telegram_Admin_Alerts::notify( "Couldn’t read a Telegram order\n" . $context['label'] . "\n" . $e->getMessage(), [ 'section' => 'email-orders' ] );
			return;
		}

		// A newer message arrived while Claude was reading: that run reads it all again.
		$current = self::session( $chat_id );
		if ( ! $current || ( $current['token'] ?? '' ) !== $token ) {
			return;
		}
		$session = $current;

		$result               = self::apply_picks( self::apply_context( $result, $context ), (array) ( $session['picks'] ?? [] ) );
		$session['result']    = $result;
		$session['record_id'] = self::save_record( $chat_id, $session, $result, 'ready' );
		self::advance( $chat_id, $session, true );
	}

	private static function create_order( int $chat_id, array $session ): void {
		$result  = is_array( $session['result'] ?? null ) ? $session['result'] : null;
		$context = self::customer_context( $chat_id );
		if ( ! $result ) {
			$session['stage'] = 'collecting';
			self::save_session( $chat_id, $session );
			self::run_soon( $chat_id, 0 );
			return;
		}

		if ( class_exists( 'YeffoPrint_Sales_Pause' ) && YeffoPrint_Sales_Pause::is_paused() ) {
			self::hand_to_owner( $chat_id, $session, $context, __( 'Sales are paused (Store › Quick switches), so no order was created.', 'yeffoprint-core' ) );
			return;
		}

		$built = self::build_order( $result, $context );
		if ( is_wp_error( $built ) ) {
			self::hand_to_owner( $chat_id, $session, $context, $built->get_error_message() );
			return;
		}

		$order = $built;
		$order->add_order_note( sprintf(
			/* translators: %s: customer's Telegram name */
			__( 'Created by the Telegram bot from a chat with %s, after the customer confirmed the summary.', 'yeffoprint-core' ),
			$context['label']
		) );
		$order->save();

		$record_id = (int) ( $session['record_id'] ?? 0 );
		if ( $record_id ) {
			YeffoPrint_Email_Orders::mark_created( $record_id, $order->get_id() ); // Sends the pay link (on_draft_created()).
		} else {
			self::send_pay_link( $chat_id, (string) $session['connection_id'], $order );
		}
		delete_transient( self::SESSION_PREFIX . $chat_id );

		YeffoPrint_Telegram_Admin_Alerts::notify(
			sprintf( "Telegram order #%s created\n%s · %s\n%s", $order->get_order_number(), $context['label'], self::money( (float) $order->get_total() ), $chat_id > 0 ? 'Pay link sent in your chat.' : 'They ordered in the bot chat; pay link sent there.' ),
			[ 'section' => 'orders', 'order_id' => $order->get_id() ]
		);
	}

	/** Something the bot can't do itself: the draft waits in Email Orders, and Create Order there sends the pay link. */
	private static function hand_to_owner( int $chat_id, array $session, array $context, string $reason ): void {
		$session['stage'] = 'waiting_owner';
		self::save_session( $chat_id, $session );
		self::send( $chat_id, __( "Thanks! I'll double-check your order and send your payment link here shortly.", 'yeffoprint-core' ) );
		YeffoPrint_Telegram_Admin_Alerts::notify(
			"Telegram order needs you\n" . $context['label'] . "\n" . $reason . "\nOpen it in Orders › Email Orders. Create Order sends the pay link in the chat.",
			[ 'section' => 'email-orders' ]
		);
	}

	/* ---------- Turning the draft into an order ---------- */

	/**
	 * Anything about this draft that Create Order's single form can't take
	 * automatically, in words for Jeff. Empty when the bot can go ahead.
	 */
	private static function blocker( array $result ): string {
		$items = array_filter( $result['items'], static function ( $item ) { return 'unclear' !== $item['kind']; } );
		if ( ! $items ) {
			return __( 'No labels were found in the chat.', 'yeffoprint-core' );
		}
		foreach ( $result['items'] as $item ) {
			if ( 'unclear' === $item['kind'] ) {
				return sprintf( __( 'The chat also asks for something that isn’t a label: “%s”.', 'yeffoprint-core' ), $item['source_text'] ?: $item['note'] );
			}
		}
		foreach ( self::template_groups( $result ) as $group ) {
			if ( count( array_unique( array_column( $group, 'size_id' ) ) ) > 1 || count( array_unique( array_column( $group, 'material_id' ) ) ) > 1 ) {
				return sprintf( __( '%s is asked for in more than one size or material.', 'yeffoprint-core' ), $group[0]['template_title'] );
			}
		}
		return '';
	}

	/** @return array<int, array> template items grouped by template id. */
	private static function template_groups( array $result ): array {
		$groups = [];
		foreach ( $result['items'] as $item ) {
			if ( 'template' === $item['kind'] ) {
				$groups[ $item['template_id'] ][] = $item;
			}
		}
		return array_values( $groups );
	}

	/** @return \WC_Order|\WP_Error */
	private static function build_order( array $result, array $context ) {
		$custom = array_values( array_filter( $result['items'], static function ( $item ) { return 'custom_design' === $item['kind']; } ) );
		$groups = self::template_groups( $result );

		$template_payload = static function ( array $group ): array {
			return [
				'template_id' => $group[0]['template_id'],
				'size_id'     => $group[0]['size_id'],
				'material_id' => $group[0]['material_id'],
				'variants'    => array_map( static function ( $item ) {
					return [ 'quantity' => $item['quantity'], 'values' => $item['fields'] ];
				}, $group ),
			];
		};

		$customer = $context['user_id']
			? [ 'id' => $context['user_id'] ]
			: [ 'name' => $result['customer']['name'], 'email' => $result['customer']['email'] ];

		$address = self::complete_address( $result['shipping_address'] ) ? $result['shipping_address'] + [ 'phone' => $result['customer']['phone'] ] : null;
		if ( $address ) {
			$names = explode( ' ', trim( $result['customer']['name'] ), 2 );
			$address['first_name'] = $address['first_name'] ?: $names[0];
			$address['last_name']  = $address['last_name'] ?: ( $names[1] ?? '' );
		}

		$payload = [
			'customer'                  => $customer,
			'requires_proof'            => true, // Same default as Create Order.
			'send_invoice_email'        => false, // The pay link goes in the chat instead.
			'customer_picks_shipping'   => true,
			'customer_provides_address' => ! $address,
		];
		if ( $address ) {
			$payload['shipping_address'] = $address;
		}

		if ( $custom ) {
			$previous = $result['previous_design'];
			$payload['custom_design'] = [
				'brand_name'       => $result['brand_name'] ?: (string) ( $custom[0]['brand_name'] ?? '' ),
				'batch'            => array_map( static function ( $item ) use ( $result ) {
					return [
						'size_id'           => $item['size_id'],
						'material_id'       => $item['material_id'],
						'quantity'          => $item['quantity'],
						'compound_strength' => $item['compound_strength'],
						'brand_name'        => $item['brand_name'] && $item['brand_name'] !== $result['brand_name'] ? $item['brand_name'] : '',
					];
				}, $custom ),
				'waive_design_fee' => ! empty( $result['reorder'] ),
				'instructions'     => ! empty( $result['reorder'] ) && $previous
					? sprintf( 'Reorder: reuse the design from order %s (custom order #%d).', $previous['order_number'] ?: 'from ' . $previous['date'], $previous['custom_order_id'] )
					: '',
				'style_notes'      => '',
			];
		}

		if ( $groups ) {
			$payload['template'] = $template_payload( array_shift( $groups ) );
		}

		$created = YeffoPrint_Manual_Order_Creator::create( $payload );
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		$order = $created['order'];

		// Create Order takes one template design per order; the others go on with Add items, as Jeff would.
		foreach ( $groups as $group ) {
			$added = YeffoPrint_Manual_Order_Creator::add_items( $order, [ 'template' => $template_payload( $group ), 'requires_proof' => true ] );
			if ( is_wp_error( $added ) ) {
				$order->delete( true );
				return $added;
			}
			$order = wc_get_order( $order->get_id() );
		}

		return $order;
	}

	/** A draft Jeff opened in Create Order (or the bot created) became an order: send its pay link in the chat. */
	public static function on_draft_created( int $record_id, int $order_id ): void {
		if ( 'telegram' !== get_post_meta( $record_id, YeffoPrint_Email_Orders::META_SOURCE, true ) || get_post_meta( $record_id, self::META_PAY_LINK_SENT, true ) ) {
			return;
		}
		$chat_id = (int) get_post_meta( $record_id, YeffoPrint_Email_Orders::META_TG_CHAT, true );
		$order   = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $chat_id || ! $order instanceof \WC_Order ) {
			return;
		}

		$session       = self::session( $chat_id );
		$connection_id = (string) ( $session['connection_id'] ?? get_post_meta( $record_id, '_yp_eo_tg_connection', true ) );
		if ( self::send_pay_link( $chat_id, $connection_id, $order ) ) {
			update_post_meta( $record_id, self::META_PAY_LINK_SENT, 1 );
		} else {
			YeffoPrint_Telegram_Admin_Alerts::notify(
				sprintf( "Couldn’t send the pay link for order #%s in Telegram.\nCopy it from the order and send it yourself.", $order->get_order_number() ),
				[ 'section' => 'orders', 'order_id' => $order->get_id() ]
			);
		}
		delete_transient( self::SESSION_PREFIX . $chat_id );
	}

	private static function send_pay_link( int $chat_id, string $connection_id, \WC_Order $order ): bool {
		$text = sprintf(
			/* translators: 1: order number, 2: labels total, 3: pay link */
			__( "Your order #%1\$s is ready (%2\$s before shipping).\n\nYou can check your address, choose shipping and pay here:\n%3\$s\n\nWe'll send a proof to approve before anything is printed.", 'yeffoprint-core' ),
			$order->get_order_number(),
			self::money( (float) $order->get_total() ),
			$order->get_checkout_payment_url()
		);
		return self::send( $chat_id, $text, $connection_id );
	}

	private static function money( float $amount ): string {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
	}

	/* ---------- What the customer still needs to tell us ---------- */

	/**
	 * Who the customer is, before Claude reads anything: the account their
	 * Telegram is linked to (My Account › Connect Telegram), else the
	 * email they gave on an earlier Telegram order.
	 *
	 * @return array{user_id:int, email:string, name:string, label:string}
	 */
	private static function customer_context( int $chat_id ): array {
		$user_id = YeffoPrint_Telegram_Account_Link::get_user_id_for_chat( abs( $chat_id ) );
		$user    = $user_id ? get_user_by( 'id', $user_id ) : false;

		$log  = self::get_log( $chat_id );
		$them = array_values( array_filter( $log, static function ( $entry ) { return 'customer' === $entry['who']; } ) );
		$last = $them ? end( $them ) : [];
		$name = $user ? $user->display_name : (string) ( $last['name'] ?? '' );
		$tg   = (string) ( $last['user'] ?? '' );

		$email = $user ? $user->user_email : self::earlier_email( $chat_id );

		return [
			'user_id'     => $user ? (int) $user->ID : 0,
			'email'       => $email,
			'name'        => $name,
			'label'       => trim( $name . ( '' !== $tg ? ' (@' . $tg . ')' : '' ) ) ?: 'Telegram customer',
		];
	}

	/** The customer email on this chat's last Telegram order, if any. */
	private static function earlier_email( int $chat_id ): string {
		$ids = get_posts( [
			'post_type'      => YeffoPrint_Email_Orders::POST_TYPE,
			'post_status'    => 'private',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[ 'key' => YeffoPrint_Email_Orders::META_TG_CHAT, 'value' => [ $chat_id, -$chat_id ], 'compare' => 'IN' ],
				[ 'key' => '_yp_eo_state', 'value' => 'created' ],
			],
		] );
		if ( ! $ids ) {
			return '';
		}
		$record = YeffoPrint_Email_Orders::format( get_post( (int) $ids[0] ) );
		return (string) ( $record['result']['customer']['email'] ?? '' );
	}

	/** The linked account wins for email and name; a saved account address counts as having one. */
	private static function apply_context( array $result, array $context ): array {
		if ( $context['user_id'] ) {
			$result['customer']['email'] = $context['email'];
		} elseif ( '' === $result['customer']['email'] ) {
			$result['customer']['email'] = $context['email'];
		}
		if ( '' === $result['customer']['name'] ) {
			$result['customer']['name'] = $context['name'];
		}
		return $result;
	}

	private static function complete_address( array $address ): bool {
		foreach ( [ 'address_1', 'city', 'state', 'postcode', 'country' ] as $key ) {
			if ( '' === (string) ( $address[ $key ] ?? '' ) ) {
				return false;
			}
		}
		return true;
	}

	/** Whether the account with this email already has a shipping address saved. */
	private static function account_has_address( string $email ): bool {
		$user = '' !== $email ? get_user_by( 'email', $email ) : false;
		return $user && '' !== (string) get_user_meta( $user->ID, 'shipping_address_1', true ) && '' !== (string) get_user_meta( $user->ID, 'shipping_postcode', true );
	}

	/**
	 * What to ask the customer: typed answers ('text', one line each) and
	 * button choices ('size' / 'material', id => name, with '…_for' naming
	 * the labels when only some need it).
	 *
	 * @return array{text:string[], size:array<int,string>, size_for:string, material:array<int,string>, material_for:string}
	 */
	private static function missing( array $result, array $context ): array {
		$out    = [ 'text' => [], 'size' => [], 'size_for' => '', 'material' => [], 'material_for' => '' ];
		$lines  = [];
		$labels = array_values( array_filter( $result['items'], static function ( $item ) { return 'unclear' !== $item['kind']; } ) );

		if ( ! $labels ) {
			if ( ! $result['items'] ) {
				$out['text'][] = __( 'Your label list (what each says and how many of each)', 'yeffoprint-core' );
			}
			return $out;
		}

		if ( '' === $result['customer']['email'] || ! is_email( $result['customer']['email'] ) ) {
			$lines[] = __( 'Email', 'yeffoprint-core' );
		}

		if ( ! self::complete_address( $result['shipping_address'] ) && ! self::account_has_address( $result['customer']['email'] ) ) {
			$lines[] = __( 'Shipping address', 'yeffoprint-core' );
		}

		$custom = array_filter( $labels, static function ( $item ) { return 'custom_design' === $item['kind']; } );
		if ( $custom && '' === $result['brand_name'] && array_filter( $custom, static function ( $item ) { return '' === $item['brand_name']; } ) ) {
			$lines[] = __( 'Brand name for the labels', 'yeffoprint-core' );
		}

		$catalog = YeffoPrint_Email_Order_Reader::catalog();
		$sizes   = array_column( $catalog['sizes'], 'name', 'id' );
		$stocked = array_column( array_filter( $catalog['materials'], static function ( $m ) { return $m['in_stock']; } ), 'name', 'id' );
		$templates = array_column( $catalog['templates'], null, 'id' );

		$needs = [ 'size' => [], 'material' => [], 'quantity' => [] ];
		foreach ( $labels as $item ) {
			$label = 'template' === $item['kind']
				? $item['template_title'] . ( $item['fields'] ? ' (' . implode( ', ', $item['fields'] ) . ')' : '' )
				: ( $item['compound_strength'] ?: $item['source_text'] );
			if ( ! $item['size_id'] ) {
				$needs['size'][ $label ] = 'template' === $item['kind'] && isset( $templates[ $item['template_id'] ] )
					? array_intersect_key( $sizes, array_flip( $templates[ $item['template_id'] ]['size_ids'] ) )
					: $sizes;
			}
			if ( ! $item['material_id'] ) {
				$needs['material'][ $label ] = 'template' === $item['kind'] && isset( $templates[ $item['template_id'] ] )
					? array_intersect_key( $stocked, array_flip( $templates[ $item['template_id'] ]['material_ids'] ) )
					: $stocked;
			}
			if ( ! $item['quantity'] ) {
				$needs['quantity'][ $label ] = [];
			}
		}

		// Direct feedback: the first version named every label and was too long to read. Name labels only
		// when some (not all) need this, and at most two of them.
		$which = static function ( array $for ) use ( $labels ): string {
			$names = array_keys( $for );
			return count( $for ) === count( $labels ) ? '' : ' for ' . implode( ', ', array_slice( $names, 0, 2 ) ) . ( count( $names ) > 2 ? sprintf( ' +%d more', count( $names ) - 2 ) : '' );
		};

		if ( $needs['quantity'] ) {
			$lines[] = __( 'How many of each', 'yeffoprint-core' ) . $which( $needs['quantity'] );
		}
		foreach ( [ 'size', 'material' ] as $what ) {
			if ( $needs[ $what ] ) {
				$options = [];
				foreach ( $needs[ $what ] as $choices ) {
					$options += $choices; // Keeps ids as keys.
				}
				$out[ $what ]          = array_slice( $options, 0, 16, true );
				$out[ $what . '_for' ] = $which( $needs[ $what ] );
			}
		}

		$out['text'] = $lines;
		return $out;
	}

	private static function question_text( array $missing, int $round ): string {
		$intro = 1 === $round
			? __( 'Thanks! Just need a few things:', 'yeffoprint-core' )
			: __( 'Thanks! Still need:', 'yeffoprint-core' );
		return $intro . "\n\n" . implode( "\n", array_map( static function ( $line ) { return '• ' . $line; }, $missing ) );
	}

	private static function summary_text( array $result, array $context ): string {
		$catalog   = YeffoPrint_Email_Order_Reader::catalog();
		$sizes     = array_column( $catalog['sizes'], 'name', 'id' );
		$materials = array_column( $catalog['materials'], 'name', 'id' );

		$lines = [];
		foreach ( $result['items'] as $item ) {
			$what    = 'template' === $item['kind']
				? $item['template_title'] . ( $item['fields'] ? ': ' . implode( ', ', $item['fields'] ) : '' )
				: $item['compound_strength'];
			$lines[] = sprintf( '• %d × %s (%s, %s)', $item['quantity'], $what, $sizes[ $item['size_id'] ] ?? '', $materials[ $item['material_id'] ] ?? '' );
		}

		$address = $result['shipping_address'];
		$ship    = self::complete_address( $address )
			? implode( ', ', array_filter( [ $address['address_1'], $address['address_2'], $address['city'], $address['state'] . ' ' . $address['postcode'], 'US' === $address['country'] ? '' : $address['country'] ] ) )
			: __( 'the address on your account', 'yeffoprint-core' );

		$extra = [];
		if ( '' !== $result['brand_name'] ) {
			$extra[] = sprintf( __( 'Brand: %s', 'yeffoprint-core' ), $result['brand_name'] );
		}
		if ( ! empty( $result['reorder'] ) ) {
			$extra[] = __( 'Reusing your last design, so there’s no design fee', 'yeffoprint-core' );
		}
		$extra[] = sprintf( __( 'Email: %s', 'yeffoprint-core' ), $result['customer']['email'] );
		$extra[] = sprintf( __( 'Ship to: %s', 'yeffoprint-core' ), $ship );

		return __( "Here's your order:", 'yeffoprint-core' ) . "\n\n" . implode( "\n", $lines ) . "\n\n" . implode( "\n", $extra ) . "\n\n" .
			__( 'Tap below, or reply YES or with what to change.', 'yeffoprint-core' );
	}

	private static function is_yes( string $text ): bool {
		$clean = trim( preg_replace( '/[^\p{L}\p{N}\' ]+/u', ' ', mb_strtolower( $text ) ) );
		if ( '' === $clean ) {
			return (bool) preg_match( '/^\s*(👍|✅|👌)+\s*$/u', $text );
		}
		return (bool) preg_match( "/^(yes|yeah|yea|yep|yup|y|si|sí|correct|confirm|confirmed|looks good|look good|sounds good|that's right|thats right|that's correct|perfect|ok|okay|all good|good|go ahead|send it|send the link)( (please|thanks|thank you|thx|send it|go ahead))*$/u", $clean );
	}

	/* ---------- Draft record in Orders › Email Orders ---------- */

	private static function save_record( int $chat_id, array $session, ?array $result, string $state, string $error = '' ): int {
		$context = self::customer_context( $chat_id );
		$id      = YeffoPrint_Email_Orders::save_chat( (int) ( $session['record_id'] ?? 0 ), [
			'title'   => sprintf( 'Telegram chat with %s', $context['label'] ),
			'from'    => 'Telegram: ' . $context['label'],
			'text'    => self::transcript( $chat_id ),
			'chat_id' => $chat_id,
			'result'  => $result,
			'state'   => $state,
			'error'   => $error,
		] );
		if ( $id ) {
			update_post_meta( $id, '_yp_eo_tg_connection', (string) $session['connection_id'] );
		}
		return $id;
	}

	/** "Read again" in Email Orders: reads the chat as it is now, without asking the customer anything. */
	public static function reread_record( int $id ): bool {
		$chat_id = (int) get_post_meta( $id, YeffoPrint_Email_Orders::META_TG_CHAT, true );
		$context = self::customer_context( $chat_id );
		$text    = self::get_log( $chat_id ) ? self::transcript( $chat_id ) : (string) get_post_field( 'post_content', $id );
		try {
			$result = self::apply_context( YeffoPrint_Email_Order_Reader::read( [
				'from_email' => $context['email'],
				'from_name'  => $context['name'],
				'subject'    => '',
				'date'       => '',
				'text'       => $text,
				'earlier'    => '',
			], 'telegram' ), $context );
		} catch ( \RuntimeException $e ) {
			YeffoPrint_Email_Orders::set_state( $id, 'failed' );
			update_post_meta( $id, '_yp_eo_error', $e->getMessage() );
			return false;
		}
		update_post_meta( $id, '_yp_eo_result', wp_slash( wp_json_encode( $result ) ) );
		delete_post_meta( $id, '_yp_eo_error' );
		wp_update_post( [ 'ID' => $id, 'post_content' => $text ] );
		YeffoPrint_Email_Orders::set_state( $id, 'ready' );
		return true;
	}

	/* ---------- Chat log, session, sending ---------- */

	private static function log( int $chat_id, array $entry, bool $edited = false ): void {
		$log = self::get_log( $chat_id );
		if ( $edited && $entry['id'] ) {
			foreach ( $log as &$existing ) {
				if ( $existing['id'] === $entry['id'] && $existing['who'] === $entry['who'] ) {
					$existing['text'] = $entry['text'] . ' (edited)';
					set_transient( self::LOG_PREFIX . $chat_id, $log, self::LOG_TTL );
					return;
				}
			}
			unset( $existing );
		}
		$cutoff = time() - self::LOG_TTL;
		$log    = array_values( array_filter( $log, static function ( $old ) use ( $cutoff ) { return $old['time'] >= $cutoff; } ) );
		$log[]  = $entry;
		set_transient( self::LOG_PREFIX . $chat_id, array_slice( $log, -self::LOG_MAX ), self::LOG_TTL );
	}

	private static function get_log( int $chat_id ): array {
		$log = get_transient( self::LOG_PREFIX . $chat_id );
		return is_array( $log ) ? $log : [];
	}

	private static function transcript( int $chat_id ): string {
		$timezone = wp_timezone();
		return implode( "\n", array_map( static function ( $entry ) use ( $timezone ) {
			$time = ( new \DateTimeImmutable( '@' . $entry['time'] ) )->setTimezone( $timezone )->format( 'M j g:ia' );
			return '[' . $time . '] ' . ( 'owner' === $entry['who'] ? 'Owner' : 'Customer' ) . ': ' . $entry['text'];
		}, self::get_log( $chat_id ) ) );
	}

	private static function session( int $chat_id ): ?array {
		$session = get_transient( self::SESSION_PREFIX . $chat_id );
		return is_array( $session ) ? $session : null;
	}

	private static function save_session( int $chat_id, array $session ): void {
		set_transient( self::SESSION_PREFIX . $chat_id, $session, 2 * DAY_IN_SECONDS );
	}

	private static function client(): YeffoPrint_Telegram_Client {
		return new YeffoPrint_Telegram_Client( YeffoPrint_Telegram_Settings::get_bot_token() );
	}

	/** Sends in the customer's chat as Jeff, and logs it so the next read sees what was asked. */
	private static function send( int $chat_id, string $text, ?string $connection_id = null, ?array $keyboard = null ): bool {
		if ( null === $connection_id || '' === $connection_id ) {
			$session       = self::session( $chat_id );
			$connection_id = (string) ( $session['connection_id'] ?? $connection_id ?? '' );
		}
		// The bot's own chat (negative session id) needs no business connection.
		if ( '' === $connection_id && $chat_id > 0 ) {
			return false;
		}
		$sent = self::client()->send_message( abs( $chat_id ), $text, $keyboard, $chat_id > 0 ? $connection_id : '' );
		if ( $sent ) {
			self::log( $chat_id, [ 'id' => 0, 'who' => 'owner', 'name' => '', 'user' => '', 'time' => time(), 'text' => $text ] );
		}
		return $sent;
	}

	/** Removes Jeff's /order or /cancel from the chat, when Telegram allows it. */
	private static function delete_command( string $connection_id, int $chat_id, int $message_id ): void {
		if ( ! $message_id ) {
			return;
		}
		$token = YeffoPrint_Telegram_Settings::get_bot_token();
		if ( '' === $token ) {
			return;
		}
		wp_remote_post( 'https://api.telegram.org/bot' . $token . '/deleteBusinessMessages', [
			'timeout'  => 5,
			'blocking' => false,
			'body'     => [
				'business_connection_id' => $connection_id,
				'message_ids'            => wp_json_encode( [ $message_id ] ),
			],
		] );
	}
}
