<?php
/**
 * /home-screen/ — a picture guide for adding the YeffoHealth Peptide
 * Calculator and Dose Tracker to a phone's Home Screen. Direct request:
 * a customer asked the chat bot how to do this and it couldn't answer;
 * Jeff: "Including screenshots would be great". The bot's answer
 * (class-telegram-faq.php) links here, since neither the web chat nor
 * Telegram replies can carry pictures.
 *
 * Served straight from the plugin as one self-contained page (no theme
 * dependency, same rewrite approach as class-tracker-app.php). The
 * Safari/Chrome steps are drawn in HTML so they stay readable at any
 * size; the "Add it to your Home Screen" cards are real screenshots of
 * the calculator's own install card.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Home_Screen_Help {

	public const SLUG = 'home-screen';

	private const QUERY_VAR = 'yeffoprint_home_screen';

	public function __construct() {
		add_action( 'init', [ $this, 'register_rewrite' ] );
		add_filter( 'query_vars', [ $this, 'register_query_var' ] );
		add_action( 'template_redirect', [ $this, 'maybe_serve' ], 0 );
	}

	public static function url(): string {
		return home_url( '/' . self::SLUG . '/' );
	}

	public function register_rewrite(): void {
		add_rewrite_rule( '^' . self::SLUG . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	public function register_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public function maybe_serve(): void {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );

		$calculator_url = home_url( '/peptide-calculator/' );
		$tracker_url    = home_url( '/tracker/' );
		$images         = YEFFOPRINT_CORE_URL . 'assets/home-screen/';
		$calculator_icon = YEFFOPRINT_CORE_URL . 'assets/calculator/icons/apple-touch-icon.png';
		$tracker_icon    = YEFFOPRINT_CORE_URL . 'assets/tracker/icons/icon-192.png?v=2';

		require YEFFOPRINT_CORE_PATH . 'includes/health/home-screen-page.php';
		exit;
	}
}
