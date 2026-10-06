<?php
/**
 * Keyword-matched FAQ answers for the Telegram bot. Most copy
 * deliberately mirrors yeffoprint/patterns/faq.php's own FAQ entries
 * rather than reading them from the theme — PROJECT_SPEC §3:
 * business-critical functionality (this plugin) must never depend on
 * the active theme. Bulk pricing is the one exception, and
 * deliberately not mirrored copy: pricing_answer() below reads
 * YeffoPrint_Pricing_Rule's live base price/tiers directly (the same
 * authoritative source the storefront and checkout price off of,
 * includes/pricing/class-pricing-rule.php), since those numbers are
 * admin-edited and would silently drift out of sync with a hardcoded
 * answer the next time someone changed a tier.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Telegram_Faq {

	/**
	 * `requires` (optional): the entry only counts when at least one of
	 * these also appears, so a booster keyword like "iphone" alone
	 * ("my proof won't load on my iphone") doesn't pull up this answer
	 * instead of escalating to a person.
	 *
	 * @return array{keywords:string[],requires?:string[],answer:string}[]
	 */
	private static function entries(): array {
		return [
			[
				// Direct request: a customer asked the bot how to add the
				// calculator/tracker to their Home Screen and it couldn't
				// answer. Listed before the order-tracking entry so
				// "tracker" questions don't tie with its "track" keyword
				// and lose (ties go to the earlier entry).
				'keywords' => [ 'home screen', 'homescreen', 'home-screen', 'add to home', 'install', 'shortcut', 'an app', 'the app', 'app store', 'play store', 'download', 'calculator', 'tracker', 'yeffohealth', 'yeffo health', 'iphone', 'ipad', ' ios', 'android', 'safari', 'chrome', 'phone' ],
				'requires' => [ 'home screen', 'homescreen', 'home-screen', 'add to home', 'install', 'shortcut', 'an app', 'the app', 'app store', 'play store', 'download', 'calculator', 'tracker', 'yeffohealth', 'yeffo health' ],
				'answer'   => self::home_screen_answer(),
			],
			[
				'keywords' => [ 'size', 'sizes', 'material', 'materials', 'finish', 'finishes', 'ml', 'glossy', 'matte', 'holographic', 'metallic' ],
				'answer'   => __( 'We launch with 3 mL and 10 mL sizes across five finishes: Glossy White, Matte White, Holographic, Clear, and Metallic. Availability is shown per design in the configurator.', 'yeffoprint-core' ),
			],
			[
				'keywords' => [ 'bulk', 'discount', 'discounts', 'pricing', 'price', 'prices', 'priced', 'tier', 'tiers', 'how much', 'cost', 'costs', 'wholesale', 'quantity discount' ],
				'answer'   => self::pricing_answer(),
			],
			[
				'keywords' => [ 'batch', 'variant', 'variants', 'multiple designs', 'different labels', 'split order', 'mix and match' ],
				'answer'   => __( 'Yes — split a single order quantity across multiple personalized variants of the same design, size, and material. Your combined quantity still counts toward bulk pricing.', 'yeffoprint-core' ),
			],
			[
				'keywords' => [ 'custom design', 'design fee', '$25', '25 fee', 'custom label', 'proof', 'fully custom' ],
				'answer'   => __( "The \$25 custom design fee is a one-time charge for a fully custom label built from your brand, colors, and instructions, shown separately from your per-label price. You'll review and approve a proof before anything prints.", 'yeffoprint-core' ),
			],
			[
				'keywords' => [ 'shipping', 'ship', 'delivery', 'usps', 'ups', 'international' ],
				'answer'   => __( 'USPS Ground Advantage ($6) or UPS 2nd Day Air ($15) within the US; international shipping is $25.', 'yeffoprint-core' ),
			],
			[
				'keywords' => [ 'account', 'guest', 'sign up', 'login', 'log in', 'create account', 'register' ],
				'answer'   => __( "No account needed — checkout as a guest any time. An account just makes it easier to reorder and track past designs.", 'yeffoprint-core' ),
			],
			[
				'keywords' => [ 'track', 'tracking', 'status', 'where is my order', 'order status' ],
				'answer'   => __( "Send me your order number and the email you used at checkout — for example:\nYP-1042 jane@example.com\n— and I'll pull up its status.", 'yeffoprint-core' ),
			],
		];
	}

	/** Picture version: /home-screen/ (class-home-screen-help.php). Steps match the install cards on the pages themselves (assets/tracker/tracker.js, yeffoprint/assets/js/peptide-calculator.js). */
	private static function home_screen_answer(): string {
		return sprintf(
			/* translators: 1: Peptide Calculator URL, 2: Dose Tracker URL, 3: picture guide URL */
			__( "You can put the YeffoHealth Peptide Calculator and Dose Tracker on your Home Screen so they open like an app. They're free, and there's nothing to download from an app store.\n\nPeptide Calculator: %1\$s\nDose Tracker: %2\$s\n\nStep-by-step with pictures: %3\$s\n\n📱 iPhone or iPad (Safari)\n1. Open the link above in Safari.\n2. Tap the menu button with three lines (☰) just left of the web address, then tap Share. On older iPhones, the Share button (a square with an arrow pointing up) is right in the bottom bar.\n3. Scroll down and tap \"Add to Home Screen\".\n4. If you see \"Open as Web App\", leave it on, then tap Add.\n\n🤖 Android (Chrome)\n1. Open the link above in Chrome.\n2. If the page shows an \"Install app\" button, tap it and you're done.\n3. Otherwise tap ⋮ at the top right, then \"Add to Home screen\" (or \"Install app\"), then Install.\n\nThe calculator and the tracker are two separate icons, so add each one you want. For the Dose Tracker, open it from the new icon and sign in once; it keeps you signed in. On iPhone, dose reminders only work when the tracker is opened from the Home Screen (iOS 16.4 or newer).", 'yeffoprint-core' ),
			home_url( '/peptide-calculator/' ),
			home_url( '/tracker/' ),
			YeffoPrint_Home_Screen_Help::url()
		);
	}

	/**
	 * Built from YeffoPrint_Pricing_Rule's live base price + bulk
	 * discount tiers — see this class's own docblock for why this one
	 * entry isn't static copy. Mirrors that class's own `calculate()`
	 * logic for what a tier actually resolves to (a `percent` tier
	 * discounts the base rate; a `fixed_unit_price` tier sets it
	 * directly) rather than re-deriving it a different way.
	 */
	private static function pricing_answer(): string {
		$base  = YeffoPrint_Pricing_Rule::get_base_unit_price();
		$tiers = YeffoPrint_Pricing_Rule::get_tiers();

		if ( ! $tiers ) {
			return sprintf(
				/* translators: %s: base per-label price */
				__( 'Our base price is %s per label, with no minimum order. Material and size choices can add to that — ask me about those if you want specifics.', 'yeffoprint-core' ),
				'$' . number_format_i18n( $base, 2 )
			);
		}

		$lines = [];
		foreach ( $tiers as $tier ) {
			$resulting_base = 'percent' === $tier['type']
				? $base * ( 1 - $tier['value'] / 100 )
				: (float) $tier['value'];

			$lines[] = sprintf(
				/* translators: 1: minimum label quantity for this tier, 2: resulting per-label base price */
				__( '• %1$s+ labels: %2$s each', 'yeffoprint-core' ),
				number_format_i18n( $tier['threshold'] ),
				'$' . number_format_i18n( max( 0, $resulting_base ), 2 )
			);
		}

		return sprintf(
			/* translators: 1: base per-label price, 2: bulk discount tier lines, one per line */
			__( "Our base price is %1\$s per label, and it drops automatically as your quantity goes up:\n%2\$s\n\nYou can mix multiple designs, sizes, or materials in one order and your combined quantity still counts toward these tiers — material/size charges are added on top of the discounted base price, not discounted themselves.", 'yeffoprint-core' ),
			'$' . number_format_i18n( $base, 2 ),
			implode( "\n", $lines )
		);
	}

	/** Best keyword-overlap match, or null if nothing scored. */
	public static function match( string $text ): ?string {
		$haystack    = ' ' . strtolower( $text ) . ' ';
		$best_score  = 0;
		$best_answer = null;

		foreach ( self::entries() as $entry ) {
			if ( isset( $entry['requires'] ) && ! self::contains_any( $haystack, $entry['requires'] ) ) {
				continue;
			}

			$score = 0;
			foreach ( $entry['keywords'] as $keyword ) {
				if ( false !== strpos( $haystack, strtolower( $keyword ) ) ) {
					++$score;
				}
			}

			if ( $score > $best_score ) {
				$best_score  = $score;
				$best_answer = $entry['answer'];
			}
		}

		return $best_answer;
	}

	/** @param string[] $keywords */
	private static function contains_any( string $haystack, array $keywords ): bool {
		foreach ( $keywords as $keyword ) {
			if ( false !== strpos( $haystack, strtolower( $keyword ) ) ) {
				return true;
			}
		}
		return false;
	}

	public static function topics_text(): string {
		return __( "Ask me about:\n• Sizes & materials\n• Bulk pricing & discounts\n• Multi-design batches\n• The \$25 custom design fee\n• Shipping\n• Guest checkout & accounts\n• Adding the Peptide Calculator or Dose Tracker to your Home Screen\n\nOr send your order number and checkout email to check an order's status.", 'yeffoprint-core' );
	}
}
