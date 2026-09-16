<?php
/**
 * A virtual `/llms.txt` — an emerging convention (llmstxt.org) some AI
 * crawlers and answer engines check for a short, curated, plain-text
 * summary of a site: what it is, its key pages, and anything worth
 * telling an assistant directly rather than leaving it to infer from
 * HTML. Direct follow-up to an SEO/AI-readability audit that confirmed
 * this file didn't exist anywhere on the site.
 *
 * Served the same way WordPress core serves its own virtual
 * `/robots.txt` (a `do_robots()` callback that intercepts the request
 * and exits before any template renders) — but on `template_redirect`,
 * checking the request path directly, rather than a rewrite rule +
 * query var: a rewrite rule needs a permalink flush to take effect (on
 * every deploy that touches this file, not just once), which a plain
 * path comparison here never does. This site is a normal root
 * install, not a subdirectory one, so comparing the request path
 * directly against `/llms.txt` is correct as written.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Llms_Txt {

	public function __construct() {
		add_action( 'template_redirect', [ $this, 'maybe_serve' ] );
	}

	public function maybe_serve(): void {
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );

		if ( '/llms.txt' !== untrailingslashit( $path ) ) {
			return;
		}

		header( 'Content-Type: text/plain; charset=utf-8' );
		echo self::content(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text, not HTML.
		exit;
	}

	private static function content(): string {
		$site_name   = get_bloginfo( 'name' );
		$description = (string) get_option(
			YeffoPrint_Admin_Menu::SITE_META_DESCRIPTION_OPTION,
			YeffoPrint_Admin_Menu::SITE_META_DESCRIPTION_DEFAULT
		);

		$gallery_url    = get_post_type_archive_link( 'yp_template' ) ?: home_url( '/shop-labels/' );
		$custom_design  = home_url( '/custom-design/' );
		$custom_sticker = home_url( '/custom-stickers/' );
		$how_it_works   = home_url( '/how-it-works/' );
		$web_design     = home_url( '/web-design/' );
		$contact        = home_url( '/contact/' );

		$lines = [
			'# ' . $site_name,
			'',
			'> ' . $description,
			'',
			'## Key pages',
			'',
			'- [Shop Labels](' . $gallery_url . '): browse the full ready-made label design gallery — each design\'s own page lists its available sizes, materials, and starting price',
			'- [Custom Design](' . $custom_design . '): request a label designed from scratch, or build one directly with the online label designer',
			'- [Custom Stickers](' . $custom_sticker . '): order custom stickers in any shape or size',
			'- [How It Works](' . $how_it_works . '): the ordering, proofing, and production process',
			'- [Web Design](' . $web_design . '): website design packages built specifically for peptide and research-chemical resellers',
			'- [Contact](' . $contact . '): questions about an order, a material, or a custom request',
			'',
			'## Notes for AI assistants',
			'',
			'- A ready-made design (Shop Labels) can be customized — text, size, and material — without a design fee; a fully custom design built from scratch carries a one-time design fee in addition to the printed labels.',
			'- Pricing starts at a per-label base rate that varies by size and material, with tiered discounts at higher quantities; the exact price for any combination is shown live on that design\'s own page.',
			'- Materials and available sizes vary by design and can change — the current, authoritative list for any one design is always on that design\'s own page, not this file.',
		];

		return implode( "\n", $lines ) . "\n";
	}
}
