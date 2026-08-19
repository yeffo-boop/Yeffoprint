<?php
/**
 * Renders one Shop Labels gallery card inside the Query Loop.
 *
 * @var array    $attributes Block attributes (none used).
 * @var string   $content    Inner block content (unused — this block has no children).
 * @var WP_Block $block      Block instance; $block->context['postId'] is set by the
 *                           enclosing Query Loop / Post Template block.
 */

defined( 'ABSPATH' ) || exit;

$post_id = $block->context['postId'] ?? 0;

if ( ! $post_id || ! function_exists( 'yeffoprint_core_get_template_card_data' ) ) {
	return;
}

$card = yeffoprint_core_get_template_card_data( (int) $post_id );

if ( ! $card ) {
	return;
}

/**
 * Vial mockup leads (direct request: show the vial preview, not just
 * the flat label, on the gallery cards), flat artwork is the
 * hover-swap — the reverse of Phase 3's original pairing. Falls back
 * to artwork-only when a Template has no vial mockup uploaded yet
 * (still admin-optional per template-api.php), rather than showing an
 * empty media box.
 */
$primary_image_url = $card['vial_mockup_url'] ?: $card['artwork_url'];
$hover_image_url   = $card['vial_mockup_url'] ? $card['artwork_url'] : null;
?>
<a class="yp-card yp-template-card" href="<?php echo esc_url( $card['permalink'] ); ?>">
	<div class="yp-card__media yp-template-card__media">
		<?php if ( $primary_image_url ) : ?>
			<img
				class="yp-template-card__image yp-template-card__image--primary"
				src="<?php echo esc_url( $primary_image_url ); ?>"
				alt=""
				loading="lazy"
				decoding="async"
			/>
		<?php endif; ?>
		<?php if ( $hover_image_url ) : ?>
			<img
				class="yp-template-card__image yp-template-card__image--hover"
				src="<?php echo esc_url( $hover_image_url ); ?>"
				alt=""
				loading="lazy"
				decoding="async"
			/>
		<?php endif; ?>
		<?php if ( $card['badge_label'] ) : ?>
			<span class="yp-card__badge yp-template-card__badge"><?php echo esc_html( $card['badge_label'] ); ?></span>
		<?php endif; ?>
	</div>
	<div class="yp-card__body yp-template-card__body">
		<span class="yp-template-card__title"><?php echo esc_html( $card['title'] ); ?></span>
		<?php if ( $card['material_label'] || $card['size_label'] ) : ?>
			<div class="yp-template-card__specs">
				<?php if ( $card['size_label'] ) : ?>
					<span class="yp-spec-chip"><?php echo esc_html( $card['size_label'] ); ?></span>
				<?php endif; ?>
				<?php if ( $card['material_label'] ) : ?>
					<span class="yp-spec-chip"><?php echo esc_html( $card['material_label'] ); ?></span>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<div class="yp-template-card__foot">
			<span class="yp-template-card__price"><?php echo esc_html( $card['starting_price'] ); ?></span>
			<span class="yp-template-card__cta">Customize</span>
		</div>
	</div>
</a>
