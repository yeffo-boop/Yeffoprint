<?php
/**
 * Admin REST endpoint for the Templates screen (docs/ARCHITECTURE.md,
 * Phase 5) — `yp_template` itself is already reachable through WP
 * core's own `/wp/v2/yp_template` route (title, content, status,
 * featured_media, and the simple meta fields registered in
 * class-template-meta.php: featured/popularity/vial_mockup/badge/
 * preview_font are all `show_in_rest => true`). This controller only
 * covers the three fields that route can't reach at all:
 * compatible_sizes, compatible_materials (plain arrays, never
 * REST-registered — see class-template-meta.php's own docblock), and
 * field_schema (its own class, YeffoPrint_Field_Schema, with no meta
 * registration of its own either).
 *
 * The admin app's save flow for a Template is therefore always two
 * REST calls: one to core's own route for everything it already
 * covers, one here for the rest — same "reuse core wherever it
 * reaches, fill only the real gap" split the plan called for.
 *
 * The field-type/alignment/formatting/preview-behavior/badge label
 * maps are NOT repeated in this payload — unlike Pricing Rules'
 * `tier_types` (which only ever accompanies an existing rule), a new
 * Template's editor needs those maps before any post/id exists yet
 * (the drawer must render fully before the first Save creates the
 * post), so class-admin-app.php localizes them once for the whole app
 * instead — see YeffoPrint_Admin_App::enqueue_assets()'s `fieldSchema`
 * key.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_Template_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/admin/template/(?P<id>\d+)', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_template' ],
				'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
			],
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'save_template' ],
				'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
			],
		] );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function get_template( \WP_REST_Request $request ) {
		$post_id = $this->validate_template( (int) $request['id'] );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		return rest_ensure_response( $this->template_payload( $post_id ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function save_template( \WP_REST_Request $request ) {
		$post_id = $this->validate_template( (int) $request['id'] );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$params = $request->get_json_params() ?: [];

		$compatible_sizes = is_array( $params['compatible_sizes'] ?? null )
			? array_map( 'absint', $params['compatible_sizes'] )
			: [];
		$compatible_materials = is_array( $params['compatible_materials'] ?? null )
			? array_map( 'absint', $params['compatible_materials'] )
			: [];

		update_post_meta( $post_id, YeffoPrint_Template_Meta::COMPATIBLE_SIZES, $compatible_sizes );
		update_post_meta( $post_id, YeffoPrint_Template_Meta::COMPATIBLE_MATERIALS, $compatible_materials );

		// Skipped, not just overridden on the next read, whenever a shared
		// default preset is active for this Template — the admin app's own
		// editor already renders read-only in that case (views/templates.js),
		// but a stale tab or a direct API call could still submit a
		// field_schema here; writing it would silently drift from what's
		// actually shown/used everywhere else, for no benefit.
		if ( ! YeffoPrint_Field_Schema::is_shared_for_template( $post_id ) ) {
			YeffoPrint_Field_Schema::update( $post_id, is_array( $params['field_schema'] ?? null ) ? $params['field_schema'] : [] );
		}

		return rest_ensure_response( $this->template_payload( $post_id ) );
	}

	/** @return int|\WP_Error Post ID on success. */
	private function validate_template( int $post_id ) {
		if ( ! $post_id || 'yp_template' !== get_post_type( $post_id ) ) {
			return new \WP_Error(
				'yeffoprint_template_not_found',
				__( 'That template could not be found.', 'yeffoprint-core' ),
				[ 'status' => 404 ]
			);
		}

		return $post_id;
	}

	private function template_payload( int $post_id ): array {
		return [
			'compatible_sizes'     => array_map( 'absint', (array) get_post_meta( $post_id, YeffoPrint_Template_Meta::COMPATIBLE_SIZES, true ) ),
			'compatible_materials' => array_map( 'absint', (array) get_post_meta( $post_id, YeffoPrint_Template_Meta::COMPATIBLE_MATERIALS, true ) ),
			'field_schema'         => YeffoPrint_Field_Schema::get( $post_id ),
		];
	}
}
