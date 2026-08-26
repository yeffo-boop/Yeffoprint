<?php
/**
 * Admin REST endpoint for the standalone Proofs screen (docs/ARCHITECTURE.md,
 * Phase 6) — the same "browse every proof across every order" view the
 * classic `edit.php?post_type=yp_proof` list table gave staff, plus
 * creating a new one. `yp_proof` has `show_in_rest` on at the
 * post-type level but neither of its two meta fields was ever
 * registered for REST (`class-proof-editor.php`'s classic screen reads/
 * writes both directly), so this covers the whole thing rather than a
 * small gap.
 *
 * `create_proof()` is a thin wrapper, not a reimplementation: it does
 * nothing `class-proof-editor.php::save()` doesn't already do — create
 * the post, then hand off to `YeffoPrint_Proof_Meta::attach_file()` for
 * the meta + status-advance + customer-email logic both entry points
 * share.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_Proof_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/admin/proofs', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'list_proofs' ],
				'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_proof' ],
				'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/admin/proof/(?P<id>\d+)', [
			'methods'             => \WP_REST_Server::DELETABLE,
			'callback'            => [ $this, 'delete_proof' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'admin_write' ],
		] );
	}

	public function list_proofs(): \WP_REST_Response {
		$posts = get_posts( [
			'post_type'      => 'yp_proof',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		] );

		return rest_ensure_response( array_map( [ $this, 'row' ], $posts ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function create_proof( \WP_REST_Request $request ) {
		$params          = $request->get_json_params() ?: [];
		$custom_order_id = absint( $params['custom_order_id'] ?? 0 );
		$file_id         = absint( $params['file_id'] ?? 0 );

		$custom_order = get_post( $custom_order_id );
		if ( ! $custom_order || 'yp_custom_order' !== $custom_order->post_type ) {
			return new \WP_Error( 'yeffoprint_custom_order_not_found', __( 'That request could not be found.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}

		if ( ! $file_id || ! wp_get_attachment_url( $file_id ) ) {
			return new \WP_Error( 'yeffoprint_invalid_file', __( 'Choose a file for this proof.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$proof_id = wp_insert_post( [
			'post_type'   => 'yp_proof',
			'post_status' => 'publish',
			/* translators: 1: custom order title, 2: current date */
			'post_title'  => sprintf( __( 'Proof for %1$s — %2$s', 'yeffoprint-core' ), get_the_title( $custom_order ), wp_date( get_option( 'date_format' ) ) ),
		], true );

		if ( is_wp_error( $proof_id ) ) {
			return $proof_id;
		}

		YeffoPrint_Proof_Meta::attach_file( $proof_id, $custom_order_id, $file_id );

		return rest_ensure_response( $this->row( get_post( $proof_id ) ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function delete_proof( \WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post || 'yp_proof' !== $post->post_type ) {
			return new \WP_Error( 'yeffoprint_proof_not_found', __( 'That proof could not be found.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}

		wp_trash_post( $post->ID );

		return new \WP_REST_Response( null, 204 );
	}

	private function row( \WP_Post $post ): array {
		$custom_order_id = (int) get_post_meta( $post->ID, YeffoPrint_Proof_Meta::CUSTOM_ORDER_ID, true );
		$file_id         = (int) get_post_meta( $post->ID, YeffoPrint_Proof_Meta::FILE_ID, true );

		return [
			'id'                 => $post->ID,
			'title'              => get_the_title( $post ),
			'date'               => get_the_date( 'c', $post ),
			'custom_order_id'    => $custom_order_id,
			'custom_order_title' => $custom_order_id ? get_the_title( $custom_order_id ) : '',
			'file_url'           => $file_id ? wp_get_attachment_url( $file_id ) : '',
		];
	}
}
