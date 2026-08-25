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
		$template_args = $this->args(
			__( 'Templates', 'yeffoprint-core' ),
			__( 'Template', 'yeffoprint-core' ),
			[ 'title', 'editor', 'thumbnail', 'custom-fields' ],
			true // publicly queryable: the storefront gallery reads these.
		);
		// Renamed in the sidebar since this is also the landing tab of the
		// consolidated Templates/Sizes/Materials/Sticker Sizes page — see
		// class-design-setup-menu.php.
		$template_args['labels']['menu_name'] = __( 'Design Setup', 'yeffoprint-core' );

		register_post_type( 'yp_template', array_merge(
			$template_args,
			[
				// Archive lives at /shop-labels/ per PROJECT_SPEC §9 — the
				// Shop Labels page *is* the yp_template archive.
				'has_archive' => 'shop-labels',
				'rewrite'     => [ 'slug' => 'shop-labels', 'with_front' => false ],
				'query_var'   => 'yp_template',
			]
		) );

		register_post_type( 'yp_material', $this->args(
			__( 'Materials', 'yeffoprint-core' ),
			__( 'Material', 'yeffoprint-core' ),
			// 'editor' is the material's description; 'thumbnail' is its
			// swatch image; 'page-attributes' gives native drag-orderable
			// sort_order via menu_order. Active/inactive reuses post_status
			// (publish/draft) rather than a redundant meta flag.
			[ 'title', 'editor', 'thumbnail', 'page-attributes', 'custom-fields' ],
			false,
			false // Consolidated onto the "Design Setup" tabbed page — see class-design-setup-menu.php.
		) );

		register_post_type( 'yp_size', $this->args(
			__( 'Sizes', 'yeffoprint-core' ),
			__( 'Size', 'yeffoprint-core' ),
			[ 'title', 'page-attributes', 'custom-fields' ],
			false,
			false // Consolidated onto the "Design Setup" tabbed page — see class-design-setup-menu.php.
		) );

		// Custom Stickers (PROJECT_SPEC §19 non-goal, now in scope): a
		// preset size tier (name, width/height in inches, a real fixed
		// price per sticker — not a small price_adjustment on top of a
		// shared base like yp_size, since sticker cost scales with size
		// itself). One special tier is flagged is_custom — the customer
		// enters exact dimensions and price is computed live from an
		// admin-set $/sq in rate (YeffoPrint_Sticker_Pricing), so a
		// genuinely custom size still prices before checkout rather than
		// needing a manual quote.
		register_post_type( 'yp_sticker_size', $this->args(
			__( 'Sticker Sizes', 'yeffoprint-core' ),
			__( 'Sticker Size', 'yeffoprint-core' ),
			[ 'title', 'page-attributes', 'custom-fields' ],
			false,
			false // Consolidated onto the "Design Setup" tabbed page — see class-design-setup-menu.php.
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

		// A reusable set of field *definitions* (label, type, max chars,
		// alignment, font sizing, formatting rule, admin/tooltip text —
		// everything but position, which is inherently per-template
		// since it's placed against that template's own artwork) an
		// admin builds once and inserts into any Template's own field
		// schema instead of recreating the same fields from scratch each
		// time (direct request). Stores field data in the identical
		// shape/meta key as a Template's own field_schema
		// (YeffoPrint_Field_Schema) — a preset is really just a
		// field_schema that isn't attached to any one Template yet.
		register_post_type( 'yp_field_preset', $this->args(
			__( 'Field Presets', 'yeffoprint-core' ),
			__( 'Field Preset', 'yeffoprint-core' ),
			[ 'title', 'custom-fields' ],
			false
		) );

		// Customer-owned, not admin-managed content — created/read/
		// deleted entirely through REST by the customer who owns it
		// (post_author), same as a cart session but persisted without
		// purchasing (Architecture §8's "Saved Designs" anticipation).
		// Still a CPT for consistency with every other record here.
		register_post_type( 'yp_saved_design', $this->args(
			__( 'Saved Designs', 'yeffoprint-core' ),
			__( 'Saved Design', 'yeffoprint-core' ),
			[ 'title', 'custom-fields', 'author' ],
			false
		) );

		// One record per Stripe maintenance-plan subscriber, kept in sync
		// by class-stripe-webhook-controller.php — see
		// includes/maintenance/class-maintenance-sub-meta.php. Not
		// publicly queryable; admin-managed the same way every other
		// record type here is, just written by a webhook instead of a
		// human filling in a form.
		register_post_type( 'yp_maintenance_sub', $this->args(
			__( 'Maintenance Subscribers', 'yeffoprint-core' ),
			__( 'Maintenance Subscriber', 'yeffoprint-core' ),
			[ 'title', 'custom-fields' ],
			false
		) );

		// One record per tier on the Web Design page's pricing table
		// (yeffoprint theme, patterns/web-design-packages.php) — direct
		// request: make the placeholder pricing editable from the admin
		// panel instead of a code deploy. 'page-attributes' gives the
		// same native drag-orderable sort (menu_order) Material/Size
		// already use; active/inactive reuses post_status like they do
		// too, rather than a redundant meta flag.
		register_post_type( 'yp_web_design_package', $this->args(
			__( 'Web Design Packages', 'yeffoprint-core' ),
			__( 'Web Design Package', 'yeffoprint-core' ),
			[ 'title', 'page-attributes', 'custom-fields' ],
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
	 *
	 * $show_in_menu defaults to attaching as its own "YeffoPrint" submenu
	 * item. Sizes/Materials/Sticker Sizes instead pass false — direct
	 * request, to fold them into Templates' own submenu item (relabeled
	 * "Design Setup" below) with tabs between the four, rather than four
	 * separate sidebar entries (class-design-setup-menu.php adds the tab
	 * strip). show_ui stays true regardless, so each one's post.php/
	 * post-new.php/edit.php still work fine at their direct URLs — the
	 * tabs just link straight to them — they're just not duplicated in
	 * the sidebar.
	 */
	private function args( string $plural, string $singular, array $supports, bool $public, $show_in_menu = 'yeffoprint' ): array {
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
			'show_in_menu'        => $show_in_menu,
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
