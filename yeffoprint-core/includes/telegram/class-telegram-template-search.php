<?php
/**
 * Template (design) search for the Telegram bot — direct report:
 * asking the bot "help me find the labs label" got escalated to a
 * human instead of just finding it. Reuses the exact same search
 * WordPress core already runs for the site's own header search box
 * (assets/js/search.js) and the admin manual-order Template picker —
 * a plain `WP_Query`/`get_posts()` with `s`, not a bespoke search
 * implementation. class-template-search.php's own hooks (title +
 * yp_style/yp_color/yp_material_tag taxonomy terms flattened into a
 * `_yp_search_index` meta value) widen that query automatically for
 * any `yp_template` search, including this one — nothing here needs
 * to know that widening exists.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Telegram_Template_Search {

	private const MAX_RESULTS = 6;

	/**
	 * Filler/stopwords stripped before searching. WordPress's own
	 * search requires every remaining word to appear somewhere in a
	 * matching post (title/content/taxonomy index) — a raw natural-
	 * language message like "can you help me find the labs label"
	 * would never match a Template titled "Labs" without this, since
	 * "help"/"me"/"find"/"the"/"label" don't appear in that title but
	 * "labs" does. Deliberately generic (not a bot-specific phrase
	 * list) so it holds up across however a customer actually phrases
	 * it, not just the one example reported.
	 */
	private const STOPWORDS = [
		'a', 'an', 'the', 'is', 'are', 'do', 'does', 'did', 'you', 'have',
		'has', 'had', 'help', 'me', 'my', 'find', 'finding', 'found',
		'looking', 'look', 'for', 'show', 'see', 'want', 'wanted', 'need',
		'needed', 'please', 'can', 'could', 'would', 'will', 'site',
		'website', 'label', 'labels', 'design', 'designs', 'template',
		'templates', 'on', 'in', 'at', 'of', 'to', 'with', 'and', 'or',
		'get', 'got', 'give', 'there', 'that', 'this', 'it', 'your',
		'yours', 'any', 'some',
	];

	/**
	 * @return string|null A formatted chat reply, or null if nothing
	 *                      searchable remains after stripping filler
	 *                      words, or the search itself found nothing —
	 *                      either way the caller (class-telegram-
	 *                      message-handler.php) decides what to do
	 *                      next (an explicit "no matches" reply for a
	 *                      deliberate /search, or falling through to
	 *                      FAQ/escalation for a free-text attempt) —
	 *                      this class only ever answers "found
	 *                      something or didn't."
	 */
	public static function reply( string $query ): ?string {
		$keywords = self::extract_keywords( $query );
		if ( '' === $keywords ) {
			return null;
		}

		$templates = get_posts( [
			'post_type'      => 'yp_template',
			'post_status'    => 'publish',
			's'              => $keywords,
			'posts_per_page' => self::MAX_RESULTS,
			'orderby'        => 'relevance',
		] );

		if ( ! $templates ) {
			return null;
		}

		$lines = [ __( "Here's what I found:", 'yeffoprint-core' ) ];
		foreach ( $templates as $template ) {
			$lines[] = sprintf( '%1$s — %2$s', $template->post_title, get_permalink( $template ) );
		}

		return implode( "\n", $lines );
	}

	private static function extract_keywords( string $text ): string {
		$cleaned = preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', strtolower( $text ) );
		$words   = preg_split( '/\s+/', trim( (string) $cleaned ), -1, PREG_SPLIT_NO_EMPTY );

		$words = array_filter( $words, static function ( string $word ): bool {
			return ! in_array( $word, self::STOPWORDS, true );
		} );

		return implode( ' ', $words );
	}
}
