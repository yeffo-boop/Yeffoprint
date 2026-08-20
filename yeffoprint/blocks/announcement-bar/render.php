<?php
/**
 * Renders the announcement bar's text, set from the YeffoPrint admin
 * menu (yeffoprint-core/includes/admin/class-admin-menu.php) rather
 * than hardcoded here — this is the only PHP-capable spot inside the
 * otherwise-static parts/announcement-bar.html template part, same
 * reasoning as yeffoprint/gallery-toolbar and yeffoprint/template-card.
 */

defined( 'ABSPATH' ) || exit;

$text = function_exists( 'yeffoprint_core_get_announcement_bar_text' )
	? yeffoprint_core_get_announcement_bar_text()
	: '';

$text = trim( $text );

if ( '' === $text ) {
	// .yp-announcement-bar:empty (global.css) hides the surrounding bar
	// entirely once its only child renders nothing.
	return;
}
?>
<p class="has-text-align-center has-small-font-size"><?php echo esc_html( $text ); ?></p>
