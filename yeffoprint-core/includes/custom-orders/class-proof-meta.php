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
}
