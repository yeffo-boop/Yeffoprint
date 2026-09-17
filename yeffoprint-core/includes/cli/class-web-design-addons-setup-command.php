<?php
/**
 * Dev-only setup: creates the two Web Design Add-on records (Maintenance,
 * Hosting) with the exact copy patterns/web-design-packages.php's own
 * badges/modals used to hold before they became admin-editable — same
 * idempotent, dev-triggered-only, never-overwrites-existing-work pattern
 * as `wp yeffoprint setup-web-design-packages`: skips entirely if any
 * Web Design Add-on record already exists.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Web_Design_Addons_Setup_Command {

	public function register(): void {
		\WP_CLI::add_command( 'yeffoprint setup-web-design-addons', [ $this, 'setup' ] );
	}

	/**
	 * ## EXAMPLES
	 *
	 *     wp yeffoprint setup-web-design-addons
	 */
	public function setup(): void {
		$existing = get_posts( [
			'post_type'      => 'yp_web_design_addon',
			'post_status'    => 'any',
			'posts_per_page' => 1,
		] );

		if ( $existing ) {
			\WP_CLI::log( 'Web Design Add-on records already exist — leaving them as-is.' );
			return;
		}

		$addons = [
			[
				'name'          => 'Ongoing Maintenance & Monitoring',
				'price'         => '$35/mo',
				'badge_text'    => 'Every package can add ongoing maintenance & monitoring for $35/mo',
				'modal_heading' => 'Ongoing Maintenance & Monitoring',
				'modal_body'    => "A launched site still needs attention — plugin and core updates, and someone watching for issues before your customers find them. Add this to any package for \$35/mo and we'll keep your store current and monitored, month to month.",
				'features'      => [
					'Core, theme, and plugin updates — applied and tested, not just installed blind',
					'Uptime monitoring, so we know before your customers do',
					'Regular backups',
					'Security monitoring for common vulnerabilities',
					'Priority support if something needs attention',
				],
				'cta_label'     => 'Ask About Maintenance',
				'cta_url'       => '',
				'icon'          => 'wrench',
			],
			[
				'name'          => 'Hosting Add-On',
				'price'         => '$35/mo',
				'badge_text'    => 'Need hosting too? Add it from $35/mo — email & a domain included',
				'modal_heading' => 'Hosting Add-On',
				'modal_body'    => "None of the packages above include hosting or domain registration — those are ongoing costs you hold directly, or you can add our hosting for \$35/mo.",
				'features'      => [
					'Hosting for your storefront',
					'Business email at your own domain',
					'1 year of domain registration',
				],
				'cta_label'     => 'Ask About Hosting',
				'cta_url'       => '',
				'icon'          => 'globe',
			],
		];

		foreach ( $addons as $order => $addon ) {
			$post_id = wp_insert_post( [
				'post_type'   => 'yp_web_design_addon',
				'post_title'  => $addon['name'],
				'post_status' => 'publish',
				'menu_order'  => $order,
			], true );

			if ( is_wp_error( $post_id ) ) {
				\WP_CLI::error( $post_id->get_error_message() );
				return;
			}

			update_post_meta( $post_id, YeffoPrint_Web_Design_Addon_Meta::PRICE, $addon['price'] );
			update_post_meta( $post_id, YeffoPrint_Web_Design_Addon_Meta::BADGE_TEXT, $addon['badge_text'] );
			update_post_meta( $post_id, YeffoPrint_Web_Design_Addon_Meta::MODAL_HEADING, $addon['modal_heading'] );
			update_post_meta( $post_id, YeffoPrint_Web_Design_Addon_Meta::MODAL_BODY, $addon['modal_body'] );
			update_post_meta( $post_id, YeffoPrint_Web_Design_Addon_Meta::FEATURES, $addon['features'] );
			update_post_meta( $post_id, YeffoPrint_Web_Design_Addon_Meta::CTA_LABEL, $addon['cta_label'] );
			update_post_meta( $post_id, YeffoPrint_Web_Design_Addon_Meta::CTA_URL, $addon['cta_url'] );
			update_post_meta( $post_id, YeffoPrint_Web_Design_Addon_Meta::ICON, $addon['icon'] );
		}

		\WP_CLI::success( 'Created the two Web Design Add-on records.' );
	}
}
