<?php
/**
 * Data model for the Web Design post-purchase workflow (docs/
 * ARCHITECTURE.md's own "Web Design Package orders" section already
 * notes a package purchase never gets a yp_custom_order shell — this is
 * the parallel, order-meta-only lifecycle that fills the gap after
 * payment: agreement -> staging delivery -> client review -> go-live
 * credentials -> live). Everything lives as plain WC_Order meta,
 * exactly like class-manual-order-creator.php's own `_yp_manually_created`
 * flag — a web design order is a real, single WC_Order (never a
 * yp_custom_order), so its own project state belongs on it directly
 * rather than in a second post type with nothing else to attach to.
 *
 * Direct request, in order: an "agreement" covering due dates,
 * expectations, add-ons and milestones the customer e-signs before
 * build starts; staged-site credentials staff fill in once ready, sent
 * to the customer with one click; a client review step (approve or
 * request changes) before go-live; and a secure customer-facing portal
 * to collect their production server credentials so staff can push the
 * final site live.
 *
 * Go-live credentials and staged-site passwords are the only secrets
 * this plugin stores reversibly (YeffoPrint_Secret_Box) rather than
 * hashed — staff genuinely need to read them back to do the upload.
 * Go-live credentials also carry an expiry (30 days after submission)
 * that class-web-design-credential-purge.php's cron sweep enforces —
 * once a site is live there's no legitimate reason to keep a client's
 * server password around indefinitely.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Web_Design_Project_Meta {

	// ---- Identity / access ----
	public const ACCESS_TOKEN = '_yp_wd_access_token';

	/**
	 * Set unconditionally on every order a Web Design Package line item
	 * is ever added to (both class-manual-order-creator.php and the
	 * self-serve class-web-design-order-controller.php) — direct
	 * request: a "Web Design Orders" list and a dashboard milestones
	 * panel need to find every such order in ONE bulk query
	 * (wc_get_orders() with a meta_query below), rather than scanning
	 * every order's line items the way is_web_design_order() does for a
	 * single already-known order. That per-order scan stays correct and
	 * unchanged; this flag exists purely so a listing query doesn't have
	 * to load every order in the store to find the handful that qualify.
	 */
	public const ORDER_FLAG = '_yp_web_design_order';

	// ---- Agreement ----
	public const KICKOFF_DATE       = '_yp_wd_kickoff_date';
	public const STAGING_DUE_DATE   = '_yp_wd_staging_due_date';
	public const GOLIVE_DUE_DATE    = '_yp_wd_golive_due_date';
	public const MILESTONES         = '_yp_wd_milestones';          // JSON: [ { label, due_date } ]
	public const ADDONS             = '_yp_wd_addons';              // JSON: [ { label, price } ]
	public const SCOPE_TEXT         = '_yp_wd_scope_text';
	public const AGREEMENT_SENT_AT  = '_yp_wd_agreement_sent_at';
	public const AGREEMENT_SIGNED_NAME = '_yp_wd_agreement_signed_name';
	public const AGREEMENT_SIGNED_AT   = '_yp_wd_agreement_signed_at';
	public const AGREEMENT_SIGNED_IP   = '_yp_wd_agreement_signed_ip';

	// ---- Staged site ----
	public const STAGING_URL           = '_yp_wd_staging_url';
	public const STAGING_ADMIN_URL     = '_yp_wd_staging_admin_url';
	public const STAGING_ADMIN_USER    = '_yp_wd_staging_admin_user';
	public const STAGING_ADMIN_PASS    = '_yp_wd_staging_admin_pass';    // encrypted
	public const STAGING_PREVIEW_USER  = '_yp_wd_staging_preview_user';
	public const STAGING_PREVIEW_PASS  = '_yp_wd_staging_preview_pass';  // encrypted
	public const STAGING_NOTE          = '_yp_wd_staging_note';
	public const STAGING_SENT_AT       = '_yp_wd_staging_sent_at';

	// ---- Client review ----
	public const CLIENT_RESPONSE       = '_yp_wd_client_response'; // 'approved' | 'changes_requested'
	public const CLIENT_RESPONSE_NOTES = '_yp_wd_client_response_notes';
	public const CLIENT_RESPONSE_AT    = '_yp_wd_client_response_at';

	// ---- Go-live access ----
	public const GOLIVE_METHOD       = '_yp_wd_golive_method'; // 'ftp' | 'wp_admin'
	public const GOLIVE_HOST         = '_yp_wd_golive_host';
	public const GOLIVE_PORT         = '_yp_wd_golive_port';
	public const GOLIVE_USERNAME     = '_yp_wd_golive_username';
	public const GOLIVE_PASSWORD     = '_yp_wd_golive_password'; // encrypted
	public const GOLIVE_WP_URL       = '_yp_wd_golive_wp_url';
	public const GOLIVE_NOTES        = '_yp_wd_golive_notes';
	public const GOLIVE_SUBMITTED_AT = '_yp_wd_golive_submitted_at';
	public const GOLIVE_SUBMITTED_IP = '_yp_wd_golive_submitted_ip';
	public const GOLIVE_EXPIRES_AT   = '_yp_wd_golive_expires_at';
	public const GOLIVE_PURGED       = '_yp_wd_golive_purged';

	// ---- Live ----
	public const MARKED_LIVE_AT = '_yp_wd_marked_live_at';

	/** How long a submitted go-live credential is kept before the purge sweep deletes it. */
	private const CREDENTIAL_TTL = 30 * DAY_IN_SECONDS;

	/** Field => type map for encode_list() below — 'done' is the only boolean field either list carries. */
	private const MILESTONE_FIELDS = [ 'label' => 'text', 'due_date' => 'text', 'done' => 'bool' ];
	private const ADDON_FIELDS     = [ 'label' => 'text', 'price' => 'price' ];

	/**
	 * Whether this order contains a Web Design Package line item — the
	 * one thing every endpoint/UI surface in this workflow gates on,
	 * since a normal print order should never grow an Agreement/Staging/
	 * Go-Live panel. Scans line items for the package-product's own
	 * META_PACKAGE_ID (class-web-design-package-product.php), same
	 * signal that product carries regardless of which order-creation
	 * path added it (self-serve Order Now, staff's manual order screen,
	 * or a classic wp-admin "Orders -> Add New").
	 */
	public static function is_web_design_order( \WC_Order $order ): bool {
		return 0 !== self::get_package_id( $order );
	}

	public static function get_package_id( \WC_Order $order ): int {
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			$package_id = (int) $product->get_meta( YeffoPrint_Web_Design_Package_Product::META_PACKAGE_ID );
			if ( $package_id ) {
				return $package_id;
			}
		}
		return 0;
	}

	/** Called once, right where each creation path adds the Web Design Package line item — see ORDER_FLAG's own docblock for why this needs to be a real, queryable flag rather than derived on demand. */
	public static function mark_order( \WC_Order $order ): void {
		$order->update_meta_data( self::ORDER_FLAG, 1 );
	}

	/**
	 * Every order ORDER_FLAG was ever set on, newest first — the "Web
	 * Design Orders" list screen's own data source (direct request: "a
	 * new link... to show me all active web design orders... especially
	 * if I have more than one web design project going at a time").
	 * Bounded at 200 — this is a boutique service's own order list, not
	 * a high-volume report; the screen has no pagination yet because
	 * there's realistically never enough of these to need it, same
	 * "glanceable, not a full report" reasoning as the dashboard's own
	 * ROW_LIMIT elsewhere.
	 *
	 * @return \WC_Order[]
	 */
	public static function get_all_orders(): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return [];
		}

		return wc_get_orders( [
			'limit'      => 200,
			'orderby'    => 'date',
			'order'      => 'DESC',
			'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded, infrequent (one admin screen load), no indexed alternative for "this order flag is set."
				[ 'key' => self::ORDER_FLAG, 'value' => 1 ],
			],
		] );
	}

	/**
	 * Every not-yet-done milestone, across every not-yet-live Web Design
	 * order, due within $lookahead_days or already overdue — direct
	 * request: "add upcoming/overdue milestones onto my main dashboard
	 * so I can see those at a glance." One flat list (not grouped per
	 * order) sorted soonest-due first, so the most urgent item across
	 * every project in flight is always the top row regardless of which
	 * order it belongs to.
	 *
	 * @return array<int, array{order_id:int, order_number:string, package_name:string,
	 *   customer_name:string, label:string, due_date:string, is_overdue:bool}>
	 */
	public static function get_milestone_alerts( int $lookahead_days = 7 ): array {
		$today   = current_time( 'Y-m-d' );
		$horizon = gmdate( 'Y-m-d', strtotime( $today . " +{$lookahead_days} days" ) );
		$alerts  = [];

		foreach ( self::get_all_orders() as $order ) {
			if ( self::is_live( $order ) ) {
				continue;
			}

			$package_id   = self::get_package_id( $order );
			$package      = $package_id ? get_post( $package_id ) : null;
			$package_name = $package ? $package->post_title : __( 'Web Design', 'yeffoprint-core' );
			$customer     = trim( $order->get_formatted_billing_full_name() ) ?: $order->get_billing_email();

			foreach ( self::get_agreement( $order )['milestones'] as $milestone ) {
				$due = (string) ( $milestone['due_date'] ?? '' );
				if ( ! empty( $milestone['done'] ) || '' === $due || $due > $horizon ) {
					continue;
				}

				$alerts[] = [
					'order_id'      => $order->get_id(),
					'order_number'  => $order->get_order_number(),
					'package_name'  => $package_name,
					'customer_name' => (string) $customer,
					'label'         => (string) ( $milestone['label'] ?? '' ),
					'due_date'      => $due,
					'is_overdue'    => $due < $today,
				];
			}
		}

		usort( $alerts, static fn( array $a, array $b ) => $a['due_date'] <=> $b['due_date'] );

		return $alerts;
	}

	/** Lazily mints (and persists) this order's access token the first time anything needs it — same "generate once, never rotate" trust model as YeffoPrint_Custom_Order_Meta::ACCESS_TOKEN. */
	public static function ensure_access_token( \WC_Order $order ): string {
		$token = (string) $order->get_meta( self::ACCESS_TOKEN );
		if ( $token ) {
			return $token;
		}

		$token = wp_generate_password( 40, false );
		$order->update_meta_data( self::ACCESS_TOKEN, $token );
		$order->save();

		return $token;
	}

	// ---- Agreement payload ----

	public static function get_agreement( \WC_Order $order ): array {
		return [
			'kickoff_date'  => (string) $order->get_meta( self::KICKOFF_DATE ),
			'staging_due'   => (string) $order->get_meta( self::STAGING_DUE_DATE ),
			'golive_due'    => (string) $order->get_meta( self::GOLIVE_DUE_DATE ),
			'milestones'    => self::decode_list( $order->get_meta( self::MILESTONES ) ),
			'addons'        => self::decode_list( $order->get_meta( self::ADDONS ) ),
			'scope_text'    => (string) $order->get_meta( self::SCOPE_TEXT ),
			'sent_at'       => (string) $order->get_meta( self::AGREEMENT_SENT_AT ),
			'signed_name'   => (string) $order->get_meta( self::AGREEMENT_SIGNED_NAME ),
			'signed_at'     => (string) $order->get_meta( self::AGREEMENT_SIGNED_AT ),
		];
	}

	/**
	 * @param array $fields { kickoff_date, staging_due, golive_due, milestones: [{label,due_date,done}], addons: [{label,price}], scope_text }
	 */
	public static function save_agreement( \WC_Order $order, array $fields ): void {
		$order->update_meta_data( self::KICKOFF_DATE, sanitize_text_field( (string) ( $fields['kickoff_date'] ?? '' ) ) );
		$order->update_meta_data( self::STAGING_DUE_DATE, sanitize_text_field( (string) ( $fields['staging_due'] ?? '' ) ) );
		$order->update_meta_data( self::GOLIVE_DUE_DATE, sanitize_text_field( (string) ( $fields['golive_due'] ?? '' ) ) );
		$order->update_meta_data( self::MILESTONES, self::encode_list( $fields['milestones'] ?? [], self::MILESTONE_FIELDS ) );
		$order->update_meta_data( self::ADDONS, self::encode_list( $fields['addons'] ?? [], self::ADDON_FIELDS ) );
		$order->update_meta_data( self::SCOPE_TEXT, sanitize_textarea_field( (string) ( $fields['scope_text'] ?? '' ) ) );
		$order->save();
	}

	/**
	 * Milestones live in the same meta field the rest of the agreement
	 * does, but unlike the dates/add-ons/scope text — the actual terms
	 * the customer signed — they're a living checklist: direct request,
	 * staff need to add, edit, reschedule and check off milestones as
	 * the project actually progresses, signed agreement or not. Its own
	 * save path (rather than routing through save_agreement(), which the
	 * admin app locks once is_agreement_signed()) so updating one due
	 * date never has to resend the rest of a now-locked agreement.
	 *
	 * @param array $milestones [{label, due_date, done}]
	 */
	public static function save_milestones( \WC_Order $order, array $milestones ): void {
		$order->update_meta_data( self::MILESTONES, self::encode_list( $milestones, self::MILESTONE_FIELDS ) );
		$order->save();
	}

	public static function is_agreement_signed( \WC_Order $order ): bool {
		return '' !== (string) $order->get_meta( self::AGREEMENT_SIGNED_AT );
	}

	public static function sign_agreement( \WC_Order $order, string $name, string $ip ): void {
		$order->update_meta_data( self::AGREEMENT_SIGNED_NAME, $name );
		$order->update_meta_data( self::AGREEMENT_SIGNED_AT, current_time( 'mysql' ) );
		$order->update_meta_data( self::AGREEMENT_SIGNED_IP, $ip );
		$order->add_order_note(
			sprintf(
				/* translators: 1: signer's typed name, 2: IP address */
				__( 'Web design agreement signed by "%1$s" (IP %2$s).', 'yeffoprint-core' ),
				$name,
				$ip
			)
		);
		$order->save();
	}

	// ---- Staging payload ----

	public static function get_staging( \WC_Order $order, bool $reveal_secrets = false ): array {
		$payload = [
			'staging_url'      => (string) $order->get_meta( self::STAGING_URL ),
			'staging_admin_url' => (string) $order->get_meta( self::STAGING_ADMIN_URL ),
			'admin_user'        => (string) $order->get_meta( self::STAGING_ADMIN_USER ),
			'preview_user'      => (string) $order->get_meta( self::STAGING_PREVIEW_USER ),
			'note'              => (string) $order->get_meta( self::STAGING_NOTE ),
			'sent_at'           => (string) $order->get_meta( self::STAGING_SENT_AT ),
			'has_admin_password'   => '' !== (string) $order->get_meta( self::STAGING_ADMIN_PASS ),
			'has_preview_password' => '' !== (string) $order->get_meta( self::STAGING_PREVIEW_PASS ),
		];

		if ( $reveal_secrets ) {
			$payload['admin_password']   = YeffoPrint_Secret_Box::decrypt( (string) $order->get_meta( self::STAGING_ADMIN_PASS ) );
			$payload['preview_password'] = YeffoPrint_Secret_Box::decrypt( (string) $order->get_meta( self::STAGING_PREVIEW_PASS ) );
		}

		return $payload;
	}

	/**
	 * @param array $fields { staging_url, staging_admin_url, admin_user, admin_password, preview_user, preview_password, note }
	 *                      A blank *_password leaves the previously stored secret untouched — this is a settings-style
	 *                      save, not a fresh submission, so re-showing masked dots and posting them back verbatim would
	 *                      just re-encrypt the same mask text. Staff clear the field and type a new value to replace it.
	 */
	public static function save_staging( \WC_Order $order, array $fields ): void {
		$order->update_meta_data( self::STAGING_URL, esc_url_raw( (string) ( $fields['staging_url'] ?? '' ) ) );
		$order->update_meta_data( self::STAGING_ADMIN_URL, esc_url_raw( (string) ( $fields['staging_admin_url'] ?? '' ) ) );
		$order->update_meta_data( self::STAGING_ADMIN_USER, sanitize_text_field( (string) ( $fields['admin_user'] ?? '' ) ) );
		$order->update_meta_data( self::STAGING_PREVIEW_USER, sanitize_text_field( (string) ( $fields['preview_user'] ?? '' ) ) );
		$order->update_meta_data( self::STAGING_NOTE, sanitize_textarea_field( (string) ( $fields['note'] ?? '' ) ) );

		if ( '' !== (string) ( $fields['admin_password'] ?? '' ) ) {
			$order->update_meta_data( self::STAGING_ADMIN_PASS, YeffoPrint_Secret_Box::encrypt( (string) $fields['admin_password'] ) );
		}
		if ( '' !== (string) ( $fields['preview_password'] ?? '' ) ) {
			$order->update_meta_data( self::STAGING_PREVIEW_PASS, YeffoPrint_Secret_Box::encrypt( (string) $fields['preview_password'] ) );
		}

		$order->save();
	}

	public static function mark_staging_sent( \WC_Order $order ): void {
		$order->update_meta_data( self::STAGING_SENT_AT, current_time( 'mysql' ) );
		$order->add_order_note( __( 'Staging site credentials emailed to the customer.', 'yeffoprint-core' ) );
		$order->save();
	}

	public static function is_staging_sent( \WC_Order $order ): bool {
		return '' !== (string) $order->get_meta( self::STAGING_SENT_AT );
	}

	// ---- Client review ----

	public static function record_client_response( \WC_Order $order, string $response, string $notes ): void {
		$order->update_meta_data( self::CLIENT_RESPONSE, $response );
		$order->update_meta_data( self::CLIENT_RESPONSE_NOTES, $notes );
		$order->update_meta_data( self::CLIENT_RESPONSE_AT, current_time( 'mysql' ) );

		if ( 'approved' === $response ) {
			$order->add_order_note( __( 'Customer approved the staging site — ready for go-live access.', 'yeffoprint-core' ) );
		} else {
			$order->add_order_note(
				sprintf(
					/* translators: %s: the customer's change-request notes */
					__( "Customer requested changes on the staging site:\n\n%s", 'yeffoprint-core' ),
					'' !== $notes ? $notes : __( '(No additional notes provided.)', 'yeffoprint-core' )
				)
			);
		}

		$order->save();
	}

	// ---- Go-live payload ----

	public static function has_golive_submission( \WC_Order $order ): bool {
		return '' !== (string) $order->get_meta( self::GOLIVE_SUBMITTED_AT ) && ! $order->get_meta( self::GOLIVE_PURGED );
	}

	public static function get_golive( \WC_Order $order, bool $reveal_secrets = false ): array {
		$payload = [
			'method'       => (string) $order->get_meta( self::GOLIVE_METHOD ),
			'host'         => (string) $order->get_meta( self::GOLIVE_HOST ),
			'port'         => (string) $order->get_meta( self::GOLIVE_PORT ),
			'username'     => (string) $order->get_meta( self::GOLIVE_USERNAME ),
			'wp_url'       => (string) $order->get_meta( self::GOLIVE_WP_URL ),
			'notes'        => (string) $order->get_meta( self::GOLIVE_NOTES ),
			'submitted_at' => (string) $order->get_meta( self::GOLIVE_SUBMITTED_AT ),
			'expires_at'   => (string) $order->get_meta( self::GOLIVE_EXPIRES_AT ),
			'purged'       => (bool) $order->get_meta( self::GOLIVE_PURGED ),
			'has_password' => '' !== (string) $order->get_meta( self::GOLIVE_PASSWORD ),
		];

		if ( $reveal_secrets ) {
			$payload['password'] = YeffoPrint_Secret_Box::decrypt( (string) $order->get_meta( self::GOLIVE_PASSWORD ) );
		}

		return $payload;
	}

	/**
	 * The one write the customer-facing go-live portal makes. `$ip` is
	 * request-supplied by the caller (REST controller), not read from
	 * $_SERVER here, so this class stays testable without superglobals.
	 *
	 * @param array $fields { method, host, port, username, password, wp_url, notes }
	 */
	public static function submit_golive( \WC_Order $order, array $fields, string $ip ): void {
		$order->update_meta_data( self::GOLIVE_METHOD, 'wp_admin' === ( $fields['method'] ?? '' ) ? 'wp_admin' : 'ftp' );
		$order->update_meta_data( self::GOLIVE_HOST, sanitize_text_field( (string) ( $fields['host'] ?? '' ) ) );
		$order->update_meta_data( self::GOLIVE_PORT, sanitize_text_field( (string) ( $fields['port'] ?? '' ) ) );
		$order->update_meta_data( self::GOLIVE_USERNAME, sanitize_text_field( (string) ( $fields['username'] ?? '' ) ) );
		$order->update_meta_data( self::GOLIVE_WP_URL, esc_url_raw( (string) ( $fields['wp_url'] ?? '' ) ) );
		$order->update_meta_data( self::GOLIVE_NOTES, sanitize_textarea_field( (string) ( $fields['notes'] ?? '' ) ) );
		$order->update_meta_data( self::GOLIVE_PASSWORD, YeffoPrint_Secret_Box::encrypt( (string) ( $fields['password'] ?? '' ) ) );
		$order->update_meta_data( self::GOLIVE_SUBMITTED_AT, current_time( 'mysql' ) );
		$order->update_meta_data( self::GOLIVE_SUBMITTED_IP, $ip );
		$order->update_meta_data( self::GOLIVE_EXPIRES_AT, gmdate( 'Y-m-d H:i:s', time() + self::CREDENTIAL_TTL ) );
		// Deleted, not set to '' — class-web-design-credential-purge.php's
		// sweep finds unpurged submissions with a 'NOT EXISTS' meta query,
		// which only matches a genuinely absent row; a resubmission after
		// an earlier purge needs this row gone, not merely empty, or the
		// new submission would silently never be swept again.
		$order->delete_meta_data( self::GOLIVE_PURGED );
		$order->add_order_note( __( 'Customer submitted go-live server access through the secure portal.', 'yeffoprint-core' ) );
		$order->save();
	}

	public static function mark_credentials_revealed( \WC_Order $order, string $which ): void {
		$order->add_order_note(
			sprintf(
				/* translators: 1: staff display name, 2: which credential set was revealed */
				__( '%1$s revealed the %2$s credentials.', 'yeffoprint-core' ),
				wp_get_current_user()->display_name,
				$which
			)
		);
	}

	/** Wipes the encrypted go-live secret (called only by the purge sweep and by "Mark site as live" — see class-web-design-credential-purge.php). */
	public static function purge_golive_secret( \WC_Order $order, string $reason ): void {
		$order->update_meta_data( self::GOLIVE_PASSWORD, '' );
		$order->update_meta_data( self::GOLIVE_PURGED, 1 );
		$order->add_order_note(
			sprintf(
				/* translators: %s: why the credential was purged */
				__( 'Go-live server password deleted (%s).', 'yeffoprint-core' ),
				$reason
			)
		);
		$order->save();
	}

	public static function mark_live( \WC_Order $order ): void {
		$order->update_meta_data( self::MARKED_LIVE_AT, current_time( 'mysql' ) );
		$order->add_order_note( __( 'Site marked as live.', 'yeffoprint-core' ) );
		self::purge_golive_secret( $order, __( 'site went live', 'yeffoprint-core' ) );
	}

	public static function is_live( \WC_Order $order ): bool {
		return '' !== (string) $order->get_meta( self::MARKED_LIVE_AT );
	}

	/**
	 * Derives one of seven lifecycle stages purely from what's already
	 * on the order — no separate "status" field to keep in sync, same
	 * positional-derivation approach class-order-status-stepper.php's
	 * own docblock already uses for the print-order pipeline ("whichever
	 * stage is first not yet complete becomes current").
	 *
	 * @return string One of: agreement_pending, staging_in_progress,
	 *                client_reviewing, golive_pending, golive_received, live.
	 */
	public static function get_stage( \WC_Order $order ): string {
		if ( self::is_live( $order ) ) {
			return 'live';
		}
		if ( self::has_golive_submission( $order ) ) {
			return 'golive_received';
		}
		$response = (string) $order->get_meta( self::CLIENT_RESPONSE );
		if ( 'approved' === $response ) {
			return 'golive_pending';
		}
		if ( self::is_staging_sent( $order ) ) {
			return 'client_reviewing';
		}
		if ( self::is_agreement_signed( $order ) || ( self::get_staging( $order )['staging_url'] ?? '' ) ) {
			return 'staging_in_progress';
		}
		return 'agreement_pending';
	}

	/**
	 * Builds the `$steps` array YeffoPrint_Order_Status_Stepper's own
	 * render_html()/render_email_html() already know how to draw
	 * (`{state, label, sublabel}`, state one of complete/current/
	 * upcoming) — reusing that renderer rather than duplicating its
	 * markup/CSS for a second, near-identical stepper.
	 */
	public static function get_stepper_steps( \WC_Order $order ): array {
		$stage = self::get_stage( $order );

		$order_index = [
			'agreement_pending'   => 0,
			'staging_in_progress' => 1,
			'client_reviewing'    => 2,
			'golive_pending'      => 2,
			'golive_received'     => 3,
			'live'                => 4,
		];
		// Payment is always complete by the time a Web Design panel can
		// even be shown (see class-admin-order-controller.php's own gate:
		// this only ever renders on a real WC_Order, and every one of
		// these orders is created already needing payment).
		$labels = [
			0 => [ __( 'Agreement Signed', 'yeffoprint-core' ), self::is_agreement_signed( $order ) ? '' : __( 'Awaiting signature', 'yeffoprint-core' ) ],
			1 => [ __( 'Staging Delivered', 'yeffoprint-core' ), '' ],
			2 => [ __( 'Client Reviewing', 'yeffoprint-core' ), 'golive_pending' === $stage ? __( 'Approved', 'yeffoprint-core' ) : '' ],
			3 => [ __( 'Go-Live Access', 'yeffoprint-core' ), '' ],
			4 => [ __( 'Site Live', 'yeffoprint-core' ), '' ],
		];

		$current = $order_index[ $stage ] ?? 0;

		$steps = [
			[ 'state' => 'complete', 'label' => __( 'Order Placed', 'yeffoprint-core' ), 'sublabel' => '' ],
			[ 'state' => $order->needs_payment() ? 'upcoming' : 'complete', 'label' => __( 'Payment Received', 'yeffoprint-core' ), 'sublabel' => '' ],
		];

		foreach ( $labels as $i => $label_pair ) {
			if ( $i < $current ) {
				$state = 'complete';
			} elseif ( $i === $current ) {
				$state = 'current';
			} else {
				$state = 'upcoming';
			}
			$steps[] = [ 'state' => $state, 'label' => $label_pair[0], 'sublabel' => $label_pair[1] ];
		}

		return $steps;
	}

	/**
	 * A compact, email-safe stand-in for get_stepper_steps() — direct
	 * report, with a screenshot: the agreement-ready/staging-ready
	 * emails' progress row rendered badly broken on mobile (the card
	 * itself overflowing the screen). Root cause: those emails fed all
	 * 7 Web Design steps through YeffoPrint_Order_Status_Stepper::
	 * render_email_html(), a component whose own docblock already notes
	 * it targets "an inbox at 600px wide across 4-5 columns" — the
	 * print-order pipeline it was built for never has more than 5. 7
	 * dot-plus-two-line-label columns simply don't fit in 600px, and
	 * email clients (Gmail's app worst of all) don't reliably shrink an
	 * overflowing table the way a browser would.
	 *
	 * This renders a slim, label-free, fixed-pixel-width segmented bar
	 * (no per-step text to wrap) plus one line of plain text naming the
	 * current step — the same information, laid out in a shape that
	 * cannot overflow regardless of client. Used only by the two Web
	 * Design emails; every other order email keeps the real stepper
	 * unchanged (theirs never exceeds 5 steps).
	 */
	public static function get_email_progress_html( \WC_Order $order ): string {
		$steps = self::get_stepper_steps( $order );
		$total = count( $steps );

		$current_index = 0;
		$current_label = '';
		foreach ( $steps as $i => $step ) {
			if ( 'upcoming' === $step['state'] ) {
				break;
			}
			$current_index = $i;
			$current_label = $step['label'];
		}

		// 70px per segment, 6px gaps, 7 segments = 526px — comfortably
		// inside the ~536px content width email-styles.php's own
		// #body_content padding (32px each side of a 600px wrapper)
		// leaves. A fixed pixel width="…" HTML attribute, not a
		// percentage — same Outlook-safety reasoning as the real
		// stepper's own connector cells (class-order-status-stepper.php).
		$segment_width = 70;
		$gap           = 6;

		$cells = '';
		foreach ( $steps as $i => $step ) {
			$filled       = 'upcoming' !== $step['state'];
			$padding_right = $i < $total - 1 ? $gap : 0;
			$cells        .= sprintf(
				'<td width="%1$d" style="width:%1$dpx;padding-right:%2$dpx;"><div style="height:6px;line-height:6px;font-size:0;mso-line-height-rule:exactly;border-radius:3px;background-color:%3$s;">&nbsp;</div></td>',
				$segment_width,
				$padding_right,
				$filled ? '#C2007A' : '#E7E5E1'
			);
		}

		return sprintf(
			'<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>%1$s</tr></table>' .
			'<p style="margin:10px 0 20px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#C2007A;">%2$s</p>',
			$cells, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built entirely from fixed values above, nothing user-supplied.
			sprintf(
				/* translators: 1: current step number, 2: total steps, 3: current step's label */
				esc_html__( 'Step %1$d of %2$d — %3$s', 'yeffoprint-core' ),
				$current_index + 1,
				$total,
				esc_html( $current_label )
			)
		);
	}

	// ---- Public-page links (guest access via ACCESS_TOKEN, same trust model as yeffoprint_core_proof_approval_url()) ----

	public static function get_agreement_url( \WC_Order $order ): string {
		return add_query_arg(
			[ 'order' => $order->get_id(), 'token' => self::ensure_access_token( $order ) ],
			home_url( '/web-design-agreement/' )
		);
	}

	public static function get_staging_review_url( \WC_Order $order ): string {
		return add_query_arg(
			[ 'order' => $order->get_id(), 'token' => self::ensure_access_token( $order ) ],
			home_url( '/web-design-staging-review/' )
		);
	}

	public static function get_golive_url( \WC_Order $order ): string {
		return add_query_arg(
			[ 'order' => $order->get_id(), 'token' => self::ensure_access_token( $order ) ],
			home_url( '/web-design-golive/' )
		);
	}

	// ---- Small helpers ----

	/** @param array<string,string> $field_types Field name => 'text'|'price'|'bool' (see MILESTONE_FIELDS/ADDON_FIELDS above). A row with no 'label' is dropped — a bare "done" toggle with nothing else typed isn't a real entry yet. */
	private static function encode_list( $raw, array $field_types ): string {
		if ( ! is_array( $raw ) ) {
			return '[]';
		}

		$clean = [];
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) || '' === trim( (string) ( $row['label'] ?? '' ) ) ) {
				continue;
			}
			$entry = [];
			foreach ( $field_types as $key => $type ) {
				$value = $row[ $key ] ?? '';
				if ( 'bool' === $type ) {
					$entry[ $key ] = ! empty( $value );
				} elseif ( 'price' === $type ) {
					$entry[ $key ] = (string) round( (float) $value, 2 );
				} else {
					$entry[ $key ] = sanitize_text_field( (string) $value );
				}
			}
			$clean[] = $entry;
		}

		return wp_json_encode( $clean );
	}

	private static function decode_list( $raw ): array {
		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? $decoded : [];
	}
}
