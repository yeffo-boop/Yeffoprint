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
?>
<a class="yp-card yp-template-card" href="<?php echo esc_url( $card['permalink'] ); ?>">
	<div class="yp-card__media yp-template-card__media">
		<?php if ( $card['artwork_url'] ) : ?>
			<img
				class="yp-template-card__image yp-template-card__image--primary"
				src="<?php echo esc_url( $card['artwork_url'] ); ?>"
				alt=""
				loading="lazy"
				decoding="async"
			/>
		<?php endif; ?>
		<?php if ( $card['vial_mockup_url'] ) : ?>
			<img
				class="yp-template-card__image yp-template-card__image--hover"
				src="<?php echo esc_url( $card['vial_mockup_url'] ); ?>"
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
		<span class="yp-template-card__price"><?php echo esc_html( $card['starting_price'] ); ?></span>
	</div>
</a>
