<?php
/**
 * Data model for Proofs — Architecture §2's `proof_history[]`, stored
 * as one yp_proof post per proof rather than an array on CustomOrder,
 * so each proof carries its own file, timestamp (post_date), and —
 * when the future customer-facing proof portal is built (Architecture
 * §8) — its own approval/comment state without reshaping this record.
 *
 * V1 is admin-upload only: staff attach a proof file to a CustomOrder,
 * which advances that order's status to "Proof ready". The customer-
 * facing view/approve/request-changes flow is an explicit V1 non-goal
 * (PROJECT_SPEC §19) — this only has to not require a rebuild later.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Proof_Meta {

	public const CUSTOM_ORDER_ID = '_yp_custom_order_id';
	public const FILE_ID         = '_yp_file_id';

	/** @return int[] Proof post IDs for a CustomOrder, newest first. */
	public static function get_for_custom_order( int $custom_order_id ): array {
		return get_posts( [
			'post_type'      => 'yp_proof',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'meta_query'     => [
				[
					'key'   => self::CUSTOM_ORDER_ID,
					'value' => $custom_order_id,
				],
			],
		] );
	}

	/**
	 * Sets a Proof's two meta fields and — when both are actually
	 * present — advances its CustomOrder to "Awaiting Proof Approval"
	 * and emails the customer their approval link. Moved down here from
	 * class-proof-editor.php (originally private methods on that class,
	 * only ever reachable from its own `save_post_yp_proof` hook) so the
	 * new admin REST proof endpoint (class-admin-proof-controller.php)
	 * can trigger the exact same behavior instead of reimplementing it —
	 * same "business logic lives in the data layer, not any one UI"
	 * reasoning as YeffoPrint_Sticker_Size_Meta::enforce_single_custom_tier().
	 */
	public static function attach_file( int $proof_id, int $custom_order_id, int $file_id ): void {
		update_post_meta( $proof_id, self::CUSTOM_ORDER_ID, $custom_order_id );
		update_post_meta( $proof_id, self::FILE_ID, $file_id );

		if ( $custom_order_id && $file_id ) {
			self::advance_status_to_awaiting_approval( $custom_order_id );
		}
	}

	/**
	 * Direct report: staff attaching a corrected second proof while the
	 * order was still `awaiting_approval` (the customer hadn't yet
	 * clicked "Request changes" on the first one — a phone call, or
	 * staff catching their own mistake) sent no email at all. The old
	 * guard here only allowed this from `design_in_progress`/`proof_ready`
	 * — a fresh order and a completed "Request changes" round trip — on
	 * the assumption a proof is only ever attached from one of those two
	 * states. That's true for the *first* proof, but staff can and do
	 * attach a follow-up proof before the customer has responded to the
	 * one already up for review. Denylisting the handful of states where
	 * the design is genuinely finished (same set FEE_FREE_REORDER_
	 * STATUSES already uses for "this design is done") instead of
	 * allowlisting the pre-states covers every legitimate "staff just
	 * attached a proof" case, including re-entering `awaiting_approval`
	 * from itself — which is also exactly the reset AWAITING_APPROVAL_AT/
	 * PROOF_REMINDER_STAGE below already need: a new proof means a new
	 * 24h/48h reminder clock, not the old one still ticking against a
	 * proof that's no longer current.
	 */
	private static function advance_status_to_awaiting_approval( int $custom_order_id ): void {
		$current = (string) get_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::STATUS, true );

		if ( in_array( $current, [ 'approved', 'printing', 'shipped' ], true ) ) {
			return;
		}

		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::STATUS, 'awaiting_approval' );
		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::AWAITING_APPROVAL_AT, time() );
		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::PROOF_REMINDER_STAGE, 0 );
		self::notify_customer( $custom_order_id );

		/**
		 * Lets other modules (the Telegram proactive-notification
		 * integration, includes/telegram/class-telegram-order-
		 * notifications.php) react to a proof becoming ready without this
		 * class needing to know they exist — same reasoning as the
		 * Contact form's yeffoprint_contact_form_submitted action. Fired
		 * regardless of whether notify_customer()'s own email actually
		 * went out (it bails early on a missing/invalid address), since a
		 * Telegram ping doesn't depend on the customer having a usable
		 * email at all.
		 */
		do_action( 'yeffoprint_proof_ready_for_review', $custom_order_id );
	}

	/**
	 * Best-effort only — a failed/absent email is never the sole way to
	 * reach the customer, since the admin screen always shows the same
	 * link for staff to copy and send directly (guest orders especially
	 * may have gone through with an email that bounces or was mistyped).
	 */
	private static function notify_customer( int $custom_order_id ): void {
		$email = (string) get_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::CUSTOMER_EMAIL, true );
		if ( ! $email || ! is_email( $email ) ) {
			return;
		}

		$name = (string) get_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::CUSTOMER_NAME, true );
		$url  = yeffoprint_core_proof_approval_url( $custom_order_id );
		if ( ! $url ) {
			return;
		}

		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$subject = sprintf(
			/* translators: %s: site name */
			__( 'Your proof is ready to review — %s', 'yeffoprint-core' ),
			$site_name
		);

		self::send_branded_notice( $email, $subject, [
			'email_heading'   => __( 'Your proof is ready to review', 'yeffoprint-core' ),
			'name'            => $name ? $name : __( 'there', 'yeffoprint-core' ),
			'eyebrow'         => __( 'Proof ready', 'yeffoprint-core' ),
			'intro'           => __( "Take a look at your custom label proof and let us know if it's good to print.", 'yeffoprint-core' ),
			'cta_url'         => $url,
			'proof_image_url' => self::latest_proof_image_url( $custom_order_id ),
		] );
	}

	/**
	 * The 24h/48h proof-approval reminder email — same "best-effort,
	 * bails silently on a missing/invalid address" shape as
	 * notify_customer() above, called instead of reimplemented by
	 * class-proof-reminder-scheduler.php's sweep so this class stays the
	 * one place that knows how to build a proof-related email. Public
	 * (unlike notify_customer()) since the scheduler is a different
	 * class; $stage is 1 (24h) or 2 (48h), only used to vary the copy's
	 * urgency.
	 */
	public static function send_reminder_email( int $custom_order_id, int $stage ): void {
		$email = (string) get_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::CUSTOMER_EMAIL, true );
		if ( ! $email || ! is_email( $email ) ) {
			return;
		}

		$name = (string) get_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::CUSTOMER_NAME, true );
		$url  = yeffoprint_core_proof_approval_url( $custom_order_id );
		if ( ! $url ) {
			return;
		}

		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$subject = 2 === $stage
			? sprintf( /* translators: %s: site name */ __( 'Still waiting on your OK — %s', 'yeffoprint-core' ), $site_name )
			: sprintf( /* translators: %s: site name */ __( "Don't forget to review your proof — %s", 'yeffoprint-core' ), $site_name );

		$email_heading = 2 === $stage
			? __( 'Still waiting on your OK', 'yeffoprint-core' )
			: __( "Don't forget to review your proof", 'yeffoprint-core' );

		$intro = 2 === $stage
			? __( "It's been a couple of days since your custom label proof went up for review, and we haven't heard back yet. We'd love to get this printing for you.", 'yeffoprint-core' )
			: __( 'Just a friendly reminder — your custom label proof is still waiting on your review.', 'yeffoprint-core' );

		self::send_branded_notice( $email, $subject, [
			'email_heading'   => $email_heading,
			'name'            => $name ? $name : __( 'there', 'yeffoprint-core' ),
			'eyebrow'         => __( 'Still waiting', 'yeffoprint-core' ),
			'intro'           => $intro,
			'cta_url'         => $url,
			'proof_image_url' => self::latest_proof_image_url( $custom_order_id ),
		] );
	}

	/**
	 * @see notify_customer()/send_reminder_email() above — the one place
	 * both build the branded HTML email instead of a bare wp_mail()
	 * string. `WC()->mailer()` first, same as class-manual-order-
	 * creator.php's own `WC()->mailer()->customer_invoice()` call — the
	 * base \WC_Email class our email class extends is only ever
	 * `include_once`'d lazily, inside WC_Emails::init() (itself only
	 * ever run the first time something calls WC()->mailer()), not
	 * eagerly at plugin bootstrap. This runs from a WP-Cron sweep
	 * (class-proof-reminder-scheduler.php) as well as normal admin/REST
	 * requests, so it can't assume something else already forced that
	 * init this request.
	 */
	private static function send_branded_notice( string $to, string $subject, array $args ): void {
		WC()->mailer();

		require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-email-proof-notice.php';
		( new YeffoPrint_Email_Proof_Notice() )->send_notice( $to, $subject, $args );
	}

	/**
	 * Public wrapper for account Proofs thumbs (and anything else that
	 * wants the latest image proof without reimplementing the PDF skip).
	 */
	public static function get_latest_proof_image_url( int $custom_order_id ): string {
		return self::latest_proof_image_url( $custom_order_id );
	}

	/**
	 * The latest proof's own attachment URL, only when it's actually an
	 * image — same wp_attachment_is_image() check the public proof-
	 * approval page's own REST payload already makes
	 * (class-proof-approval-controller.php::get_proof()) — so a PDF
	 * proof correctly renders no thumbnail in the email rather than a
	 * broken/empty one.
	 */
	private static function latest_proof_image_url( int $custom_order_id ): string {
		$proof_ids = self::get_for_custom_order( $custom_order_id );
		if ( ! $proof_ids ) {
			return '';
		}

		$file_id = (int) get_post_meta( $proof_ids[0], self::FILE_ID, true );
		if ( ! $file_id || ! wp_attachment_is_image( $file_id ) ) {
			return '';
		}

		return (string) wp_get_attachment_url( $file_id );
	}
}

if ( ! function_exists( 'yeffoprint_core_proof_approval_url' ) ) {
	/**
	 * The one link that gets a guest customer (no account required) to
	 * their proof — shared by the "notify customer" email and the admin
	 * Proofs box (so staff can also copy/resend it directly). Empty if
	 * the CustomOrder has no access token yet (shouldn't happen for any
	 * request created after this feature shipped — class-custom-order-
	 * controller.php generates one at submission).
	 */
	function yeffoprint_core_proof_approval_url( int $custom_order_id ): string {
		$token = (string) get_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::ACCESS_TOKEN, true );
		if ( ! $token ) {
			return '';
		}

		return add_query_arg(
			[
				'custom_order' => $custom_order_id,
				'token'        => $token,
			],
			home_url( '/proof-approval/' )
		);
	}
}
