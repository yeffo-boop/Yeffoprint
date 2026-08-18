<?php
/**
 * Dev-only demo data for validating the Phase 3 storefront.
 *
 * `wp yeffoprint seed` — inserts a handful of demo Templates with
 * taxonomy terms and gallery-relevant meta so the Shop Labels gallery,
 * filters, sort, and predictive search can be exercised end to end.
 *
 * Never runs automatically (no activation hook, no admin-init hook) —
 * only via an explicit WP-CLI invocation — and is idempotent: each
 * demo template is looked up by slug first, so re-running the command
 * updates rather than duplicates. See docs/ARCHITECTURE.md §1
 * ("Seed/demo data tooling ... never auto-overwrites production
 * content").
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Seed_Command {

	private const DEMO_TEMPLATES = [
		[
			'slug'       => 'pure',
			'title'      => 'Pure',
			'style'      => 'Minimal',
			'color'      => 'White',
			'material'   => [ 'Glossy White', 'Matte White' ],
			'featured'   => true,
			'popularity' => 92,
			'badge'      => 'featured',
		],
		[
			'slug'       => 'nova',
			'title'      => 'Nova',
			'style'      => 'Bold',
			'color'      => 'Black',
			'material'   => [ 'Matte White', 'Metallic' ],
			'featured'   => true,
			'popularity' => 88,
			'badge'      => 'popular',
		],
		[
			'slug'       => 'aurora',
			'title'      => 'Aurora',
			'style'      => 'Gradient',
			'color'      => 'Multicolor',
			'material'   => [ 'Holographic' ],
			'featured'   => false,
			'popularity' => 75,
			'badge'      => 'new',
		],
		[
			'slug'       => 'clarity',
			'title'      => 'Clarity',
			'style'      => 'Minimal',
			'color'      => 'Clear',
			'material'   => [ 'Clear' ],
			'featured'   => false,
			'popularity' => 60,
			'badge'      => '',
		],
		[
			'slug'       => 'foundry',
			'title'      => 'Foundry',
			'style'      => 'Industrial',
			'color'      => 'Charcoal',
			'material'   => [ 'Matte White', 'Metallic' ],
			'featured'   => false,
			'popularity' => 54,
			'badge'      => 'customizable',
		],
		[
			'slug'       => 'signal',
			'title'      => 'Signal',
			'style'      => 'Bold',
			'color'      => 'Cyan',
			'material'   => [ 'Glossy White', 'Holographic' ],
			'featured'   => false,
			'popularity' => 47,
			'badge'      => '',
		],
	];

	public function register(): void {
		\WP_CLI::add_command( 'yeffoprint seed', [ $this, 'seed' ] );
	}

	/**
	 * Insert or update the demo Template records.
	 *
	 * ## EXAMPLES
	 *
	 *     wp yeffoprint seed
	 */
	public function seed(): void {
		foreach ( self::DEMO_TEMPLATES as $demo ) {
			$existing = get_page_by_path( $demo['slug'], OBJECT, 'yp_template' );

			$post_id = wp_insert_post( [
				'ID'          => $existing ? $existing->ID : 0,
				'post_type'   => 'yp_template',
				'post_title'  => $demo['title'],
				'post_name'   => $demo['slug'],
				'post_status' => 'publish',
			], true );

			if ( is_wp_error( $post_id ) ) {
				\WP_CLI::warning( "Skipped {$demo['title']}: " . $post_id->get_error_message() );
				continue;
			}

			wp_set_object_terms( $post_id, $demo['style'], 'yp_style' );
			wp_set_object_terms( $post_id, $demo['color'], 'yp_color' );
			wp_set_object_terms( $post_id, $demo['material'], 'yp_material_tag' );

			update_post_meta( $post_id, YeffoPrint_Template_Meta::FEATURED, $demo['featured'] );
			update_post_meta( $post_id, YeffoPrint_Template_Meta::POPULARITY, $demo['popularity'] );
			update_post_meta( $post_id, YeffoPrint_Template_Meta::BADGE, $demo['badge'] );

			// Terms are set after wp_insert_post(), so the search-index
			// rebuild that ran on save_post_yp_template above missed them
			// — re-fire it now that taxonomy terms exist.
			do_action( 'save_post_yp_template', $post_id );

			\WP_CLI::log( ( $existing ? 'Updated' : 'Created' ) . ": {$demo['title']}" );
		}

		\WP_CLI::success( 'Demo templates seeded.' );
	}
}
