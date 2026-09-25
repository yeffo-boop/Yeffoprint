<?php
/**
 * Read-only template tags the theme uses to render Template data.
 *
 * The theme's yeffoprint/template-card block (Phase 3) calls this
 * instead of reading `_yp_*` post meta or plugin class constants
 * directly, so the storefront presentation layer never needs to know
 * how Template data is stored — only yeffoprint-core does. See
 * docs/ARCHITECTURE.md §1: the theme "consumes state/data from
 * plugin-provided APIs."
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'yeffoprint_core_get_template_card_data' ) ) {
	/**
	 * @return array{
	 *     id:int, title:string, permalink:string, badge:string,
	 *     badge_label:string, starting_price:string,
	 *     artwork_url:string|null, vial_mockup_url:string|null,
	 *     material_label:string|null, size_label:string|null
	 * }|null
	 */
	function yeffoprint_core_get_template_card_data( int $post_id ): ?array {
		$post = get_post( $post_id );

		if ( ! $post || 'yp_template' !== $post->post_type ) {
			return null;
		}

		$badge = (string) get_post_meta( $post_id, YeffoPrint_Template_Meta::BADGE, true );

		$vial_mockup_id  = (int) get_post_meta( $post_id, YeffoPrint_Template_Meta::VIAL_MOCKUP, true );
		$vial_mockup_url = $vial_mockup_id ? wp_get_attachment_image_url( $vial_mockup_id, 'medium_large' ) : null;

		return [
			'id'              => $post_id,
			'title'           => get_the_title( $post_id ),
			'permalink'       => (string) get_permalink( $post_id ),
			'badge'           => $badge,
			'badge_label'     => yeffoprint_core_badge_label( $badge ),
			'starting_price'  => yeffoprint_core_starting_price_label(),
			'artwork_url'     => get_the_post_thumbnail_url( $post_id, 'medium_large' ) ?: null,
			'vial_mockup_url' => $vial_mockup_url ?: null,
			'material_label'  => yeffoprint_core_compatible_record_label( $post_id, YeffoPrint_Template_Meta::COMPATIBLE_MATERIALS, 'materials' ),
			'size_label'      => yeffoprint_core_compatible_record_label( $post_id, YeffoPrint_Template_Meta::COMPATIBLE_SIZES, 'sizes' ),
		];
	}
}

if ( ! function_exists( 'yeffoprint_core_compatible_record_label' ) ) {
	/**
	 * A one- or two-word gallery-card teaser for a Template's compatible
	 * Materials/Sizes: the record's own name when there's exactly one,
	 * otherwise a count ("4 materials") — the full list is what the
	 * configurator's own pickers are for, not this card.
	 */
	function yeffoprint_core_compatible_record_label( int $template_id, string $meta_key, string $plural ): ?string {
		$ids = array_map( 'absint', (array) get_post_meta( $template_id, $meta_key, true ) );

		$published = array_values( array_filter( $ids, static function ( $id ) {
			return 'publish' === get_post_status( $id );
		} ) );

		if ( ! $published ) {
			return null;
		}

		if ( 1 === count( $published ) ) {
			return get_the_title( $published[0] ) ?: null;
		}

		return count( $published ) . ' ' . $plural;
	}
}

if ( ! function_exists( 'yeffoprint_core_compatible_record_names' ) ) {
	/**
	 * Every published compatible Size/Material's own title, in full —
	 * the sibling of yeffoprint_core_compatible_record_label() above,
	 * which only ever returns a one-record name or a bare count. Used
	 * by yeffoprint_core_get_template_seo_data() below to list every
	 * option out for real, crawlable page copy, where a gallery card's
	 * "4 materials" teaser would say nothing useful.
	 *
	 * @return string[]
	 */
	function yeffoprint_core_compatible_record_names( int $template_id, string $meta_key ): array {
		$ids = array_map( 'absint', (array) get_post_meta( $template_id, $meta_key, true ) );

		$names = [];
		foreach ( $ids as $id ) {
			if ( 'publish' === get_post_status( $id ) ) {
				$names[] = get_the_title( $id );
			}
		}

		return $names;
	}
}

if ( ! function_exists( 'yeffoprint_core_get_template_seo_data' ) ) {
	/**
	 * Direct report: "ChatGPT and the like cannot index my site" — a
	 * Template's single page is a JS-only configurator (assets/js/
	 * configurator.js fetches everything from the /templates/{id}/
	 * configurator REST endpoint and fills it in client-side); a crawler
	 * that doesn't execute JavaScript — most AI-answer-engine bots
	 * included — sees an empty `<h1>` and no other page copy at all. This
	 * is the server-side counterpart of that same REST endpoint
	 * (class-template-schema-controller.php), read by the theme's new
	 * blocks/label-configurator block to render a real, always-visible
	 * title/description/specs summary alongside the (unchanged)
	 * JS-hydrated interactive tool — same "theme consumes a plugin API,
	 * never plugin-owned data, directly" split as every other template
	 * tag in this file.
	 *
	 * @return array{title:string, description:string, starting_price:string, size_names:string[], material_names:string[]}|null
	 */
	function yeffoprint_core_get_template_seo_data( int $post_id ): ?array {
		$post = get_post( $post_id );

		if ( ! $post || 'yp_template' !== $post->post_type ) {
			return null;
		}

		return [
			'title'           => get_the_title( $post_id ),
			// Same the_content-filtered-then-stripped shape as the REST
			// endpoint's own 'description' field, so this always matches
			// exactly what the interactive configurator itself would show.
			'description'     => wp_strip_all_tags( apply_filters( 'the_content', $post->post_content ) ),
			'starting_price'  => yeffoprint_core_starting_price_label(),
			'size_names'      => yeffoprint_core_compatible_record_names( $post_id, YeffoPrint_Template_Meta::COMPATIBLE_SIZES ),
			'material_names'  => yeffoprint_core_compatible_record_names( $post_id, YeffoPrint_Template_Meta::COMPATIBLE_MATERIALS ),
		];
	}
}

if ( ! function_exists( 'yeffoprint_core_get_announcement_bar_text' ) ) {
	/**
	 * Set from the YeffoPrint admin menu (class-admin-menu.php); read
	 * here by the theme's yeffoprint/announcement-bar block instead of
	 * the block calling get_option() directly, same "theme consumes a
	 * plugin API, never plugin-owned data, directly" split as every
	 * other template tag in this file.
	 */
	function yeffoprint_core_get_announcement_bar_text(): string {
		return (string) get_option(
			YeffoPrint_Admin_Menu::ANNOUNCEMENT_BAR_OPTION,
			YeffoPrint_Admin_Menu::ANNOUNCEMENT_BAR_DEFAULT
		);
	}
}

if ( ! function_exists( 'yeffoprint_core_away_mode' ) ) {
	/**
	 * Set from the YeffoPrint admin menu (class-admin-menu.php); read
	 * here by the theme's away-bar/away-card blocks and the checkout-
	 * notice/email-notice classes below, instead of any of them calling
	 * get_option() or YeffoPrint_Admin_Menu::away_mode() directly — same
	 * "theme/other classes consume a plugin API, never plugin-owned data,
	 * directly" split as every other template tag in this file.
	 *
	 * @return array{return_date:string, return_label:string}|null Null when off/unconfigured/past.
	 */
	function yeffoprint_core_away_mode(): ?array {
		return class_exists( 'YeffoPrint_Admin_Menu' ) ? YeffoPrint_Admin_Menu::away_mode() : null;
	}
}

if ( ! function_exists( 'yeffoprint_core_rewards_points_per_dollar_label' ) ) {
	/**
	 * Used by patterns/rewards-promo.php so the homepage promo's earn
	 * rate is always the same live, admin-configurable number the
	 * points engine itself uses (includes/rewards/class-rewards.php),
	 * never a hardcoded copy of it.
	 */
	function yeffoprint_core_rewards_points_per_dollar_label(): string {
		return class_exists( 'YeffoPrint_Rewards' ) ? YeffoPrint_Rewards::points_per_dollar_label() : '1';
	}
}

if ( ! function_exists( 'yeffoprint_core_badge_label' ) ) {
	function yeffoprint_core_badge_label( string $badge ): string {
		$labels = [
			'new'          => __( 'New', 'yeffoprint-core' ),
			'popular'      => __( 'Popular', 'yeffoprint-core' ),
			'featured'     => __( 'Featured', 'yeffoprint-core' ),
			'customizable' => __( 'Customizable', 'yeffoprint-core' ),
		];

		return $labels[ $badge ] ?? '';
	}
}

if ( ! function_exists( 'yeffoprint_core_order_addon_owns_order' ) ) {
	/**
	 * Used by blocks/order-addon-gate/render.php to verify the order+key
	 * pair in the /add-to-order/ URL before showing anything about that
	 * order — never plugin-owned data read or trusted directly.
	 */
	function yeffoprint_core_order_addon_owns_order( \WC_Order $order, string $key ): bool {
		return class_exists( 'YeffoPrint_Order_Addon' ) && YeffoPrint_Order_Addon::owns_order( $order, $key );
	}
}

if ( ! function_exists( 'yeffoprint_core_order_addon_eligibility' ) ) {
	/**
	 * @return array{eligible:bool, reason:string, root:\WC_Order}|null
	 */
	function yeffoprint_core_order_addon_eligibility( \WC_Order $order ): ?array {
		return class_exists( 'YeffoPrint_Order_Addon' ) ? YeffoPrint_Order_Addon::eligibility( $order ) : null;
	}
}

if ( ! function_exists( 'yeffoprint_core_order_addon_start_session' ) ) {
	function yeffoprint_core_order_addon_start_session( int $root_id ): void {
		if ( class_exists( 'YeffoPrint_Order_Addon' ) ) {
			YeffoPrint_Order_Addon::start_session( $root_id );
		}
	}
}

if ( ! function_exists( 'yeffoprint_core_get_print_data' ) ) {
	/**
	 * A 3D print's product-page data (blocks/print-product/render.php,
	 * blocks/print-card/render.php): title, base price, ships-in, main
	 * photo, and each color choice with its offered filament colors.
	 * Null for anything that isn't a yp_print.
	 */
	function yeffoprint_core_get_print_data( int $print_id ): ?array {
		return class_exists( 'YeffoPrint_Print_Meta' ) ? YeffoPrint_Print_Meta::get_item_payload( $print_id ) : null;
	}
}
