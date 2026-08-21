<?php
/**
 * Post meta fields on Template records used by the storefront gallery.
 *
 * Covers the fields from the Template data model (docs/ARCHITECTURE.md
 * §2): featured flag, popularity score, vial mockup image (for the
 * card hover-swap in PROJECT_SPEC §9), badge, and — as of Phase 4 —
 * compatible_sizes/compatible_materials. field_schema is big enough to
 * warrant its own class; see class-field-schema.php.
 *
 * compatible_sizes/compatible_materials are deliberately NOT
 * REST-exposed yet (no register_post_meta call, just plain
 * get/update_post_meta from class-template-editor.php) — Phase 5's
 * schema-fetch endpoint is what will format them for the configurator.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Template_Meta {

	public const FEATURED               = '_yp_featured';
	public const POPULARITY             = '_yp_popularity';
	public const VIAL_MOCKUP            = '_yp_vial_mockup_id';
	public const BADGE                  = '_yp_badge';
	public const SEARCH_INDEX           = '_yp_search_index';
	public const COMPATIBLE_SIZES       = '_yp_compatible_sizes';
	public const COMPATIBLE_MATERIALS   = '_yp_compatible_materials';

	public const BADGES = [ '', 'new', 'popular', 'featured', 'customizable' ];

	public function __construct() {
		add_action( 'init', [ $this, 'register_meta' ] );
	}

	public function register_meta(): void {
		register_post_meta( 'yp_template', self::FEATURED, [
			'type'          => 'boolean',
			'single'        => true,
			'default'       => false,
			'show_in_rest'  => true,
			'auth_callback' => [ $this, 'can_edit_templates' ],
		] );

		register_post_meta( 'yp_template', self::POPULARITY, [
			'type'          => 'integer',
			'single'        => true,
			'default'       => 0,
			'show_in_rest'  => true,
			'auth_callback' => [ $this, 'can_edit_templates' ],
		] );

		register_post_meta( 'yp_template', self::VIAL_MOCKUP, [
			'type'          => 'integer',
			'single'        => true,
			'default'       => 0,
			'show_in_rest'  => true,
			'auth_callback' => [ $this, 'can_edit_templates' ],
		] );

		register_post_meta( 'yp_template', self::BADGE, [
			'type'          => 'string',
			'single'        => true,
			'default'       => '',
			'show_in_rest'  => [
				'schema' => [
					'type' => 'string',
					'enum' => self::BADGES,
				],
			],
			'auth_callback' => [ $this, 'can_edit_templates' ],
		] );

		// Internal — not exposed in REST. Rebuilt on save; see
		// class-template-search.php.
		register_post_meta( 'yp_template', self::SEARCH_INDEX, [
			'type'         => 'string',
			'single'       => true,
			'default'      => '',
			'show_in_rest' => false,
		] );
	}

	public function can_edit_templates(): bool {
		return current_user_can( 'edit_posts' );
	}
}
