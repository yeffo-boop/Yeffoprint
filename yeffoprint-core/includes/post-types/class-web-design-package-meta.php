<?php
/**
 * Post meta for the yp_web_design_pkg record — one per tier on the
 * Web Design page's pricing table (yeffoprint theme,
 * patterns/web-design-packages.php). Direct follow-up request: "I'd
 * like to make it future proof and be able to adjust prices from the
 * YeffoPrint admin panel" — the tier's name is the post title, sort
 * order is menu_order (native "Page Attributes" drag-order, same as
 * Material/Size), and active/inactive reuses post_status
 * (publish/draft) — this file only adds what's genuinely new: price,
 * tagline, the "Most Popular" flag, and the feature bullet list.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Web_Design_Package_Meta {

	public const PRICE    = '_yp_price';
	public const TAGLINE  = '_yp_tagline';
	public const FEATURED = '_yp_featured';

	/**
	 * A plain array of bullet strings. REST-registered (docs/ARCHITECTURE.md,
	 * admin dashboard Phase 3) so the new admin app can read/write it
	 * through WP core's own `/wp/v2/yp_web_design_pkg` route — until then
	 * this was deliberately unregistered, since only the classic editor
	 * (class-web-design-package-editor.php) ever touched it and plain
	 * get_post_meta()/update_post_meta() round-trips an array fine either
	 * way.
	 */
	public const FEATURES = '_yp_features';

	public function __construct() {
		add_action( 'init', [ $this, 'register_meta' ] );
	}

	public function register_meta(): void {
		register_post_meta( 'yp_web_design_pkg', self::PRICE, [
			'type'          => 'string',
			'single'        => true,
			'default'       => '',
			'show_in_rest'  => true,
			'auth_callback' => [ $this, 'can_edit' ],
		] );

		register_post_meta( 'yp_web_design_pkg', self::TAGLINE, [
			'type'          => 'string',
			'single'        => true,
			'default'       => '',
			'show_in_rest'  => true,
			'auth_callback' => [ $this, 'can_edit' ],
		] );

		register_post_meta( 'yp_web_design_pkg', self::FEATURED, [
			'type'          => 'boolean',
			'single'        => true,
			'default'       => false,
			'show_in_rest'  => true,
			'auth_callback' => [ $this, 'can_edit' ],
		] );

		register_post_meta( 'yp_web_design_pkg', self::FEATURES, [
			'type'          => 'array',
			'single'        => true,
			'default'       => [],
			'show_in_rest'  => [
				'schema' => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
			],
			'auth_callback' => [ $this, 'can_edit' ],
		] );
	}

	public function can_edit(): bool {
		return current_user_can( 'edit_posts' );
	}

	/** Every published tier, in the admin's own drag-order — what the pricing table itself renders. */
	public static function get_published(): array {
		return get_posts( [
			'post_type'      => 'yp_web_design_pkg',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
		] );
	}
}
