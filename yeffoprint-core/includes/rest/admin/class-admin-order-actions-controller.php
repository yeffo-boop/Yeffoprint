<?php
/**
 * Order jobs that used to need the WooCommerce dashboard (direct request,
 * "build all of these"): bulk status changes, trash / restore / delete,
 * resending a customer email, and a printable invoice or packing slip.
 *
 * The print pages are plain admin-post.php screens (not REST) so a link
 * can open them in a new tab with the login cookie, and print dialogs
 * work like any other page. Several ids print one page per order, so a
 * whole day's packing slips go out in one print job.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_Order_Actions_Controller {

	private const NAMESPACE   = 'yeffoprint-core/v1';
	private const PRINT_ACTION = 'yeffoprint_print_orders';
	private const MAX_BULK    = 100;

	/**
	 * Emails that can be sent again from an order: key => [ WC_Emails key, label ].
	 * The keys on the right are the ones this plugin's own overrides register under
	 * (class-order-processing-email.php, class-order-shipped-email.php,
	 * class-order-completed-email.php) or WooCommerce's own.
	 */
	private const EMAILS = [
		'confirmation' => [ 'WC_Email_Customer_Processing_Order', 'Order confirmation' ],
		'invoice'      => [ 'WC_Email_Customer_Invoice', 'Invoice with pay link' ],
		'shipped'      => [ 'customer_shipped_order', 'Shipped, with tracking' ],
		'completed'    => [ 'WC_Email_Customer_Completed_Order', 'Delivered' ],
	];

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_action( 'admin_post_' . self::PRINT_ACTION, [ $this, 'render_print_page' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/admin/orders/bulk', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'bulk' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/admin/order/(?P<id>\d+)/email', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'send_email' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );
	}

	/**
	 * The print link's base (no ids), for the app to add &kind= and &ids= to.
	 * No nonce: the page only displays, and the app stays open for days.
	 */
	public static function print_base_url(): string {
		return add_query_arg( 'action', self::PRINT_ACTION, admin_url( 'admin-post.php' ) );
	}

	public static function email_options(): array {
		return array_map( static function ( array $email ): string {
			return $email[1];
		}, self::EMAILS );
	}

	/**
	 * Body: `{ ids: [], action: 'status'|'trash'|'restore'|'delete', status? }`.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function bulk( \WP_REST_Request $request ) {
		$params = $request->get_json_params() ?: [];
		$action = sanitize_key( (string) ( $params['action'] ?? '' ) );
		$ids    = array_slice( array_values( array_unique( array_filter( array_map( 'absint', (array) ( $params['ids'] ?? [] ) ) ) ) ), 0, self::MAX_BULK );

		if ( ! $ids ) {
			return new \WP_Error( 'yeffoprint_bulk_no_orders', __( 'Pick at least one order.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}
		if ( ! in_array( $action, [ 'status', 'trash', 'restore', 'delete' ], true ) ) {
			return new \WP_Error( 'yeffoprint_bulk_bad_action', __( 'Unknown action.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$status = '';
		if ( 'status' === $action ) {
			$status = sanitize_key( (string) ( $params['status'] ?? '' ) );
			if ( ! array_key_exists( 'wc-' . $status, wc_get_order_statuses() ) ) {
				return new \WP_Error( 'yeffoprint_invalid_status', __( 'That is not a valid status.', 'yeffoprint-core' ), [ 'status' => 400 ] );
			}
		}

		$done   = 0;
		$failed = [];
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order instanceof \WC_Order ) {
				$failed[] = $id;
				continue;
			}

			$ok = true;
			switch ( $action ) {
				case 'status':
					if ( $order->get_status() !== $status ) {
						$order->set_status( $status, __( 'Status changed from the dashboard (bulk).', 'yeffoprint-core' ) );
						$order->save();
					}
					break;
				case 'trash':
					$ok = 'trash' === $order->get_status() || $order->delete( false );
					break;
				case 'restore':
					$ok = 'trash' !== $order->get_status() || $order->untrash();
					break;
				case 'delete':
					// Only from the trash, so a slip of the finger can't destroy a live order.
					$ok = 'trash' === $order->get_status() && $order->delete( true );
					break;
			}

			if ( $ok ) {
				++$done;
			} else {
				$failed[] = $id;
			}
		}

		return rest_ensure_response( [ 'done' => $done, 'failed' => $failed ] );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function send_email( \WP_REST_Request $request ) {
		$order = wc_get_order( (int) $request['id'] );
		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error( 'yeffoprint_order_not_found', __( 'That order could not be found.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}

		$params = $request->get_json_params() ?: [];
		$key    = sanitize_key( (string) ( $params['email'] ?? '' ) );
		if ( ! isset( self::EMAILS[ $key ] ) ) {
			return new \WP_Error( 'yeffoprint_unknown_email', __( 'Unknown email.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}
		if ( ! $order->get_billing_email() ) {
			return new \WP_Error( 'yeffoprint_no_customer_email', __( 'This order has no customer email address.', 'yeffoprint-core' ), [ 'status' => 409 ] );
		}

		// Same setup WooCommerce's own "Resend" order action does first.
		WC()->payment_gateways();
		WC()->shipping();
		$emails = WC()->mailer()->get_emails();
		$email  = $emails[ self::EMAILS[ $key ][0] ] ?? null;

		if ( ! $email instanceof \WC_Email ) {
			return new \WP_Error( 'yeffoprint_email_missing', __( 'That email isn’t set up on this site.', 'yeffoprint-core' ), [ 'status' => 409 ] );
		}
		// The invoice is a manual email and always sends; the rest honour their on/off switch.
		if ( 'invoice' !== $key && ! $email->is_enabled() ) {
			return new \WP_Error( 'yeffoprint_email_disabled', __( 'That email is turned off in WooCommerce’s email settings.', 'yeffoprint-core' ), [ 'status' => 409 ] );
		}

		$email->trigger( $order->get_id(), $order );

		/* translators: %s: email name, e.g. "Order confirmation". */
		$order->add_order_note( sprintf( __( '%s email sent again from the dashboard.', 'yeffoprint-core' ), self::EMAILS[ $key ][1] ), false, true );

		return rest_ensure_response( [ 'sent' => true, 'to' => $order->get_billing_email() ] );
	}

	/** admin-post.php?action=yeffoprint_print_orders&kind=invoice|slip&ids=1,2 */
	public function render_print_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'yeffoprint-core' ), 403 );
		}

		$kind   = 'slip' === sanitize_key( (string) ( $_GET['kind'] ?? '' ) ) ? 'slip' : 'invoice';
		$ids    = array_slice( array_filter( array_map( 'absint', explode( ',', (string) wp_unslash( $_GET['ids'] ?? '' ) ) ) ), 0, self::MAX_BULK );
		$orders = array_values( array_filter( array_map( 'wc_get_order', $ids ), static function ( $order ) {
			return $order instanceof \WC_Order;
		} ) );

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );

		$title = 'slip' === $kind ? __( 'Packing slip', 'yeffoprint-core' ) : __( 'Invoice', 'yeffoprint-core' );
		?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $title . ( 1 === count( $orders ) ? ' #' . $orders[0]->get_order_number() : '' ) ); ?></title>
<style>
	* { box-sizing: border-box; }
	body { margin: 0; font: 14px/1.45 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #141414; background: #f2f2f0; }
	.bar { position: sticky; top: 0; display: flex; gap: 8px; align-items: center; padding: 12px 16px; background: #141414; color: #fff; }
	.bar button { font: inherit; font-weight: 600; padding: 8px 16px; border: 0; border-radius: 8px; background: #ec008c; color: #fff; cursor: pointer; }
	.page { max-width: 8.5in; margin: 16px auto; padding: 0.6in; background: #fff; page-break-after: always; break-after: page; }
	.page:last-child { page-break-after: auto; break-after: auto; }
	.head { display: flex; justify-content: space-between; gap: 24px; margin-bottom: 28px; }
	.brand { font-size: 22px; font-weight: 800; letter-spacing: -0.02em; }
	.brand small { display: block; font-size: 12px; font-weight: 400; color: #555; letter-spacing: 0; white-space: pre-line; }
	.doc { text-align: right; }
	.doc h1 { margin: 0 0 4px; font-size: 20px; text-transform: uppercase; letter-spacing: 0.08em; }
	.doc div { color: #555; }
	.addr { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px; }
	.addr h2, .note h2 { margin: 0 0 4px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em; color: #777; }
	.addr p { margin: 0; white-space: pre-line; }
	table { width: 100%; border-collapse: collapse; }
	th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em; color: #777; border-bottom: 2px solid #141414; padding: 6px 4px; }
	td { vertical-align: top; border-bottom: 1px solid #e3e3e0; padding: 8px 4px; }
	td.n, th.n { text-align: right; white-space: nowrap; }
	.meta { margin: 4px 0 0; font-size: 12px; color: #555; }
	.meta ul, .meta p { margin: 0; padding: 0; list-style: none; }
	.meta table { margin-top: 4px; }
	.meta th, .meta td { font-size: 11px; padding: 2px 4px; border-color: #eee; }
	.box { display: inline-block; width: 14px; height: 14px; border: 1.5px solid #141414; border-radius: 3px; }
	.totals { margin-left: auto; margin-top: 12px; width: 280px; }
	.totals td { border: 0; padding: 3px 4px; }
	.totals tr.grand td { border-top: 2px solid #141414; font-weight: 700; padding-top: 8px; }
	.note { margin-top: 24px; padding: 12px; background: #f7f7f5; border-radius: 6px; }
	.note p { margin: 0; white-space: pre-line; }
	.thanks { margin-top: 32px; color: #555; font-size: 13px; }
	@media print {
		body { background: #fff; }
		.bar { display: none; }
		.page { margin: 0; padding: 0; max-width: none; }
		@page { margin: 0.5in; }
	}
	@media (max-width: 640px) {
		.page { margin: 0; padding: 20px 16px; }
		.head, .addr { display: block; }
		.doc { text-align: left; margin-top: 12px; }
		.addr > div + div { margin-top: 16px; }
	}
</style>
</head>
<body>
<div class="bar"><button type="button" onclick="window.print()"><?php esc_html_e( 'Print', 'yeffoprint-core' ); ?></button><span><?php echo esc_html( sprintf( _n( '%1$s for %2$d order', '%1$s for %2$d orders', count( $orders ), 'yeffoprint-core' ), $title, count( $orders ) ) ); ?></span></div>
		<?php
		if ( ! $orders ) {
			echo '<div class="page"><p>' . esc_html__( 'No orders to print.', 'yeffoprint-core' ) . '</p></div>';
		}
		foreach ( $orders as $order ) {
			$this->render_order_page( $order, $kind );
		}
		?>
<script>window.addEventListener( 'load', function () { setTimeout( function () { window.print(); }, 300 ); } );</script>
</body>
</html>
		<?php
		exit;
	}

	private function render_order_page( \WC_Order $order, string $kind ): void {
		$is_slip  = 'slip' === $kind;
		$store    = array_filter( [
			get_option( 'woocommerce_store_address' ),
			get_option( 'woocommerce_store_address_2' ),
			trim( get_option( 'woocommerce_store_city' ) . ' ' . get_option( 'woocommerce_store_postcode' ) ),
		] );
		$billing  = $order->get_formatted_billing_address();
		$shipping = $order->get_formatted_shipping_address() ?: $billing;
		$date     = $order->get_date_created();
		$to_text  = static function ( string $html ): string {
			return trim( wp_strip_all_tags( str_replace( [ '<br/>', '<br>', '<br />' ], "\n", $html ) ) );
		};
		?>
<div class="page">
	<div class="head">
		<div class="brand"><?php echo esc_html( get_bloginfo( 'name' ) ); ?><small><?php echo esc_html( implode( "\n", $store ) ); ?></small></div>
		<div class="doc">
			<h1><?php echo esc_html( $is_slip ? __( 'Packing slip', 'yeffoprint-core' ) : __( 'Invoice', 'yeffoprint-core' ) ); ?></h1>
			<div><?php echo esc_html( sprintf( __( 'Order #%s', 'yeffoprint-core' ), $order->get_order_number() ) ); ?></div>
			<?php if ( $date ) : ?><div><?php echo esc_html( wc_format_datetime( $date ) ); ?></div><?php endif; ?>
			<?php if ( ! $is_slip ) : ?><div><?php echo esc_html( $order->is_paid() ? sprintf( __( 'Paid by %s', 'yeffoprint-core' ), $order->get_payment_method_title() ) : __( 'Not paid yet', 'yeffoprint-core' ) ); ?></div><?php endif; ?>
			<?php if ( $is_slip && $order->get_shipping_method() ) : ?><div><?php echo esc_html( $order->get_shipping_method() ); ?></div><?php endif; ?>
		</div>
	</div>

	<div class="addr">
		<div><h2><?php esc_html_e( 'Ship to', 'yeffoprint-core' ); ?></h2><p><?php echo esc_html( $to_text( (string) $shipping ) ?: '—' ); ?></p></div>
		<div><h2><?php echo esc_html( $is_slip ? __( 'Contact', 'yeffoprint-core' ) : __( 'Bill to', 'yeffoprint-core' ) ); ?></h2><p><?php
			echo esc_html( $is_slip
				? implode( "\n", array_filter( [ $order->get_formatted_billing_full_name(), $order->get_billing_email(), $order->get_billing_phone() ] ) )
				: implode( "\n", array_filter( [ $to_text( (string) $billing ), $order->get_billing_email(), $order->get_billing_phone() ] ) ) );
		?></p></div>
	</div>

	<table>
		<thead><tr>
			<?php if ( $is_slip ) : ?><th style="width:28px"></th><?php endif; ?>
			<th><?php esc_html_e( 'Item', 'yeffoprint-core' ); ?></th>
			<th class="n"><?php esc_html_e( 'Qty', 'yeffoprint-core' ); ?></th>
			<?php if ( ! $is_slip ) : ?><th class="n"><?php esc_html_e( 'Total', 'yeffoprint-core' ); ?></th><?php endif; ?>
		</tr></thead>
		<tbody>
		<?php foreach ( $order->get_items() as $item ) : ?>
			<tr>
				<?php if ( $is_slip ) : ?><td><span class="box"></span></td><?php endif; ?>
				<td>
					<strong><?php echo esc_html( $item->get_name() ); ?></strong>
					<div class="meta"><?php wc_display_item_meta( $item, [ 'before' => '<ul><li>', 'after' => '</li></ul>', 'separator' => '</li><li>' ] ); ?></div>
				</td>
				<td class="n"><?php echo esc_html( (string) $item->get_quantity() ); ?></td>
				<?php if ( ! $is_slip ) : ?><td class="n"><?php echo wp_kses_post( $order->get_formatted_line_subtotal( $item ) ); ?></td><?php endif; ?>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( ! $is_slip ) : ?>
	<table class="totals">
		<?php foreach ( $order->get_order_item_totals() as $key => $total ) : ?>
			<tr class="<?php echo 'order_total' === $key ? 'grand' : ''; ?>"><td><?php echo esc_html( rtrim( wp_strip_all_tags( $total['label'] ), ':' ) ); ?></td><td class="n"><?php echo wp_kses_post( $total['value'] ); ?></td></tr>
		<?php endforeach; ?>
		<?php if ( (float) $order->get_total_refunded() > 0 ) : ?>
			<tr><td><?php esc_html_e( 'Refunded', 'yeffoprint-core' ); ?></td><td class="n">−<?php echo wp_kses_post( wc_price( $order->get_total_refunded(), [ 'currency' => $order->get_currency() ] ) ); ?></td></tr>
		<?php endif; ?>
	</table>
	<?php endif; ?>

	<?php if ( $order->get_customer_note() ) : ?>
		<div class="note"><h2><?php esc_html_e( 'Customer note', 'yeffoprint-core' ); ?></h2><p><?php echo esc_html( $order->get_customer_note() ); ?></p></div>
	<?php endif; ?>

	<p class="thanks"><?php esc_html_e( 'Thank you for your order!', 'yeffoprint-core' ); ?> <?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?></p>
</div>
		<?php
	}
}
