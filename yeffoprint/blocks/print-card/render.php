<?php
/**
 * One 3D print in the /3d-prints/ grid: photo, name, starting price and
 * how many colors the customer gets to pick.
 *
 * @var WP_Block $block Block instance; $block->context['postId'] is set by the Query Loop.
 */

defined( 'ABSPATH' ) || exit;

$post_id = (int) ( $block->context['postId'] ?? 0 );
$print   = $post_id && function_exists( 'yeffoprint_core_get_print_data' ) ? yeffoprint_core_get_print_data( $post_id ) : null;

if ( ! $print ) {
	return;
}

$count = count( $print['slots'] );
?>
<a class="yp-print-card" href="<?php echo esc_url( get_permalink( $post_id ) ); ?>">
	<span class="yp-print-card__media">
		<?php if ( $print['image_url'] ) : ?>
			<img src="<?php echo esc_url( $print['image_url'] ); ?>" alt="" loading="lazy" />
		<?php endif; ?>
	</span>
	<span class="yp-print-card__body">
		<span class="yp-print-card__title"><?php echo esc_html( $print['title'] ); ?></span>
		<span class="yp-print-card__meta">
			<?php
			echo esc_html( sprintf(
				/* translators: %s: price, e.g. "$34.00" */
				__( 'From %s', 'yeffoprint' ),
				'$' . number_format( $print['price'], 2 )
			) );
			?>
			<?php if ( $count ) : ?>
				&middot;
				<?php
				/* translators: %d: number of color choices */
				echo esc_html( sprintf( _n( 'Pick %d color', 'Pick %d colors', $count, 'yeffoprint' ), $count ) );
				?>
			<?php endif; ?>
		</span>
	</span>
</a>
