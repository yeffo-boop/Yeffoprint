<?php
/**
 * The 12 seasonal themes the homepage promo banner
 * (yeffoprint/blocks/promo-banner) can be set to — Dashboard →
 * YeffoPrint → Settings → Homepage Promo picks one; everything else
 * (palette, copy templates, icon) lives here as one source of truth
 * rather than scattered across the block's render.php.
 *
 * Every theme keeps the same structural device the original
 * SummerWeen mockup used: the site's own press-proof/registration-
 * mark motif (patterns/hero.php's .yp-proof), recolored per theme with
 * a small themed icon standing in for the brand's usual CMY bars — so
 * switching themes never stops looking like this brand's own
 * checkout, just in a different season's colors. Colors/copy are
 * fixed per theme (a design decision, not a limitation); the offer
 * text and code are what an admin actually fills in per promotion —
 * see class-admin-menu.php's Homepage Promo section.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Promo_Themes {

	/**
	 * @return array<string, array{
	 *   label:string, eyebrow:string, headline:string, body:string,
	 *   bg:string, glow_a:string, glow_b:string, ink:string,
	 *   ink_soft:string, accent:string, accent_ink:string,
	 *   bars:array{0:string,1:string,2:string}, code_bg:string,
	 *   code_ink:string, icon:string
	 * }> Keyed by a stable slug (stored in the option; never rename a
	 *    key that's shipped, or an existing site's saved selection
	 *    silently falls back to the default).
	 */
	public static function all(): array {
		return [
			'new-years'    => [
				'label'      => __( "New Year's", 'yeffoprint-core' ),
				'eyebrow'    => __( 'New Year, New Labels', 'yeffoprint-core' ),
				/* translators: %s: the admin-entered offer, e.g. "15% off" */
				'headline'   => __( 'Ring in the New Year with %s', 'yeffoprint-core' ),
				'body'       => __( "Kick off the year with fresh labels, faster proofs, and a print run that's ready when you are.", 'yeffoprint-core' ),
				'bg'         => '#0B1220',
				'glow_a'     => 'rgba(212,175,55,.28)',
				'glow_b'     => 'rgba(27,35,82,.55)',
				'ink'        => '#F5F3EC',
				'ink_soft'   => '#C9C6BC',
				'accent'     => '#D4AF37',
				'accent_ink' => '#14110A',
				'bars'       => [ '#D4AF37', '#C7CCD1', '#1B2352' ],
				'code_bg'    => '#F5F3EC',
				'code_ink'   => '#14110A',
				'icon'       => '<circle cx="12" cy="12" r="1.4" fill="currentColor" stroke="none"/><line x1="12" y1="12" x2="12" y2="4"/><line x1="12" y1="12" x2="12" y2="20"/><line x1="12" y1="12" x2="4" y2="12"/><line x1="12" y1="12" x2="20" y2="12"/><line x1="12" y1="12" x2="6.5" y2="6.5"/><line x1="12" y1="12" x2="17.5" y2="17.5"/><line x1="12" y1="12" x2="6.5" y2="17.5"/><line x1="12" y1="12" x2="17.5" y2="6.5"/>',
			],
			'valentines'   => [
				'label'      => __( "Valentine's Day", 'yeffoprint-core' ),
				'eyebrow'    => __( 'Sealed With a Kiss', 'yeffoprint-core' ),
				/* translators: %s: the admin-entered offer, e.g. "15% off" */
				'headline'   => __( 'Fall in love with %s', 'yeffoprint-core' ),
				'body'       => __( 'Show your labels some love — every order still gets the same free proof before anything prints.', 'yeffoprint-core' ),
				'bg'         => '#2B0B14',
				'glow_a'     => 'rgba(214,51,108,.30)',
				'glow_b'     => 'rgba(122,20,49,.45)',
				'ink'        => '#FBEAEE',
				'ink_soft'   => '#E3B9C4',
				'accent'     => '#D6336C',
				'accent_ink' => '#FBEAEE',
				'bars'       => [ '#D6336C', '#F2B8C6', '#C9A227' ],
				'code_bg'    => '#FBEAEE',
				'code_ink'   => '#2B0B14',
				'icon'       => '<path d="M12 20.5s-7.5-4.6-10-9.4C.4 7.6 2.6 4 6.3 4c2 0 3.6 1 5.7 3.4C14.1 5 15.7 4 17.7 4c3.7 0 5.9 3.6 4.3 7.1-2.5 4.8-10 9.4-10 9.4z"/>',
			],
			'st-patricks'  => [
				'label'      => __( "St. Patrick's Day", 'yeffoprint-core' ),
				'eyebrow'    => __( 'Lucky You', 'yeffoprint-core' ),
				/* translators: %s: the admin-entered offer, e.g. "15% off" */
				'headline'   => __( 'Get lucky with %s', 'yeffoprint-core' ),
				'body'       => __( "No luck required — just waterproof labels, exact color matching, and a proof you approve first.", 'yeffoprint-core' ),
				'bg'         => '#0B2A20',
				'glow_a'     => 'rgba(56,161,105,.30)',
				'glow_b'     => 'rgba(201,162,39,.25)',
				'ink'        => '#F1F7F1',
				'ink_soft'   => '#BFE0C9',
				'accent'     => '#2FA65A',
				'accent_ink' => '#05170F',
				'bars'       => [ '#2FA65A', '#C9A227', '#0F3B2E' ],
				'code_bg'    => '#F1F7F1',
				'code_ink'   => '#05170F',
				'icon'       => '<circle cx="9" cy="9" r="3.3"/><circle cx="15" cy="9" r="3.3"/><circle cx="12" cy="13.2" r="3.3"/><line x1="12" y1="16" x2="12" y2="21"/>',
			],
			'mothers-day'  => [
				'label'      => __( "Mother's Day", 'yeffoprint-core' ),
				'eyebrow'    => __( 'For Mom', 'yeffoprint-core' ),
				/* translators: %s: the admin-entered offer, e.g. "15% off" */
				'headline'   => __( 'Treat Mom to %s', 'yeffoprint-core' ),
				'body'       => __( "Whatever you're labeling this month, we'll get it exactly right — free proof before anything prints.", 'yeffoprint-core' ),
				'bg'         => '#FBEFEA',
				'glow_a'     => 'rgba(196,130,140,.20)',
				'glow_b'     => 'rgba(150,168,140,.20)',
				'ink'        => '#3A2A2C',
				'ink_soft'   => '#7A5F62',
				'accent'     => '#B5646F',
				'accent_ink' => '#FBEFEA',
				'bars'       => [ '#C48D8C', '#96A88C', '#D9B872' ],
				'code_bg'    => '#FFFFFF',
				'code_ink'   => '#3A2A2C',
				'icon'       => '<circle cx="12" cy="12" r="2"/><circle cx="12" cy="6.5" r="2.6"/><circle cx="12" cy="17.5" r="2.6"/><circle cx="6.5" cy="12" r="2.6"/><circle cx="17.5" cy="12" r="2.6"/>',
			],
			'easter'       => [
				'label'      => __( 'Easter', 'yeffoprint-core' ),
				'eyebrow'    => __( 'Hop To It', 'yeffoprint-core' ),
				/* translators: %s: the admin-entered offer, e.g. "15% off" */
				'headline'   => __( 'Hop into %s', 'yeffoprint-core' ),
				'body'       => __( 'New season, new labels — waterproof stock and a free proof before anything hits press.', 'yeffoprint-core' ),
				'bg'         => '#F3F0FA',
				'glow_a'     => 'rgba(168,196,224,.25)',
				'glow_b'     => 'rgba(247,214,153,.25)',
				'ink'        => '#2E2A3A',
				'ink_soft'   => '#6D6580',
				'accent'     => '#8E6FB0',
				'accent_ink' => '#F3F0FA',
				'bars'       => [ '#F2A6B8', '#A8C4E0', '#F7D699' ],
				'code_bg'    => '#FFFFFF',
				'code_ink'   => '#2E2A3A',
				'icon'       => '<path d="M12 21c4.5 0 7-4.3 7-9.2C19 6.9 15.9 3 12 3S5 6.9 5 11.8C5 16.7 7.5 21 12 21z"/><path d="M6 12.5c2-1 4-1 6 0s4 1 6 0" stroke-width="1.3"/><path d="M6.5 16c1.7-.8 3.6-.8 5.5 0s3.8.8 5.5 0" stroke-width="1.3"/>',
			],
			'spring'       => [
				'label'      => __( 'Spring', 'yeffoprint-core' ),
				'eyebrow'    => __( 'Fresh Start', 'yeffoprint-core' ),
				/* translators: %s: the admin-entered offer, e.g. "15% off" */
				'headline'   => __( 'Spring forward with %s', 'yeffoprint-core' ),
				'body'       => __( 'Time for a refresh — waterproof, solvent-resistant labels with a free proof before printing.', 'yeffoprint-core' ),
				'bg'         => '#F2F8EF',
				'glow_a'     => 'rgba(122,178,94,.22)',
				'glow_b'     => 'rgba(240,150,110,.20)',
				'ink'        => '#223420',
				'ink_soft'   => '#5B7256',
				'accent'     => '#4C9A4C',
				'accent_ink' => '#F2F8EF',
				'bars'       => [ '#4C9A4C', '#F0966E', '#F2C744' ],
				'code_bg'    => '#FFFFFF',
				'code_ink'   => '#223420',
				'icon'       => '<line x1="12" y1="21" x2="12" y2="10"/><path d="M12 12c0-3.5-2.5-5.5-6-5.5C6 10 8.5 12 12 12z"/><path d="M12 10c0-3.5 2.5-5.5 6-5.5C18 8 15.5 10 12 10z"/>',
			],
			'summer'       => [
				'label'      => __( 'Summer', 'yeffoprint-core' ),
				'eyebrow'    => __( 'Heat Wave', 'yeffoprint-core' ),
				/* translators: %s: the admin-entered offer, e.g. "15% off" */
				'headline'   => __( 'Soak up %s this summer', 'yeffoprint-core' ),
				'body'       => __( "Beat the heat with labels that don't quit — waterproof, exact color match, free proof first.", 'yeffoprint-core' ),
				'bg'         => '#EAF7FB',
				'glow_a'     => 'rgba(0,174,239,.22)',
				'glow_b'     => 'rgba(255,138,101,.22)',
				'ink'        => '#0E2A33',
				'ink_soft'   => '#3E6672',
				'accent'     => '#0090C8',
				'accent_ink' => '#EAF7FB',
				'bars'       => [ '#00AEEF', '#FF8A65', '#FFC93C' ],
				'code_bg'    => '#FFFFFF',
				'code_ink'   => '#0E2A33',
				'icon'       => '<circle cx="12" cy="12" r="4.3"/><line x1="12" y1="2.5" x2="12" y2="5"/><line x1="12" y1="19" x2="12" y2="21.5"/><line x1="2.5" y1="12" x2="5" y2="12"/><line x1="19" y1="12" x2="21.5" y2="12"/><line x1="5.1" y1="5.1" x2="6.8" y2="6.8"/><line x1="17.2" y1="17.2" x2="18.9" y2="18.9"/><line x1="5.1" y1="18.9" x2="6.8" y2="17.2"/><line x1="17.2" y1="6.8" x2="18.9" y2="5.1"/>',
			],
			'fall'         => [
				'label'      => __( 'Fall', 'yeffoprint-core' ),
				'eyebrow'    => __( 'Falling For Savings', 'yeffoprint-core' ),
				/* translators: %s: the admin-entered offer, e.g. "15% off" */
				'headline'   => __( 'Fall for %s', 'yeffoprint-core' ),
				'body'       => __( 'Cozy season, crisp labels — free proof before anything prints, every single order.', 'yeffoprint-core' ),
				'bg'         => '#2B1B12',
				'glow_a'     => 'rgba(196,93,26,.28)',
				'glow_b'     => 'rgba(120,60,20,.35)',
				'ink'        => '#F7EDE1',
				'ink_soft'   => '#D9BBA0',
				'accent'     => '#C4661A',
				'accent_ink' => '#2B1B12',
				'bars'       => [ '#C4661A', '#D9A441', '#5B3A22' ],
				'code_bg'    => '#F7EDE1',
				'code_ink'   => '#2B1B12',
				'icon'       => '<path d="M12 3c4 2 7 6 7 10.5A6.5 6.5 0 0 1 5 13.5C5 9 8 5 12 3z"/><line x1="12" y1="3" x2="12" y2="21"/>',
			],
			'summerween'   => [
				'label'      => __( 'SummerWeen', 'yeffoprint-core' ),
				'eyebrow'    => __( 'Two Seasons, One Sale', 'yeffoprint-core' ),
				/* translators: %s: the admin-entered offer, e.g. "15% off" */
				'headline'   => __( "It's SummerWeen — enjoy %s", 'yeffoprint-core' ),
				'body'       => __( 'One sale, two seasons — vial labels, custom designs, and stickers alike, all at the same rate.', 'yeffoprint-core' ),
				'bg'         => '#17111C',
				'glow_a'     => 'rgba(217,89,26,.35)',
				'glow_b'     => 'rgba(74,25,66,.55)',
				'ink'        => '#FBF3E7',
				'ink_soft'   => '#D9D2C8',
				'accent'     => '#E8631A',
				'accent_ink' => '#FBF3E7',
				'bars'       => [ '#E8631A', '#6B2D6E', '#D4A017' ],
				'code_bg'    => '#FBF3E7',
				'code_ink'   => '#17111C',
				'icon'       => '<path d="M12 3.5v2.2"/><path d="M10 4.3c1 .6 1 1.6 0 2.2"/><ellipse cx="12" cy="14" rx="7.2" ry="6.3"/><path d="M8.7 8.3v11.4M12 7.7v12.6M15.3 8.3v11.4" stroke-width="1"/>',
			],
			'halloween'    => [
				'label'      => __( 'Halloween', 'yeffoprint-core' ),
				'eyebrow'    => __( 'Trick or Treat', 'yeffoprint-core' ),
				/* translators: %s: the admin-entered offer, e.g. "15% off" */
				'headline'   => __( 'Trick-or-treat yourself to %s', 'yeffoprint-core' ),
				'body'       => __( 'No tricks, just treats — waterproof labels, exact color matching, and a free proof before anything prints.', 'yeffoprint-core' ),
				'bg'         => '#120B18',
				'glow_a'     => 'rgba(90,30,120,.35)',
				'glow_b'     => 'rgba(232,99,26,.25)',
				'ink'        => '#F3E9F7',
				'ink_soft'   => '#C9B3D6',
				'accent'     => '#D9480F',
				'accent_ink' => '#120B18',
				'bars'       => [ '#D9480F', '#7A2E8C', '#14100A' ],
				'code_bg'    => '#F3E9F7',
				'code_ink'   => '#120B18',
				'icon'       => '<path d="M12 9.5c-1.5-2.5-4.5-3.5-8-2 1.6.4 3 1.6 3.6 3.2-1.8.3-3.3 1.4-4.1 3 1.8-.4 3.3.1 4.2 1.3-1 .8-1.4 2-1.1 3.4 1.1-1.3 2.2-1.8 3.1-1.5L12 21"/><path d="M12 9.5c1.5-2.5 4.5-3.5 8-2-1.6.4-3 1.6-3.6 3.2 1.8.3 3.3 1.4 4.1 3-1.8-.4-3.3.1-4.2 1.3 1 .8 1.4 2 1.1 3.4-1.1-1.3-2.2-1.8-3.1-1.5L12 21"/><ellipse cx="12" cy="10.5" rx="1.3" ry="1.7"/>',
			],
			'thanksgiving' => [
				'label'      => __( 'Thanksgiving', 'yeffoprint-core' ),
				'eyebrow'    => __( 'Grateful For You', 'yeffoprint-core' ),
				/* translators: %s: the admin-entered offer, e.g. "15% off" */
				'headline'   => __( 'Give thanks for %s', 'yeffoprint-core' ),
				'body'       => __( 'A little something to be thankful for — free proof before printing, every order, every time.', 'yeffoprint-core' ),
				'bg'         => '#2A160F',
				'glow_a'     => 'rgba(178,58,46,.28)',
				'glow_b'     => 'rgba(196,142,41,.28)',
				'ink'        => '#F8ECDD',
				'ink_soft'   => '#D8B999',
				'accent'     => '#B23A2E',
				'accent_ink' => '#F8ECDD',
				'bars'       => [ '#B23A2E', '#C4661A', '#C48E29' ],
				'code_bg'    => '#F8ECDD',
				'code_ink'   => '#2A160F',
				'icon'       => '<line x1="12" y1="21" x2="12" y2="5"/><path d="M12 6l-3-2M12 6l3-2M12 9l-3-2M12 9l3-2M12 12l-3-2M12 12l3-2M12 15l-3-2M12 15l3-2" stroke-width="1.3"/>',
			],
			'christmas'    => [
				'label'      => __( 'Christmas', 'yeffoprint-core' ),
				'eyebrow'    => __( "Season's Greetings", 'yeffoprint-core' ),
				/* translators: %s: the admin-entered offer, e.g. "15% off" */
				'headline'   => __( 'Deck the halls with %s', 'yeffoprint-core' ),
				'body'       => __( 'Wrap up the year with labels that print exactly right — free proof before anything ships to press.', 'yeffoprint-core' ),
				'bg'         => '#0E1F16',
				'glow_a'     => 'rgba(178,34,52,.28)',
				'glow_b'     => 'rgba(201,162,39,.25)',
				'ink'        => '#F5F3EC',
				'ink_soft'   => '#C4D6C8',
				'accent'     => '#C2222E',
				'accent_ink' => '#F5F3EC',
				'bars'       => [ '#C2222E', '#1F5C3F', '#C9A227' ],
				'code_bg'    => '#F5F3EC',
				'code_ink'   => '#0E1F16',
				'icon'       => '<line x1="12" y1="3" x2="12" y2="21"/><line x1="4.5" y1="7.5" x2="19.5" y2="16.5"/><line x1="19.5" y1="7.5" x2="4.5" y2="16.5"/><path d="M12 6l-1.6 1M12 6l1.6 1M12 18l-1.6-1M12 18l1.6-1" stroke-width="1.1"/><path d="M6.7 9l.4-1.8M6.7 9l-1.8-.4M17.3 15l-.4 1.8M17.3 15l1.8.4" stroke-width="1.1"/><path d="M17.3 9l.4-1.8M17.3 9l1.8-.4M6.7 15l-.4 1.8M6.7 15l-1.8-.4" stroke-width="1.1"/>',
			],
		];
	}

	/** @return array{label:string, eyebrow:string, headline:string, body:string, bg:string, glow_a:string, glow_b:string, ink:string, ink_soft:string, accent:string, accent_ink:string, bars:array{0:string,1:string,2:string}, code_bg:string, code_ink:string, icon:string}|null */
	public static function get( string $slug ): ?array {
		$themes = self::all();
		return $themes[ $slug ] ?? null;
	}
}
