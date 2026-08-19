<?php
/**
 * Dev-only demo data for validating the storefront end to end.
 *
 * `wp yeffoprint seed` — inserts demo Templates (with taxonomy terms
 * and gallery-relevant meta), the launch Sizes and Materials, and
 * wires each Template's compatible_sizes/compatible_materials plus a
 * small field_schema, so the Shop Labels gallery, filters, sort,
 * search, AND the Phase 5 configurator can all be exercised against
 * real data instead of empty states.
 *
 * Never runs automatically (no activation hook, no admin-init hook) —
 * only via an explicit WP-CLI invocation — and is idempotent:
 * everything is looked up by slug first, so re-running the command
 * updates rather than duplicates. See docs/ARCHITECTURE.md §1
 * ("Seed/demo data tooling ... never auto-overwrites production
 * content").
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Seed_Command {

	private const DEMO_SIZES = [
		[ 'slug' => '3ml', 'title' => '3 mL', 'width_mm' => 20, 'height_mm' => 40, 'price_adjustment' => 0 ],
		[ 'slug' => '10ml', 'title' => '10 mL', 'width_mm' => 30, 'height_mm' => 60, 'price_adjustment' => 0.05 ],
	];

	private const DEMO_MATERIALS = [
		[ 'slug' => 'glossy-white', 'title' => 'Glossy White', 'price_adjustment' => 0 ],
		[ 'slug' => 'matte-white', 'title' => 'Matte White', 'price_adjustment' => 0 ],
		[ 'slug' => 'holographic', 'title' => 'Holographic', 'price_adjustment' => 0.08 ],
		[ 'slug' => 'clear', 'title' => 'Clear', 'price_adjustment' => 0.03 ],
		[ 'slug' => 'metallic', 'title' => 'Metallic', 'price_adjustment' => 0.08 ],
	];

	private const DEMO_FIELD_SCHEMA = [
		[
			'id'                => 'product-name',
			'label'             => 'Product Name',
			'type'              => 'text',
			'default'           => 'Your Product',
			'required'          => true,
			'max_chars'         => 24,
			'position'          => [ 'x' => 50, 'y' => 38 ],
			'alignment'         => 'center',
			'font_size_min'     => 12,
			'font_size_max'     => 28,
			'formatting_rule'   => 'uppercase',
			'preview_behavior'  => 'scale-to-fit',
			'admin_description' => 'The bold headline on the label.',
		],
		[
			'id'                => 'details',
			'label'             => 'Details',
			'type'              => 'textarea',
			'default'           => "10 mg/mL\nFor research use only",
			'required'          => false,
			'max_chars'         => 60,
			'position'          => [ 'x' => 50, 'y' => 62 ],
			'alignment'         => 'center',
			'font_size_min'     => 8,
			'font_size_max'     => 13,
			'formatting_rule'   => 'none',
			'preview_behavior'  => 'scale-to-fit',
			'admin_description' => 'Smaller supporting text below the product name.',
		],
	];

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
	 * Insert or update the demo Size/Material/Template records.
	 *
	 * ## EXAMPLES
	 *
	 *     wp yeffoprint seed
	 */
	public function seed(): void {
		$size_ids       = $this->seed_records( 'yp_size', self::DEMO_SIZES );
		$material_ids   = $this->seed_records( 'yp_material', self::DEMO_MATERIALS );
		$all_size_ids   = array_values( $size_ids );

		foreach ( $size_ids as $slug => $post_id ) {
			$size = $this->find_demo( self::DEMO_SIZES, $slug );
			update_post_meta( $post_id, YeffoPrint_Commerce_Record_Meta::PRINT_WIDTH_MM, $size['width_mm'] );
			update_post_meta( $post_id, YeffoPrint_Commerce_Record_Meta::PRINT_HEIGHT_MM, $size['height_mm'] );
			update_post_meta( $post_id, YeffoPrint_Commerce_Record_Meta::PRICE_ADJUSTMENT, $size['price_adjustment'] );
		}

		foreach ( $material_ids as $slug => $post_id ) {
			$material = $this->find_demo( self::DEMO_MATERIALS, $slug );
			update_post_meta( $post_id, YeffoPrint_Commerce_Record_Meta::PRICE_ADJUSTMENT, $material['price_adjustment'] );
		}

		// Map by title so DEMO_TEMPLATES' 'material' lists (plain names)
		// can look up the material IDs just created above.
		$material_ids_by_title = [];
		foreach ( self::DEMO_MATERIALS as $material ) {
			$material_ids_by_title[ $material['title'] ] = $material_ids[ $material['slug'] ];
		}

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
			update_post_meta( $post_id, YeffoPrint_Template_Meta::COMPATIBLE_SIZES, $all_size_ids );

			$compatible_material_ids = array_values( array_filter( array_map(
				static function ( $name ) use ( $material_ids_by_title ) {
					return $material_ids_by_title[ $name ] ?? null;
				},
				$demo['material']
			) ) );
			update_post_meta( $post_id, YeffoPrint_Template_Meta::COMPATIBLE_MATERIALS, $compatible_material_ids );

			YeffoPrint_Field_Schema::update( $post_id, self::DEMO_FIELD_SCHEMA );

			// Terms are set after wp_insert_post(), so the search-index
			// rebuild that ran on save_post_yp_template above missed them
			// — re-fire it now that taxonomy terms exist.
			do_action( 'save_post_yp_template', $post_id );

			\WP_CLI::log( ( $existing ? 'Updated' : 'Created' ) . ": {$demo['title']}" );
		}

		\WP_CLI::success( 'Demo sizes, materials, and templates seeded.' );
	}

	/**
	 * @param string $post_type
	 * @param array  $records Each with a 'slug' and 'title' key at minimum.
	 * @return array<string, int> slug => post ID
	 */
	private function seed_records( string $post_type, array $records ): array {
		$ids = [];

		foreach ( $records as $record ) {
			$existing = get_page_by_path( $record['slug'], OBJECT, $post_type );

			$post_id = wp_insert_post( [
				'ID'          => $existing ? $existing->ID : 0,
				'post_type'   => $post_type,
				'post_title'  => $record['title'],
				'post_name'   => $record['slug'],
				'post_status' => 'publish',
			], true );

			if ( is_wp_error( $post_id ) ) {
				\WP_CLI::warning( "Skipped {$record['title']}: " . $post_id->get_error_message() );
				continue;
			}

			$ids[ $record['slug'] ] = $post_id;
		}

		return $ids;
	}

	private function find_demo( array $records, string $slug ): array {
		foreach ( $records as $record ) {
			if ( $record['slug'] === $slug ) {
				return $record;
			}
		}

		return [];
	}
}
