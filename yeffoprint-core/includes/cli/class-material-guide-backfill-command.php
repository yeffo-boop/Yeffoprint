<?php
/**
 * One-time backfill for the Material Guide's V6 dynamic rewrite (see
 * docs/ARCHITECTURE.md). Before that change, the How It Works page's
 * per-material copy (a longer description, plus an occasional caution
 * note) was hardcoded in yeffoprint_material_guide_entries(); now both
 * come from each yp_material record's own Description (post_content)
 * and new Guide note meta field. Direct request right after that
 * ship: "give me a cli I can run to have the data you have there now
 * imported so I don't have to rewrite everything" — this pushes that
 * old hardcoded copy into whichever existing Material record it
 * matches, once, so nothing has to be retyped by hand.
 *
 * Matching is by keyword against the record's title/slug (the same
 * approach this codebase already used and then replaced — see the
 * V6 entry in docs/ARCHITECTURE.md for that history) since a live
 * record's exact slug isn't guaranteed to equal any of these six.
 * Never overwrites a Description or Guide note that's already been
 * filled in — safe to run more than once, and safe to run after the
 * owner has already started customizing a record — unless --force is
 * passed.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Material_Guide_Backfill_Command {

	private const ENTRIES = [
		[
			'keywords' => [ 'glossy', 'white' ],
			'body'     => 'Our standard material, recommended for most projects. A bright white base with a slight shine, and very easy to apply.',
			'note'     => '',
		],
		[
			'keywords' => [ 'matte', 'white' ],
			'body'     => 'Our standard material, recommended for most projects. The same bright white base as our glossy option, without the shine, and very easy to apply.',
			'note'     => '',
		],
		[
			'keywords' => [ 'holographic' ],
			'body'     => 'Our most popular holographic option, slightly thicker than our standard labels. Anywhere your design shows white, it takes on a rainbow, holographic sheen in the light.',
			'note'     => "Designs with highly saturated solid colors can occasionally cause holographic sheets to curl slightly during shipping. This never affects a label's print quality or stickiness, but it does mean holographic orders ship about 24 hours later than usual to account for it.",
		],
		[
			'keywords' => [ 'prism' ],
			'body'     => 'One of the newest additions to our lineup, slightly thicker than our standard labels. Anywhere your design shows white, it takes on our prism pattern — best suited to simpler designs, since a busier design can make the effect feel overwhelming.',
			'note'     => '',
		],
		[
			'keywords' => [ 'metallic' ],
			'body'     => 'A newer addition with a true chrome finish. Anywhere your design shows white, it takes on a metallic shine.',
			'note'     => '',
		],
		[
			'keywords' => [ 'clear' ],
			'body'     => 'Exactly what it sounds like — a fully clear label. Works best with simpler designs and less image detail, since fine detail can oversaturate during printing and edges can appear slightly blurred.',
			'note'     => '',
		],
	];

	public function register(): void {
		\WP_CLI::add_command( 'yeffoprint backfill-material-guide', [ $this, 'run' ] );
	}

	/**
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Overwrite a Description/Guide note even if the record already has one.
	 *
	 * ## EXAMPLES
	 *
	 *     wp yeffoprint backfill-material-guide
	 *     wp yeffoprint backfill-material-guide --force
	 */
	public function run( array $args, array $assoc_args ): void {
		$force = \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );

		$published = get_posts( [
			'post_type'      => 'yp_material',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
		] );

		if ( ! $published ) {
			\WP_CLI::warning( 'No published Materials found — nothing to backfill. Run this again after Materials exist.' );
			return;
		}

		$claimed = [];

		foreach ( self::ENTRIES as $entry ) {
			$record = $this->find_by_keywords( $published, $entry['keywords'], $claimed );

			if ( ! $record ) {
				\WP_CLI::log( sprintf( 'No Material matched "%s" — nothing to fill in, add its copy by hand.', implode( ' + ', $entry['keywords'] ) ) );
				continue;
			}

			$claimed[] = $record->ID;
			$label     = sprintf( '%s (#%d)', $record->post_title, $record->ID );

			if ( '' === trim( wp_strip_all_tags( $record->post_content ) ) || $force ) {
				wp_update_post( [
					'ID'           => $record->ID,
					'post_content' => $entry['body'],
				] );
				\WP_CLI::log( sprintf( 'Set Description on %s', $label ) );
			} else {
				\WP_CLI::log( sprintf( '%s already has a Description — left as-is.', $label ) );
			}

			if ( '' === $entry['note'] ) {
				continue;
			}

			$existing_note = get_post_meta( $record->ID, YeffoPrint_Commerce_Record_Meta::GUIDE_NOTE, true );
			if ( '' === trim( (string) $existing_note ) || $force ) {
				update_post_meta( $record->ID, YeffoPrint_Commerce_Record_Meta::GUIDE_NOTE, $entry['note'] );
				\WP_CLI::log( sprintf( 'Set Guide note on %s', $label ) );
			} else {
				\WP_CLI::log( sprintf( '%s already has a Guide note — left as-is.', $label ) );
			}
		}

		\WP_CLI::success( 'Material Guide backfill complete. Re-run with --force to overwrite anything already filled in.' );
	}

	/** First published Material (not already claimed by an earlier entry) whose title/slug contains every keyword. */
	private function find_by_keywords( array $published, array $keywords, array $claimed ): ?\WP_Post {
		foreach ( $published as $post ) {
			if ( in_array( $post->ID, $claimed, true ) ) {
				continue;
			}

			$haystack = sanitize_title( $post->post_title ) . '-' . $post->post_name;
			$matched  = true;

			foreach ( $keywords as $keyword ) {
				if ( false === strpos( $haystack, $keyword ) ) {
					$matched = false;
					break;
				}
			}

			if ( $matched ) {
				return $post;
			}
		}

		return null;
	}
}
