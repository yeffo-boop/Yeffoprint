<?php
/**
 * "Delete my account" on My Account > Account details — direct request:
 * "Users can delete their account and all associated data on demand",
 * promised in the Privacy Policy (blocks/legal-document/docs/
 * privacy-policy.html, sections 8 and 9).
 *
 * Two steps, so nobody else at an unlocked computer (or a stolen session)
 * can wipe an account: the button emails a one-hour link, and the link
 * opens a final "Delete my account permanently" button. Confirming by
 * email also works for Google/Apple/Discord/Telegram sign-ins, which
 * have no password to re-enter. The link page itself never deletes, so
 * an email scanner prefetching the link can't trigger it.
 *
 * What goes, matching the policy text:
 *   - the WordPress user, which takes its user meta (addresses, rewards,
 *     Telegram link) and, through YeffoPrint_Tracker_Store's own
 *     delete_user hook, all Dose Tracker data, share links and feedback;
 *   - unpaid orders and custom design requests nobody paid for;
 *   - uploaded artwork and inspiration files on every custom order;
 *   - saved designs, reviews (and their photos), unfinished checkouts and
 *     staff notes kept under the customer's email.
 * What stays: paid orders, because tax law needs them — but only the
 * billing name and address, what was bought and the amounts. Email,
 * phones, shipping address, IP and order notes from the customer are
 * cleared, and the order is detached from any login.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Account_Deletion {

	private const META        = '_yp_delete_account_request';
	private const LINK_TTL    = HOUR_IN_SECONDS;
	private const QUERY_ARG   = 'yp_delete_account';
	private const NONCE_SEND  = 'yeffoprint_delete_account_send';
	private const NONCE_FINAL = 'yeffoprint_delete_account_final';
	private const DONE_ARG    = 'account-deleted';

	/** Orders in these states with no money received are deleted outright. */
	private const UNPAID_STATUSES = [ 'pending', 'failed', 'cancelled', 'on-hold', 'checkout-draft' ];

	public function __construct() {
		add_action( 'woocommerce_after_edit_account_form', [ $this, 'render' ] );
		add_action( 'template_redirect', [ $this, 'handle_post' ] );
		add_action( 'wp_footer', [ $this, 'render_done_notice' ] );
	}

	public static function can_self_delete( \WP_User $user ): bool {
		// Staff accounts are removed by an admin, never from the storefront.
		return ! user_can( $user, 'edit_posts' ) && ! user_can( $user, 'manage_woocommerce' );
	}

	/* ---------- My Account > Account details ---------- */

	public function render(): void {
		$user = wp_get_current_user();
		if ( ! $user->exists() ) {
			return;
		}

		$policy  = get_privacy_policy_url();
		$token   = isset( $_GET[ self::QUERY_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the emailed token is the check.
		$confirm = '' !== $token && self::token_valid( $user->ID, $token );
		?>
		<section class="yp-delete-account" id="delete-account" aria-labelledby="yp-delete-account-title">
			<h2 id="yp-delete-account-title"><?php esc_html_e( 'Delete my account', 'yeffoprint-core' ); ?></h2>
			<?php if ( ! self::can_self_delete( $user ) ) : ?>
				<p><?php esc_html_e( 'Staff accounts can only be removed by an administrator.', 'yeffoprint-core' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'This permanently deletes your account, saved addresses, Dose Tracker data, share links, saved designs, uploaded artwork, reviews and photos. It can’t be undone.', 'yeffoprint-core' ); ?></p>
				<p class="yp-delete-account__note">
					<?php esc_html_e( 'We keep the basic record of paid orders (what you bought, amount, date, billing name and address) only as long as tax law requires, with no login attached.', 'yeffoprint-core' ); ?>
					<?php if ( $policy ) : ?>
						<a href="<?php echo esc_url( $policy ); ?>"><?php esc_html_e( 'Privacy Policy', 'yeffoprint-core' ); ?></a>
					<?php endif; ?>
				</p>
				<?php if ( $confirm ) : ?>
					<form method="post" class="yp-delete-account__form">
						<?php wp_nonce_field( self::NONCE_FINAL ); ?>
						<input type="hidden" name="yp_delete_account_token" value="<?php echo esc_attr( $token ); ?>">
						<p><strong><?php esc_html_e( 'Last step: this deletes your account right now.', 'yeffoprint-core' ); ?></strong></p>
						<button type="submit" name="yp_delete_account_final" value="1" class="button yp-delete-account__button"><?php esc_html_e( 'Delete my account permanently', 'yeffoprint-core' ); ?></button>
					</form>
				<?php elseif ( '' !== $token ) : ?>
					<p class="yp-delete-account__error"><?php esc_html_e( 'That link has expired or was already used. Send yourself a new one below.', 'yeffoprint-core' ); ?></p>
				<?php endif; ?>
				<?php if ( ! $confirm ) : ?>
					<form method="post" class="yp-delete-account__form">
						<?php wp_nonce_field( self::NONCE_SEND ); ?>
						<p><?php echo esc_html( sprintf( /* translators: %s: email address */ __( 'We’ll email a confirmation link to %s. The link works for 1 hour.', 'yeffoprint-core' ), $user->user_email ) ); ?></p>
						<button type="submit" name="yp_delete_account_send" value="1" class="button yp-delete-account__button"><?php esc_html_e( 'Email me a link to delete my account', 'yeffoprint-core' ); ?></button>
					</form>
				<?php endif; ?>
			<?php endif; ?>
		</section>
		<?php
	}

	public function handle_post(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! is_user_logged_in() ) {
			return;
		}
		$user = wp_get_current_user();

		if ( isset( $_POST['yp_delete_account_send'] ) ) {
			check_admin_referer( self::NONCE_SEND );
			if ( self::can_self_delete( $user ) ) {
				self::send_link( $user );
				wc_add_notice( __( 'Check your email for the link to delete your account.', 'yeffoprint-core' ) );
			}
			wp_safe_redirect( wc_get_account_endpoint_url( 'edit-account' ) . '#delete-account' );
			exit;
		}

		if ( isset( $_POST['yp_delete_account_final'] ) ) {
			check_admin_referer( self::NONCE_FINAL );
			$token = isset( $_POST['yp_delete_account_token'] ) ? sanitize_text_field( wp_unslash( $_POST['yp_delete_account_token'] ) ) : '';
			if ( ! self::can_self_delete( $user ) || ! self::token_valid( $user->ID, $token ) ) {
				wc_add_notice( __( 'That link has expired or was already used. Send yourself a new one.', 'yeffoprint-core' ), 'error' );
				wp_safe_redirect( wc_get_account_endpoint_url( 'edit-account' ) . '#delete-account' );
				exit;
			}

			$email = $user->user_email;
			$name  = $user->first_name ?: $user->display_name;
			delete_user_meta( $user->ID, self::META );

			self::delete_customer( $user->ID );
			wp_logout();
			self::send_done_email( $email, $name );

			wp_safe_redirect( add_query_arg( self::DONE_ARG, '1', home_url( '/' ) ) );
			exit;
		}
	}

	public function render_done_notice(): void {
		if ( empty( $_GET[ self::DONE_ARG ] ) || is_user_logged_in() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		?>
		<div class="yp-delete-account__done" role="status" style="position:fixed;left:50%;bottom:1.5rem;z-index:9999;transform:translateX(-50%);max-width:calc(100vw - 2rem);padding:.875rem 1.25rem;border-radius:12px;background:#141414;color:#fff;font-weight:600;box-shadow:0 10px 30px rgba(0,0,0,.2)"><?php esc_html_e( 'Your account and data have been deleted.', 'yeffoprint-core' ); ?></div>
		<?php
	}

	/* ---------- The emailed link ---------- */

	private static function token_valid( int $user_id, string $token ): bool {
		$request = get_user_meta( $user_id, self::META, true );
		if ( '' === $token || ! is_array( $request ) || empty( $request['hash'] ) || (int) ( $request['expires'] ?? 0 ) < time() ) {
			return false;
		}
		return hash_equals( (string) $request['hash'], hash( 'sha256', $token ) );
	}

	private static function send_link( \WP_User $user ): void {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		$token = wp_generate_password( 32, false );
		update_user_meta( $user->ID, self::META, [
			'hash'    => hash( 'sha256', $token ),
			'expires' => time() + self::LINK_TTL,
		] );

		$url  = add_query_arg( self::QUERY_ARG, $token, wc_get_account_endpoint_url( 'edit-account' ) ) . '#delete-account';
		$name = $user->first_name ?: $user->display_name;

		ob_start();
		?>
		<p><?php echo esc_html( sprintf( /* translators: %s: first name */ __( 'Hi %s,', 'yeffoprint-core' ), $name ) ); ?></p>
		<p><?php esc_html_e( 'You asked to delete your YeffoDesign account. Use the button below to finish. You’ll get one more chance to confirm before anything is deleted.', 'yeffoprint-core' ); ?></p>
		<table class="yp-payment-cta" role="presentation" cellpadding="0" cellspacing="0" width="100%">
			<tbody><tr><td>
				<span class="yp-payment-cta-label"><?php esc_html_e( 'Delete my account', 'yeffoprint-core' ); ?></span>
				<a class="yp-payment-cta-button" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Continue →', 'yeffoprint-core' ); ?></a>
				<span class="yp-payment-cta-sub"><?php esc_html_e( 'This link works for 1 hour.', 'yeffoprint-core' ); ?></span>
			</td></tr></tbody>
		</table>
		<p><?php esc_html_e( 'If you didn’t ask for this, ignore this email and your account stays as it is.', 'yeffoprint-core' ); ?></p>
		<?php
		$body = ob_get_clean();

		$mailer = WC()->mailer();
		$mailer->send(
			$user->user_email,
			__( 'Confirm deleting your YeffoDesign account', 'yeffoprint-core' ),
			$mailer->wrap_message( __( 'Delete your account', 'yeffoprint-core' ), $body )
		);
	}

	private static function send_done_email( string $email, string $name ): void {
		if ( ! function_exists( 'WC' ) || ! is_email( $email ) ) {
			return;
		}
		ob_start();
		?>
		<p><?php echo esc_html( sprintf( /* translators: %s: first name */ __( 'Hi %s,', 'yeffoprint-core' ), $name ) ); ?></p>
		<p><?php esc_html_e( 'Your YeffoDesign account and its data have been deleted. We only keep the basic record of paid orders that tax law requires, with no login attached.', 'yeffoprint-core' ); ?></p>
		<p><?php esc_html_e( 'You’re always welcome back. Thanks for ordering with us.', 'yeffoprint-core' ); ?></p>
		<?php
		$body   = ob_get_clean();
		$mailer = WC()->mailer();
		$mailer->send( $email, __( 'Your YeffoDesign account was deleted', 'yeffoprint-core' ), $mailer->wrap_message( __( 'Account deleted', 'yeffoprint-core' ), $body ) );
	}

	/* ---------- The deletion itself ---------- */

	public static function delete_customer( int $user_id ): void {
		$user = get_userdata( $user_id );
		if ( ! $user || ! self::can_self_delete( $user ) ) {
			return;
		}

		$emails = array_values( array_unique( array_filter( array_map( 'strtolower', [
			$user->user_email,
			(string) get_user_meta( $user_id, 'billing_email', true ),
		] ) ) ) );

		$orders = self::orders_for( $user_id, $emails );
		foreach ( $orders as $order ) {
			if ( self::has_money( $order ) ) {
				self::scrub_order( $order );
			} else {
				$order->delete( true );
			}
		}

		self::delete_custom_orders( $user_id, $emails );
		self::delete_saved_designs( $user_id );
		self::delete_reviews( $user_id, $emails );
		self::delete_email_rows( $emails, $user_id );

		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user_id );
	}

	/** @return \WC_Order[] */
	private static function orders_for( int $user_id, array $emails ): array {
		$found = [];
		$sets  = [ wc_get_orders( [ 'customer_id' => $user_id, 'limit' => -1, 'type' => 'shop_order' ] ) ];
		foreach ( $emails as $email ) {
			$sets[] = wc_get_orders( [ 'billing_email' => $email, 'limit' => -1, 'type' => 'shop_order' ] );
		}
		foreach ( $sets as $set ) {
			foreach ( $set as $order ) {
				if ( $order instanceof \WC_Order ) {
					$found[ $order->get_id() ] = $order;
				}
			}
		}
		return array_values( $found );
	}

	private static function has_money( \WC_Order $order ): bool {
		if ( $order->get_date_paid() || $order->is_paid() || ! in_array( $order->get_status(), self::UNPAID_STATUSES, true ) ) {
			return true;
		}
		if ( (float) $order->get_meta( '_yp_amount_received' ) > 0 ) {
			return true;
		}
		return (float) $order->get_total_refunded() > 0;
	}

	private static function scrub_order( \WC_Order $order ): void {
		$order->set_customer_id( 0 );
		$order->set_billing_email( '' );
		$order->set_billing_phone( '' );
		foreach ( [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' ] as $field ) {
			$order->{"set_shipping_{$field}"}( '' );
		}
		$order->set_customer_ip_address( '' );
		$order->set_customer_user_agent( '' );
		$order->set_customer_note( '' );
		$order->update_meta_data( '_yp_account_deleted', current_time( 'mysql' ) );
		$order->add_order_note( __( 'Customer deleted their account. Contact details and shipping address were removed; billing name and address kept for tax records.', 'yeffoprint-core' ) );
		$order->save();
	}

	private static function delete_custom_orders( int $user_id, array $emails ): void {
		if ( ! class_exists( 'YeffoPrint_Custom_Order_Meta' ) ) {
			return;
		}
		$M        = 'YeffoPrint_Custom_Order_Meta';
		$meta_any = [ 'relation' => 'OR', [ 'key' => $M::CUSTOMER_ID, 'value' => $user_id ] ];
		foreach ( $emails as $email ) {
			$meta_any[] = [ 'key' => $M::CUSTOMER_EMAIL, 'value' => $email ];
		}
		$ids = get_posts( [
			'post_type'      => 'yp_custom_order',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => $meta_any, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		] );

		foreach ( $ids as $id ) {
			foreach ( [ $M::INSPIRATION_UPLOADS, $M::ARTWORK_UPLOADS, $M::CANVAS_SOURCE_IMAGE_UPLOADS ] as $key ) {
				foreach ( array_filter( array_map( 'absint', (array) get_post_meta( $id, $key, true ) ) ) as $attachment_id ) {
					wp_delete_attachment( $attachment_id, true );
				}
				delete_post_meta( $id, $key );
			}

			$wc_order = wc_get_order( (int) get_post_meta( $id, $M::WC_ORDER_ID, true ) );
			if ( $wc_order && self::has_money( $wc_order ) ) {
				// Kept with its paid order, minus who it belonged to.
				update_post_meta( $id, $M::CUSTOMER_EMAIL, '' );
				update_post_meta( $id, $M::CUSTOMER_NAME, '' );
				update_post_meta( $id, $M::CUSTOMER_ID, 0 );
			} else {
				wp_delete_post( $id, true );
			}
		}
	}

	private static function delete_saved_designs( int $user_id ): void {
		$ids = get_posts( [
			'post_type'      => 'yp_saved_design',
			'post_status'    => 'any',
			'author'         => $user_id,
			'posts_per_page' => -1,
			'fields'         => 'ids',
		] );
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
	}

	private static function delete_reviews( int $user_id, array $emails ): void {
		$photo_meta = class_exists( 'YeffoPrint_Order_Reviews' ) ? YeffoPrint_Order_Reviews::META_PHOTOS : '_yp_review_photos';
		$comments   = get_comments( [ 'type' => 'review', 'user_id' => $user_id, 'status' => 'all' ] );
		foreach ( $emails as $email ) {
			$comments = array_merge( $comments, get_comments( [ 'type' => 'review', 'author_email' => $email, 'status' => 'all' ] ) );
		}
		$done = [];
		foreach ( $comments as $comment ) {
			if ( isset( $done[ $comment->comment_ID ] ) ) {
				continue;
			}
			$done[ $comment->comment_ID ] = true;
			foreach ( array_filter( array_map( 'absint', (array) get_comment_meta( $comment->comment_ID, $photo_meta, true ) ) ) as $attachment_id ) {
				wp_delete_attachment( $attachment_id, true );
			}
			wp_delete_comment( $comment->comment_ID, true );
		}
	}

	/** Unfinished checkouts and staff notes, both keyed by email. */
	private static function delete_email_rows( array $emails, int $user_id ): void {
		global $wpdb;
		$tables = [];
		if ( class_exists( 'YeffoPrint_Abandoned_Carts' ) ) {
			$tables[ YeffoPrint_Abandoned_Carts::table_name() ] = 'email';
		}
		if ( class_exists( 'YeffoPrint_Customer_Notes' ) ) {
			$tables[ YeffoPrint_Customer_Notes::table_name() ] = 'customer_email';
		}
		foreach ( $tables as $table => $column ) {
			if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
				continue;
			}
			foreach ( $emails as $email ) {
				$wpdb->delete( $table, [ $column => $email ], [ '%s' ] );
			}
		}
		if ( class_exists( 'YeffoPrint_Abandoned_Carts' ) && isset( $tables[ YeffoPrint_Abandoned_Carts::table_name() ] ) ) {
			$wpdb->delete( YeffoPrint_Abandoned_Carts::table_name(), [ 'user_id' => $user_id ], [ '%d' ] );
		}
	}
}
