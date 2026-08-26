<?php
/**
 * Keyword-matched FAQ answers for the Telegram bot. Copy deliberately
 * mirrors yeffoprint/patterns/faq.php's own FAQ entries rather than
 * reading them from the theme — PROJECT_SPEC §3: business-critical
 * functionality (this plugin) must never depend on the active theme.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Telegram_Faq {

	/** @return array{keywords:string[],answer:string}[] */
	private static function entries(): array {
		return [
			[
				'keywords' => [ 'size', 'sizes', 'material', 'materials', 'finish', 'finishes', 'ml', 'glossy', 'matte', 'holographic', 'metallic' ],
				'answer'   => __( 'We launch with 3 mL and 10 mL sizes across five finishes: Glossy White, Matte White, Holographic, Clear, and Metallic. Availability is shown per design in the configurator.', 'yeffoprint-core' ),
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

	/** Best keyword-overlap match, or null if nothing scored. */
	public static function match( string $text ): ?string {
		$haystack    = ' ' . strtolower( $text ) . ' ';
		$best_score  = 0;
		$best_answer = null;

		foreach ( self::entries() as $entry ) {
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

	public static function topics_text(): string {
		return __( "Ask me about:\n• Sizes & materials\n• Multi-design batches\n• The \$25 custom design fee\n• Shipping\n• Guest checkout & accounts\n\nOr send your order number and checkout email to check an order's status.", 'yeffoprint-core' );
	}
}
