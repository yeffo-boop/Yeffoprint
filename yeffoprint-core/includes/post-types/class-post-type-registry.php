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
			true, // publicly queryable: the storefront gallery reads these.
			// Phase 8 (docs/ARCHITECTURE.md): the custom admin app's own
			// Templates screen is the primary path now — classic post.php/
			// edit.php still work fine at their direct URLs (data, meta,
			// and save logic are all untouched, see class-template-editor.php),
			// they're just no longer linked from the sidebar.
			false
		);
		// Never shown now that show_in_menu is false above, but harmless
		// to leave — see the old class-design-setup-menu.php tab strip,
		// itself likewise unlinked but still functional at its direct URLs.
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

		// Phase 8 (docs/ARCHITECTURE.md): show_in_menu false on all three
		// below, same reasoning as Templates above — each has a full
		// replacement in the custom admin app now.
		register_post_type( 'yp_pricing_rule', $this->args(
			__( 'Pricing Rules', 'yeffoprint-core' ),
			__( 'Pricing Rule', 'yeffoprint-core' ),
			[ 'title', 'custom-fields' ],
			false,
			false
		) );

		register_post_type( 'yp_custom_order', $this->args(
			__( 'Custom Orders', 'yeffoprint-core' ),
			__( 'Custom Order', 'yeffoprint-core' ),
			[ 'title', 'custom-fields' ],
			false,
			false
		) );

		register_post_type( 'yp_proof', $this->args(
			__( 'Proofs', 'yeffoprint-core' ),
			__( 'Proof', 'yeffoprint-core' ),
			[ 'title', 'custom-fields' ],
			false,
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
			false,
			false // Phase 8: replaced by the custom admin app's own Field Presets screen.
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
			false,
			// Phase 8: hidden along with everything else — this is
			// customer-owned data with no admin workflow of its own
			// (created/read/deleted entirely through REST by the owning
			// customer), so it never got a custom admin app screen and
			// isn't planned to. Still directly reachable at edit.php?
			// post_type=yp_saved_design if a look is ever needed.
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
			false,
			false // Phase 8: replaced by the custom admin app's own Maintenance Subscribers screen.
		) );

		// One record per tier on the Web Design page's pricing table
		// (yeffoprint theme, patterns/web-design-packages.php) — direct
		// request: make the placeholder pricing editable from the admin
		// panel instead of a code deploy. 'page-attributes' gives the
		// same native drag-orderable sort (menu_order) Material/Size
		// already use; active/inactive reuses post_status like they do
		// too, rather than a redundant meta flag.
		register_post_type( 'yp_web_design_pkg', $this->args(
			__( 'Web Design Packages', 'yeffoprint-core' ),
			__( 'Web Design Package', 'yeffoprint-core' ),
			[ 'title', 'page-attributes', 'custom-fields' ],
			false,
			false // Phase 8: replaced by the custom admin app's own Web Design Packages screen.
		) );
	}

	/**
	 * Shared register_post_type() args for a YeffoPrint data record.
	 *
	 * All records are admin-managed (not author-facing content), and
	 * internal by default — only Templates are publicly queryable, since
	 * the storefront gallery and configurator read them directly.
	 *
	 * $show_in_menu defaults to attaching as its own "YeffoPrint" submenu
	 * item, but every call site above now passes `false` explicitly —
	 * the custom admin app (docs/ARCHITECTURE.md) has its own screen for
	 * every one of these record types as of Phase 7, so Phase 8 hides
	 * all of them from the classic wp-admin sidebar rather than leaving
	 * two working paths to the same data. `show_ui` stays `true`
	 * regardless, so each type's post.php/post-new.php/edit.php (and its
	 * classic editor class's meta boxes/save logic) still work fine at
	 * their direct URLs — a deliberately-kept, unlinked fallback, not a
	 * dead end — they're just no longer linked from anywhere. The
	 * now-unused `'yeffoprint'` default is left in place only so a
	 * future record type can still opt into the classic pattern if it
	 * genuinely needs to.
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
