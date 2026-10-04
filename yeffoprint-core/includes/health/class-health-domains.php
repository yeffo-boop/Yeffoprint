<?php
/**
 * YeffoHealth web addresses — direct request: "Is there a way if I point
 * a subdomain or another domain to my server running Wordpress that you
 * automatically direct it to a yeffohealth page? Like
 * health.yeffodesign.com could get there?"
 *
 * Any request that reaches WordPress on a YeffoHealth hostname is sent
 * with a 301 to the main site, so sign-in, cookies and the Home Screen
 * apps all stay on one domain:
 *
 *   health.yeffodesign.com/                    → yeffodesign.com/health/
 *   health.yeffodesign.com/tracker/...         → yeffodesign.com/tracker/...  (path + query kept)
 *   health.yeffodesign.com/peptide-calculator/ → yeffodesign.com/peptide-calculator/
 *   health.yeffodesign.com/anything-else       → yeffodesign.com/health/
 *
 * The hostnames are health.<main domain>, yeffohealth.com and
 * www.yeffohealth.com out of the box, so a new domain only needs DNS,
 * the web server and an SSL certificate. More can be added in the
 * yeffoprint_health_hosts option (array) or the filter of the same name.
 *
 * Also creates the /health/ landing page (theme template "yeffohealth")
 * once, the same way class-legal-pages.php creates the policy pages.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Health_Domains {

	public const SLUG     = 'health';
	public const TEMPLATE = 'yeffohealth';

	public const HOSTS_OPTION = 'yeffoprint_health_hosts';

	/** Paths that keep their own address on the main site instead of landing on /health/. */
	private const PASSTHROUGH = [ 'tracker', 'peptide-calculator' ];

	private const SETUP_VERSION = 1;
	private const SETUP_OPTION  = 'yeffoprint_health_page_version';
	private const LOCK_OPTION   = 'yeffoprint_health_page_lock';

	public function __construct() {
		// As early as a plugin can: nothing else on the site needs to run
		// for a request that's only going to be redirected.
		add_action( 'plugins_loaded', [ $this, 'maybe_redirect' ], 0 );
		add_action( 'init', [ __CLASS__, 'ensure_page' ], 30 );
	}

	public static function url(): string {
		return home_url( '/' . self::SLUG . '/' );
	}

	/** @return string[] lowercase hostnames, no port. */
	public static function hosts(): array {
		$main  = self::main_host();
		$hosts = [ 'yeffohealth.com', 'www.yeffohealth.com' ];
		if ( '' !== $main ) {
			$hosts[] = 'health.' . preg_replace( '/^www\./', '', $main );
		}

		$extra = get_option( self::HOSTS_OPTION, [] );
		if ( is_array( $extra ) ) {
			$hosts = array_merge( $hosts, $extra );
		}

		$hosts = (array) apply_filters( 'yeffoprint_health_hosts', $hosts );
		$hosts = array_map( [ __CLASS__, 'normalize_host' ], $hosts );

		// Never redirect the main site to itself.
		return array_values( array_unique( array_filter( $hosts, static function ( $h ) use ( $main ) {
			return '' !== $h && $h !== $main;
		} ) ) );
	}

	private static function main_host(): string {
		return self::normalize_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	private static function normalize_host( $host ): string {
		$host = strtolower( trim( (string) $host ) );
		$host = preg_replace( '/:\d+$/', '', $host ); // Port.
		return rtrim( $host, '.' );
	}

	public function maybe_redirect(): void {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() || empty( $_SERVER['HTTP_HOST'] ) ) {
			return;
		}

		$host = self::normalize_host( wp_unslash( $_SERVER['HTTP_HOST'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared against a fixed list only.
		if ( ! in_array( $host, self::hosts(), true ) ) {
			return;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only a known prefix is passed on.
		$target = self::target_for( $uri );

		nocache_headers();
		header( 'Location: ' . esc_url_raw( $target ), true, 301 );
		exit;
	}

	/** Where a request path on a YeffoHealth hostname belongs on the main site. */
	public static function target_for( string $uri ): string {
		$path  = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$query = (string) wp_parse_url( $uri, PHP_URL_QUERY );
		$first = strtok( trim( $path, '/' ), '/' );

		if ( false !== $first && in_array( strtolower( $first ), self::PASSTHROUGH, true ) ) {
			return home_url( '/' . ltrim( $path, '/' ) ) . ( '' !== $query ? '?' . $query : '' );
		}

		return self::url();
	}

	/** Creates the /health/ page on the yeffohealth template once per SETUP_VERSION. */
	public static function ensure_page(): void {
		if ( (int) get_option( self::SETUP_OPTION, 0 ) >= self::SETUP_VERSION ) {
			return;
		}

		if ( ! add_option( self::LOCK_OPTION, time(), '', false ) ) {
			if ( time() - (int) get_option( self::LOCK_OPTION ) < MINUTE_IN_SECONDS ) {
				return;
			}
			update_option( self::LOCK_OPTION, time(), false );
		}

		$existing = get_page_by_path( self::SLUG, OBJECT, 'page' );
		if ( $existing && 'trash' !== $existing->post_status ) {
			// A page Jeff already made at /health/ keeps its title and text.
			update_post_meta( $existing->ID, '_wp_page_template', self::TEMPLATE );
			$ok = true;
		} else {
			$page_id = wp_insert_post( [
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'YeffoHealth',
				'post_name'    => self::SLUG,
				'post_content' => '',
				'meta_input'   => [ '_wp_page_template' => self::TEMPLATE ],
			], true );
			$ok = ! is_wp_error( $page_id );
		}

		if ( $ok ) {
			update_option( self::SETUP_OPTION, self::SETUP_VERSION, false );
		}
		delete_option( self::LOCK_OPTION );
	}
}
