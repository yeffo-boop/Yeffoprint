<?php
/**
 * Shop Labels gallery query rules: default ordering and sort options.
 *
 * PROJECT_SPEC §9: "Default order: featured/relevant → newest →
 * popularity. Sort options: Featured / Newest / Most Popular." This
 * runs entirely server-side against the ?orderby= query arg — no JS
 * needed, matching the spec's "richer JS is justified [only in] the
 * configurator" performance stance. Style/Color/Material filters need
 * no code at all: their taxonomies are registered with matching
 * query_vars (see class-template-taxonomies.php) and WordPress folds
 * ?yp_style=...&yp_color=... into the main archive query automatically.
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
