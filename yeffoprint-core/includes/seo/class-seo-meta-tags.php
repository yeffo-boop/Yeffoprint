<?php
/**
 * Meta description / canonical URL / Open Graph / Twitter Card tags,
 * plus schema.org Product markup on a Template's own single page —
 * direct follow-up to an SEO/AI-readability audit that found none of
 * this anywhere on the site (no SEO plugin is installed; WordPress
 * core supplies a real `<title>` for free — block themes get
 * `title-tag` support automatically — but nothing else).
 *
 * One class resolves "what is this page" once and renders every tag
 * from that single result, so title/description/canonical/image can
 * never drift apart the way three separately-written resolvers would.
 * Deliberately plugin-owned rather than theme-owned, per
 * yeffoprint/functions.php's own header comment: "Business logic lives
 * entirely in the yeffoprint-core plugin."
 *
 * Explicitly takes over the canonical tag: WordPress core's own
 * `rel_canonical()` should cover ordinary singular pages on its own,
 * but a direct test of this exact site found no `rel="canonical"` at
 * all on a live page, and nothing in this theme or plugin removes it —
 * the cause couldn't be fully root-caused from the codebase alone (a
 * server-level or must-use difference outside version control is the
 * likely explanation). The `remove_action()` below guarantees there is
 * never a duplicate/conflicting tag regardless of that, and gives this
 * class one place to decide the canonical URL for every page type
 * (including a Template's own single page, which core's version
 * wouldn't know needs a `yp_template`-specific description alongside
 * it anyway).
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Seo_Meta_Tags {

	/** Conventional meta-description length before search engines truncate it anyway. */
	private const DESCRIPTION_MAX_CHARS = 155;

	/**
	 * Branded 1200×630 share image (Facebook/LinkedIn/X's own
	 * recommended 1.91:1 size) used wherever a page has no featured
	 * image of its own. This used to be the 512×512 site icon, which
	 * Facebook center-crops to 1.91:1 — a link to the homepage showed
	 * nothing but a blown-up slice of the CMY bars.
	 */
	private const DEFAULT_SHARE_IMAGE        = 'assets/images/og-default.png';
	private const DEFAULT_SHARE_IMAGE_WIDTH  = 1200;
	private const DEFAULT_SHARE_IMAGE_HEIGHT = 630;

	public function __construct() {
		remove_action( 'wp_head', 'rel_canonical' );
		add_action( 'wp_head', [ $this, 'render' ], 1 );
	}

	public function render(): void {
		$data = $this->resolve();

		if ( ! $data ) {
			return;
		}

		echo '<link rel="canonical" href="' . esc_url( $data['url'] ) . '" />' . "\n";

		if ( $data['description'] ) {
			echo '<meta name="description" content="' . esc_attr( self::truncate( $data['description'] ) ) . '" />' . "\n";
		}

		echo '<meta property="og:type" content="' . esc_attr( $data['og_type'] ) . '" />' . "\n";
		echo '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . '" />' . "\n";
		echo '<meta property="og:title" content="' . esc_attr( $data['title'] ) . '" />' . "\n";
		echo '<meta property="og:url" content="' . esc_url( $data['url'] ) . '" />' . "\n";

		if ( $data['description'] ) {
			echo '<meta property="og:description" content="' . esc_attr( self::truncate( $data['description'] ) ) . '" />' . "\n";
		}

		$image = $data['image'];

		echo '<meta property="og:image" content="' . esc_url( $image['url'] ) . '" />' . "\n";

		if ( $image['width'] && $image['height'] ) {
			echo '<meta property="og:image:width" content="' . (int) $image['width'] . '" />' . "\n";
			echo '<meta property="og:image:height" content="' . (int) $image['height'] . '" />' . "\n";
		}

		echo '<meta property="og:image:alt" content="' . esc_attr( $image['alt'] ) . '" />' . "\n";

		echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
		echo '<meta name="twitter:title" content="' . esc_attr( $data['title'] ) . '" />' . "\n";

		if ( $data['description'] ) {
			echo '<meta name="twitter:description" content="' . esc_attr( self::truncate( $data['description'] ) ) . '" />' . "\n";
		}

		echo '<meta name="twitter:image" content="' . esc_url( $image['url'] ) . '" />' . "\n";
		echo '<meta name="twitter:image:alt" content="' . esc_attr( $image['alt'] ) . '" />' . "\n";

		if ( is_singular( 'yp_template' ) ) {
			$this->render_product_schema( (int) get_the_ID() );
		}
	}

	/** @return array{title:string,description:string,url:string,image:array{url:string,width:int,height:int,alt:string},og_type:string}|null */
	private function resolve(): ?array {
		if ( is_singular( 'yp_template' ) ) {
			return $this->resolve_template( (int) get_the_ID() );
		}

		if ( is_front_page() ) {
			return [
				'title'       => self::front_page_share_title(),
				'description' => self::site_meta_description(),
				'url'         => home_url( '/' ),
				'image'       => self::default_share_image(),
				'og_type'     => 'website',
			];
		}

		// The Shop Labels gallery — the one archive on this site worth a
		// canonical/description of its own; there's no blog/category
		// content yet for a generic archive branch to cover.
		if ( is_post_type_archive( 'yp_template' ) ) {
			$url = get_post_type_archive_link( 'yp_template' );
			return $url ? [
				'title'       => wp_get_document_title(),
				'description' => self::site_meta_description(),
				'url'         => $url,
				'image'       => self::default_share_image(),
				'og_type'     => 'website',
			] : null;
		}

		// Every other singular page/post — this also covers WooCommerce's
		// own Cart/Checkout/My Account pages, which are ordinary `page`
		// posts under the hood, so they keep a real canonical here the
		// same way core's own rel_canonical() (removed above) already
		// gave them. Only non-singular contexts (search, 404, a future
		// blog's category/tag archives) get nothing from this class.
		if ( is_singular() ) {
			return $this->resolve_singular( (int) get_the_ID() );
		}

		return null;
	}

	private function resolve_template( int $post_id ): ?array {
		if ( ! $post_id || ! function_exists( 'yeffoprint_core_get_template_seo_data' ) ) {
			return null;
		}

		$seo = yeffoprint_core_get_template_seo_data( $post_id );

		if ( ! $seo ) {
			return null;
		}

		return [
			'title'       => wp_get_document_title(),
			'description' => $seo['description'] ?: self::site_meta_description(),
			'url'         => (string) get_permalink( $post_id ),
			'image'       => self::featured_image( $post_id ) ?: self::default_share_image(),
			'og_type'     => 'product',
		];
	}

	private function resolve_singular( int $post_id ): ?array {
		if ( ! $post_id ) {
			return null;
		}

		$excerpt = wp_strip_all_tags( get_the_excerpt( $post_id ) );
		$url     = wp_get_canonical_url( $post_id ) ?: get_permalink( $post_id );

		if ( ! $url ) {
			return null;
		}

		return [
			'title'       => wp_get_document_title(),
			'description' => $excerpt ?: self::site_meta_description(),
			'url'         => (string) $url,
			'image'       => self::featured_image( $post_id ) ?: self::default_share_image(),
			'og_type'     => 'article',
		];
	}

	/**
	 * schema.org Product markup — the audit's other headline finding:
	 * WooCommerce would normally emit this automatically, but a
	 * Template's single page is a custom post type with its own
	 * template, not WooCommerce's own single-product template, so it
	 * never fires. `base_unit_price` here is the same Phase 3 provisional
	 * starting price class-template-schema-controller.php's own docblock
	 * already flags as "not a real PricingRule" — this is a starting
	 * price for search/AI-answer purposes, same honesty scope as that
	 * endpoint, not a promise of the exact checkout total for any one
	 * combination of size/material/quantity.
	 */
	private function render_product_schema( int $post_id ): void {
		if ( ! function_exists( 'yeffoprint_core_get_template_seo_data' ) ) {
			return;
		}

		$seo = yeffoprint_core_get_template_seo_data( $post_id );

		if ( ! $seo ) {
			return;
		}

		$url   = (string) get_permalink( $post_id );
		$price = function_exists( 'yeffoprint_core_base_unit_price' ) ? (float) yeffoprint_core_base_unit_price() : 0.0;

		$schema = [
			'@context' => 'https://schema.org',
			'@type'    => 'Product',
			'name'     => $seo['title'],
			'url'      => $url,
			'brand'    => [
				'@type' => 'Brand',
				'name'  => get_bloginfo( 'name' ),
			],
			'offers'   => [
				'@type'         => 'Offer',
				'url'           => $url,
				'priceCurrency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
				'price'         => number_format( $price, 2, '.', '' ),
				'availability'  => 'https://schema.org/InStock',
			],
		];

		if ( $seo['description'] ) {
			$schema['description'] = $seo['description'];
		}

		$image = get_the_post_thumbnail_url( $post_id, 'large' );
		if ( $image ) {
			$schema['image'] = $image;
		}

		echo '<script type="application/ld+json">' . wp_json_encode( $schema ) . '</script>' . "\n";
	}

	private static function site_meta_description(): string {
		return (string) get_option(
			YeffoPrint_Admin_Menu::SITE_META_DESCRIPTION_OPTION,
			YeffoPrint_Admin_Menu::SITE_META_DESCRIPTION_DEFAULT
		);
	}

	/**
	 * The page's own featured image at `large` size, with the real
	 * rendered dimensions so Facebook can lay out the card on the first
	 * share instead of waiting for its crawler to download the file.
	 *
	 * @return array{url:string,width:int,height:int,alt:string}|null
	 */
	private static function featured_image( int $post_id ): ?array {
		$attachment_id = (int) get_post_thumbnail_id( $post_id );
		$src           = $attachment_id ? wp_get_attachment_image_src( $attachment_id, 'large' ) : false;

		if ( ! $src ) {
			return null;
		}

		$alt = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );

		return [
			'url'    => (string) $src[0],
			'width'  => (int) $src[1],
			'height' => (int) $src[2],
			'alt'    => $alt ?: get_the_title( $post_id ),
		];
	}

	/** @return array{url:string,width:int,height:int,alt:string} */
	private static function default_share_image(): array {
		return [
			'url'    => YEFFOPRINT_CORE_URL . self::DEFAULT_SHARE_IMAGE . '?ver=' . YEFFOPRINT_CORE_VERSION,
			'width'  => self::DEFAULT_SHARE_IMAGE_WIDTH,
			'height' => self::DEFAULT_SHARE_IMAGE_HEIGHT,
			'alt'    => get_bloginfo( 'name' ) . ' — custom labels for peptide vials',
		];
	}

	/**
	 * `wp_get_document_title()` on the front page is just the site name
	 * when no tagline is set (the live site has none), which made every
	 * shared homepage link read as a bare "YeffoDesign". Uses the real
	 * tagline if one is ever set in Settings → General.
	 */
	private static function front_page_share_title(): string {
		$name    = get_bloginfo( 'name' );
		$tagline = trim( (string) get_bloginfo( 'description' ) );

		return $name . ' — ' . ( $tagline ?: 'Custom Labels for Peptide Vials' );
	}

	private static function truncate( string $text ): string {
		$text = trim( $text );

		if ( mb_strlen( $text ) <= self::DESCRIPTION_MAX_CHARS ) {
			return $text;
		}

		return rtrim( mb_substr( $text, 0, self::DESCRIPTION_MAX_CHARS - 1 ) ) . '…';
	}
}
