<?php
/**
 * Shop Labels sort + filter bar.
 *
 * Entirely GET-based server rendering — no client JS required for
 * correctness (a small enhancement in assets/js/site.js auto-submits
 * the sort <select> on change; the Apply button covers JS-disabled
 * visitors). Sorting is handled by YeffoPrint_Template_Query and
 * filtering by WordPress's native taxonomy query vars — see
 * docs/ARCHITECTURE.md §9.
 */

defined( 'ABSPATH' ) || exit;

$base_url = get_post_type_archive_link( 'yp_template' );

if ( ! $base_url ) {
	return;
}

$sort_options = [
	'featured'   => __( 'Featured', 'yeffoprint' ),
	'newest'     => __( 'Newest', 'yeffoprint' ),
	'popularity' => __( 'Most Popular', 'yeffoprint' ),
];

$filter_taxonomies = [
	'yp_style'        => __( 'Style', 'yeffoprint' ),
	'yp_color'        => __( 'Color', 'yeffoprint' ),
	'yp_material_tag' => __( 'Material', 'yeffoprint' ),
];

$current_sort = 'featured';
if ( isset( $_GET['sort'] ) ) {
	$requested = sanitize_key( wp_unslash( $_GET['sort'] ) );
	if ( array_key_exists( $requested, $sort_options ) ) {
		$current_sort = $requested;
	}
}

$active_filters = [];
foreach ( array_keys( $filter_taxonomies ) as $taxonomy ) {
	if ( ! empty( $_GET[ $taxonomy ] ) ) {
		$active_filters[ $taxonomy ] = sanitize_title( wp_unslash( $_GET[ $taxonomy ] ) );
	}
}
?>
<div class="yp-gallery-toolbar">

	<div class="yp-filter-group">
		<?php foreach ( $filter_taxonomies as $taxonomy => $label ) :
			$terms = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => true ] );

			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) :
				$is_active = isset( $active_filters[ $taxonomy ] ) && $active_filters[ $taxonomy ] === $term->slug;

				$pill_args = $active_filters;
				if ( $is_active ) {
					unset( $pill_args[ $taxonomy ] );
				} else {
					$pill_args[ $taxonomy ] = $term->slug;
				}
				if ( 'featured' !== $current_sort ) {
					$pill_args['sort'] = $current_sort;
				}

				$href = $pill_args ? add_query_arg( $pill_args, $base_url ) : $base_url;
				?>
				<a
					class="yp-filter-pill<?php echo $is_active ? ' is-active' : ''; ?>"
					href="<?php echo esc_url( $href ); ?>"
					<?php echo $is_active ? 'aria-current="true"' : ''; ?>
				><?php echo esc_html( $term->name ); ?></a>
			<?php endforeach;
		endforeach; ?>
	</div>

	<form class="yp-sort-form" method="get" action="<?php echo esc_url( $base_url ); ?>">
		<?php foreach ( $active_filters as $taxonomy => $slug ) : ?>
			<input type="hidden" name="<?php echo esc_attr( $taxonomy ); ?>" value="<?php echo esc_attr( $slug ); ?>" />
		<?php endforeach; ?>

		<label class="screen-reader-text" for="yp-sort-select"><?php esc_html_e( 'Sort by', 'yeffoprint' ); ?></label>
		<select class="yp-sort-select" id="yp-sort-select" name="sort">
			<?php foreach ( $sort_options as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_sort, $value ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<button type="submit" class="wp-block-button__link"><?php esc_html_e( 'Apply', 'yeffoprint' ); ?></button>
	</form>

</div>
