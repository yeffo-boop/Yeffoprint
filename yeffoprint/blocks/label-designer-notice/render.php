<?php
/**
 * Custom Design's cross-link notice pointing at the Label Designer —
 * gated the same as the Label Designer itself (YeffoPrint_Feature_Gate)
 * so a customer is never pointed at a page that just shows them
 * "Coming Soon". Renders nothing at all for a non-admin viewer, rather
 * than an empty box.
 */

defined( 'ABSPATH' ) || exit;

if ( ! YeffoPrint_Feature_Gate::is_admin_viewer() ) {
	return;
}
?>
<div class="yp-form-redirect-notice" role="note">
	<div class="yp-form-redirect-notice__icon" aria-hidden="true">i</div>
	<div class="yp-form-redirect-notice__body">
		<p><strong>Designing a general product label yourself?</strong> If you'd rather build your own label — any size, with text, shapes, icons, and colors — instead of having a designer create it for you, try our Label Designer. It's built for cosmetics, skincare, and other everyday products, with a live preview as you design.</p>
		<a class="yp-form-redirect-notice__cta" href="/design-your-label/">Try the Label Designer &rarr;</a>
	</div>
</div>
