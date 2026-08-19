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
