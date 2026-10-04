<?php
/**
 * Dose Tracker app shell — see class-tracker-app.php. Everything below
 * <main> is rendered by assets/tracker/tracker.js.
 *
 * @var array $config
 * @var array $assets
 * @var string $script_nonce
 */

defined( 'ABSPATH' ) || exit;
?><!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<meta name="robots" content="noindex">
	<meta name="theme-color" content="#FAF9F6">
	<meta name="apple-mobile-web-app-capable" content="yes">
	<meta name="mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-status-bar-style" content="default">
	<meta name="apple-mobile-web-app-title" content="YeffoHealth">
	<title><?php esc_html_e( 'YeffoHealth · Dose & Health Tracker', 'yeffoprint-core' ); ?></title>
	<?php if ( ! empty( $config['share'] ) ) : ?>
		<?php // Link previews in texts stay generic: the protocol itself only shows once the page is open. ?>
		<meta property="og:title" content="<?php esc_attr_e( 'Someone shared a dose schedule with you', 'yeffoprint-core' ); ?>">
		<meta property="og:description" content="<?php esc_attr_e( 'Open it to add it to your free YeffoHealth tracker.', 'yeffoprint-core' ); ?>">
		<meta property="og:image" content="<?php echo esc_url( YEFFOPRINT_CORE_URL . 'assets/tracker/icons/icon-512.png' ); ?>">
	<?php endif; ?>
	<link rel="manifest" href="<?php echo esc_url( home_url( '/' . YeffoPrint_Tracker_App::SLUG . '/manifest.webmanifest' ) ); ?>">
	<link rel="apple-touch-icon" href="<?php echo esc_url( YEFFOPRINT_CORE_URL . 'assets/tracker/icons/apple-touch-icon.png' ); ?>">
	<link rel="icon" type="image/png" sizes="192x192" href="<?php echo esc_url( YEFFOPRINT_CORE_URL . 'assets/tracker/icons/icon-192.png' ); ?>">
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Geist:wght@600;700&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap">
	<link rel="stylesheet" href="<?php echo esc_url( $assets['css'] ); ?>">
</head>
<body>
	<main id="yp-tracker" class="ypt" aria-live="polite">
		<div class="ypt-boot"><?php esc_html_e( 'Loading your tracker…', 'yeffoprint-core' ); ?></div>
	</main>
	<noscript><p style="padding:24px"><?php esc_html_e( 'YeffoHealth needs JavaScript turned on.', 'yeffoprint-core' ); ?></p></noscript>
	<script nonce="<?php echo esc_attr( $script_nonce ); ?>">window.YP_TRACKER = <?php echo wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES ); ?>;</script>
	<script src="<?php echo esc_url( $assets['js'] ); ?>" defer></script>
</body>
</html>
