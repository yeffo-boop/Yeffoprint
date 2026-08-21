<?php
/**
 * Post meta for Material and Size records.
 *
 * Both are otherwise plain admin-managed records that lean on native
 * WordPress fields rather than reinventing them: active/inactive is
 * post_status (publish/draft), sort_order is menu_order (via the
 * 'page-attributes' support, which also gives a native drag-orderable
 * admin UI for free), and Material's description is post_content. The
 * only genuinely new data is price_adjustment (both) and Size's print
 * dimensions — see docs/ARCHITECTURE.md §9.
 *
 * "compatible_products" from the Material data model (ARCHITECTURE §2)
 * isn't duplicated here: Template.compatible_materials (see
 * class-template-editor.php) is the single source of truth for that
 * relationship, read from the Template side.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Commerce_Record_Meta {

	public const PRICE_ADJUSTMENT = '_yp_price_adjustment';
	public const PRINT_WIDTH_MM   = '_yp_print_width_mm';
	public const PRINT_HEIGHT_MM  = '_yp_print_height_mm';

	public function __construct() {
		add_action( 'init', [ $this, 'register_meta' ] );
	}

	public function register_meta(): void {
		foreach ( [ 'yp_material', 'yp_size' ] as $post_type ) {
			register_post_meta( $post_type, self::PRICE_ADJUSTMENT, [
				'type'          => 'number',
				'single'        => true,
				'default'       => 0,
				'show_in_rest'  => true,
				'auth_callback' => [ $this, 'can_edit' ],
			] );
		}

		register_post_meta( 'yp_size', self::PRINT_WIDTH_MM, [
			'type'          => 'number',
			'single'        => true,
			'default'       => 0,
			'show_in_rest'  => true,
			'auth_callback' => [ $this, 'can_edit' ],
		] );

		register_post_meta( 'yp_size', self::PRINT_HEIGHT_MM, [
			'type'          => 'number',
			'single'        => true,
			'default'       => 0,
			'show_in_rest'  => true,
			'auth_callback' => [ $this, 'can_edit' ],
		] );
	}

	public function can_edit(): bool {
		return current_user_can( 'edit_posts' );
	}
}
