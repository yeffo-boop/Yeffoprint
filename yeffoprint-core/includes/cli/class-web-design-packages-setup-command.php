<?php
/**
 * Dev-only setup: creates the three Web Design Package records
 * (patterns/web-design-packages.php now reads yp_web_design_package
 * posts live instead of a hardcoded array) with the exact placeholder
 * copy that array used to hold, so the page isn't blank the moment
 * this ships — the owner then edits real pricing/features on each
 * record from the admin panel. Same idempotent, dev-triggered-only,
 * never-overwrites-existing-work pattern as `wp yeffoprint setup-pages`:
 * skips entirely if any Web Design Package record already exists,
 * rather than trying to reconcile individual tiers.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Web_Design_Packages_Setup_Command {

	public function register(): void {
		\WP_CLI::add_command( 'yeffoprint setup-web-design-packages', [ $this, 'setup' ] );
	}

	/**
	 * ## EXAMPLES
	 *
	 *     wp yeffoprint setup-web-design-packages
	 */
	public function setup(): void {
		$existing = get_posts( [
			'post_type'      => 'yp_web_design_package',
			'post_status'    => 'any',
			'posts_per_page' => 1,
		] );

		if ( $existing ) {
			\WP_CLI::log( 'Web Design Package records already exist — leaving them as-is.' );
			return;
		}

		$packages = [
			[
				'name'     => 'Starter',
				'price'    => '$X,XXX',
				'tagline'  => 'A focused, single-line storefront to get selling fast.',
				'features' => [
					'Up to 5 pages, built on a proven template',
					'WooCommerce set up for your product catalog',
					'Domain connected',
					'Launch checklist & handoff walkthrough',
				],
				'featured' => false,
			],
			[
				'name'     => 'Growth',
				'price'    => '$X,XXX',
				'tagline'  => 'A fully custom storefront designed around your brand.',
				'features' => [
					'Custom design, not a template',
					'WooCommerce configured for your full catalog',
					'Domain + business email set up',
					'Basic on-page SEO setup',
					'Two rounds of revisions before launch',
				],
				'featured' => true,
			],
			[
				'name'     => 'Full-Service',
				'price'    => '$X,XXX',
				'tagline'  => 'Everything in Growth, plus we run the entire launch.',
				'features' => [
					'Everything in Growth',
					'Payment processing & shipping setup',
					'Product photography guidance',
					'Priority launch support',
					'30 days of post-launch support included',
				],
				'featured' => false,
			],
		];

		foreach ( $packages as $order => $package ) {
			$post_id = wp_insert_post( [
				'post_type'   => 'yp_web_design_package',
				'post_title'  => $package['name'],
				'post_status' => 'publish',
				'menu_order'  => $order,
			], true );

			if ( is_wp_error( $post_id ) ) {
				\WP_CLI::error( $post_id->get_error_message() );
				return;
			}

			update_post_meta( $post_id, YeffoPrint_Web_Design_Package_Meta::PRICE, $package['price'] );
			update_post_meta( $post_id, YeffoPrint_Web_Design_Package_Meta::TAGLINE, $package['tagline'] );
			update_post_meta( $post_id, YeffoPrint_Web_Design_Package_Meta::FEATURED, $package['featured'] );
			update_post_meta( $post_id, YeffoPrint_Web_Design_Package_Meta::FEATURES, $package['features'] );
		}

		\WP_CLI::success( 'Created the three Web Design Package records.' );
	}
}
