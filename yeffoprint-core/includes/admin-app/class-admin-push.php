<?php
/**
 * Phone alerts for the new admin app (direct request: "I'd like to
 * install it as a web app on my iPhone so I get notifications of new
 * orders and stuff").
 *
 * Every owner alert the store already sends to Telegram fires
 * `yeffoprint_owner_alert` first (class-telegram-admin-alerts.php's
 * notify(), plus Dose Tracker feedback), so the same events reach the
 * phone without a second copy of their trigger logic: a paid order, a
 * new review, a contact form message, a web design quote, tracker
 * feedback. Alerts are sent whether or not Telegram is set up.
 *
 * Delivery reuses the Dose Tracker's Web Push sender and its VAPID key
 * (class-tracker-push.php). Subscriptions are kept per admin user in
 * user meta, one per device, and dropped when the push service says
 * the device is gone (404/410).
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Admin_Push {

	public const META = 'yeffoprint_admin_push_subscriptions';

	/** The new admin app's wp-admin page slug (class-admin-menu.php). Here rather than on YeffoPrint_Admin_App, which only loads in wp-admin, while alerts also fire from checkout and REST requests. */
	public const APP_SLUG = 'yeffoprint-next';

	public function __construct() {
		add_action( 'yeffoprint_owner_alert', [ $this, 'on_owner_alert' ], 10, 2 );
	}

	public static function available(): bool {
		return class_exists( 'YeffoPrint_Tracker_Push' ) && '' !== YeffoPrint_Tracker_Push::public_key();
	}

	/** @return array<string,array> keyed by a hash of the endpoint */
	public static function subscriptions( int $user_id ): array {
		$subs = get_user_meta( $user_id, self::META, true );
		return is_array( $subs ) ? $subs : [];
	}

	public static function subscribe( int $user_id, array $subscription, string $device ): bool {
		$endpoint = (string) ( $subscription['endpoint'] ?? '' );
		$p256dh   = (string) ( $subscription['keys']['p256dh'] ?? '' );
		$auth     = (string) ( $subscription['keys']['auth'] ?? '' );

		if ( ! YeffoPrint_Tracker_Push::valid_endpoint( $endpoint ) || '' === $p256dh || '' === $auth ) {
			return false;
		}

		$subs                    = self::subscriptions( $user_id );
		$subs[ md5( $endpoint ) ] = [
			'endpoint' => $endpoint,
			'keys'     => [ 'p256dh' => sanitize_text_field( $p256dh ), 'auth' => sanitize_text_field( $auth ) ],
			'device'   => mb_substr( sanitize_text_field( $device ), 0, 80 ),
			'created'  => time(),
		];
		update_user_meta( $user_id, self::META, $subs );
		return true;
	}

	public static function unsubscribe( int $user_id, string $endpoint ): void {
		$subs = self::subscriptions( $user_id );
		unset( $subs[ md5( $endpoint ) ] );
		update_user_meta( $user_id, self::META, $subs );
	}

	/** @param array{order_id?:int,section?:string} $context */
	public function on_owner_alert( string $text, array $context = [] ): void {
		$lines = array_values( array_filter( array_map( 'trim', explode( "\n", $text ) ), 'strlen' ) );
		if ( ! $lines ) {
			return;
		}

		$app = admin_url( 'admin.php?page=' . self::APP_SLUG );
		if ( ! empty( $context['order_id'] ) ) {
			$url = $app . '#/order/' . (int) $context['order_id'];
		} elseif ( ! empty( $context['section'] ) ) {
			$url = $app . '#/' . sanitize_key( $context['section'] );
		} else {
			$url = $app;
		}

		self::send_to_admins( [
			'title' => mb_strimwidth( $lines[0], 0, 120, '…' ),
			'body'  => mb_strimwidth( implode( ' · ', array_slice( $lines, 1, 4 ) ), 0, 240, '…' ),
			'url'   => $url,
			'tag'   => ! empty( $context['order_id'] ) ? 'order-' . (int) $context['order_id'] : '',
		] );
	}

	/** @return int devices the alert was delivered to */
	public static function send_to_admins( array $message, int $only_user = 0 ): int {
		if ( ! self::available() ) {
			return 0;
		}

		$user_ids = $only_user ? [ $only_user ] : get_users( [
			'meta_key' => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a handful of admin users at most.
			'fields'   => 'ID',
		] );

		$message += [
			'icon'  => YEFFOPRINT_CORE_URL . 'assets/admin-app/next/icons/icon-192.png',
			'badge' => YEFFOPRINT_CORE_URL . 'assets/admin-app/next/icons/badge-96.png',
		];

		$delivered = 0;
		foreach ( $user_ids as $user_id ) {
			$user_id = (int) $user_id;
			if ( ! user_can( $user_id, 'manage_options' ) ) {
				continue;
			}
			$subs    = self::subscriptions( $user_id );
			$changed = false;
			foreach ( $subs as $key => $sub ) {
				$code = YeffoPrint_Tracker_Push::send( $sub, $message, 12 * HOUR_IN_SECONDS );
				if ( 404 === $code || 410 === $code ) {
					unset( $subs[ $key ] );
					$changed = true;
				} elseif ( $code >= 200 && $code < 300 ) {
					$delivered++;
				}
			}
			if ( $changed ) {
				update_user_meta( $user_id, self::META, $subs );
			}
		}
		return $delivered;
	}
}
