<?php
/**
 * The public "Our Work" Showcase of finished web design projects.
 * Direct request: "some kind of 'Showcase' for my website design
 * customers. Like a portfolio potential customers can see." Jeff picked
 * the case-study layout (mockups/website-showcase/b-case-studies-*.png):
 * one section per site with screenshots, what we built, an optional
 * customer quote and a link to the live site.
 *
 * Each entry lives on its Web Design order (one JSON meta,
 * self::META), edited from the order window's Showcase panel. An entry
 * shows on /our-work/ only when all of these hold:
 *
 * - staff switched "Show on website" on (self::META_ON, its own meta so
 *   the public page can query for it),
 * - the customer agreed to be featured (self::META_PERMISSION = 'yes'),
 *   asked as a yes/no question on the agreement they sign (follow-up:
 *   "add a disclaimer or a question to the web design agreement asking
 *   if we can feature their site"), or set by staff for customers who
 *   said yes some other way,
 * - it has a name and a desktop screenshot.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Web_Design_Showcase {

	public const META            = '_yp_wd_showcase';
	public const META_ON         = '_yp_wd_showcase_on';
	public const META_PERMISSION = '_yp_wd_feature_ok';      // 'yes' | 'no' | ''
	public const META_PERM_FROM  = '_yp_wd_feature_ok_from'; // 'agreement' | 'staff'
	public const META_PERM_AT    = '_yp_wd_feature_ok_at';

	public const PAGE_SLUG     = 'our-work';
	public const PAGE_TEMPLATE = 'web-design-showcase';

	private const PAGE_OPTION = 'yeffoprint_showcase_page_id';
	private const MAX_TEXT    = 600;
	private const MAX_DID     = 12;

	public function __construct() {
		add_action( 'init', [ __CLASS__, 'ensure_page' ], 30 );
	}

	/* ---------- The /our-work/ page ---------- */

	/** Same create-once approach as YeffoPrint_Order_Reviews::ensure_page(). */
	public static function ensure_page(): void {
		$page_id = (int) get_option( self::PAGE_OPTION, 0 );
		if ( $page_id && get_post_status( $page_id ) ) {
			return;
		}

		$existing = get_page_by_path( self::PAGE_SLUG );
		if ( $existing ) {
			update_option( self::PAGE_OPTION, (int) $existing->ID, false );
			return;
		}

		$lock = 'yeffoprint_showcase_page_lock';
		if ( ! add_option( $lock, time(), '', false ) ) {
			if ( time() - (int) get_option( $lock ) < MINUTE_IN_SECONDS ) {
				return;
			}
			update_option( $lock, time(), false );
		}

		$page_id = wp_insert_post( [
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => __( 'Our Work', 'yeffoprint-core' ),
			'post_name'    => self::PAGE_SLUG,
			'post_content' => '',
			'meta_input'   => [ '_wp_page_template' => self::PAGE_TEMPLATE ],
		] );

		if ( $page_id && ! is_wp_error( $page_id ) ) {
			update_option( self::PAGE_OPTION, (int) $page_id, false );
		}
		delete_option( $lock );
	}

	public static function page_url(): string {
		$page_id = (int) get_option( self::PAGE_OPTION, 0 );
		$url     = $page_id ? get_permalink( $page_id ) : '';
		return $url ? $url : home_url( '/' . self::PAGE_SLUG . '/' );
	}

	/* ---------- Permission ---------- */

	public static function get_permission( \WC_Order $order ): array {
		return [
			'answer' => (string) $order->get_meta( self::META_PERMISSION ),
			'from'   => (string) $order->get_meta( self::META_PERM_FROM ),
			'at'     => (string) $order->get_meta( self::META_PERM_AT ),
		];
	}

	/** Does not save; callers save the order. */
	public static function set_permission( \WC_Order $order, string $answer, string $from ): void {
		$answer = in_array( $answer, [ 'yes', 'no' ], true ) ? $answer : '';
		if ( $answer === (string) $order->get_meta( self::META_PERMISSION ) ) {
			return;
		}
		$order->update_meta_data( self::META_PERMISSION, $answer );
		$order->update_meta_data( self::META_PERM_FROM, $from );
		$order->update_meta_data( self::META_PERM_AT, current_time( 'mysql' ) );
		if ( 'no' === $answer ) {
			$order->update_meta_data( self::META_ON, '' );
		}
		$order->add_order_note(
			'yes' === $answer
				? __( 'Customer agreed to have their site featured in the Showcase.', 'yeffoprint-core' )
				: ( 'no' === $answer
					? __( 'Customer asked not to have their site featured in the Showcase.', 'yeffoprint-core' )
					: __( 'Showcase permission cleared.', 'yeffoprint-core' ) )
		);
	}

	/* ---------- Entry ---------- */

	public static function get( \WC_Order $order ): array {
		$saved = json_decode( (string) $order->get_meta( self::META ), true );
		$saved = is_array( $saved ) ? $saved : [];
		$has   = ! empty( $saved );

		$entry = [
			'on'         => 'yes' === $order->get_meta( self::META_ON ),
			'featured'   => ! empty( $saved['featured'] ),
			'name'       => (string) ( $saved['name'] ?? ( $has ? '' : $order->get_billing_company() ) ),
			'url'        => (string) ( $saved['url'] ?? ( $has ? '' : self::guess_url( $order ) ) ),
			'blurb'      => (string) ( $saved['blurb'] ?? '' ),
			'story'      => (string) ( $saved['story'] ?? '' ),
			'did'        => array_values( array_filter( array_map( 'strval', (array) ( $saved['did'] ?? ( $has ? [] : self::package_features( $order ) ) ) ) ) ),
			'quote'      => (string) ( $saved['quote'] ?? '' ),
			'quote_by'   => (string) ( $saved['quote_by'] ?? '' ),
			'desktop_id' => (int) ( $saved['desktop_id'] ?? 0 ),
			'phone_id'   => (int) ( $saved['phone_id'] ?? 0 ),
		];

		$entry['desktop_url'] = $entry['desktop_id'] ? (string) wp_get_attachment_image_url( $entry['desktop_id'], 'large' ) : '';
		$entry['phone_url']   = $entry['phone_id'] ? (string) wp_get_attachment_image_url( $entry['phone_id'], 'large' ) : '';

		return $entry;
	}

	/** @return true|\WP_Error */
	public static function save( \WC_Order $order, array $input ) {
		$text = static fn( $value ): string => mb_substr( sanitize_text_field( (string) $value ), 0, self::MAX_TEXT );

		$did = array_slice( array_values( array_filter( array_map( $text, (array) ( $input['did'] ?? [] ) ) ) ), 0, self::MAX_DID );

		$url = trim( (string) ( $input['url'] ?? '' ) );
		if ( '' !== $url && ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . $url;
		}

		$entry = [
			'featured'   => ! empty( $input['featured'] ),
			'name'       => mb_substr( sanitize_text_field( (string) ( $input['name'] ?? '' ) ), 0, 80 ),
			'url'        => esc_url_raw( $url ),
			'blurb'      => $text( $input['blurb'] ?? '' ),
			'story'      => mb_substr( sanitize_textarea_field( (string) ( $input['story'] ?? '' ) ), 0, 1200 ),
			'did'        => $did,
			'quote'      => $text( $input['quote'] ?? '' ),
			'quote_by'   => mb_substr( sanitize_text_field( (string) ( $input['quote_by'] ?? '' ) ), 0, 80 ),
			'desktop_id' => self::image_id( $input['desktop_id'] ?? 0 ),
			'phone_id'   => self::image_id( $input['phone_id'] ?? 0 ),
		];

		if ( array_key_exists( 'permission', $input ) ) {
			self::set_permission( $order, (string) $input['permission'], 'staff' );
		}

		$on = ! empty( $input['on'] );
		if ( $on ) {
			if ( 'yes' !== $order->get_meta( self::META_PERMISSION ) ) {
				return new \WP_Error( 'yeffoprint_showcase_no_permission', __( 'The customer hasn’t said yes to being featured yet.', 'yeffoprint-core' ), [ 'status' => 400 ] );
			}
			if ( '' === $entry['name'] || ! $entry['desktop_id'] ) {
				return new \WP_Error( 'yeffoprint_showcase_incomplete', __( 'Add a name and a desktop screenshot before showing this on the website.', 'yeffoprint-core' ), [ 'status' => 400 ] );
			}
		}

		$order->update_meta_data( self::META, wp_json_encode( $entry ) );
		$order->update_meta_data( self::META_ON, $on ? 'yes' : '' );
		$order->save();

		return true;
	}

	/* ---------- Public list ---------- */

	/**
	 * Every entry that can show on /our-work/, featured first, then the
	 * most recently launched.
	 *
	 * @return array<int, array>
	 */
	public static function public_entries(): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return [];
		}

		$orders = wc_get_orders( [
			'limit'      => 60,
			'type'       => 'shop_order',
			'meta_key'   => self::META_ON, // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value' => 'yes',         // phpcs:ignore WordPress.DB.SlowDBQuery
		] );

		$entries = [];
		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order || 'yes' !== $order->get_meta( self::META_PERMISSION ) ) {
				continue;
			}
			$entry = self::get( $order );
			if ( '' === $entry['name'] || '' === $entry['desktop_url'] ) {
				continue;
			}

			$live = (string) $order->get_meta( YeffoPrint_Web_Design_Project_Meta::MARKED_LIVE_AT );
			$date = $live ? $live : ( $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : '' );

			$entry['id']       = $order->get_id();
			$entry['package']  = self::package_name( $order );
			$entry['launched'] = $date;
			$entries[]         = $entry;
		}

		usort( $entries, static function ( array $a, array $b ): int {
			if ( $a['featured'] !== $b['featured'] ) {
				return $a['featured'] ? -1 : 1;
			}
			return strcmp( $b['launched'], $a['launched'] );
		} );

		return $entries;
	}

	public static function package_name( \WC_Order $order ): string {
		$package_id = YeffoPrint_Web_Design_Project_Meta::get_package_id( $order );
		$package    = $package_id ? get_post( $package_id ) : null;
		return $package ? (string) $package->post_title : '';
	}

	/* ---------- Helpers ---------- */

	private static function image_id( $value ): int {
		$id = absint( $value );
		return $id && wp_attachment_is_image( $id ) ? $id : 0;
	}

	/** The site's address from the go-live WP-admin link, when there is one. */
	private static function guess_url( \WC_Order $order ): string {
		$wp_url = (string) $order->get_meta( YeffoPrint_Web_Design_Project_Meta::GOLIVE_WP_URL );
		if ( '' === $wp_url ) {
			return '';
		}
		$parts = wp_parse_url( $wp_url );
		return empty( $parts['host'] ) ? '' : ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'];
	}

	private static function package_features( \WC_Order $order ): array {
		$package_id = YeffoPrint_Web_Design_Project_Meta::get_package_id( $order );
		if ( ! $package_id ) {
			return [];
		}
		$features = get_post_meta( $package_id, YeffoPrint_Web_Design_Package_Meta::FEATURES, true );
		return is_array( $features ) ? array_slice( array_map( 'strval', $features ), 0, self::MAX_DID ) : [];
	}
}
