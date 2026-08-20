<?php
/**
 * The "template schema fetch" REST endpoint (docs/ARCHITECTURE.md §7).
 *
 * Public, read-only: everything it returns is already public storefront
 * data (published Template + its compatible, published Sizes/Materials).
 * This is what the Phase 5 configurator (assets/js/configurator.js)
 * loads on a Template's single page to know what fields, sizes,
 * materials, and starting price to render — it never talks to the
 * database directly from the theme.
 *
 * base_unit_price here is still the Phase 3 placeholder constant, not
 * a real PricingRule; the price this endpoint returns is provisional
 * only. See docs/ARCHITECTURE.md §9 — Phase 6 replaces it with an
 * authoritative, server-validated calculation the client can't spoof.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Template_Schema_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/templates/(?P<id>\d+)/configurator', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_configurator_data' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'id' => [
					'validate_callback' => static function ( $value ) {
						return is_numeric( $value );
					},
				],
			],
		] );
	}

	public function get_configurator_data( \WP_REST_Request $request ) {
		$template_id = absint( $request->get_param( 'id' ) );
		$template    = get_post( $template_id );

		if ( ! $template || 'yp_template' !== $template->post_type || 'publish' !== $template->post_status ) {
			return new \WP_Error(
				'yeffoprint_template_not_found',
				__( 'This design is not available.', 'yeffoprint-core' ),
				[ 'status' => 404 ]
			);
		}

		return rest_ensure_response( [
			'id'               => $template->ID,
			'title'            => get_the_title( $template ),
			// post_content is raw block markup, not renderable text as-is
			// — run it through the_content first (so blocks actually
			// render to HTML) before stripping tags down to plain text.
			'description'      => wp_strip_all_tags( apply_filters( 'the_content', $template->post_content ) ),
			'artwork_url'      => get_the_post_thumbnail_url( $template, 'large' ) ?: null,
			'vial_mockup_url'  => $this->vial_mockup_url( $template->ID ),
			// Empty string (the default/unset state) tells configurator.js
			// to leave Label View on the site's own font rather than set
			// an empty font-family.
			'preview_font'     => (string) get_post_meta( $template->ID, YeffoPrint_Template_Meta::PREVIEW_FONT, true ),
			'base_unit_price'  => function_exists( 'yeffoprint_core_base_unit_price' ) ? yeffoprint_core_base_unit_price() : 0,
			'quantity_presets' => function_exists( 'yeffoprint_core_quantity_presets' ) ? yeffoprint_core_quantity_presets() : [],
			'field_schema'     => YeffoPrint_Field_Schema::get( $template->ID ),
			'sizes'            => $this->records( YeffoPrint_Template_Meta::COMPATIBLE_SIZES, $template->ID, [ $this, 'format_size' ] ),
			'materials'        => $this->records( YeffoPrint_Template_Meta::COMPATIBLE_MATERIALS, $template->ID, [ $this, 'format_material' ] ),
		] );
	}

	private function vial_mockup_url( int $template_id ): ?string {
		$vial_id = (int) get_post_meta( $template_id, YeffoPrint_Template_Meta::VIAL_MOCKUP, true );

		return $vial_id ? ( wp_get_attachment_image_url( $vial_id, 'large' ) ?: null ) : null;
	}

	private function records( string $meta_key, int $template_id, callable $formatter ): array {
		$ids = array_map( 'absint', (array) get_post_meta( $template_id, $meta_key, true ) );
		$out = [];

		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( $post && 'publish' === $post->post_status ) {
				$out[] = $formatter( $post );
			}
		}

		return $out;
	}

	private function format_size( \WP_Post $size ): array {
		return [
			'id'               => $size->ID,
			'name'             => get_the_title( $size ),
			'print_width_mm'   => (float) get_post_meta( $size->ID, YeffoPrint_Commerce_Record_Meta::PRINT_WIDTH_MM, true ),
			'print_height_mm'  => (float) get_post_meta( $size->ID, YeffoPrint_Commerce_Record_Meta::PRINT_HEIGHT_MM, true ),
			'price_adjustment' => (float) get_post_meta( $size->ID, YeffoPrint_Commerce_Record_Meta::PRICE_ADJUSTMENT, true ),
		];
	}

	private function format_material( \WP_Post $material ): array {
		$hover_id = (int) get_post_meta( $material->ID, YeffoPrint_Commerce_Record_Meta::HOVER_IMAGE, true );

		return [
			'id'               => $material->ID,
			'name'             => get_the_title( $material ),
			'description'      => wp_strip_all_tags( $material->post_content ),
			'swatch_url'       => get_the_post_thumbnail_url( $material, 'thumbnail' ) ?: null,
			'hover_image_url'  => $hover_id ? ( wp_get_attachment_image_url( $hover_id, 'thumbnail' ) ?: null ) : null,
			'price_adjustment' => (float) get_post_meta( $material->ID, YeffoPrint_Commerce_Record_Meta::PRICE_ADJUSTMENT, true ),
		];
	}
}
