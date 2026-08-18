<?php
/**
 * Predictive search over Templates.
 *
 * PROJECT_SPEC §9: "Predictive search indexing name, tags, color,
 * style, material, metadata." Core WordPress search only matches
 * post_title/content/excerpt, so on every save we flatten the
 * template's name + taxonomy terms into a single `_yp_search_index`
 * meta value, then extend the search SQL to also match against it.
 * Runs for both the classic `s=` query (used by the archive/search
 * template) and the REST `?search=` param (used by the header's
 * predictive dropdown — see assets/js/search.js in the theme).
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Template_Search {

	public function __construct() {
		add_action( 'save_post_yp_template', [ $this, 'rebuild_search_index' ], 20, 1 );
		add_filter( 'posts_join', [ $this, 'join_search_index' ], 10, 2 );
		add_filter( 'posts_search', [ $this, 'match_search_index' ], 10, 2 );
		add_filter( 'posts_distinct', [ $this, 'distinct_when_joined' ], 10, 2 );
	}

	public function rebuild_search_index( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		$parts = [ $post->post_title ];

		foreach ( [ 'yp_style', 'yp_color', 'yp_material_tag' ] as $taxonomy ) {
			$terms = get_the_terms( $post_id, $taxonomy );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$parts[] = $term->name;
				}
			}
		}

		$index = strtolower( implode( ' ', array_filter( $parts ) ) );

		update_post_meta( $post_id, YeffoPrint_Template_Meta::SEARCH_INDEX, $index );
	}

	private function is_template_search( \WP_Query $query ): bool {
		if ( ! $query->is_search() || empty( $query->query_vars['s'] ) ) {
			return false;
		}

		$post_type = $query->get( 'post_type' );

		return 'yp_template' === $post_type || ( is_array( $post_type ) && in_array( 'yp_template', $post_type, true ) );
	}

	public function join_search_index( string $join, \WP_Query $query ): string {
		global $wpdb;

		if ( ! $this->is_template_search( $query ) ) {
			return $join;
		}

		$join .= " LEFT JOIN {$wpdb->postmeta} AS yp_search_index ON ({$wpdb->posts}.ID = yp_search_index.post_id AND yp_search_index.meta_key = '" . esc_sql( YeffoPrint_Template_Meta::SEARCH_INDEX ) . "')";

		return $join;
	}

	/**
	 * $search arrives as the self-contained fragment core builds for
	 * the 's' param, e.g. " AND ((wp_posts.post_title LIKE '%x%') OR
	 * (wp_posts.post_content LIKE '%x%'))" — already wrapped in its own
	 * parens and AND'd onto the rest of the query. We splice our OR
	 * condition inside that closing paren so it stays inside the same
	 * group instead of loosening the surrounding post_type/post_status
	 * AND conditions.
	 */
	public function match_search_index( string $search, \WP_Query $query ): string {
		global $wpdb;

		if ( '' === $search || ! $this->is_template_search( $query ) ) {
			return $search;
		}

		$like = '%' . $wpdb->esc_like( strtolower( $query->query_vars['s'] ) ) . '%';
		$meta_clause = $wpdb->prepare( ' OR (yp_search_index.meta_value LIKE %s)', $like );

		return preg_replace( '/\)\s*$/', $meta_clause . ')', $search, 1 );
	}

	public function distinct_when_joined( string $distinct, \WP_Query $query ): string {
		if ( $this->is_template_search( $query ) ) {
			return 'DISTINCT';
		}

		return $distinct;
	}
}
