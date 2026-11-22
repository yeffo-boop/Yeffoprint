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
 * Follow-up request: "can the bot allow them to edit/add to the list of
 * labels after they've confirmed the order but before they've paid?" The
 * pay link message has an "✏️ Edit my order" button (or /edit in the bot
 * chat, or Jeff typing /order again). The session stays open as
 * 'awaiting_payment' while the order is unpaid; editing goes back through
 * the same read, questions and summary, and the confirmed order replaces
 * the unpaid one: a new order and pay link, and the old order is
 * cancelled with a note. Once it's paid, the button says so instead.
 *
 * Follow-up request: "Can we have the bot be able to select from a list of
 * templates? Skip proof if they order a template and have the bot ask for
 * missing fields."
 *   - "📋 Pick from our templates" (on the /neworder prompt and the
 *     summary, or typing "templates") opens category buttons, then that
 *     category's templates (8 a page). A tap logs "(picked template: X)"
 *     for the reader and asks what each label should say.
 *   - Template labels are added without a proof shell, like a template
 *     bought on the site. Custom design labels still get their proof.
 *   - Required template fields the chat doesn't fill (color choices have a
 *     default) are asked as typed questions.
 *
 * Follow-up request: "Can we also order 3d prints from the bot? Same dropdown to
 * select a print and options. Ask missing fields?"
 *   - "🖨 Shop 3D prints" (on the /neworder prompt and the summary, or typing
 *     "3d prints") lists the prints, then asks with buttons for the size, a
 *     color for each part, optional lid text (typed) and its color, and how
 *     many (tap or type). Picks are checked like the product page checks them.
 *   - Prints live in the session ('prints'), not in Claude's read, and go on
 *     the order as their own lines (Manual_Order_Creator 'prints'). The pay
 *     page raises shipping to the prints' shipping prices. Pause all sales
 *     doesn't stop a prints-only order.
 *
 * Follow-up request: "Will the bot link the telegram account to that email for
 * future orders? Will the bot send order confirmation through telegram once
 * they pay their order and when shipping updates happen?"
 *   - The order remembers its chat: a "Payment received" message when it's
 *     paid, and the shipped message with tracking (the bot's own chat gets
 *     it from YeffoPrint_Telegram_Order_Notifications).
 *   - A chat not linked to an account gets a Connect Telegram email for the
 *     order's account (email_link_offer()); tapping it links the account.
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
		add_action( 'woocommerce_payment_complete', [ self::class, 'on_paid' ], 20 );
		add_action( 'woocommerce_order_status_processing', [ self::class, 'on_paid' ], 20 );
		add_action( 'woocommerce_order_status_completed', [ self::class, 'on_paid' ], 20 );
		add_action( 'woocommerce_order_status_shipped', [ self::class, 'on_shipped' ], 20 );
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

		if ( ! $is_owner && ! $edited ) {
			$open = self::session( $chat_id );
			if ( $open && self::print_typed( $chat_id, $open, $text ) ) {
				return; // Lid text or how many for a 3D print, not part of the label list.
			}
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
		if ( in_array( $session['stage'], [ 'collecting', 'confirming' ], true ) && self::asks_for_templates( $text ) ) {
			self::send_categories( $chat_id );
			return;
		}
		if ( in_array( $session['stage'], [ 'collecting', 'confirming' ], true ) && self::asks_for_prints( $text ) ) {
			self::send_prints( $chat_id );
			return;
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
				'prints'        => [],
			] );
			$buttons = [ [ [ 'text' => __( '📋 Pick from our templates', 'yeffoprint-core' ), 'callback_data' => 'tgco:tl' ] ] ];
			if ( self::orderable_prints() ) {
				$buttons[] = [ [ 'text' => __( '🖨 Shop 3D prints', 'yeffoprint-core' ), 'callback_data' => 'tgco:pl:0' ] ];
			}
			self::send( $sid, __( "Great, let's start your order!\n\nFor labels, send me your list: what each label says (like “BPC-157 10mg”) and how many of each. One message or a few is fine. Or tap below for our ready-made designs or 3D prints:", 'yeffoprint-core' ), null, $buttons );
			return true;
		}

		$session = self::session( $sid );
		if ( $session && 'awaiting_payment' === $session['stage'] && in_array( $command, [ '/edit', '/change' ], true ) ) {
			self::begin_edit( $sid, $session );
			return true;
		}
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
		if ( self::print_typed( $sid, $session, $text ) ) {
			return true;
		}
		if ( self::asks_for_templates( $text ) ) {
			self::send_categories( $sid );
			return true;
		}
		if ( self::asks_for_prints( $text ) ) {
			self::send_prints( $sid );
			return true;
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

		if ( $session && $allowed && 'awaiting_payment' === $session['stage'] && 'change' === ( $parts[1] ?? '' ) ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) ( $session['order_id'] ?? 0 ) ) : null;
			if ( ! $order instanceof \WC_Order || ! YeffoPrint_Manual_Order_Creator::is_editable( $order ) ) {
				$client->answer_callback_query( $query_id, __( 'This order is already paid. Message us to add to it.', 'yeffoprint-core' ) );
				return;
			}
			$client->answer_callback_query( $query_id );
			self::begin_edit( $sid, $session );
			return;
		}

		if ( $session && $allowed && in_array( $parts[1] ?? '', [ 'tl', 'tc', 't' ], true ) && in_array( $session['stage'], [ 'listing', 'collecting', 'confirming' ], true ) ) {
			$client->answer_callback_query( $query_id );
			self::template_tap( $sid, $session, $parts, $message, $business );
			return;
		}

		if ( $session && $allowed && in_array( $parts[1] ?? '', [ 'pl', 'p', 'ps', 'pc', 'pt', 'px', 'pq', 'pr' ], true ) && in_array( $session['stage'], [ 'listing', 'collecting', 'confirming' ], true ) ) {
			$client->answer_callback_query( $query_id );
			self::print_tap( $sid, $session, $parts, $message, $business );
			return;
		}

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

	/**
	 * The chat's newest bot-made order that's still unpaid, from its Email
	 * Orders record. Direct request: chats whose pay link went out before
	 * the Edit button existed have no open session, so Jeff's /order there
	 * would otherwise make a second order instead of replacing the first.
	 */
	private static function unpaid_order_for_chat( int $chat_id ): int {
		$ids = get_posts( [
			'post_type'      => YeffoPrint_Email_Orders::POST_TYPE,
			'post_status'    => 'private',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[ 'key' => YeffoPrint_Email_Orders::META_TG_CHAT, 'value' => $chat_id ],
				[ 'key' => '_yp_eo_state', 'value' => 'created' ],
			],
		] );
		if ( ! $ids || ! function_exists( 'wc_get_order' ) ) {
			return 0;
		}
		$order = wc_get_order( (int) get_post_meta( (int) $ids[0], '_yp_eo_order_id', true ) );
		return $order instanceof \WC_Order && YeffoPrint_Manual_Order_Creator::is_editable( $order ) ? $order->get_id() : 0;
	}

	/* ---------- Template picker ---------- */

	private const TEMPLATES_PER_PAGE = 8;

	private static function asks_for_templates( string $text ): bool {
		return (bool) preg_match( '/^\s*(show (me )?(the |your )?)?(templates?|designs?|ready[- ]made( designs?)?)\s*[?!.]*\s*$/i', $text );
	}

	/** Product Type categories that have at least one published template. @return array<int,string> term id => name */
	private static function template_categories(): array {
		$terms = get_terms( [ 'taxonomy' => 'yp_product_type', 'hide_empty' => true ] );
		if ( is_wp_error( $terms ) ) {
			return [];
		}
		$out = [];
		foreach ( $terms as $term ) {
			if ( self::templates_in( (int) $term->term_id, 1 ) ) {
				$out[ (int) $term->term_id ] = $term->name;
			}
		}
		return $out;
	}

	/** @return \WP_Post[] */
	private static function templates_in( int $term_id, int $limit, int $offset = 0 ): array {
		return get_posts( [
			'post_type'      => 'yp_template',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'offset'         => $offset,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'tax_query'      => [ [ 'taxonomy' => 'yp_product_type', 'field' => 'term_id', 'terms' => $term_id ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		] );
	}

	private static function send_categories( int $chat_id ): void {
		$categories = self::template_categories();
		if ( ! $categories ) {
			self::send( $chat_id, __( 'Our templates aren’t available right now. Send me what your labels should say and we’ll design them for you.', 'yeffoprint-core' ) );
			return;
		}
		$buttons = [];
		foreach ( $categories as $id => $name ) {
			$buttons[] = [ 'text' => $name, 'callback_data' => 'tgco:tc:' . $id . ':0' ];
		}
		self::send( $chat_id, __( 'Which kind of label?', 'yeffoprint-core' ), null, array_chunk( $buttons, 2 ) );
	}

	/** tgco:tl (categories), tgco:tc:TERM:PAGE (that category's templates), tgco:t:ID (picked). */
	private static function template_tap( int $chat_id, array $session, array $parts, array $message, string $business ): void {
		$action = $parts[1];
		$chat   = abs( $chat_id );

		if ( 'tl' === $action ) {
			self::send_categories( $chat_id );
			return;
		}

		if ( 'tc' === $action ) {
			$term_id = absint( $parts[2] ?? 0 );
			$page    = absint( $parts[3] ?? 0 );
			$posts   = self::templates_in( $term_id, self::TEMPLATES_PER_PAGE + 1, $page * self::TEMPLATES_PER_PAGE );
			$more    = count( $posts ) > self::TEMPLATES_PER_PAGE;
			$buttons = [];
			foreach ( array_slice( $posts, 0, self::TEMPLATES_PER_PAGE ) as $post ) {
				$buttons[] = [ [ 'text' => mb_strimwidth( $post->post_title, 0, 40, '…' ), 'callback_data' => 'tgco:t:' . $post->ID ] ];
			}
			$nav = [];
			if ( $page > 0 ) {
				$nav[] = [ 'text' => __( '‹ Back', 'yeffoprint-core' ), 'callback_data' => 'tgco:tc:' . $term_id . ':' . ( $page - 1 ) ];
			}
			if ( $more ) {
				$nav[] = [ 'text' => __( 'More ›', 'yeffoprint-core' ), 'callback_data' => 'tgco:tc:' . $term_id . ':' . ( $page + 1 ) ];
			}
			$nav[]     = [ 'text' => __( 'Other kinds', 'yeffoprint-core' ), 'callback_data' => 'tgco:tl' ];
			$buttons[] = $nav;
			$term      = get_term( $term_id, 'yp_product_type' );
			self::send( $chat_id, sprintf( __( 'Pick a %s template:', 'yeffoprint-core' ), $term && ! is_wp_error( $term ) ? $term->name : '' ), null, $buttons );
			return;
		}

		$template = get_post( absint( $parts[2] ?? 0 ) );
		if ( ! $template || 'yp_template' !== $template->post_type || 'publish' !== $template->post_status ) {
			return;
		}
		self::client()->edit_message_text( $chat, (int) ( $message['message_id'] ?? 0 ), __( 'Template', 'yeffoprint-core' ) . ': ' . $template->post_title . ' ✓', $business );
		self::log( $chat_id, [ 'id' => 0, 'who' => 'customer', 'name' => '', 'user' => '', 'time' => time(), 'text' => '(picked template: ' . $template->post_title . ')' ] );

		$session['stage'] = 'collecting';
		self::save_session( $chat_id, $session );
		$link = get_permalink( $template );
		self::send( $chat_id, sprintf(
			/* translators: 1: template title, 2: its page on the site */
			__( "%1\$s, great choice! What should each label say, and how many of each? For example “BPC-157 10mg, 100”.%2\$s", 'yeffoprint-core' ),
			$template->post_title,
			$link ? "\n\n" . sprintf( __( 'See it here: %s', 'yeffoprint-core' ), $link ) : ''
		) );
	}

	/* ---------- 3D print picker ---------- */

	private const PRINTS_PER_PAGE = 8;

	/** Most color buttons shown for one choice (Telegram allows 100 buttons; more is unreadable). */
	private const MAX_COLOR_BUTTONS = 30;

	private static function asks_for_prints( string $text ): bool {
		return (bool) preg_match( '/^\s*(show (me )?(the |your )?)?(3\s*-?d( prints?)?|prints?)\s*[?!.]*\s*$/i', $text );
	}

	/** Published 3D prints with a price and a sellable product, by title. @return \WP_Post[] */
	private static function orderable_prints(): array {
		$posts = get_posts( [
			'post_type'      => 'yp_print',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );
		return array_values( array_filter( $posts, static function ( $post ) {
			$product_id = YeffoPrint_Print_Product::get_linked_product_id( $post->ID );
			$product    = $product_id && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
			return YeffoPrint_Print_Meta::from_price( $post->ID ) > 0 && $product && 'publish' === $product->get_status();
		} ) );
	}

	private static function send_prints( int $chat_id, int $page = 0 ): void {
		$prints = self::orderable_prints();
		if ( ! $prints ) {
			self::send( $chat_id, __( 'Our 3D prints aren’t available to order right now.', 'yeffoprint-core' ) );
			return;
		}
		$buttons = [];
		foreach ( array_slice( $prints, $page * self::PRINTS_PER_PAGE, self::PRINTS_PER_PAGE ) as $post ) {
			$buttons[] = [ [
				/* translators: 1: print name, 2: its lowest price */
				'text'          => sprintf( __( '%1$s · from %2$s', 'yeffoprint-core' ), mb_strimwidth( $post->post_title, 0, 36, '…' ), self::money( YeffoPrint_Print_Meta::from_price( $post->ID ) ) ),
				'callback_data' => 'tgco:p:' . $post->ID,
			] ];
		}
		$nav = [];
		if ( $page > 0 ) {
			$nav[] = [ 'text' => __( '‹ Back', 'yeffoprint-core' ), 'callback_data' => 'tgco:pl:' . ( $page - 1 ) ];
		}
		if ( count( $prints ) > ( $page + 1 ) * self::PRINTS_PER_PAGE ) {
			$nav[] = [ 'text' => __( 'More ›', 'yeffoprint-core' ), 'callback_data' => 'tgco:pl:' . ( $page + 1 ) ];
		}
		if ( $nav ) {
			$buttons[] = $nav;
		}
		self::send( $chat_id, __( 'Which 3D print?', 'yeffoprint-core' ), null, $buttons );
	}

	/**
	 * tgco:pl:PAGE (list), tgco:p:ID (picked), tgco:ps:N (size), tgco:pc:SLOT:FILAMENT (color),
	 * tgco:pt:1|0 (custom text or not), tgco:px:FILAMENT (text color), tgco:pq:N (how many),
	 * tgco:pr (remove the order's 3D prints).
	 */
	private static function print_tap( int $chat_id, array $session, array $parts, array $message, string $business ): void {
		$action = $parts[1];
		$chat   = abs( $chat_id );
		$msg_id = (int) ( $message['message_id'] ?? 0 );
		$draft  = is_array( $session['print_draft'] ?? null ) ? $session['print_draft'] : null;

		if ( 'pl' === $action ) {
			self::send_prints( $chat_id, absint( $parts[2] ?? 0 ) );
			return;
		}

		if ( 'pr' === $action ) {
			$session['prints'] = [];
			unset( $session['print_draft'], $session['awaiting'] );
			self::client()->edit_message_text( $chat, $msg_id, (string) ( $message['text'] ?? '' ), $business );
			self::send( $chat_id, __( 'Okay, I took the 3D prints off.', 'yeffoprint-core' ) );
			self::after_print_change( $chat_id, $session );
			return;
		}

		if ( 'p' === $action ) {
			$print = get_post( absint( $parts[2] ?? 0 ) );
			if ( ! $print || 'yp_print' !== $print->post_type || 'publish' !== $print->post_status ) {
				return;
			}
			self::client()->edit_message_text( $chat, $msg_id, __( '3D print', 'yeffoprint-core' ) . ': ' . $print->post_title . ' ✓', $business );
			$session['print_draft'] = [ 'print_id' => $print->ID, 'size' => null, 'colors' => [], 'text' => null, 'text_color' => 0, 'quantity' => 0 ];
			unset( $session['awaiting'] );
			self::save_session( $chat_id, $session );
			$link = get_permalink( $print );
			if ( $link ) {
				self::send( $chat_id, sprintf( __( '%1$s, nice pick! See it here: %2$s', 'yeffoprint-core' ), $print->post_title, $link ) );
			}
			self::print_next( $chat_id, $session );
			return;
		}

		if ( ! $draft ) {
			return; // A button from a print that's already added or was dropped.
		}
		$print_id = (int) $draft['print_id'];
		$label    = '';

		if ( 'ps' === $action ) {
			$options = self::print_sizes( $print_id );
			$index   = absint( $parts[2] ?? 0 );
			if ( ! isset( $options[ $index ] ) ) {
				return;
			}
			$draft['size'] = $options[ $index ]['name'];
			$label         = __( 'Size', 'yeffoprint-core' ) . ': ' . $draft['size'];
		} elseif ( 'pc' === $action ) {
			$slot  = absint( $parts[2] ?? 0 );
			$slots = self::print_slots( $print_id );
			$color = self::in_stock_color( $slots[ $slot ]['colors'] ?? [], absint( $parts[3] ?? 0 ) );
			if ( ! $color ) {
				return;
			}
			$draft['colors'][ $slot ] = $color['id'];
			$label                    = $slots[ $slot ]['name'] . ': ' . $color['name'];
		} elseif ( 'pt' === $action ) {
			if ( '1' === ( $parts[2] ?? '' ) ) {
				$session['awaiting'] = 'print_text';
				$session['print_draft'] = $draft;
				self::save_session( $chat_id, $session );
				self::client()->edit_message_text( $chat, $msg_id, (string) ( $message['text'] ?? '' ) . ' ' . __( 'Yes', 'yeffoprint-core' ), $business );
				$addons = YeffoPrint_Print_Meta::get_addons( $print_id );
				/* translators: 1: where on the print, e.g. "lid", 2: max characters */
				self::send( $chat_id, sprintf( __( 'Type the %1$s text (up to %2$d characters):', 'yeffoprint-core' ), $addons['area'], $addons['text_max'] ) );
				return;
			}
			$draft['text'] = '';
			$label         = (string) ( $message['text'] ?? '' ) . ' ' . __( 'No thanks', 'yeffoprint-core' );
		} elseif ( 'px' === $action ) {
			$color = self::in_stock_color( array_values( YeffoPrint_Print_Meta::get_filaments() ), absint( $parts[2] ?? 0 ) );
			if ( ! $color ) {
				return;
			}
			$draft['text_color'] = $color['id'];
			$label               = __( 'Text color', 'yeffoprint-core' ) . ': ' . $color['name'];
		} elseif ( 'pq' === $action ) {
			$draft['quantity'] = max( 1, min( 100, absint( $parts[2] ?? 1 ) ) );
			unset( $session['awaiting'] );
			$label = __( 'How many', 'yeffoprint-core' ) . ': ' . $draft['quantity'];
		} else {
			return;
		}

		self::client()->edit_message_text( $chat, $msg_id, $label . ' ✓', $business );
		$session['print_draft'] = $draft;
		self::save_session( $chat_id, $session );
		self::print_next( $chat_id, $session );
	}

	/** A typed answer the print picker asked for (lid text, or how many). @return bool true when it was one. */
	private static function print_typed( int $chat_id, array $session, string $text ): bool {
		$draft = is_array( $session['print_draft'] ?? null ) ? $session['print_draft'] : null;
		$what  = (string) ( $session['awaiting'] ?? '' );
		if ( ! $draft || '' === $what ) {
			return false;
		}

		if ( 'print_text' === $what ) {
			$addons = YeffoPrint_Print_Meta::get_addons( (int) $draft['print_id'] );
			$clean  = trim( sanitize_text_field( $text ) );
			if ( '' === $clean || mb_strlen( $clean ) > $addons['text_max'] ) {
				/* translators: %d: max characters */
				self::send( $chat_id, sprintf( __( 'Please keep it to %d characters or fewer. Type it again:', 'yeffoprint-core' ), $addons['text_max'] ) );
				return true;
			}
			$draft['text'] = $clean;
		} elseif ( 'print_qty' === $what ) {
			if ( ! preg_match( '/^\s*(\d{1,3})\s*$/', $text, $m ) || (int) $m[1] < 1 || (int) $m[1] > 100 ) {
				return false; // Not a number: read it as part of the order instead.
			}
			$draft['quantity'] = (int) $m[1];
		} else {
			return false;
		}

		unset( $session['awaiting'] );
		$session['print_draft'] = $draft;
		self::save_session( $chat_id, $session );
		self::print_next( $chat_id, $session );
		return true;
	}

	/** Sizes the print can be bought in (priced ones), in admin order. */
	private static function print_sizes( int $print_id ): array {
		return array_values( array_filter( YeffoPrint_Print_Meta::get_size_options( $print_id ), static function ( $option ) { return (float) $option['price'] > 0; } ) );
	}

	/** The print's color choices with their colors resolved (get_item_payload()'s slots). */
	private static function print_slots( int $print_id ): array {
		$payload = YeffoPrint_Print_Meta::get_item_payload( $print_id );
		return $payload ? $payload['slots'] : [];
	}

	private static function in_stock_color( array $colors, int $id ): ?array {
		foreach ( $colors as $color ) {
			if ( (int) $color['id'] === $id && ! empty( $color['in_stock'] ) ) {
				return $color;
			}
		}
		return null;
	}

	/** @param array[] $colors filament data */
	private static function color_keyboard( string $prefix, array $colors, bool $show_extra = true ): array {
		$buttons = [];
		foreach ( array_slice( array_values( array_filter( $colors, static function ( $c ) { return ! empty( $c['in_stock'] ); } ) ), 0, self::MAX_COLOR_BUTTONS ) as $color ) {
			$buttons[] = [
				'text'          => $color['name'] . ( $show_extra && (float) $color['extra_charge'] > 0 ? ' +' . self::money( (float) $color['extra_charge'] ) : '' ),
				'callback_data' => 'tgco:' . $prefix . ':' . $color['id'],
			];
		}
		return array_chunk( $buttons, 2 );
	}

	/** Asks the next thing the print being added needs (size, each color, lid text, how many), or adds it. */
	private static function print_next( int $chat_id, array $session ): void {
		$draft    = (array) $session['print_draft'];
		$print_id = (int) $draft['print_id'];
		$title    = get_the_title( $print_id );

		$sizes = self::print_sizes( $print_id );
		if ( $sizes && null === $draft['size'] ) {
			$buttons = [];
			foreach ( $sizes as $index => $option ) {
				$buttons[] = [ 'text' => $option['name'] . ' · ' . self::money( (float) $option['price'] ), 'callback_data' => 'tgco:ps:' . $index ];
			}
			self::send( $chat_id, __( 'Tap a size:', 'yeffoprint-core' ), null, array_chunk( $buttons, 2 ) );
			return;
		}

		foreach ( self::print_slots( $print_id ) as $index => $slot ) {
			if ( isset( $draft['colors'][ $index ] ) ) {
				continue;
			}
			$keyboard = self::color_keyboard( 'pc:' . $index, $slot['colors'] );
			if ( ! $keyboard ) {
				unset( $session['print_draft'] );
				self::save_session( $chat_id, $session );
				/* translators: 1: part name, 2: print name */
				self::send( $chat_id, sprintf( __( 'Sorry, every %1$s color for the %2$s is out of stock right now, so I can’t add it.', 'yeffoprint-core' ), $slot['name'], $title ) );
				self::after_print_change( $chat_id, $session );
				return;
			}
			/* translators: %s: part name, e.g. "Base" */
			self::send( $chat_id, sprintf( __( 'Tap a color for the %s:', 'yeffoprint-core' ), $slot['name'] ) . ( '' !== (string) ( $slot['hint'] ?? '' ) ? "\n" . $slot['hint'] : '' ), null, $keyboard );
			return;
		}

		$addons = YeffoPrint_Print_Meta::get_addons( $print_id );
		if ( null === $draft['text'] ) {
			if ( ! $addons['text'] ) {
				$draft['text'] = '';
			} else {
				$session['print_draft'] = $draft;
				self::save_session( $chat_id, $session );
				self::send( $chat_id, sprintf(
					/* translators: 1: where on the print, e.g. "lid", 2: price */
					__( 'Add custom %1$s text? (%2$s)', 'yeffoprint-core' ),
					$addons['area'],
					$addons['text_price'] > 0 ? '+' . self::money( $addons['text_price'] ) : __( 'free', 'yeffoprint-core' )
				), null, [ [
					[ 'text' => __( 'Yes', 'yeffoprint-core' ), 'callback_data' => 'tgco:pt:1' ],
					[ 'text' => __( 'No thanks', 'yeffoprint-core' ), 'callback_data' => 'tgco:pt:0' ],
				] ] );
				return;
			}
		}

		if ( '' !== $draft['text'] && ! $draft['text_color'] ) {
			$keyboard = self::color_keyboard( 'px', array_values( YeffoPrint_Print_Meta::get_filaments() ), false ); // Lid text color has no extra charge.
			if ( $keyboard ) {
				/* translators: %s: where on the print, e.g. "lid" */
				self::send( $chat_id, sprintf( __( 'Tap a color for the %s text:', 'yeffoprint-core' ), $addons['area'] ), null, $keyboard );
				return;
			}
			$draft['text'] = ''; // No color to print it in.
		}

		if ( ! $draft['quantity'] ) {
			$session['print_draft'] = $draft;
			$session['awaiting']    = 'print_qty';
			self::save_session( $chat_id, $session );
			$buttons = [];
			foreach ( [ 1, 2, 3, 4, 5 ] as $n ) {
				$buttons[] = [ 'text' => (string) $n, 'callback_data' => 'tgco:pq:' . $n ];
			}
			self::send( $chat_id, __( 'How many? Tap or type a number:', 'yeffoprint-core' ), null, [ $buttons ] );
			return;
		}

		// Done: check it like checkout would, then add it to the order.
		$check = self::print_payload( $draft );
		$picks = YeffoPrint_Print_Meta::resolve_picks( $print_id, $check['colors'] );
		if ( is_wp_error( $picks ) ) {
			unset( $session['print_draft'] );
			self::save_session( $chat_id, $session );
			self::send( $chat_id, $picks->get_error_message() . ' ' . __( 'Tap 🖨 to pick it again.', 'yeffoprint-core' ), null, [ [ [ 'text' => __( '🖨 Add a 3D print', 'yeffoprint-core' ), 'callback_data' => 'tgco:p:' . $print_id ] ] ] );
			return;
		}

		$session['prints']   = array_values( (array) ( $session['prints'] ?? [] ) );
		$session['prints'][] = $draft;
		unset( $session['print_draft'], $session['awaiting'] );
		/* translators: %s: e.g. "2 × Ghost Can Holder (12oz Can · Base: Black) $28.00" */
		self::send( $chat_id, sprintf( __( 'Added: %s', 'yeffoprint-core' ), self::print_line( $draft ) ) );
		self::after_print_change( $chat_id, $session );
	}

	/** After a print is added or removed: read the chat again for email/address and show the next question or the summary. */
	private static function after_print_change( int $chat_id, array $session ): void {
		$session['stage']  = 'collecting';
		$session['rounds'] = max( 0, (int) ( $session['rounds'] ?? 0 ) - 1 ); // The re-read may repeat a question; don't spend a round on it.
		self::save_session( $chat_id, $session );
		self::run_soon( $chat_id, 0 );
	}

	/** What Create Order takes for one print (Manual_Order_Creator's 'prints'). */
	private static function print_payload( array $draft ): array {
		return [
			'print_id'   => (int) $draft['print_id'],
			'size'       => (string) ( $draft['size'] ?? '' ),
			'colors'     => array_map( 'intval', (array) $draft['colors'] ),
			'text'       => (string) ( $draft['text'] ?? '' ),
			'text_color' => (int) ( $draft['text_color'] ?? 0 ),
			'quantity'   => max( 1, (int) $draft['quantity'] ),
		];
	}

	/** "2 × Ghost Can Holder (12oz Can · Base: Black · Lid text “Bob”) $28.00" */
	private static function print_line( array $draft ): string {
		$print_id = (int) $draft['print_id'];
		$bits     = [];
		if ( '' !== (string) ( $draft['size'] ?? '' ) ) {
			$bits[] = $draft['size'];
		}
		$picks = YeffoPrint_Print_Meta::resolve_picks( $print_id, array_map( 'intval', (array) $draft['colors'] ) );
		if ( ! is_wp_error( $picks ) ) {
			foreach ( $picks as $pick ) {
				$bits[] = $pick['slot'] . ': ' . preg_replace( '/\s*\(.*\)$/', '', $pick['name'] );
			}
		} else {
			$picks = [];
		}
		$text = (string) ( $draft['text'] ?? '' );
		if ( '' !== $text ) {
			$bits[] = sprintf( '%s text “%s”', ucfirst( YeffoPrint_Print_Meta::get_addons( $print_id )['area'] ), $text );
		}
		$unit = YeffoPrint_Print_Meta::unit_price( $print_id, $picks, (string) ( $draft['size'] ?? '' ), $text, 0 );
		return sprintf( '%d × %s%s %s', (int) $draft['quantity'], get_the_title( $print_id ), $bits ? ' (' . implode( ' · ', $bits ) . ')' : '', self::money( $unit * (int) $draft['quantity'] ) );
	}

	/* ---------- Telegram messages after the pay link ---------- */

	private const META_CHAT       = '_yp_tgco_chat';
	private const META_CONNECTION = '_yp_tgco_connection';
	private const META_PAID_SENT  = '_yp_tgco_paid_sent';
	private const LINK_EMAIL_META = '_yp_tgco_link_emailed';

	/** Remembers which chat an order came from, so payment and shipping updates go back there. */
	private static function remember_chat( \WC_Order $order, int $chat_id, string $connection_id ): void {
		$order->update_meta_data( self::META_CHAT, $chat_id );
		$order->update_meta_data( self::META_CONNECTION, $connection_id );
		$order->save_meta_data();
		// The bot's own chat: the existing order updates (shipped, proof ready) go there too.
		if ( $chat_id < 0 && class_exists( 'YeffoPrint_Telegram_Order_Notifications' ) ) {
			YeffoPrint_Telegram_Order_Notifications::link( $order, abs( $chat_id ) );
		}
	}

	/** Direct request: "send order confirmation through telegram once they pay". */
	public static function on_paid( int $order_id ): void {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order || ! $order->get_date_paid() ) {
			return;
		}
		$chat_id = (int) $order->get_meta( self::META_CHAT, true );
		if ( ! $chat_id || $order->get_meta( self::META_PAID_SENT, true ) ) {
			return;
		}
		$order->update_meta_data( self::META_PAID_SENT, time() );
		$order->save_meta_data();

		$proof = false;
		foreach ( $order->get_items() as $item ) {
			if ( (int) $item->get_meta( '_yp_custom_order_id', true ) ) {
				$proof = true;
			}
		}
		$text = sprintf( __( '✅ Payment received for order #%s. Thank you!', 'yeffoprint-core' ), $order->get_order_number() ) . "\n\n" . ( $proof
			? __( 'Next we’ll design your labels and send you a proof to approve before anything is printed.', 'yeffoprint-core' )
			: __( 'We’re getting it ready and will message you here when it ships.', 'yeffoprint-core' ) );
		self::send( $chat_id, $text, (string) $order->get_meta( self::META_CONNECTION, true ) );
	}

	/** Direct request: "...and when shipping updates happen". The bot's own chat already gets this (Order_Notifications). */
	public static function on_shipped( int $order_id ): void {
		$order   = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		$chat_id = $order instanceof \WC_Order ? (int) $order->get_meta( self::META_CHAT, true ) : 0;
		if ( $chat_id <= 0 ) {
			return;
		}
		if ( class_exists( 'YeffoPrint_Order_Addon_Shipping' ) && ! YeffoPrint_Order_Addon_Shipping::is_email_sender( $order ) ) {
			return; // One message per box for a linked add-on group.
		}
		$lines = [ sprintf( __( '📦 Your order #%s has shipped!', 'yeffoprint-core' ), $order->get_order_number() ) ];
		foreach ( YeffoPrint_Order_Tracking::get_shipments( $order ) as $shipment ) {
			$lines[] = trim( $shipment['carrier_label'] . ' ' . $shipment['tracking_number'] );
			if ( $shipment['carrier_url'] ) {
				$lines[] = $shipment['carrier_url'];
			}
		}
		self::send( $chat_id, implode( "\n", $lines ), (string) $order->get_meta( self::META_CONNECTION, true ) );
	}

	/**
	 * Direct request: "Will the bot link the telegram account to that email for
	 * future orders?" Only once the account's owner says so: the account's
	 * email gets a Connect Telegram link (the same one-time code as My Account ›
	 * Connect Telegram, good for a few days), because a linked chat can see the
	 * account's orders and approve its proofs. At most one email a month.
	 *
	 * @return bool true when the email went out.
	 */
	private static function email_link_offer( \WC_Order $order ): bool {
		$user_id = (int) $order->get_customer_id();
		if ( ! $user_id || YeffoPrint_Telegram_Account_Link::is_linked( $user_id ) ) {
			return false;
		}
		$last = (int) get_user_meta( $user_id, self::LINK_EMAIL_META, true );
		if ( $last && $last > time() - 30 * DAY_IN_SECONDS ) {
			return false;
		}
		$user = get_user_by( 'id', $user_id );
		$url  = $user ? YeffoPrint_Telegram_Account_Link::deep_link_url( YeffoPrint_Telegram_Account_Link::generate_code( $user_id, 5 * DAY_IN_SECONDS ) ) : '';
		if ( '' === $url || ! function_exists( 'WC' ) || ! WC()->mailer() ) {
			return false;
		}

		$mailer  = WC()->mailer();
		$heading = __( 'Connect your Telegram', 'yeffoprint-core' );
		$body    = '<p>' . esc_html( sprintf( __( 'Thanks for your order #%s on Telegram!', 'yeffoprint-core' ), $order->get_order_number() ) ) . '</p>'
			. '<p>' . esc_html__( 'Tap the button below to connect your Telegram to your account. Next time the bot will already know your email and address, and it can message you about your orders.', 'yeffoprint-core' ) . '</p>'
			. '<p><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:12px 20px;background:#229ED9;color:#fff;border-radius:6px;text-decoration:none;font-weight:bold;">' . esc_html__( 'Connect Telegram', 'yeffoprint-core' ) . '</a></p>'
			. '<p>' . esc_html__( 'The link works for 5 days. If you didn’t order on Telegram, you can ignore this email.', 'yeffoprint-core' ) . '</p>';
		$sent = $mailer->send( $user->user_email, $heading, $mailer->wrap_message( $heading, $body ), [ 'Content-Type: text/html; charset=UTF-8' ] );
		if ( $sent ) {
			update_user_meta( $user_id, self::LINK_EMAIL_META, time() );
		}
		return (bool) $sent;
	}

	/** "Edit my order": back to collecting, keeping the order it will replace. */
	private static function begin_edit( int $chat_id, array $session ): void {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) ( $session['order_id'] ?? 0 ) ) : null;
		if ( ! $order instanceof \WC_Order || ! YeffoPrint_Manual_Order_Creator::is_editable( $order ) ) {
			self::send( $chat_id, __( 'This order is already paid, so it can’t be changed here. Message us and we’ll add to it.', 'yeffoprint-core' ) );
			return;
		}
		$session['stage']  = 'collecting';
		$session['rounds'] = 0;
		unset( $session['asked_size'], $session['asked_material'] );
		self::save_session( $chat_id, $session );
		self::send( $chat_id, __( 'Sure! Tell me what to add or change, like “add 50 NAD+ 500mg” or “make the BPC-157 200”.', 'yeffoprint-core' ) );
	}

	/** After the pay link: keep the chat's order open for edits until it's paid. */
	private static function await_payment( int $chat_id, string $connection_id, \WC_Order $order, ?array $result ): void {
		$session = self::session( $chat_id ) ?: [ 'rounds' => 0, 'record_id' => 0, 'picks' => [], 'token' => '' ];
		$session['stage']         = 'awaiting_payment';
		$session['connection_id'] = $connection_id;
		$session['order_id']      = $order->get_id();
		$session['pending']       = false;
		if ( $result ) {
			$session['result'] = $result;
		}
		self::save_session( $chat_id, $session );

		self::remember_chat( $order, $chat_id, $connection_id );
		if ( ! YeffoPrint_Telegram_Account_Link::get_user_id_for_chat( abs( $chat_id ) ) && self::email_link_offer( $order ) ) {
			self::send( $chat_id, __( 'I also emailed you a link to connect this Telegram to your account. Tap it and next time I won’t need to ask for your email or address.', 'yeffoprint-core' ), $connection_id );
		}
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
		$result['prints'] = array_values( (array) ( $session['prints'] ?? [] ) );
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
			$buttons = [ [
				[ 'text' => __( '✅ Looks good, send my payment link', 'yeffoprint-core' ), 'callback_data' => 'tgco:ok' ],
			], [
				[ 'text' => __( '✏️ Change something', 'yeffoprint-core' ), 'callback_data' => 'tgco:edit' ],
			], [
				[ 'text' => __( '📋 Add a template label', 'yeffoprint-core' ), 'callback_data' => 'tgco:tl' ],
			] ];
			if ( self::orderable_prints() ) {
				$buttons[] = [ [ 'text' => __( '🖨 Add a 3D print', 'yeffoprint-core' ), 'callback_data' => 'tgco:pl:0' ] ];
			}
			if ( $result['prints'] ) {
				$buttons[] = [ [ 'text' => __( '🗑 Remove the 3D prints', 'yeffoprint-core' ), 'callback_data' => 'tgco:pr' ] ];
			}
			self::send( $chat_id, self::summary_text( $result, $context ), null, $buttons );
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
			'order_id'      => (int) ( $old['order_id'] ?? 0 ) ?: self::unpaid_order_for_chat( $chat_id ), // An unpaid order this one replaces.
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

		// Pause all sales stops labels only; 3D prints use a different printer and stay open.
		$has_labels = (bool) array_filter( $result['items'], static function ( $item ) { return 'unclear' !== $item['kind']; } );
		if ( $has_labels && class_exists( 'YeffoPrint_Sales_Pause' ) && YeffoPrint_Sales_Pause::is_paused() ) {
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

		// An edit of an order they haven't paid yet: this order replaces it.
		$old      = ! empty( $session['order_id'] ) ? wc_get_order( (int) $session['order_id'] ) : null;
		$replaced = $old instanceof \WC_Order && $old->get_id() !== $order->get_id() && YeffoPrint_Manual_Order_Creator::is_editable( $old ) ? $old : null;
		if ( $replaced ) {
			$order->add_order_note( sprintf( __( 'Replaces unpaid order #%s (the customer changed it in Telegram).', 'yeffoprint-core' ), $replaced->get_order_number() ) );
			$replaced->update_status( 'cancelled', sprintf( __( 'Replaced by order #%s: the customer changed their labels in Telegram before paying.', 'yeffoprint-core' ), $order->get_order_number() ) );
		}
		$order->save();

		$record_id = (int) ( $session['record_id'] ?? 0 );
		if ( $record_id ) {
			delete_post_meta( $record_id, self::META_PAY_LINK_SENT ); // An edit sends the new link too.
			YeffoPrint_Email_Orders::mark_created( $record_id, $order->get_id() ); // Sends the pay link (on_draft_created()).
		} else {
			self::send_pay_link( $chat_id, (string) $session['connection_id'], $order );
			self::await_payment( $chat_id, (string) $session['connection_id'], $order, $result );
		}

		YeffoPrint_Telegram_Admin_Alerts::notify(
			sprintf( "Telegram order #%s %s\n%s · %s\n%s", $order->get_order_number(), $replaced ? 'replaces #' . $replaced->get_order_number() . ' (customer edit)' : 'created', $context['label'], implode( ' · ', array_filter( [ YeffoPrint_Order_Label_Count::summary( $order ), self::money( (float) $order->get_total() ) ] ) ), $chat_id > 0 ? 'Pay link sent in your chat.' : 'They ordered in the bot chat; pay link sent there.' ),
			[ 'section' => 'orders', 'order_id' => $order->get_id() ]
		);
	}

	/** Something the bot can't do itself: the draft waits in Email Orders, and Create Order there sends the pay link. */
	private static function hand_to_owner( int $chat_id, array $session, array $context, string $reason ): void {
		if ( ! empty( $session['prints'] ) ) {
			// Email Orders drafts hold labels only: the prints are listed for Jeff to add.
			$reason .= "\nAlso add these 3D prints (Add items on the order):\n" . implode( "\n", array_map( [ self::class, 'print_line' ], (array) $session['prints'] ) );
		}
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
		if ( ! $items && empty( $result['prints'] ) ) {
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
				// Direct feedback: "When using a template order, we don't need a brand name."
				'optional_field_ids' => self::brand_field_ids( (int) $group[0]['template_id'] ),
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
			// Direct request: "Skip proof if they order a template." Only custom design labels get one.
			'requires_proof'            => (bool) $custom,
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

		// Template-only order: the first template makes the order. With custom labels, every template
		// goes on afterwards with Add items, so none of them gets the custom design's proof.
		if ( $groups && ! $custom ) {
			$payload['template'] = $template_payload( array_shift( $groups ) );
		}
		if ( ! empty( $result['prints'] ) ) {
			$payload['prints'] = array_map( [ self::class, 'print_payload' ], (array) $result['prints'] );
		}

		$created = YeffoPrint_Manual_Order_Creator::create( $payload );
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		$order = $created['order'];

		// Create Order takes one template design per order; the others go on with Add items, as Jeff would.
		foreach ( $groups as $group ) {
			$added = YeffoPrint_Manual_Order_Creator::add_items( $order, [ 'template' => $template_payload( $group ), 'requires_proof' => false ] );
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
		$record        = YeffoPrint_Email_Orders::format( get_post( $record_id ) );
		$sent          = self::send_pay_link( $chat_id, $connection_id, $order );
		self::await_payment( $chat_id, $connection_id, $order, is_array( $session['result'] ?? null ) ? $session['result'] : $record['result'] );
		if ( $sent ) {
			update_post_meta( $record_id, self::META_PAY_LINK_SENT, 1 );
		} else {
			YeffoPrint_Telegram_Admin_Alerts::notify(
				sprintf( "Couldn’t send the pay link for order #%s in Telegram.\nCopy it from the order and send it yourself.", $order->get_order_number() ),
				[ 'section' => 'orders', 'order_id' => $order->get_id() ]
			);
		}
	}

	private static function send_pay_link( int $chat_id, string $connection_id, \WC_Order $order ): bool {
		$labels = YeffoPrint_Order_Label_Count::summary( $order );
		$proof  = false;
		foreach ( $order->get_items() as $item ) {
			$proof = $proof || (int) $item->get_meta( '_yp_custom_order_id', true ) > 0;
		}
		$text   = sprintf(
			/* translators: 1: order number, 2: label count and order total, e.g. "120 labels, $54.00", 3: pay link, 4: proof note */
			__( "Your order #%1\$s is ready (%2\$s before shipping).\n\nYou can check your address, choose shipping and pay here:\n%3\$s\n\n%4\$sNeed to add or change something before you pay? Tap Edit my order.", 'yeffoprint-core' ),
			$order->get_order_number(),
			( '' !== $labels ? $labels . ', ' : '' ) . self::money( (float) $order->get_total() ),
			$order->get_checkout_payment_url(),
			$proof ? __( "We'll send a proof to approve before anything is printed. ", 'yeffoprint-core' ) : ''
		);
		return self::send( $chat_id, $text, $connection_id, [ [
			[ 'text' => __( '✏️ Edit my order', 'yeffoprint-core' ), 'callback_data' => 'tgco:change' ],
		] ] );
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

		$prints = (array) ( $result['prints'] ?? [] );
		if ( ! $labels && ! $prints ) {
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

		// Direct request: "have the bot ask for missing fields." Required template fields the chat left
		// blank. Color choices fall back to their default, so they're never asked.
		$field_gaps = [];
		foreach ( $labels as $item ) {
			if ( 'template' !== $item['kind'] || ! $item['template_id'] ) {
				continue;
			}
			$blank = [];
			foreach ( YeffoPrint_Field_Schema::get_with_colors( $item['template_id'] ) as $field ) {
				if ( ! empty( $field['required'] ) && ! in_array( $field['type'] ?? '', [ 'color', 'color_choice' ], true ) && ! self::is_brand_field( $field ) && '' === trim( (string) ( $item['fields'][ $field['id'] ] ?? '' ) ) ) {
					$blank[] = (string) $field['label'];
				}
			}
			if ( $blank ) {
				$first        = $item['fields'] ? reset( $item['fields'] ) : '';
				$field_gaps[] = implode( ', ', $blank ) . ' for ' . $item['template_title'] . ( '' !== $first ? ' (' . $first . ')' : '' );
			}
		}
		foreach ( array_slice( $field_gaps, 0, 3 ) as $gap ) {
			$lines[] = $gap;
		}
		if ( count( $field_gaps ) > 3 ) {
			$lines[] = sprintf( __( '…and the same for %d more template labels', 'yeffoprint-core' ), count( $field_gaps ) - 3 );
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

	/** Template labels never need a brand (Jeff), so a required Brand field is neither asked nor enforced. */
	private static function is_brand_field( array $field ): bool {
		return false !== stripos( (string) ( $field['id'] ?? '' ), 'brand' ) || false !== stripos( (string) ( $field['label'] ?? '' ), 'brand' );
	}

	/** @return string[] */
	private static function brand_field_ids( int $template_id ): array {
		$ids = [];
		foreach ( YeffoPrint_Field_Schema::get_with_colors( $template_id ) as $field ) {
			if ( self::is_brand_field( $field ) ) {
				$ids[] = (string) $field['id'];
			}
		}
		return $ids;
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
		$count = 0;
		foreach ( $result['items'] as $item ) {
			if ( 'unclear' !== $item['kind'] ) {
				$count += (int) $item['quantity'];
			}
			$what    = 'template' === $item['kind']
				? $item['template_title'] . ( $item['fields'] ? ': ' . implode( ', ', $item['fields'] ) : '' )
				: $item['compound_strength'];
			$lines[] = sprintf( '• %d × %s (%s, %s)', $item['quantity'], $what, $sizes[ $item['size_id'] ] ?? '', $materials[ $item['material_id'] ] ?? '' );
		}
		if ( $count ) {
			/* translators: %s: e.g. "120 labels" */
			$lines[] = sprintf( __( 'In total: %s', 'yeffoprint-core' ), YeffoPrint_Order_Label_Count::format( [ 'labels' => $count ] ) );
		}
		foreach ( (array) ( $result['prints'] ?? [] ) as $print ) {
			$lines[] = '• ' . self::print_line( $print );
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
