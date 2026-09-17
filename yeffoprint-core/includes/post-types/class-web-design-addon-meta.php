<?php
/**
 * Post meta for the yp_web_design_addon record — one per badge/modal
 * above the Web Design page's pricing table (yeffoprint theme,
 * patterns/web-design-packages.php). Direct request: "remember the
 * add-ons we offer. I'd like to be able to add/edit available add-on
 * options that can be added to web design orders." Replaces the two
 * hardcoded badges (Maintenance, Hosting) that pattern used to hold —
 * same "make it admin-editable instead of a code deploy" shape as
 * yp_web_design_pkg itself, and its own tier's name/sort-order/active-
 * inactive conventions (post_title, menu_order, post_status) apply here
 * unchanged.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Web_Design_Addon_Meta {

	/** Display text, e.g. "$35/mo" — shown on the badge and in the modal. */
	public const PRICE = '_yp_price';

	/**
	 * The badge's own one-line teaser (the pill button's visible text) —
	 * plain text, no markup, same "shown exactly as typed" philosophy as
	 * every other display-copy field in this codebase (e.g. the
	 * package's own PRICE field). The price naturally reads as part of
	 * the sentence the admin writes, rather than a separately-bolded
	 * span — simpler to store and edit than reconciling two hardcoded
	 * badges that didn't even bold the same parts of their own text.
	 */
	public const BADGE_TEXT = '_yp_badge_text';

	public const MODAL_HEADING = '_yp_modal_heading';
	public const MODAL_BODY    = '_yp_modal_body';

	/** A plain array of bullet strings — same shape as YeffoPrint_Web_Design_Package_Meta::FEATURES. */
	public const FEATURES = '_yp_features';

	public const CTA_LABEL = '_yp_cta_label';

	/** Optional. Empty means the CTA falls back to the /web-design-quote/ intake form — same as Hosting's own CTA today, no live Stripe link. */
	public const CTA_URL = '_yp_cta_url';

	/** One of ICON_CHOICES below — mapped to real inline SVG server-side (blocks/patterns never render raw markup typed into an admin field). */
	public const ICON = '_yp_icon';

	public const ICON_CHOICES = [ 'wrench', 'globe', 'shield', 'clock', 'tag', 'star' ];

	public function __construct() {
		add_action( 'init', [ $this, 'register_meta' ] );
	}

	public function register_meta(): void {
		register_post_meta( 'yp_web_design_addon', self::PRICE, [
			'type'          => 'string',
			'single'        => true,
			'default'       => '',
			'show_in_rest'  => true,
			'auth_callback' => [ $this, 'can_edit' ],
		] );

		register_post_meta( 'yp_web_design_addon', self::BADGE_TEXT, [
			'type'          => 'string',
			'single'        => true,
			'default'       => '',
			'show_in_rest'  => true,
			'auth_callback' => [ $this, 'can_edit' ],
		] );

		register_post_meta( 'yp_web_design_addon', self::MODAL_HEADING, [
			'type'          => 'string',
			'single'        => true,
			'default'       => '',
			'show_in_rest'  => true,
			'auth_callback' => [ $this, 'can_edit' ],
		] );

		register_post_meta( 'yp_web_design_addon', self::MODAL_BODY, [
			'type'          => 'string',
			'single'        => true,
			'default'       => '',
			'show_in_rest'  => true,
			'auth_callback' => [ $this, 'can_edit' ],
		] );

		register_post_meta( 'yp_web_design_addon', self::FEATURES, [
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

		register_post_meta( 'yp_web_design_addon', self::CTA_LABEL, [
			'type'          => 'string',
			'single'        => true,
			'default'       => '',
			'show_in_rest'  => true,
			'auth_callback' => [ $this, 'can_edit' ],
		] );

		register_post_meta( 'yp_web_design_addon', self::CTA_URL, [
			'type'          => 'string',
			'single'        => true,
			'default'       => '',
			'show_in_rest'  => true,
			'auth_callback' => [ $this, 'can_edit' ],
		] );

		register_post_meta( 'yp_web_design_addon', self::ICON, [
			'type'          => 'string',
			'single'        => true,
			'default'       => 'tag',
			'show_in_rest'  => true,
			'auth_callback' => [ $this, 'can_edit' ],
		] );
	}

	public function can_edit(): bool {
		return current_user_can( 'edit_posts' );
	}

	/** Every published add-on, in the admin's own drag-order — what the badge row itself renders. */
	public static function get_published(): array {
		return get_posts( [
			'post_type'      => 'yp_web_design_addon',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
		] );
	}
}
