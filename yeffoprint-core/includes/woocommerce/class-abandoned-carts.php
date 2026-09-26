<?php
/**
 * Abandoned cart recovery — direct request: "Can we work on abandoned
 * cart recovery?" (option chosen from the mockups: two emails showing
 * the customer's own cart, an optional discount code in the second,
 * Telegram nudges for linked customers, owner alerts, and an admin
 * page).
 *
 * WooCommerce 11 ships its own recovery email, but it only schedules
 * for classic-checkout orders left `pending` — this store runs the
 * block Checkout, whose half-finished orders sit in `checkout-draft`,
 * which that feature skips. So nothing was ever sent, and nothing kept
 * a record of who left.
 *
 * Capture: a cart is only tracked once the shopper types an email at
 * checkout (or reaches checkout signed in). The block Checkout pushes
 * the email through the Store API's update-customer route
 * (capture_from_store_api()), and assets/blocks/abandoned-cart-
 * checkout.js also sends it through the cart-extensions endpoint the
 * moment the field changes, the same round trip class-express-order.php
 * uses — whichever lands first creates the row, the other just
 * refreshes it. Nobody who never typed an email at checkout is ever
 * contacted.
 *
 * Sending: same 5-minute sweep-over-a-table shape as class-telegram-
 * express-alerts.php (WP-Cron, so a quiet night can make a reminder a
 * few minutes late). Email 1 goes out after DELAY1 minutes of no cart
 * activity, Email 2 (with a single-use code for that email address
 * only, when the discount is on) after DELAY2 hours. The owner's
 * Telegram alert lands ~5 minutes before Email 1 with Send now / Don't
 * send / Send code instead buttons (class-telegram-callback-handler.php
 * routes `ac_*` taps to handle_owner_action()).
 *
 * Stopping: the row closes the moment an order from that cart (or any
 * order from that email) is paid, when the shopper taps "Don't remind
 * me again" (which also opts that email out for good), when the owner
 * taps Don't send, when any other order for that customer shows up
 * after the cart was left — paid or not, e.g. a pay-link order the
 * owner built from their custom design request, which would otherwise
 * get reminders (and a code) for the stale cart it replaced — or on its
 * own once both reminders are out. Paused
 * while Away Mode is on, and never sends for a cart left more than
 * STALE_DAYS ago, so turning recovery on (or coming back from Away
 * Mode) never mass-mails old carts.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Abandoned_Carts {

	// Must match the NAMESPACE the checkout script sends.
	const NAMESPACE = 'yeffoprint-abandoned-cart';

	const SETTINGS_OPTION = 'yeffoprint_abandoned_cart_settings';
	const OPTOUTS_OPTION  = 'yeffoprint_abandoned_cart_optouts';

	/** Order meta linking a placed order back to its cart row. */
	const ORDER_META = '_yp_abandoned_cart_id';

	/** Coupon meta marking codes this class generated. */
	const COUPON_META = '_yp_abandoned_cart_coupon';

	const STATUS_OPEN      = 'open';
	const STATUS_RECOVERED = 'recovered';  // Paid after at least one reminder.
	const STATUS_PURCHASED = 'purchased';  // Paid before any reminder went out — never really abandoned.
	const STATUS_OPTED_OUT = 'opted_out';
	const STATUS_STOPPED   = 'stopped';    // Owner tapped Don't send.
	const STATUS_ORDERED   = 'ordered';    // Another order for this customer was placed after the cart was left.

	private const DB_VERSION        = '1.0';
	private const DB_VERSION_OPTION = 'yeffoprint_abandoned_cart_db_version';

	private const HOOK     = 'yeffoprint_abandoned_cart_sweep';
	private const SCHEDULE = 'yeffoprint_five_minutes';

	private const SESSION_ROW_KEY  = 'yp_abandoned_cart_id';
	private const SESSION_HASH_KEY = 'yp_abandoned_cart_hash';

	private const RECOVER_QUERY = 'yp_recover_cart';
	private const OPTOUT_QUERY  = 'yp_cart_optout';

	/** Never send for a cart whose last activity is older than this. */
	private const STALE_DAYS = 3;

	/** Rows (and their emails) are deleted after this. */
	private const RETENTION_DAYS = 90;

	/** The owner's heads-up lands this long before Email 1. */
	private const OWNER_HEADS_UP = 5 * MINUTE_IN_SECONDS;

	/** On Hold counts: a Venmo/Zelle order waits there until the owner confirms payment. */
	private const PAID_STATUSES = [ 'processing', 'on-hold', 'completed', 'in-production', 'shipped', 'delivered' ];

	/** Orders in these states don't count as "they already ordered". */
	private const NOT_ORDERED_STATUSES = [ 'checkout-draft', 'failed', 'cancelled', 'refunded', 'trash' ];

	/** Recalculating totals fires woocommerce_cart_updated again. */
	private static bool $refreshing = false;

	public function __construct() {
		add_action( 'init', [ __CLASS__, 'maybe_install' ] );

		add_filter( 'cron_schedules', [ YeffoPrint_Telegram_Express_Alerts::class, 'add_schedule' ] ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		add_action( self::HOOK, [ $this, 'sweep' ] );
		add_action( 'init', [ $this, 'ensure_scheduled' ] );

		add_action( 'woocommerce_store_api_cart_update_customer_from_request', [ $this, 'capture_from_store_api' ], 10, 1 );
		add_action( 'woocommerce_blocks_loaded', [ $this, 'register_update_callback' ] );
		add_action( 'template_redirect', [ $this, 'capture_signed_in_checkout' ], 20 );
		add_action( 'woocommerce_cart_updated', [ $this, 'refresh_snapshot' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );

		add_action( 'template_redirect', [ $this, 'handle_links' ], 5 );

		add_action( 'woocommerce_checkout_order_processed', [ $this, 'link_order' ], 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'link_order' ], 10, 1 );
		add_action( 'woocommerce_order_status_changed', [ $this, 'on_status_changed' ], 10, 4 );
	}

	/* ---------- Settings ---------- */

	/**
	 * @return array{enabled:bool, delay1_minutes:int, delay2_hours:int,
	 *   discount_enabled:bool, discount_percent:float, discount_hours:int,
	 *   telegram_nudge:bool, owner_alerts:bool}
	 */
	public static function settings(): array {
		$defaults = [
			'enabled'          => true,
			'delay1_minutes'   => 60,
			'delay2_hours'     => 24,
			'discount_enabled' => true,
			'discount_percent' => 10.0,
			'discount_hours'   => 48,
			'telegram_nudge'   => true,
			'owner_alerts'     => true,
		];

		$saved = get_option( self::SETTINGS_OPTION, [] );
		$s     = array_merge( $defaults, is_array( $saved ) ? $saved : [] );

		return [
			'enabled'          => (bool) $s['enabled'],
			'delay1_minutes'   => max( 15, (int) $s['delay1_minutes'] ),
			'delay2_hours'     => max( 2, (int) $s['delay2_hours'] ),
			'discount_enabled' => (bool) $s['discount_enabled'],
			'discount_percent' => min( 90.0, max( 1.0, (float) $s['discount_percent'] ) ),
			'discount_hours'   => max( 1, (int) $s['discount_hours'] ),
			'telegram_nudge'   => (bool) $s['telegram_nudge'],
			'owner_alerts'     => (bool) $s['owner_alerts'],
		];
	}

	public static function save_settings( array $input ): array {
		$current = self::settings();
		foreach ( $current as $key => $value ) {
			if ( array_key_exists( $key, $input ) ) {
				$current[ $key ] = is_bool( $value ) ? (bool) $input[ $key ] : $input[ $key ];
			}
		}
		update_option( self::SETTINGS_OPTION, $current, false );
		return self::settings();
	}

	/** Recovery is on and production isn't paused. */
	public static function is_sending(): bool {
		return self::settings()['enabled'] && ! YeffoPrint_Admin_Menu::away_mode();
	}

	/* ---------- Table ---------- */

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'yeffoprint_abandoned_carts';
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
			token VARCHAR(40) NOT NULL,
			email VARCHAR(190) NOT NULL,
			first_name VARCHAR(100) NOT NULL DEFAULT '',
			last_name VARCHAR(100) NOT NULL DEFAULT '',
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			cart LONGTEXT NOT NULL,
			summary LONGTEXT NOT NULL,
			total DECIMAL(12,2) NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'open',
			stage TINYINT UNSIGNED NOT NULL DEFAULT 0,
			coupon_code VARCHAR(40) NOT NULL DEFAULT '',
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			recovered_total DECIMAL(12,2) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			owner_alerted_at DATETIME NULL,
			email1_at DATETIME NULL,
			email2_at DATETIME NULL,
			telegram_at DATETIME NULL,
			clicked_at DATETIME NULL,
			closed_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY status (status),
			KEY email (email),
			KEY created_at (created_at)
		) {$charset_collate};" );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	public static function get_row( int $id ): ?array {
		global $wpdb;
		$table = self::table_name();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ?: null;
	}

	private static function get_row_by_token( string $token ): ?array {
		if ( '' === $token ) {
			return null;
		}
		global $wpdb;
		$table = self::table_name();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token = %s", $token ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ?: null;
	}

	private static function update_row( int $id, array $data ): void {
		global $wpdb;
		$wpdb->update( self::table_name(), $data, [ 'id' => $id ] );
	}

	private static function now(): string {
		return current_time( 'mysql', true );
	}

	/** DATETIME columns are stored in UTC. */
	private static function ts( ?string $datetime ): int {
		return $datetime ? (int) strtotime( $datetime . ' UTC' ) : 0;
	}

	/* ---------- Capture ---------- */

	public function capture_from_store_api( $customer ): void {
		if ( $customer instanceof \WC_Customer ) {
			self::capture(
				(string) $customer->get_billing_email(),
				(string) $customer->get_billing_first_name(),
				(string) $customer->get_billing_last_name()
			);
		}
	}

	public function register_update_callback(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			return;
		}

		woocommerce_store_api_register_update_callback( [
			'namespace' => self::NAMESPACE,
			'callback'  => static function ( $data ): void {
				if ( is_array( $data ) ) {
					self::capture(
						(string) ( $data['email'] ?? '' ),
						(string) ( $data['first_name'] ?? '' ),
						(string) ( $data['last_name'] ?? '' )
					);
				}
			},
		] );
	}

	/** A signed-in shopper's email is already on the checkout form, so reaching it counts. */
	public function capture_signed_in_checkout(): void {
		if ( ! is_user_logged_in() || ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url() ) {
			return;
		}
		$user = wp_get_current_user();
		self::capture( (string) $user->user_email, (string) $user->first_name, (string) $user->last_name );
	}

	private static function capture( string $email, string $first_name = '', string $last_name = '' ): void {
		$email = strtolower( trim( sanitize_email( $email ) ) );
		if ( '' === $email || ! is_email( $email ) || ! function_exists( 'WC' ) || ! WC()->session || ! WC()->cart || WC()->cart->is_empty() ) {
			return;
		}
		if ( ! self::settings()['enabled'] || self::is_opted_out( $email ) ) {
			return;
		}

		self::maybe_install();

		$row_id = (int) WC()->session->get( self::SESSION_ROW_KEY );
		$row    = $row_id ? self::get_row( $row_id ) : null;
		$now    = self::now();

		$data = array_merge( self::snapshot_fields(), [
			'email'      => $email,
			'user_id'    => get_current_user_id(),
			'updated_at' => $now,
		] );
		if ( '' !== trim( $first_name ) ) {
			$data['first_name'] = sanitize_text_field( $first_name );
		}
		if ( '' !== trim( $last_name ) ) {
			$data['last_name'] = sanitize_text_field( $last_name );
		}

		if ( $row && self::STATUS_OPEN === $row['status'] ) {
			self::update_row( (int) $row['id'], $data );
			WC()->session->set( self::SESSION_HASH_KEY, md5( $data['cart'] ) );
			return;
		}

		global $wpdb;
		$wpdb->insert( self::table_name(), array_merge( [
			'token'      => wp_generate_password( 32, false ),
			'first_name' => '',
			'last_name'  => '',
			'status'     => self::STATUS_OPEN,
			'created_at' => $now,
		], $data ) );

		WC()->session->set( self::SESSION_ROW_KEY, (int) $wpdb->insert_id );
		WC()->session->set( self::SESSION_HASH_KEY, md5( $data['cart'] ) );
	}

	/** Keeps a tracked row in step with the cart as the shopper keeps editing it. */
	public function refresh_snapshot(): void {
		if ( self::$refreshing || ! function_exists( 'WC' ) || ! WC()->session || ! WC()->cart ) {
			return;
		}
		$row_id = (int) WC()->session->get( self::SESSION_ROW_KEY );
		if ( ! $row_id || WC()->cart->is_empty() ) {
			return;
		}

		$cart = wp_json_encode( WC()->cart->get_cart_for_session() );
		if ( md5( (string) $cart ) === WC()->session->get( self::SESSION_HASH_KEY ) ) {
			return;
		}

		$row = self::get_row( $row_id );
		if ( ! $row || self::STATUS_OPEN !== $row['status'] ) {
			WC()->session->set( self::SESSION_ROW_KEY, null );
			return;
		}

		$data               = self::snapshot_fields( false );
		$data['updated_at'] = self::now();
		self::update_row( $row_id, $data );
		WC()->session->set( self::SESSION_HASH_KEY, md5( $data['cart'] ) );
	}

	/** @return array{cart:string, summary:string, total:float} */
	private static function snapshot_fields( bool $recalculate = true ): array {
		$cart = WC()->cart;
		if ( $recalculate ) {
			self::$refreshing = true;
			$cart->calculate_totals();
			self::$refreshing = false;
		}

		return [
			'cart'    => (string) wp_json_encode( $cart->get_cart_for_session() ),
			'summary' => (string) wp_json_encode( self::summarize( $cart ) ),
			'total'   => round( (float) $cart->get_total( 'edit' ), 2 ),
		];
	}

	/**
	 * What the emails, Telegram and the admin page show for each line —
	 * worked out now, while the cart's products and prices are loaded,
	 * rather than rebuilt from raw cart data at send time.
	 *
	 * @return array<int, array{name:string, detail:string, quantity:string, total:float, image:string}>
	 */
	private static function summarize( \WC_Cart $cart ): array {
		$fee_id = YeffoPrint_Custom_Design_Fee_Product::get_existing_product_id();
		$lines  = [];
		foreach ( $cart->get_cart() as $item ) {
			$product     = $item['data'] ?? null;
			$template_id = (int) ( $item[ YeffoPrint_Cart_Item_Keys::TEMPLATE_ID ] ?? 0 );
			$size_id     = (int) ( $item[ YeffoPrint_Cart_Item_Keys::SIZE_ID ] ?? 0 );
			$material_id = (int) ( $item[ YeffoPrint_Cart_Item_Keys::MATERIAL_ID ] ?? 0 );
			$total_qty   = (int) ( $item[ YeffoPrint_Cart_Item_Keys::TOTAL_QTY ] ?? 0 );

			$name = $template_id ? get_the_title( $template_id ) : ( $product instanceof \WC_Product ? $product->get_name() : '' );

			// Compound/strength tells apart lines that are otherwise the
			// same size and material (one per vial in a custom batch).
			$detail = array_filter( [
				$size_id ? get_the_title( $size_id ) : '',
				$material_id ? get_the_title( $material_id ) : '',
				(string) ( $item[ YeffoPrint_Cart_Item_Keys::COMPOUND_STRENGTH ] ?? '' ),
			] );

			$image = $template_id ? (string) get_the_post_thumbnail_url( $template_id, 'medium' ) : '';
			if ( '' === $image && $product instanceof \WC_Product && $product->get_image_id() ) {
				$image = (string) wp_get_attachment_image_url( $product->get_image_id(), 'medium' );
			}

			$is_labels = $template_id || ! empty( $item[ YeffoPrint_Cart_Item_Keys::CUSTOM_ORDER_ID ] );
			$quantity  = $total_qty > 0 && $is_labels
				/* translators: %s: number of labels */
				? sprintf( _n( '%s label', '%s labels', $total_qty, 'yeffoprint-core' ), number_format_i18n( $total_qty ) )
				/* translators: %s: item quantity */
				: sprintf( __( 'Qty %s', 'yeffoprint-core' ), number_format_i18n( (int) ( $item['quantity'] ?? 1 ) ) );

			$lines[] = [
				'name'     => html_entity_decode( wp_strip_all_tags( (string) $name ), ENT_QUOTES, 'UTF-8' ),
				'detail'   => html_entity_decode( wp_strip_all_tags( implode( ' · ', $detail ) ), ENT_QUOTES, 'UTF-8' ),
				'quantity' => $quantity,
				'total'    => round( (float) ( $item['line_total'] ?? 0 ) + (float) ( $item['line_tax'] ?? 0 ), 2 ),
				'image'    => $image,
				'is_fee'   => $product instanceof \WC_Product && $fee_id && (int) $product->get_id() === $fee_id,
			];
		}
		return $lines;
	}

	public function enqueue(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url() || ! self::settings()['enabled'] ) {
			return;
		}

		$path = 'assets/blocks/abandoned-cart-checkout.js';
		wp_enqueue_script(
			'yeffoprint-abandoned-cart-checkout',
			YEFFOPRINT_CORE_URL . $path,
			[ 'wc-blocks-checkout' ],
			yeffoprint_core_asset_version( $path ),
			true
		);
		wp_localize_script( 'yeffoprint-abandoned-cart-checkout', 'yeffoprintAbandonedCart', [
			'namespace' => self::NAMESPACE,
		] );
	}

	/* ---------- Opt-outs ---------- */

	public static function is_opted_out( string $email ): bool {
		$list = get_option( self::OPTOUTS_OPTION, [] );
		return is_array( $list ) && in_array( strtolower( trim( $email ) ), $list, true );
	}

	private static function opt_out( array $row ): void {
		$list = get_option( self::OPTOUTS_OPTION, [] );
		$list = is_array( $list ) ? $list : [];
		$list[] = strtolower( $row['email'] );
		update_option( self::OPTOUTS_OPTION, array_values( array_unique( $list ) ), false );

		global $wpdb;
		$wpdb->update(
			self::table_name(),
			[ 'status' => self::STATUS_OPTED_OUT, 'closed_at' => self::now() ],
			[ 'email' => $row['email'], 'status' => self::STATUS_OPEN ]
		);
	}

	/* ---------- Recovery / opt-out links ---------- */

	public static function recover_url( array $row ): string {
		return add_query_arg( self::RECOVER_QUERY, rawurlencode( $row['token'] ), home_url( '/' ) );
	}

	public static function optout_url( array $row ): string {
		return add_query_arg( self::OPTOUT_QUERY, rawurlencode( $row['token'] ), home_url( '/' ) );
	}

	public function handle_links(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the unguessable per-cart token is the credential, as with a pay-for-order link.
		if ( isset( $_GET[ self::OPTOUT_QUERY ] ) ) {
			$row = self::get_row_by_token( sanitize_text_field( wp_unslash( $_GET[ self::OPTOUT_QUERY ] ) ) );
			if ( $row ) {
				self::opt_out( $row );
			}
			wp_die(
				esc_html__( "You're all set. We won't send you any more reminders about your cart.", 'yeffoprint-core' ),
				esc_html__( 'Reminders turned off', 'yeffoprint-core' ),
				[ 'response' => 200, 'link_url' => esc_url( home_url( '/' ) ), 'link_text' => esc_html__( 'Back to YeffoDesign', 'yeffoprint-core' ) ]
			);
		}

		if ( ! isset( $_GET[ self::RECOVER_QUERY ] ) ) {
			return;
		}
		$row = self::get_row_by_token( sanitize_text_field( wp_unslash( $_GET[ self::RECOVER_QUERY ] ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $row || ! function_exists( 'WC' ) ) {
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}

		if ( ! $row['clicked_at'] ) {
			self::update_row( (int) $row['id'], [ 'clicked_at' => self::now() ] );
		}

		// Already paid: nothing to recover.
		if ( in_array( $row['status'], [ self::STATUS_RECOVERED, self::STATUS_PURCHASED ], true ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		// They placed the order but the payment never went through — the
		// order's own pay link keeps its number and details.
		$order = $row['order_id'] ? wc_get_order( (int) $row['order_id'] ) : null;
		if ( $order instanceof \WC_Order && $order->needs_payment() ) {
			wp_safe_redirect( $order->get_checkout_payment_url() );
			exit;
		}

		if ( ! WC()->session ) {
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}
		if ( ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}

		self::restore_cart( $row );

		WC()->session->set( self::SESSION_ROW_KEY, (int) $row['id'] );
		WC()->session->set( self::SESSION_HASH_KEY, md5( (string) wp_json_encode( WC()->cart->get_cart_for_session() ) ) );

		if ( WC()->customer && ! WC()->customer->get_billing_email() ) {
			WC()->customer->set_billing_email( $row['email'] );
			if ( $row['first_name'] ) {
				WC()->customer->set_billing_first_name( $row['first_name'] );
			}
			if ( $row['last_name'] ) {
				WC()->customer->set_billing_last_name( $row['last_name'] );
			}
			WC()->customer->save();
		}

		if ( $row['coupon_code'] && ! WC()->cart->has_discount( $row['coupon_code'] ) ) {
			WC()->cart->apply_coupon( $row['coupon_code'] );
		}

		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/**
	 * Replays the saved cart through add_to_cart() with each line's own
	 * custom data, so pricing and item keys rebuild exactly as when the
	 * shopper added them. Skipped when the cart in this browser already
	 * holds the same items (they tapped the link on the same device).
	 */
	private static function restore_cart( array $row ): void {
		$saved = json_decode( (string) $row['cart'], true );
		if ( ! is_array( $saved ) || ! $saved ) {
			return;
		}

		$current = WC()->cart->get_cart_for_session();
		if ( array_keys( $current ) === array_keys( $saved ) ) {
			return;
		}

		WC()->cart->empty_cart();

		$standard = [ 'key', 'product_id', 'variation_id', 'variation', 'quantity', 'data', 'data_hash', 'line_tax_data', 'line_subtotal', 'line_subtotal_tax', 'line_total', 'line_tax' ];
		foreach ( $saved as $item ) {
			if ( empty( $item['product_id'] ) ) {
				continue;
			}
			$custom = array_diff_key( $item, array_flip( $standard ) );

			YeffoPrint_Cart_Pricing::allow_next_add();
			try {
				WC()->cart->add_to_cart(
					(int) $item['product_id'],
					max( 1, (int) ( $item['quantity'] ?? 1 ) ),
					(int) ( $item['variation_id'] ?? 0 ),
					is_array( $item['variation'] ?? null ) ? $item['variation'] : [],
					$custom
				);
			} catch ( \Exception $e ) {
				// A product that's since been removed just doesn't come back.
				unset( $e );
			}
			YeffoPrint_Cart_Pricing::allow_next_add( false );
		}
	}

	/* ---------- Orders ---------- */

	/** @param int|\WC_Order $order */
	public function link_order( $order ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order );
		if ( ! $order instanceof \WC_Order || ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		$row_id = (int) WC()->session->get( self::SESSION_ROW_KEY );
		if ( ! $row_id ) {
			return;
		}

		$order->update_meta_data( self::ORDER_META, $row_id );
		$order->save_meta_data();
		self::update_row( $row_id, [ 'order_id' => $order->get_id() ] );

		// The session keeps pointing at the row: if the payment fails and
		// they retry, it's still the same cart. Once the row closes,
		// capture() and refresh_snapshot() start a new one.
		if ( in_array( $order->get_status(), self::PAID_STATUSES, true ) ) {
			self::close_for_order( $order );
		}
	}

	/**
	 * @param int       $order_id
	 * @param string    $from
	 * @param string    $to
	 * @param \WC_Order $order
	 */
	public function on_status_changed( $order_id, $from, $to, $order ): void {
		if ( $order instanceof \WC_Order && in_array( $to, self::PAID_STATUSES, true ) ) {
			self::close_for_order( $order );
		}
	}

	/**
	 * Closes the cart this order came from, plus any other open cart for
	 * the same email — someone who came back on another device and paid
	 * shouldn't keep getting reminders about the cart they left.
	 */
	private static function close_for_order( \WC_Order $order ): void {
		global $wpdb;
		$table = self::table_name();

		$ids   = [];
		$email = strtolower( (string) $order->get_billing_email() );
		if ( $email ) {
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE email = %s AND status = %s", $email, self::STATUS_OPEN ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$linked = (int) $order->get_meta( self::ORDER_META );
		if ( $linked ) {
			$ids[] = $linked;
		}

		foreach ( array_unique( $ids ) as $id ) {
			$row = self::get_row( $id );
			if ( ! $row || self::STATUS_OPEN !== $row['status'] ) {
				continue;
			}

			$recovered = (int) $row['stage'] > 0 || ! empty( $row['telegram_at'] );

			self::update_row( $id, [
				'status'          => $recovered ? self::STATUS_RECOVERED : self::STATUS_PURCHASED,
				'order_id'        => $order->get_id(),
				'recovered_total' => $recovered ? round( (float) $order->get_total(), 2 ) : 0,
				'closed_at'       => self::now(),
			] );

			if ( $recovered ) {
				$order->add_order_note( __( 'Recovered abandoned cart: the customer came back after a cart reminder.', 'yeffoprint-core' ) );
				self::notify_owner_recovered( $row, $order );
			}
		}
	}

	/**
	 * Any order for this customer (by email, or account when signed in)
	 * created after the cart was left, other than the cart's own checkout
	 * order — that one keeps getting reminders, which send them to its pay
	 * link. Catches orders that never went through this cart: a pay link
	 * the owner built by hand, or one placed on another device.
	 */
	private static function order_placed_since( array $row ): ?\WC_Order {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return null;
		}

		$statuses = array_diff(
			array_map( static function ( string $status ): string {
				return 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
			}, array_keys( wc_get_order_statuses() ) ),
			self::NOT_ORDERED_STATUSES
		);

		$base = [
			'type'         => 'shop_order',
			'status'       => array_values( $statuses ),
			'date_created' => '>=' . self::ts( $row['created_at'] ),
			'orderby'      => 'date',
			'order'        => 'ASC',
			'limit'        => 5,
		];

		$queries = [ array_merge( $base, [ 'billing_email' => $row['email'] ] ) ];
		if ( (int) $row['user_id'] ) {
			$queries[] = array_merge( $base, [ 'customer_id' => (int) $row['user_id'] ] );
		}

		foreach ( $queries as $query ) {
			foreach ( wc_get_orders( $query ) as $order ) {
				if ( ! $order instanceof \WC_Order ) {
					continue;
				}
				if ( (int) $order->get_id() === (int) $row['order_id'] || (int) $order->get_meta( self::ORDER_META ) === (int) $row['id'] ) {
					continue;
				}
				return $order;
			}
		}
		return null;
	}

	/** Closes an open row when the customer has since ordered; returns that order. */
	private static function close_if_ordered( array $row ): ?\WC_Order {
		$order = self::order_placed_since( $row );
		if ( ! $order ) {
			return null;
		}

		self::update_row( (int) $row['id'], [
			'status'    => self::STATUS_ORDERED,
			'order_id'  => $order->get_id(),
			'closed_at' => self::now(),
		] );
		return $order;
	}

	/* ---------- Sweep ---------- */

	public function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), self::SCHEDULE, self::HOOK );
		}
	}

	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	public function sweep(): void {
		self::maybe_install();

		global $wpdb;
		$table = self::table_name();

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! self::is_sending() ) {
			return;
		}

		$settings = self::settings();
		$delay1   = $settings['delay1_minutes'] * MINUTE_IN_SECONDS;
		$delay2   = $settings['delay2_hours'] * HOUR_IN_SECONDS;
		$stale    = gmdate( 'Y-m-d H:i:s', time() - self::STALE_DAYS * DAY_IN_SECONDS );

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s AND stage < 2 AND updated_at >= %s ORDER BY updated_at ASC LIMIT 50", self::STATUS_OPEN, $stale ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( $rows ?: [] as $row ) {
			$idle = time() - self::ts( $row['updated_at'] );

			// An order from this cart that's since been paid closes it
			// even if the status hook was missed.
			$order = $row['order_id'] ? wc_get_order( (int) $row['order_id'] ) : null;
			if ( $order instanceof \WC_Order && in_array( $order->get_status(), self::PAID_STATUSES, true ) ) {
				self::close_for_order( $order );
				continue;
			}

			if ( self::close_if_ordered( $row ) ) {
				continue;
			}

			if ( 0 === (int) $row['stage'] ) {
				if ( $settings['owner_alerts'] && ! $row['owner_alerted_at'] && $idle >= $delay1 - self::OWNER_HEADS_UP ) {
					self::alert_owner( $row );
				}
				if ( $idle >= $delay1 ) {
					self::send_stage( $row, 1 );
				}
				continue;
			}

			if ( 1 === (int) $row['stage'] && $idle >= $delay2 && time() - self::ts( $row['email1_at'] ) >= HOUR_IN_SECONDS ) {
				self::send_stage( $row, 2 );
			}
		}
	}

	/**
	 * Sends Email 1 (plus the Telegram nudge for a linked customer) or
	 * Email 2. Recorded before sending, so a mail outage never loops.
	 */
	public static function send_stage( array $row, int $stage ): bool {
		$row = self::get_row( (int) $row['id'] );
		if ( ! $row || self::STATUS_OPEN !== $row['status'] || (int) $row['stage'] >= $stage || self::is_opted_out( $row['email'] ) ) {
			return false;
		}
		if ( self::close_if_ordered( $row ) ) {
			return false;
		}

		$settings = self::settings();
		$update   = [ 'stage' => $stage, "email{$stage}_at" => self::now() ];

		if ( 2 === $stage && $settings['discount_enabled'] && '' === $row['coupon_code'] ) {
			$code = self::create_coupon( $row, $settings );
			if ( $code ) {
				$update['coupon_code'] = $code;
				$row['coupon_code']    = $code;
			}
		}
		self::update_row( (int) $row['id'], $update );

		$sent = self::send_email( $row, $stage, $settings );

		if ( 1 === $stage && $settings['telegram_nudge'] && ! $row['telegram_at'] ) {
			self::nudge_customer_on_telegram( $row );
		}

		return $sent;
	}

	private static function create_coupon( array $row, array $settings ): string {
		$code = 'COMEBACK-' . strtoupper( wp_generate_password( 6, false ) );

		$coupon = new \WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( $settings['discount_percent'] );
		$coupon->set_usage_limit( 1 );
		$coupon->set_usage_limit_per_user( 1 );
		$coupon->set_email_restrictions( [ $row['email'] ] );
		$coupon->set_date_expires( time() + $settings['discount_hours'] * HOUR_IN_SECONDS );
		$coupon->set_description( sprintf(
			/* translators: %s: customer email */
			__( 'Abandoned cart reminder for %s (created automatically).', 'yeffoprint-core' ),
			$row['email']
		) );
		$coupon->update_meta_data( self::COUPON_META, (int) $row['id'] );

		return $coupon->save() ? $code : '';
	}

	private static function send_email( array $row, int $stage, array $settings ): bool {
		WC()->mailer();
		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-email-abandoned-cart.php';

		$lines    = json_decode( (string) $row['summary'], true ) ?: [];
		// The design fee rides along with a custom design, so it's never
		// what the order is "for". Older rows have no is_fee flag.
		$first = '';
		foreach ( $lines as $line ) {
			if ( empty( $line['is_fee'] ) && __( 'Custom Design Fee', 'yeffoprint-core' ) !== ( $line['name'] ?? '' ) ) {
				$first = (string) $line['name'];
				break;
			}
		}
		$discount = 2 === $stage && '' !== $row['coupon_code'];
		$percent  = rtrim( rtrim( number_format( $settings['discount_percent'], 2 ), '0' ), '.' );

		if ( 1 === $stage ) {
			$subject = __( 'Your labels are saved. Pick up where you left off', 'yeffoprint-core' );
			$heading = __( 'Your label design is still here', 'yeffoprint-core' );
			$intro   = __( 'You were almost done. We saved your cart exactly how you left it, so you can finish checking out in a couple of taps.', 'yeffoprint-core' );
			$button  = __( 'Finish checkout', 'yeffoprint-core' );
		} elseif ( $discount ) {
			/* translators: %s: discount percent */
			$subject = sprintf( __( '%s%% off your labels, if you finish soon', 'yeffoprint-core' ), $percent );
			$heading = __( 'Still thinking it over?', 'yeffoprint-core' );
			$intro   = sprintf(
				/* translators: 1: discount percent, 2: product name */
				__( "Here's %1\$s%% off to finish your %2\$s order. The code is already applied when you tap the button.", 'yeffoprint-core' ),
				$percent,
				$first ?: __( 'label', 'yeffoprint-core' )
			);
			/* translators: %s: discount percent */
			$button = sprintf( __( 'Finish with %s%% off', 'yeffoprint-core' ), $percent );
		} else {
			$subject = __( 'Still thinking it over? Your cart is waiting', 'yeffoprint-core' );
			$heading = __( 'Still thinking it over?', 'yeffoprint-core' );
			$intro   = __( 'Your cart is still saved. Questions about sizing, materials or your design? Just reply to this email.', 'yeffoprint-core' );
			$button  = __( 'Finish checkout', 'yeffoprint-core' );
		}

		return ( new YeffoPrint_Email_Abandoned_Cart() )->send_reminder( $row['email'], $subject, [
			'email_heading'  => $heading,
			'name'           => $row['first_name'] ?: __( 'there', 'yeffoprint-core' ),
			'intro'          => $intro,
			'lines'          => $lines,
			'total'          => (float) $row['total'],
			'coupon_code'    => $discount ? $row['coupon_code'] : '',
			'discount_note'  => $discount
				/* translators: %d: hours until the code expires */
				? sprintf( _n( 'Expires in %d hour', 'Expires in %d hours', $settings['discount_hours'], 'yeffoprint-core' ), $settings['discount_hours'] )
				: '',
			'button_label'   => $button,
			'cta_url'        => self::recover_url( $row ),
			'optout_url'     => self::optout_url( $row ),
			'is_last'        => 2 === $stage,
			'telegram_url'   => self::telegram_bot_url(),
		] );
	}

	private static function telegram_bot_url(): string {
		return YeffoPrint_Telegram_Settings::is_enabled() ? (string) YeffoPrint_Telegram_Settings::public_url() : '';
	}

	/* ---------- Telegram ---------- */

	private static function telegram_client(): ?YeffoPrint_Telegram_Client {
		$token = YeffoPrint_Telegram_Settings::get_bot_token();
		if ( '' === $token || ! YeffoPrint_Telegram_Settings::is_enabled() ) {
			return null;
		}
		return new YeffoPrint_Telegram_Client( $token );
	}

	private static function owner_chat_id(): int {
		return (int) get_option( YeffoPrint_Admin_Menu::TELEGRAM_ADMIN_CHAT_ID_OPTION, 0 );
	}

	private static function cart_lines_text( array $row ): string {
		$lines = json_decode( (string) $row['summary'], true ) ?: [];
		return implode( "\n", array_map( static function ( array $line ): string {
			return '• ' . trim( $line['name'] . ' · ' . $line['quantity'] . ( $line['detail'] ? ' · ' . $line['detail'] : '' ) );
		}, $lines ) );
	}

	private static function money( float $amount ): string {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
	}

	private static function alert_owner( array $row ): void {
		self::update_row( (int) $row['id'], [ 'owner_alerted_at' => self::now() ] );

		$client  = self::telegram_client();
		$chat_id = self::owner_chat_id();
		if ( ! $client || ! $chat_id ) {
			return;
		}

		$name  = trim( $row['first_name'] . ' ' . $row['last_name'] );
		$text  = implode( "\n", array_filter( [
			__( '🛒 Cart left behind', 'yeffoprint-core' ),
			trim( ( $name ? $name . ' · ' : '' ) . $row['email'] ),
			self::cart_lines_text( $row ),
			/* translators: %s: cart total */
			sprintf( __( 'Total: %s', 'yeffoprint-core' ), self::money( (float) $row['total'] ) ),
			'',
			__( 'The first reminder email goes out in about 5 minutes unless you stop it.', 'yeffoprint-core' ),
		], static function ( $line ) { return null !== $line; } ) );

		$settings = self::settings();
		$keyboard = [
			[
				[ 'text' => __( '✉️ Send now', 'yeffoprint-core' ), 'callback_data' => 'ac_send:' . $row['id'] ],
				[ 'text' => __( "⏸ Don't send", 'yeffoprint-core' ), 'callback_data' => 'ac_stop:' . $row['id'] ],
			],
		];
		if ( $settings['discount_enabled'] ) {
			$keyboard[] = [ [
				/* translators: %s: discount percent */
				'text'          => sprintf( __( '🎟 Send %s%% code instead', 'yeffoprint-core' ), rtrim( rtrim( number_format( $settings['discount_percent'], 2 ), '0' ), '.' ) ),
				'callback_data' => 'ac_code:' . $row['id'],
			] ];
		}

		$client->send_message( $chat_id, $text, $keyboard );
	}

	private static function notify_owner_recovered( array $row, \WC_Order $order ): void {
		$client  = self::telegram_client();
		$chat_id = self::owner_chat_id();
		if ( ! $client || ! $chat_id || ! self::settings()['owner_alerts'] ) {
			return;
		}

		$name = trim( $order->get_formatted_billing_full_name() ) ?: ( trim( $row['first_name'] . ' ' . $row['last_name'] ) ?: $row['email'] );
		$via  = (int) $row['stage'] >= 2 ? __( 'the second reminder', 'yeffoprint-core' ) : __( 'the first reminder', 'yeffoprint-core' );

		$client->send_message( $chat_id, sprintf(
			/* translators: 1: customer name, 2: order total, 3: order number, 4: which reminder */
			__( "✅ Recovered! %1\$s paid %2\$s\nOrder %3\$s · came back after %4\$s", 'yeffoprint-core' ),
			$name,
			self::money( (float) $order->get_total() ),
			$order->get_order_number(),
			$via
		) );
	}

	private static function nudge_customer_on_telegram( array $row ): void {
		if ( ! $row['user_id'] ) {
			return;
		}
		$chat_id = (int) get_user_meta( (int) $row['user_id'], YeffoPrint_Telegram_Account_Link::CHAT_ID_META, true );
		$client  = self::telegram_client();
		if ( ! $chat_id || ! $client ) {
			return;
		}

		self::update_row( (int) $row['id'], [ 'telegram_at' => self::now() ] );

		$text = sprintf(
			/* translators: 1: customer first name, 2: cart lines, 3: cart total */
			__( "👋 Hi %1\$s, your cart is still saved:\n\n%2\$s\nTotal: %3\$s\n\nWant to finish up? Reply here if you have a question.", 'yeffoprint-core' ),
			$row['first_name'] ?: __( 'there', 'yeffoprint-core' ),
			self::cart_lines_text( $row ),
			self::money( (float) $row['total'] )
		);

		$client->send_message( $chat_id, $text, [
			[ [ 'text' => __( '🛒 Finish checkout', 'yeffoprint-core' ), 'url' => self::recover_url( $row ) ] ],
			[ [ 'text' => __( '🔕 No more reminders', 'yeffoprint-core' ), 'callback_data' => 'ac_optout:' . $row['id'] ] ],
		] );
	}

	/**
	 * Button taps from class-telegram-callback-handler.php. Owner
	 * actions are gated to the owner's chat; the customer's opt-out to
	 * the chat linked to the account that owns the cart.
	 *
	 * @return string The toast shown on the tapped button.
	 */
	public static function handle_telegram_action( string $action, int $row_id, int $chat_id ): string {
		$row = self::get_row( $row_id );
		if ( ! $row ) {
			return __( 'That cart is gone.', 'yeffoprint-core' );
		}

		if ( 'ac_optout' === $action ) {
			$linked = $row['user_id'] ? (int) get_user_meta( (int) $row['user_id'], YeffoPrint_Telegram_Account_Link::CHAT_ID_META, true ) : 0;
			if ( ! $linked || $linked !== $chat_id ) {
				return __( "You don't have access to that.", 'yeffoprint-core' );
			}
			self::opt_out( $row );
			return __( "Done. We won't remind you again.", 'yeffoprint-core' );
		}

		if ( ! YeffoPrint_Telegram_Admin_Commands::is_admin_chat( $chat_id ) ) {
			return __( "You don't have access to that.", 'yeffoprint-core' );
		}
		if ( self::STATUS_OPEN !== $row['status'] ) {
			return __( 'Already handled.', 'yeffoprint-core' );
		}

		if ( 'ac_stop' !== $action ) {
			$placed = self::close_if_ordered( $row );
			if ( $placed ) {
				/* translators: %s: order number */
				return sprintf( __( 'Not sent: they already have order #%s.', 'yeffoprint-core' ), $placed->get_order_number() );
			}
		}

		switch ( $action ) {
			case 'ac_stop':
				self::stop( $row_id );
				return __( 'Stopped. No reminders for this cart.', 'yeffoprint-core' );
			case 'ac_send':
				return self::send_stage( $row, 1 ) ? __( 'Reminder sent.', 'yeffoprint-core' ) : __( 'Already sent.', 'yeffoprint-core' );
			case 'ac_code':
				if ( (int) $row['stage'] >= 2 ) {
					return __( 'Already sent.', 'yeffoprint-core' );
				}
				// Skips Email 1: the code email goes out in its place.
				self::update_row( $row_id, [ 'stage' => 1, 'email1_at' => $row['email1_at'] ?: self::now() ] );
				return self::send_stage( $row, 2 ) ? __( 'Code sent.', 'yeffoprint-core' ) : __( 'Already sent.', 'yeffoprint-core' );
		}
		return '';
	}

	public static function stop( int $row_id ): void {
		self::update_row( $row_id, [ 'status' => self::STATUS_STOPPED, 'closed_at' => self::now() ] );
	}

	/* ---------- Admin page data ---------- */

	/** @return array{stats:array, carts:array} Last 30 days. */
	public static function report(): array {
		self::maybe_install();

		global $wpdb;
		$table = self::table_name();
		$since = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );

		// "Purchased" rows paid before any reminder — they were never
		// really abandoned, so they're left out of the list and the stats.
		// Same for "ordered" rows closed before any reminder went out.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE created_at >= %s AND status <> %s AND NOT ( status = %s AND stage = 0 ) ORDER BY updated_at DESC LIMIT 200", $since, self::STATUS_PURCHASED, self::STATUS_ORDERED ), ARRAY_A ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$delay1 = self::settings()['delay1_minutes'] * MINUTE_IN_SECONDS;
		$left   = array_values( array_filter( $rows, static function ( array $row ) use ( $delay1 ): bool {
			// A cart touched in the last few minutes is still someone shopping.
			return self::STATUS_OPEN !== $row['status'] || (int) $row['stage'] > 0 || time() - self::ts( $row['updated_at'] ) >= min( $delay1, 30 * MINUTE_IN_SECONDS );
		} ) );

		$recovered = array_filter( $left, static function ( array $row ): bool {
			return self::STATUS_RECOVERED === $row['status'];
		} );
		$reminded = array_filter( $left, static function ( array $row ): bool {
			return (int) $row['stage'] > 0;
		} );
		$open = array_filter( $left, static function ( array $row ): bool {
			return self::STATUS_OPEN === $row['status'];
		} );

		return [
			'stats' => [
				'carts_left'      => count( $left ),
				'recovered'       => count( $recovered ),
				'recovered_rate'  => $reminded ? (int) round( 100 * count( $recovered ) / count( $reminded ) ) : 0,
				'recovered_sales' => round( array_sum( array_column( $recovered, 'recovered_total' ) ), 2 ),
				'still_open'      => round( array_sum( array_map( 'floatval', array_column( $open, 'total' ) ) ), 2 ),
			],
			'carts' => array_map( [ __CLASS__, 'row_payload' ], $left ),
		];
	}

	private static function row_payload( array $row ): array {
		$order = $row['order_id'] ? wc_get_order( (int) $row['order_id'] ) : null;

		return [
			'id'           => (int) $row['id'],
			'name'         => trim( $row['first_name'] . ' ' . $row['last_name'] ),
			'email'        => $row['email'],
			'is_guest'     => ! (int) $row['user_id'],
			'lines'        => json_decode( (string) $row['summary'], true ) ?: [],
			'total'        => (float) $row['total'],
			'status'       => $row['status'],
			'stage'        => (int) $row['stage'],
			'coupon_code'  => $row['coupon_code'],
			'left_at'      => self::ts( $row['updated_at'] ),
			'email1_at'    => self::ts( $row['email1_at'] ),
			'email2_at'    => self::ts( $row['email2_at'] ),
			'telegram_at'  => self::ts( $row['telegram_at'] ),
			'clicked_at'   => self::ts( $row['clicked_at'] ),
			'order_id'     => $order ? $order->get_id() : 0,
			'order_number' => $order ? (string) $order->get_order_number() : '',
			'recover_url'  => self::recover_url( $row ),
		];
	}
}
