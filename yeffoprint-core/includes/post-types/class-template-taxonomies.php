<?php
/**
 * Taxonomies for filtering/searching Templates.
 *
 * Corresponds to the "tags: searchable / color / style /
 * material-compatibility" field on Template in docs/ARCHITECTURE.md
 * §2. Split into three taxonomies rather than one flat "tags" field
 * so the Shop Labels gallery filters (PROJECT_SPEC §9: Style, Color,
 * Material compatibility) can query each independently. Registered
 * with a query_var so the Shop Labels archive can filter via plain
 * URL params without any custom JS (?yp_style=bold&yp_color=black).
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Template_Taxonomies {

	public function __construct() {
		add_action( 'init', [ $this, 'register_taxonomies' ] );
	}

	public function register_taxonomies(): void {
		register_taxonomy( 'yp_style', 'yp_template', $this->args(
			__( 'Styles', 'yeffoprint-core' ),
			__( 'Style', 'yeffoprint-core' ),
			'yp_style'
		) );

		register_taxonomy( 'yp_color', 'yp_template', $this->args(
			__( 'Colors', 'yeffoprint-core' ),
			__( 'Color', 'yeffoprint-core' ),
			'yp_color'
		) );

		register_taxonomy( 'yp_material_tag', 'yp_template', $this->args(
			__( 'Compatible Materials', 'yeffoprint-core' ),
			__( 'Compatible Material', 'yeffoprint-core' ),
			'yp_material'
		) );

		// Direct request: "some kind of separation or filter so people can
		// specify if they want to see the peptide vial labels or other
		// product labels" — a primary category, rendered as its own
		// visually distinct pill row above Style/Color/Material in
		// blocks/gallery-toolbar/render.php, not just a fourth equal-weight
		// facet mixed into the same row.
		register_taxonomy( 'yp_product_type', 'yp_template', $this->args(
			__( 'Product Types', 'yeffoprint-core' ),
			__( 'Product Type', 'yeffoprint-core' ),
			'yp_product_type'
		) );
	}

	/**
	 * These are lightweight filter/search facets, not hierarchical
	 * categories — flat, non-hierarchical, admin-managed alongside
	 * Templates rather than shown as their own top-level menu.
	 */
	private function args( string $plural, string $singular, string $query_var ): array {
		return [
			'label'              => $plural,
			'labels'             => [
				'name'          => $plural,
				'singular_name' => $singular,
			],
			'hierarchical'       => false,
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => 'yeffoprint',
			'show_in_rest'       => true,
			'show_admin_column'  => true,
			'query_var'          => $query_var,
			'rewrite'            => false,
		];
	}
}
