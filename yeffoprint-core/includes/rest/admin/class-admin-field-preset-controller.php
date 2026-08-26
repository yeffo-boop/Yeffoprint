<?php
/**
 * Admin REST endpoint for the Field Presets screen (docs/ARCHITECTURE.md,
 * Phase 5). `yp_field_preset` itself is already reachable through WP
 * core's own `/wp/v2/yp_field_preset` route (title, status — full
 * list/create/update/delete) since the post type has `show_in_rest`
 * on; the only gap is field_schema, which — same as a Template's own
 * copy — has no meta registration of its own (class-field-schema.php's
 * own docblock).
 *
 * A Field Preset has no artwork of its own (class-field-preset-editor.php's
 * docblock: "a preset's own screen just has no artwork to
 * drag-position fields against"), so unlike Templates this payload
 * carries no compatible_sizes/compatible_materials at all — those
 * concepts don't apply here.
 *
 * Same as class-admin-template-controller.php, the field-type/
 * alignment/formatting/preview-behavior label maps aren't repeated in
 * this payload — class-admin-app.php localizes them once for the
 * whole app (see its `fieldSchema` key), since a new preset's editor
 * needs them before any post/id exists yet.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_Field_Preset_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/admin/field-preset/(?P<id>\d+)', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_preset' ],
				'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
			],
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'save_preset' ],
				'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
			],
		] );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function get_preset( \WP_REST_Request $request ) {
		$post_id = $this->validate_preset( (int) $request['id'] );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		return rest_ensure_response( $this->preset_payload( $post_id ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function save_preset( \WP_REST_Request $request ) {
		$post_id = $this->validate_preset( (int) $request['id'] );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$params = $request->get_json_params() ?: [];

		YeffoPrint_Field_Schema::update( $post_id, is_array( $params['field_schema'] ?? null ) ? $params['field_schema'] : [] );

		return rest_ensure_response( $this->preset_payload( $post_id ) );
	}

	/** @return int|\WP_Error Post ID on success. */
	private function validate_preset( int $post_id ) {
		if ( ! $post_id || 'yp_field_preset' !== get_post_type( $post_id ) ) {
			return new \WP_Error(
				'yeffoprint_field_preset_not_found',
				__( 'That field preset could not be found.', 'yeffoprint-core' ),
				[ 'status' => 404 ]
			);
		}

		return $post_id;
	}

	private function preset_payload( int $post_id ): array {
		return [
			'field_schema' => YeffoPrint_Field_Schema::get( $post_id ),
		];
	}
}
