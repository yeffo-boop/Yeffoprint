<?php
/**
 * The "Our Work" page (templates/web-design-showcase.html): one
 * case-study row per finished web design project. Jeff picked layout B
 * from mockups/website-showcase/: a desktop screenshot with the phone
 * one overlapping, then the package, launch month, name, story, what
 * we did, an optional quote and a link to the live site. Rows alternate
 * sides on desktop and stack on phones.
 *
 * Entries come from YeffoPrint_Web_Design_Showcase::public_entries(),
 * which already leaves out anything the customer didn't agree to.
 */

defined( 'ABSPATH' ) || exit;

$entries   = class_exists( 'YeffoPrint_Web_Design_Showcase' ) ? YeffoPrint_Web_Design_Showcase::public_entries() : [];
$quote_url = home_url( '/web-design-quote/' );

$package_class = static function ( string $package ): string {
	$p = strtolower( $package );
	if ( false !== strpos( $p, 'ultimate' ) ) {
		return 'ultimate';
	}
	if ( false !== strpos( $p, 'pro' ) ) {
		return 'pro';
	}
	if ( false !== strpos( $p, 'starter' ) ) {
		return 'starter';
	}
	return 'basic';
};
?>
<section class="yp-showcase yp-section">
	<header class="yp-showcase__hero">
		<p class="yp-showcase__eyebrow"><?php esc_html_e( 'Web Design · Our Work', 'yeffoprint' ); ?></p>
		<h1><?php esc_html_e( 'Our work', 'yeffoprint' ); ?></h1>
		<p class="yp-showcase__intro"><?php esc_html_e( "A closer look at the stores we've launched: what each customer needed, what we built, and what they said after.", 'yeffoprint' ); ?></p>

		<?php if ( count( $entries ) > 1 ) : ?>
			<nav class="yp-showcase__jump" aria-label="<?php esc_attr_e( 'Jump to a project', 'yeffoprint' ); ?>">
				<?php foreach ( $entries as $entry ) : ?>
					<a class="yp-showcase__chip" href="#work-<?php echo (int) $entry['id']; ?>"><?php echo esc_html( $entry['name'] ); ?></a>
				<?php endforeach; ?>
			</nav>
		<?php endif; ?>
	</header>

	<?php if ( ! $entries ) : ?>
		<div class="yp-showcase__empty">
			<h2><?php esc_html_e( 'Our first projects are launching soon.', 'yeffoprint' ); ?></h2>
			<p><?php esc_html_e( 'Check back shortly, or tell us about your store and be one of them.', 'yeffoprint' ); ?></p>
		</div>
	<?php endif; ?>

	<?php foreach ( $entries as $i => $entry ) : ?>
		<?php
		$launched = $entry['launched'] ? mysql2date( 'M Y', $entry['launched'] ) : '';
		$host     = $entry['url'] ? (string) wp_parse_url( $entry['url'], PHP_URL_HOST ) : '';
		?>
		<article class="yp-showcase__case<?php echo $i % 2 ? ' is-flipped' : ''; ?>" id="work-<?php echo (int) $entry['id']; ?>">
			<div class="yp-showcase__stage<?php echo $entry['phone_id'] ? ' has-phone' : ''; ?>">
				<div class="yp-showcase__browser">
					<div class="yp-showcase__bar" aria-hidden="true"><i></i><i></i><i></i><?php if ( $host ) : ?><span><?php echo esc_html( $host ); ?></span><?php endif; ?></div>
					<?php
					echo wp_get_attachment_image( $entry['desktop_id'], 'large', false, [
						'class'   => 'yp-showcase__desktop',
						/* translators: %s: business name */
						'alt'     => sprintf( __( '%s website on a desktop screen', 'yeffoprint' ), $entry['name'] ),
						'loading' => $i ? 'lazy' : 'eager',
						'sizes'   => '(max-width: 760px) 90vw, 640px',
					] );
					?>
				</div>
				<?php if ( $entry['phone_id'] ) : ?>
					<div class="yp-showcase__phone">
						<?php
						echo wp_get_attachment_image( $entry['phone_id'], 'large', false, [
							/* translators: %s: business name */
							'alt'     => sprintf( __( '%s website on a phone', 'yeffoprint' ), $entry['name'] ),
							'loading' => 'lazy',
							'sizes'   => '180px',
						] );
						?>
					</div>
				<?php endif; ?>
			</div>

			<div class="yp-showcase__text">
				<p class="yp-showcase__meta">
					<?php if ( $entry['package'] ) : ?>
						<span class="yp-showcase__pkg yp-showcase__pkg--<?php echo esc_attr( $package_class( $entry['package'] ) ); ?>"><?php echo esc_html( $entry['package'] ); ?></span>
					<?php endif; ?>
					<?php if ( $launched ) : ?>
						<span>
							<?php
							/* translators: %s: month and year, e.g. "Sep 2026" */
							echo esc_html( sprintf( __( 'Launched %s', 'yeffoprint' ), $launched ) );
							?>
						</span>
					<?php endif; ?>
				</p>
				<h2><?php echo esc_html( $entry['name'] ); ?></h2>
				<?php if ( $entry['blurb'] || $entry['story'] ) : ?>
					<p class="yp-showcase__lede"><?php echo esc_html( trim( $entry['blurb'] . ' ' . $entry['story'] ) ); ?></p>
				<?php endif; ?>

				<?php if ( $entry['did'] ) : ?>
					<ul class="yp-showcase__did">
						<?php foreach ( $entry['did'] as $item ) : ?>
							<li><?php echo esc_html( $item ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<?php if ( $entry['quote'] ) : ?>
					<figure class="yp-showcase__quote">
						<span class="yp-showcase__stars" aria-hidden="true">★★★★★</span>
						<blockquote><p>&ldquo;<?php echo esc_html( $entry['quote'] ); ?>&rdquo;</p></blockquote>
						<?php if ( $entry['quote_by'] ) : ?>
							<figcaption><?php echo esc_html( $entry['quote_by'] . ', ' . $entry['name'] ); ?></figcaption>
						<?php endif; ?>
					</figure>
				<?php endif; ?>

				<div class="yp-showcase__acts">
					<?php if ( $entry['url'] ) : ?>
						<a class="yp-showcase__btn yp-showcase__btn--dark" href="<?php echo esc_url( $entry['url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Visit live site', 'yeffoprint' ); ?> <span aria-hidden="true">↗</span></a>
					<?php endif; ?>
					<a class="yp-showcase__btn" href="<?php echo esc_url( $quote_url ); ?>"><?php esc_html_e( 'Start a project like this', 'yeffoprint' ); ?></a>
				</div>
			</div>
		</article>
	<?php endforeach; ?>

	<div class="yp-showcase__cta">
		<div>
			<h2><?php esc_html_e( 'Ready for yours?', 'yeffoprint' ); ?></h2>
			<p><?php esc_html_e( 'Pick a package, or tell us what you need.', 'yeffoprint' ); ?></p>
		</div>
		<div class="yp-showcase__cta-acts">
			<a class="yp-showcase__btn" href="<?php echo esc_url( home_url( '/web-design/' ) ); ?>"><?php esc_html_e( 'See packages', 'yeffoprint' ); ?></a>
			<a class="yp-showcase__btn yp-showcase__btn--orange" href="<?php echo esc_url( $quote_url ); ?>"><?php esc_html_e( 'Get a Quote', 'yeffoprint' ); ?> <span aria-hidden="true">→</span></a>
		</div>
	</div>
</section>
