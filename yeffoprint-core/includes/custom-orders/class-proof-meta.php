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

	private static function advance_status_to_awaiting_approval( int $custom_order_id ): void {
		$current = (string) get_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::STATUS, true );

		if ( ! in_array( $current, [ 'design_in_progress', 'proof_ready' ], true ) ) {
			return;
		}

		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::STATUS, 'awaiting_approval' );
		self::notify_customer( $custom_order_id );
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

		$body = sprintf(
			/* translators: 1: customer's first name or "there", 2: proof approval URL, 3: site name */
			__( "Hi %1\$s,\n\nYour custom label proof is ready to review. Please take a look and let us know if it's good to print:\n\n%2\$s\n\nNo account needed — that link is yours alone, so don't share it.\n\nThanks,\n%3\$s", 'yeffoprint-core' ),
			$name ? $name : __( 'there', 'yeffoprint-core' ),
			$url,
			$site_name
		);

		wp_mail( $email, $subject, $body );
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
