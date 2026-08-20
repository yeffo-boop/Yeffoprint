<?php
/**
 * Saved Designs (PROJECT_SPEC §16/§19, Architecture §8): a customer can
 * persist a Batch to their account without purchasing, then come back
 * later and restore it into the configurator to finish or reorder it.
 * Reuses the exact same {template_id, size_id, material_id, variants}
 * shape as GET /cart/item/{key} and GET /orders/{id}/items/{id}, so
 * the configurator's existing hydrateFromBatch() needs no new code
 * path to consume it — only a new source URL.
 *
 * Every route here requires a logged-in customer: there's nothing to
 * attach an anonymous "saved" design to (unlike a cart session, which
 * is guest-accessible by design). Item-specific routes additionally
 * check that the design belongs to the requesting user — this isn't
 * public storefront data like a Template.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Saved_Design_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/saved-designs', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'list_designs' ],
				'permission_callback' => 'is_user_logged_in',
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create' ],
				'permission_callback' => 'is_user_logged_in',
			],
		] );

		register_rest_route( self::NAMESPACE, '/saved-designs/(?P<id>\d+)', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_design' ],
				'permission_callback' => [ $this, 'check_ownership' ],
			],
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_design' ],
				'permission_callback' => [ $this, 'check_ownership' ],
			],
		] );
	}

	public function check_ownership( \WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$post = get_post( absint( $request->get_param( 'id' ) ) );
		if ( ! $post || 'yp_saved_design' !== $post->post_type ) {
			return new \WP_Error( 'yeffoprint_saved_design_not_found', __( 'That saved design was not found.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}

		return (int) $post->post_author === get_current_user_id();
	}

	public function create( \WP_REST_Request $request ) {
		$template_id = absint( $request->get_param( 'template_id' ) );
		$template    = get_post( $template_id );

		if ( ! $template || 'yp_template' !== $template->post_type || 'publish' !== $template->post_status ) {
			return new \WP_Error( 'yeffoprint_invalid_template', __( 'This design is not available.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$compatible_sizes     = array_map( 'absint', (array) get_post_meta( $template_id, YeffoPrint_Template_Meta::COMPATIBLE_SIZES, true ) );
		$compatible_materials = array_map( 'absint', (array) get_post_meta( $template_id, YeffoPrint_Template_Meta::COMPATIBLE_MATERIALS, true ) );

		$size_id = absint( $request->get_param( 'size_id' ) );
		if ( $compatible_sizes && ! in_array( $size_id, $compatible_sizes, true ) ) {
			return new \WP_Error( 'yeffoprint_invalid_size', __( 'Please choose a valid size.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$material_id = absint( $request->get_param( 'material_id' ) );
		if ( $compatible_materials && ! in_array( $material_id, $compatible_materials, true ) ) {
			return new \WP_Error( 'yeffoprint_invalid_material', __( 'Please choose a valid material.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		// Required fields aren't enforced here (unlike cart/add) — a
		// saved design is explicitly allowed to be an unfinished draft
		// the customer intends to come back and complete later.
		$variants = YeffoPrint_Field_Schema::sanitize_variants(
			$request->get_param( 'variants' ),
			YeffoPrint_Field_Schema::get( $template_id ),
			false
		);
		if ( is_wp_error( $variants ) ) {
			return $variants;
		}

		$design_id = wp_insert_post( [
			'post_type'   => 'yp_saved_design',
			'post_status' => 'publish',
			'post_author' => get_current_user_id(),
			'post_title'  => sprintf( '%s — %s', get_the_title( $template ), current_time( 'Y-m-d H:i' ) ),
		], true );

		if ( is_wp_error( $design_id ) ) {
			return new \WP_Error( 'yeffoprint_saved_design_failed', __( "Couldn't save this design. Please try again.", 'yeffoprint-core' ), [ 'status' => 500 ] );
		}

		update_post_meta( $design_id, YeffoPrint_Saved_Design_Meta::TEMPLATE_ID, $template_id );
		update_post_meta( $design_id, YeffoPrint_Saved_Design_Meta::SIZE_ID, $size_id );
		update_post_meta( $design_id, YeffoPrint_Saved_Design_Meta::MATERIAL_ID, $material_id );
		update_post_meta( $design_id, YeffoPrint_Saved_Design_Meta::VARIANTS, $variants );

		return rest_ensure_response( [
			'success' => true,
			'id'      => $design_id,
		] );
	}

	public function list_designs(): \WP_REST_Response {
		$ids   = YeffoPrint_Saved_Design_Meta::get_for_customer( get_current_user_id() );
		$items = [];

		foreach ( $ids as $id ) {
			$template_id = (int) get_post_meta( $id, YeffoPrint_Saved_Design_Meta::TEMPLATE_ID, true );
			$template    = $template_id ? get_post( $template_id ) : null;
			$size        = get_post( (int) get_post_meta( $id, YeffoPrint_Saved_Design_Meta::SIZE_ID, true ) );
			$material    = get_post( (int) get_post_meta( $id, YeffoPrint_Saved_Design_Meta::MATERIAL_ID, true ) );
			$variants    = (array) get_post_meta( $id, YeffoPrint_Saved_Design_Meta::VARIANTS, true );

			$items[] = [
				'id'            => $id,
				'template_id'   => $template_id,
				'template_title' => $template ? get_the_title( $template ) : __( '(design removed)', 'yeffoprint-core' ),
				'thumbnail_url' => $template ? ( get_the_post_thumbnail_url( $template, 'thumbnail' ) ?: null ) : null,
				'size_name'     => $size ? $size->post_title : '',
				'material_name' => $material ? $material->post_title : '',
				'variant_count' => count( $variants ),
				'date'          => get_the_date( '', $id ),
				'edit_url'      => $template ? add_query_arg( 'saved', $id, get_permalink( $template ) ) : '',
			];
		}

		return rest_ensure_response( [ 'designs' => $items ] );
	}

	public function get_design( \WP_REST_Request $request ) {
		$id = absint( $request->get_param( 'id' ) );

		return rest_ensure_response( [
			'template_id' => (int) get_post_meta( $id, YeffoPrint_Saved_Design_Meta::TEMPLATE_ID, true ),
			'size_id'     => (int) get_post_meta( $id, YeffoPrint_Saved_Design_Meta::SIZE_ID, true ),
			'material_id' => (int) get_post_meta( $id, YeffoPrint_Saved_Design_Meta::MATERIAL_ID, true ),
			'variants'    => (array) get_post_meta( $id, YeffoPrint_Saved_Design_Meta::VARIANTS, true ),
		] );
	}

	public function delete_design( \WP_REST_Request $request ) {
		$id      = absint( $request->get_param( 'id' ) );
		$deleted = wp_delete_post( $id, true );

		if ( ! $deleted ) {
			return new \WP_Error( 'yeffoprint_saved_design_delete_failed', __( "Couldn't remove this saved design. Please try again.", 'yeffoprint-core' ), [ 'status' => 500 ] );
		}

		return rest_ensure_response( [ 'success' => true ] );
	}
}
