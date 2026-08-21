<?php
/**
 * Shop Labels gallery query rules: default ordering, sort options, and
 * page size.
 *
 * PROJECT_SPEC §9: "Default order: featured/relevant → newest →
 * popularity. Sort options: Featured / Newest / Most Popular." This
 * runs entirely server-side against the ?orderby= query arg — no JS
 * needed, matching the spec's "richer JS is justified [only in] the
 * configurator" performance stance. Style/Color/Material filters need
 * no code at all: their taxonomies are registered with matching
 * query_vars (see class-template-taxonomies.php) and WordPress folds
 * ?yp_style=...&yp_color=... into the main archive query automatically.
 *
 * Direct request: show every template on one page instead of paginating.
 * templates/archive-yp_template.html's Query block is deliberately
 * `"inherit":true` (not its own `perPage`) — that's what lets the
 * filters/sort above work at all, since they hook is_main_query() and a
 * non-inherited query builds a separate WP_Query these wouldn't touch —
 * but it also means the block's own `perPage` attribute is ignored in
 * favor of Settings → Reading's site-wide posts-per-page. Overriding
 * posts_per_page here, in the same already-correctly-gated hook, is the
 * one place that can change this archive's page size without disturbing
 * either of those or the blog's own unrelated per-page setting.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Template_Query {

	public const SORTS = [ 'featured', 'newest', 'popularity' ];

	public function __construct() {
		add_action( 'pre_get_posts', [ $this, 'apply_shop_labels_sort' ] );
	}

	public function apply_shop_labels_sort( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( ! $query->is_post_type_archive( 'yp_template' ) ) {
			return;
		}

		// -1: every template on one page — see the class doc comment.
		$query->set( 'posts_per_page', -1 );

		$sort = isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : 'featured';
		if ( ! in_array( $sort, self::SORTS, true ) ) {
			$sort = 'featured';
		}

		switch ( $sort ) {
			case 'newest':
				$query->set( 'orderby', 'date' );
				$query->set( 'order', 'DESC' );
				break;

			case 'popularity':
				$query->set( 'meta_key', YeffoPrint_Template_Meta::POPULARITY );
				$query->set( 'orderby', [ 'meta_value_num' => 'DESC', 'date' => 'DESC' ] );
				break;

			case 'featured':
			default:
				$query->set( 'meta_key', YeffoPrint_Template_Meta::FEATURED );
				$query->set( 'orderby', [ 'meta_value' => 'DESC', 'date' => 'DESC' ] );
				break;
		}
	}
}
