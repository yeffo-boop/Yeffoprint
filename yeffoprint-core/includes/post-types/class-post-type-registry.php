<?php
/**
 * Registers the core data records as custom post types.
 *
 * See docs/ARCHITECTURE.md §2 (Core Data Model) and §9 (Open
 * Architectural Decisions) for why these are CPTs rather than custom
 * tables. Field schemas, pricing logic, and admin CRUD screens are
 * built out in later phases — this class only establishes the record
 * types themselves.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Post_Type_Registry {

	public function __construct() {
		add_action( 'init', [ $this, 'register_post_types' ] );
	}

	public function register_post_types(): void {
		register_post_type( 'yp_template', $this->args(
			__( 'Templates', 'yeffoprint-core' ),
			__( 'Template', 'yeffoprint-core' ),
			[ 'title', 'editor', 'thumbnail', 'custom-fields' ],
			true // publicly queryable: the storefront gallery reads these.
		) );

		register_post_type( 'yp_material', $this->args(
			__( 'Materials', 'yeffoprint-core' ),
			__( 'Material', 'yeffoprint-core' ),
			[ 'title', 'thumbnail', 'custom-fields' ],
			false
		) );

		register_post_type( 'yp_size', $this->args(
			__( 'Sizes', 'yeffoprint-core' ),
			__( 'Size', 'yeffoprint-core' ),
			[ 'title', 'custom-fields' ],
			false
		) );

		register_post_type( 'yp_pricing_rule', $this->args(
			__( 'Pricing Rules', 'yeffoprint-core' ),
			__( 'Pricing Rule', 'yeffoprint-core' ),
			[ 'title', 'custom-fields' ],
			false
		) );

		register_post_type( 'yp_custom_order', $this->args(
			__( 'Custom Orders', 'yeffoprint-core' ),
			__( 'Custom Order', 'yeffoprint-core' ),
			[ 'title', 'custom-fields' ],
			false
		) );

		register_post_type( 'yp_proof', $this->args(
			__( 'Proofs', 'yeffoprint-core' ),
			__( 'Proof', 'yeffoprint-core' ),
			[ 'title', 'custom-fields' ],
			false
		) );
	}

	/**
	 * Shared register_post_type() args for a YeffoPrint data record.
	 *
	 * All records are admin-managed (not author-facing content), grouped
	 * under the top-level "YeffoPrint" admin menu, and internal by
	 * default — only Templates are publicly queryable, since the
	 * storefront gallery and configurator read them directly.
	 */
	private function args( string $plural, string $singular, array $supports, bool $public ): array {
		return [
			'label'               => $plural,
			'labels'              => [
				'name'          => $plural,
				'singular_name' => $singular,
				'add_new_item'  => sprintf( __( 'Add New %s', 'yeffoprint-core' ), $singular ),
				'edit_item'     => sprintf( __( 'Edit %s', 'yeffoprint-core' ), $singular ),
				'search_items'  => sprintf( __( 'Search %s', 'yeffoprint-core' ), $plural ),
			],
			'public'              => $public,
			'publicly_queryable'  => $public,
			'show_ui'             => true,
			'show_in_menu'        => 'yeffoprint',
			'show_in_rest'        => true,
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
			'hierarchical'        => false,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'supports'            => $supports,
			'menu_icon'           => 'dashicons-tag',
		];
	}
}
