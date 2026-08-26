<?php
/**
 * Title: Featured Designs
 * Slug: yeffoprint/featured-designs
 * Categories: yeffoprint
 *
 * Direct bug report: "The featured indicator on the vial templates is
 * not what the homepage uses to decide what to put there. I checked
 * the box that said featured on the [a] template and it doesn't show
 * on the homepage." Confirmed by elimination with the owner: the
 * section WAS showing templates, but not ones actually marked
 * Featured (YeffoPrint_Template_Meta::FEATURED, '_yp_featured').
 *
 * First attempt (reverted): swapped the Query Loop block's own
 * `metaKey`/`metaValue` JSON attributes for a made-up `include`
 * attribute, still relying on WordPress core's Query Loop block to
 * translate a JSON query attribute into a WP_Query arg. That was the
 * same class of bug as the original: core's `core/query` block only
 * recognizes a fixed, documented set of `query` keys (postType,
 * perPage, offset, order, orderBy, author, search, exclude, sticky,
 * inherit, taxQuery, parents, format) — `metaKey`/`metaValue` and
 * `include` were never among them, so both were always silently
 * ignored and the block fell back to "N most recent posts of this
 * type," featured or not, no matter which unsupported attribute this
 * pattern tried next.
 *
 * Fixed for real by not routing the featured-post selection through
 * the Query Loop block's attribute system at all: resolve the actual
 * featured post IDs with a plain get_posts() meta query (the same
 * reliable "read live WordPress data directly" convention
 * materials.php/material-guide.php/web-design-packages.php already
 * use), then render each card by hand — instantiating the
 * yeffoprint/template-card block directly via WP_Block with an
 * explicit `postId` context, the same context the Query Loop's own
 * Post Template block would have set, just supplied ourselves. The
 * markup this produces (`div.wp-block-query` > `ul.wp-block-post-
 * template.yp-card-grid` > `li` per card) intentionally matches what
 * core would have rendered, so patterns.css's existing `.yp-card-grid`
 * rules apply unchanged. Deliberately no `<!-- wp:query -->` block
 * comment around the output: this whole pattern file's return value
 * still gets passed through do_blocks() once inserted into the page,
 * and `core/query` is a dynamic block — if its block comment were
 * left in place, do_blocks() would call its own render callback and
 * rebuild the query from scratch from the same unsupported
 * attributes, discarding this hand-rendered content entirely.
 */

defined( 'ABSPATH' ) || exit;

$featured_ids = get_posts( [
	'post_type'      => 'yp_template',
	'post_status'    => 'publish',
	'posts_per_page' => 4,
	'orderby'        => 'date',
	'order'          => 'DESC',
	'meta_key'       => YeffoPrint_Template_Meta::FEATURED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- small, admin-managed table.
	'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- small, admin-managed table.
	'fields'         => 'ids',
] );
?>
<?php if ( $featured_ids ) : ?>
<!-- wp:group {"tagName":"section","className":"yp-section yp-section--tint","layout":{"type":"constrained","contentSize":"1200px"}} -->
<section class="wp-block-group yp-section yp-section--tint">

	<!-- wp:group {"layout":{"type":"flex","justifyContent":"space-between"}} -->
	<div class="wp-block-group">
		<!-- wp:group {"layout":{"type":"constrained"}} -->
		<div class="wp-block-group">
			<!-- wp:paragraph {"className":"yp-eyebrow"} -->
			<p class="yp-eyebrow">Featured</p>
			<!-- /wp:paragraph -->
			<!-- wp:heading {"level":2} -->
			<h2 class="wp-block-heading">Featured Designs</h2>
			<!-- /wp:heading -->
		</div>
		<!-- /wp:group -->

		<!-- wp:paragraph -->
		<p><a class="yp-view-all-link" href="/shop-labels/">View all designs &rarr;</a></p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->

	<div class="wp-block-query">
		<ul class="wp-block-post-template yp-card-grid">
			<?php foreach ( $featured_ids as $featured_id ) : ?>
				<li>
					<?php
					$card_block = new WP_Block(
						[
							'blockName'    => 'yeffoprint/template-card',
							'attrs'        => [],
							'innerBlocks'  => [],
							'innerHTML'    => '',
							'innerContent' => [],
						],
						[
							'postType' => 'yp_template',
							'postId'   => $featured_id,
						]
					);
					echo $card_block->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- yeffoprint/template-card's own render.php already escapes everything it outputs.
					?>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>

</section>
<!-- /wp:group -->
<?php endif; ?>
