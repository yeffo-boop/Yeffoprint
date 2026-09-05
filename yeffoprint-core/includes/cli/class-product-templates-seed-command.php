<?php
/**
 * One-time backfill for the 3 new prebuilt product-label Templates
 * (Bloom/Botanica/Vital — Cosmetics/Skincare/Supplements). Direct
 * request: "I'd like to make some prebuilt product label templates
 * similar to the peptide labels but for the other products."
 *
 * The Template post shells themselves (title/slug/status/featured
 * image/taxonomy tag) are created directly against the live site via
 * the connected WordPress tooling — see docs/ARCHITECTURE.md. This
 * command exists only for the three fields that can't be reached that
 * way: field_schema, compatible_sizes, compatible_materials are
 * deliberately not REST-exposed (class-template-meta.php,
 * class-admin-template-controller.php's own docblocks), reachable only
 * through direct calls in a WP-CLI execution context — same reasoning
 * as class-pages-setup-command.php's own use of a raw update_post_meta()
 * to set a page's FSE template.
 *
 * Looks each Template up by slug and each compatible Size by title —
 * both need to already exist (created before this runs) — and never
 * overwrites a Template that already has a non-empty field_schema, so
 * it's safe to run more than once, including after the owner has
 * started customizing one of these by hand.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Product_Templates_Seed_Command {

	private const TEMPLATES = [
		[
			'slug'                => 'bloom',
			'size_title'          => 'Cosmetic Jar',
			'details_label'       => 'Net Wt. / Key Ingredient',
			'details_description' => 'e.g. "1.7 oz — Shea Butter" or "50g — Vitamin C 10%"',
		],
		[
			'slug'                => 'botanica',
			'size_title'          => 'Skincare Bottle',
			'details_label'       => 'Volume / Key Ingredient',
			'details_description' => 'e.g. "30mL — Hyaluronic Acid" or "1 fl oz — Retinol 0.5%"',
		],
		[
			'slug'                => 'vital',
			'size_title'          => 'Supplement Bottle',
			'details_label'       => 'Serving Size / Directions',
			'details_description' => 'e.g. "60 capsules — Take 2 daily" or "90ct — 500mg per serving"',
		],
	];

	public function register(): void {
		\WP_CLI::add_command( 'yeffoprint seed-product-templates', [ $this, 'run' ] );
	}

	/**
	 * ## EXAMPLES
	 *
	 *     wp yeffoprint seed-product-templates
	 */
	public function run(): void {
		$material_ids = get_posts( [
			'post_type'      => 'yp_material',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		] );

		if ( ! $material_ids ) {
			\WP_CLI::warning( 'No published Materials found — compatible_materials will be left empty. Run this again once Materials exist.' );
		}

		foreach ( self::TEMPLATES as $config ) {
			$this->backfill_one( $config, $material_ids );
		}

		\WP_CLI::success( 'Product Templates backfill complete.' );
	}

	private function backfill_one( array $config, array $material_ids ): void {
		$template = get_page_by_path( $config['slug'], OBJECT, 'yp_template' );

		if ( ! $template ) {
			\WP_CLI::log( sprintf( 'No yp_template found at slug "%s" — create it first, then re-run this command.', $config['slug'] ) );
			return;
		}

		$label = sprintf( '%s (#%d)', $template->post_title, $template->ID );

		$existing_schema = YeffoPrint_Field_Schema::get( $template->ID );
		if ( $existing_schema ) {
			\WP_CLI::log( sprintf( '%s already has customization fields — left as-is.', $label ) );
		} else {
			YeffoPrint_Field_Schema::update( $template->ID, $this->field_schema_for( $config ) );
			\WP_CLI::log( sprintf( 'Set customization fields on %s', $label ) );
		}

		$existing_sizes = (array) get_post_meta( $template->ID, YeffoPrint_Template_Meta::COMPATIBLE_SIZES, true );
		if ( $existing_sizes ) {
			\WP_CLI::log( sprintf( '%s already has compatible sizes — left as-is.', $label ) );
		} else {
			$sizes = get_posts( [
				'post_type'      => 'yp_size',
				'post_status'    => 'publish',
				'title'          => $config['size_title'],
				'posts_per_page' => 1,
			] );
			if ( $sizes ) {
				update_post_meta( $template->ID, YeffoPrint_Template_Meta::COMPATIBLE_SIZES, [ $sizes[0]->ID ] );
				\WP_CLI::log( sprintf( 'Set compatible size (%s) on %s', $config['size_title'], $label ) );
			} else {
				\WP_CLI::log( sprintf( 'No yp_size titled "%s" found — create it first, then re-run this command for %s.', $config['size_title'], $label ) );
			}
		}

		$existing_materials = (array) get_post_meta( $template->ID, YeffoPrint_Template_Meta::COMPATIBLE_MATERIALS, true );
		if ( $existing_materials ) {
			\WP_CLI::log( sprintf( '%s already has compatible materials — left as-is.', $label ) );
		} elseif ( $material_ids ) {
			update_post_meta( $template->ID, YeffoPrint_Template_Meta::COMPATIBLE_MATERIALS, $material_ids );
			\WP_CLI::log( sprintf( 'Set compatible materials (all %d published) on %s', count( $material_ids ), $label ) );
		}
	}

	/** Brand Name + Product Name + a per-vertical Details field — the "standard fields" shape approved for this round. */
	private function field_schema_for( array $config ): array {
		$field = YeffoPrint_Field_Schema::default_field();

		$brand_name = array_merge( $field, [
			'id'       => 'brand-name',
			'label'    => 'Brand Name',
			'type'     => 'text',
			'required' => true,
			'position' => [ 'x' => 50.0, 'y' => 22.0 ],
			'font_size_min' => 12,
			'font_size_max' => 22,
		] );

		$product_name = array_merge( $field, [
			'id'       => 'product-name',
			'label'    => 'Product Name',
			'type'     => 'text',
			'required' => true,
			'position' => [ 'x' => 50.0, 'y' => 48.0 ],
			'font_size_min' => 16,
			'font_size_max' => 28,
		] );

		$details = array_merge( $field, [
			'id'                => 'details',
			'label'             => $config['details_label'],
			'type'              => 'textarea',
			'required'          => false,
			'max_chars'         => 60,
			'position'          => [ 'x' => 50.0, 'y' => 78.0 ],
			'font_size_min'     => 8,
			'font_size_max'     => 12,
			'admin_description' => $config['details_description'],
		] );

		return [ $brand_name, $product_name, $details ];
	}
}
