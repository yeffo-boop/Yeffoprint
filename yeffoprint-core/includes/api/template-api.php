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
	 *     artwork_url:string|null, vial_mockup_url:string|null
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
		];
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
