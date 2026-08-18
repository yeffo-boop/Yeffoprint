<?php
/**
 * Title: FAQ
 * Slug: yeffoprint/faq
 * Categories: yeffoprint
 */

defined( 'ABSPATH' ) || exit;

$faqs = [
	[
		'q' => __( 'What sizes and materials are available?', 'yeffoprint' ),
		'a' => __( 'We launch with 3 mL and 10 mL sizes across five finishes: Glossy White, Matte White, Holographic, Clear, and Metallic. Availability is shown per design in the configurator.', 'yeffoprint' ),
	],
	[
		'q' => __( 'Can I order different labels in one batch?', 'yeffoprint' ),
		'a' => __( 'Yes — split a single order quantity across multiple personalized variants of the same design, size, and material. Your combined quantity still counts toward bulk pricing.', 'yeffoprint' ),
	],
	[
		'q' => __( 'How does the $25 custom design fee work?', 'yeffoprint' ),
		'a' => __( "It's a one-time fee for a fully custom label built from your brand, colors, and instructions, shown separately from your per-label price. You'll review and approve a proof before anything prints.", 'yeffoprint' ),
	],
	[
		'q' => __( 'What are the shipping options?', 'yeffoprint' ),
		'a' => __( 'USPS Ground Advantage ($6) or UPS 2nd Day Air ($15) within the US; international shipping is $25.', 'yeffoprint' ),
	],
	[
		'q' => __( 'Do I need an account to order?', 'yeffoprint' ),
		'a' => __( "No — checkout as a guest any time. An account just makes it easier to reorder and track past designs.", 'yeffoprint' ),
	],
];
?>
<!-- wp:group {"tagName":"section","className":"yp-section yp-faq","layout":{"type":"constrained","contentSize":"760px"}} -->
<section class="wp-block-group yp-section yp-faq">

	<!-- wp:heading {"textAlign":"center","level":2} -->
	<h2 class="wp-block-heading has-text-align-center">Frequently Asked Questions</h2>
	<!-- /wp:heading -->

	<?php foreach ( $faqs as $faq ) : ?>
	<!-- wp:details -->
	<details class="wp-block-details"><summary><?php echo esc_html( $faq['q'] ); ?></summary>
		<!-- wp:paragraph -->
		<p><?php echo esc_html( $faq['a'] ); ?></p>
		<!-- /wp:paragraph -->
	</details>
	<!-- /wp:details -->
	<?php endforeach; ?>

</section>
<!-- /wp:group -->
