<?php
/**
 * Home Screen install for the Peptide Calculator page — direct request:
 * "Can we get the calculator mobile friendly that can be added to
 * android/iPhone Home Screen?"
 *
 * The calculator itself stays the theme's page (templates/peptide-
 * calculator.html, assets/js/peptide-calculator.js). This adds what a
 * phone needs to install that page as its own app, separate from the
 * Dose Tracker's install (class-tracker-app.php):
 *
 *   /peptide-calculator/manifest.webmanifest   its own name, icon and start_url
 *   /peptide-calculator/sw.js                  service worker scoped to /peptide-calculator/,
 *                                              so the calculator opens with no signal
 *
 * plus the manifest link, Apple Home Screen tags and icon in the page's
 * <head>. The theme's calculator script registers the service worker and
 * shows the "Add to Home Screen" card when it finds that manifest link.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Calculator_App {

	public const SLUG = 'peptide-calculator';

	private const QUERY_VAR = 'yeffoprint_calculator';

	public function __construct() {
		add_action( 'init', [ $this, 'register_rewrite' ] );
		add_filter( 'query_vars', [ $this, 'register_query_var' ] );
		add_action( 'template_redirect', [ $this, 'maybe_serve' ], 0 );
		add_action( 'wp_head', [ $this, 'head_tags' ], 2 );
		add_filter( 'site_icon_meta_tags', [ $this, 'drop_site_touch_icon' ] );
	}

	public static function url(): string {
		return home_url( '/' . self::SLUG . '/' );
	}

	/** "/peptide-calculator/" (or "/sub/peptide-calculator/" when WordPress lives in a subfolder). */
	private static function path(): string {
		return (string) wp_parse_url( self::url(), PHP_URL_PATH );
	}

	private static function icons_url(): string {
		return YEFFOPRINT_CORE_URL . 'assets/calculator/icons/';
	}

	public function register_rewrite(): void {
		add_rewrite_rule( '^' . self::SLUG . '/sw\.js$', 'index.php?' . self::QUERY_VAR . '=sw', 'top' );
		add_rewrite_rule( '^' . self::SLUG . '/manifest\.webmanifest$', 'index.php?' . self::QUERY_VAR . '=manifest', 'top' );
	}

	public function register_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/** Same check the theme uses to load the calculator's CSS/JS (functions.php). */
	private static function is_calculator_page(): bool {
		return is_page() && in_array( get_page_template_slug(), [ 'peptide-calculator', 'peptide-calculator.html' ], true );
	}

	public function maybe_serve(): void {
		$what = get_query_var( self::QUERY_VAR );
		if ( 'sw' === $what ) {
			$this->serve_service_worker();
			exit;
		}
		if ( 'manifest' === $what ) {
			$this->serve_manifest();
			exit;
		}
	}

	private function serve_service_worker(): void {
		status_header( 200 );
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Cache-Control: no-cache' );
		header( 'Service-Worker-Allowed: ' . self::path() );

		$config = [
			'version' => yeffoprint_core_asset_version( 'assets/calculator/sw.js' ),
			'appUrl'  => self::url(),
		];
		echo 'self.YP_PCALC_SW = ' . wp_json_encode( $config ) . ";\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON.
		readfile( YEFFOPRINT_CORE_PATH . 'assets/calculator/sw.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
	}

	private function serve_manifest(): void {
		status_header( 200 );
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );

		$icons = self::icons_url();
		echo wp_json_encode( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON.
			'id'               => self::path(),
			'name'             => 'YeffoHealth Calculator',
			'short_name'       => 'YH Calculator',
			'description'      => 'Work out how many units to draw for peptides, HGH/HCG, hormones and blends.',
			'start_url'        => self::path(),
			'scope'            => self::path(),
			'display'          => 'standalone',
			'orientation'      => 'portrait',
			'background_color' => '#FAF9F6',
			'theme_color'      => '#FAF9F6',
			'icons'            => [
				[ 'src' => $icons . 'icon-192.png', 'sizes' => '192x192', 'type' => 'image/png' ],
				[ 'src' => $icons . 'icon-512.png', 'sizes' => '512x512', 'type' => 'image/png' ],
				[ 'src' => $icons . 'icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable' ],
			],
		], JSON_UNESCAPED_SLASHES );
	}

	public function head_tags(): void {
		if ( ! self::is_calculator_page() ) {
			return;
		}
		$icons = self::icons_url();
		?>
<link rel="manifest" href="<?php echo esc_url( home_url( '/' . self::SLUG . '/manifest.webmanifest' ) ); ?>" data-yp-pcalc-sw="<?php echo esc_url( home_url( '/' . self::SLUG . '/sw.js' ) ); ?>">
<meta name="theme-color" content="#FAF9F6">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="YH Calculator">
<link rel="apple-touch-icon" href="<?php echo esc_url( $icons . 'apple-touch-icon.png' ); ?>">
		<?php
	}

	/**
	 * The site icon's own apple-touch-icon would otherwise win on iPhone,
	 * so a Home Screen calculator would show the store logo instead of
	 * the calculator's icon. The browser-tab favicon stays the site's.
	 */
	public function drop_site_touch_icon( array $tags ): array {
		if ( ! self::is_calculator_page() ) {
			return $tags;
		}
		return array_values( array_filter( $tags, static function ( $tag ) {
			return false === strpos( (string) $tag, 'apple-touch-icon' );
		} ) );
	}
}
