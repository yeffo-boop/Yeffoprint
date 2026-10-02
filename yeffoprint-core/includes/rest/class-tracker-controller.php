<?php
/**
 * Dose Tracker REST API (`yeffoprint-core/v1/tracker/*`) — signed-in
 * customers only, every call nonce-checked, and every read/write scoped
 * to the current user: there is deliberately no route (admin or not)
 * that reads another customer's tracker.
 *
 *   GET    /tracker/state                 everything the app shows, decrypted for its owner
 *   PUT    /tracker/records/{kind}/{id}   create or replace one record
 *   DELETE /tracker/records/{kind}/{id}
 *   POST   /tracker/push                  save this browser's push subscription (reminders on)
 *   DELETE /tracker/push                  remove it
 *   POST   /tracker/push/test             send a test reminder to this customer's devices
 *   DELETE /tracker/all                   "Delete my data" — rows plus the customer's key
 *   GET    /tracker/label-templates       designs offered by "Order labels" (public storefront data)
 *   POST   /tracker/shares                a share link for one protocol (class-tracker-shares.php)
 *   DELETE /tracker/shares/{code}         stop sharing one of the customer's own links
 *   POST   /tracker/feedback              Me > Help & feedback note to the owner (class-tracker-feedback.php)
 *   PUT    /tracker/photos/{id}           save one progress photo (a JPEG data URL), encrypted like every record
 *   GET    /tracker/photos/{id}           the photo itself (the app fetches it with its nonce and shows a blob: URL)
 *   DELETE /tracker/photos/{id}
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Tracker_Controller {

	private const NAMESPACE = 'yeffoprint-core/v1';

	/** Kinds the app writes directly; `push` only goes through /tracker/push. */
	private const WRITABLE_KINDS = [ 'protocol', 'dose', 'vial', 'stock', 'settings', 'progress' ];

	/** What a progress photo may be once decoded. */
	private const PHOTO_TYPES = [ 'image/jpeg', 'image/png', 'image/webp' ];

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		$perm = [ __CLASS__, 'permission' ];

		register_rest_route( self::NAMESPACE, '/tracker/state', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_state' ],
			'permission_callback' => $perm,
		] );

		register_rest_route( self::NAMESPACE, '/tracker/records/(?P<kind>[a-z]+)/(?P<id>[A-Za-z0-9_-]{1,64})', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'put_record' ],
				'permission_callback' => $perm,
			],
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_record' ],
				'permission_callback' => $perm,
			],
		] );

		register_rest_route( self::NAMESPACE, '/tracker/push', [
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'subscribe' ],
				'permission_callback' => $perm,
			],
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'unsubscribe' ],
				'permission_callback' => $perm,
			],
		] );

		register_rest_route( self::NAMESPACE, '/tracker/push/test', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'test_push' ],
			'permission_callback' => $perm,
		] );

		register_rest_route( self::NAMESPACE, '/tracker/all', [
			'methods'             => \WP_REST_Server::DELETABLE,
			'callback'            => [ $this, 'delete_all' ],
			'permission_callback' => $perm,
		] );

		register_rest_route( self::NAMESPACE, '/tracker/shares', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'create_share' ],
			'permission_callback' => $perm,
		] );

		register_rest_route( self::NAMESPACE, '/tracker/shares/(?P<code>[A-Za-z0-9]{10})', [
			'methods'             => \WP_REST_Server::DELETABLE,
			'callback'            => [ $this, 'delete_share' ],
			'permission_callback' => $perm,
		] );

		register_rest_route( self::NAMESPACE, '/tracker/feedback', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'send_feedback' ],
			'permission_callback' => $perm,
		] );

		register_rest_route( self::NAMESPACE, '/tracker/photos/(?P<id>[A-Za-z0-9_-]{1,64})', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_photo' ],
				'permission_callback' => $perm,
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'put_photo' ],
				'permission_callback' => $perm,
			],
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_photo' ],
				'permission_callback' => $perm,
			],
		] );

		register_rest_route( self::NAMESPACE, '/tracker/label-templates', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'label_templates' ],
			'permission_callback' => $perm,
		] );
	}

	/** Signed in, a valid wp_rest nonce on every request (reads too: this is health data, and a nonce-less GET is exactly what a hostile page could make with the customer's cookies), and a working key. @return true|\WP_Error */
	public static function permission( \WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'yeffoprint_tracker_signed_out', __( 'Please sign in to use the tracker.', 'yeffoprint-core' ), [ 'status' => 401 ] );
		}

		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error( 'yeffoprint_invalid_nonce', __( 'Your session has expired. Please refresh the page and try again.', 'yeffoprint-core' ), [ 'status' => 403 ] );
		}

		if ( ! YeffoPrint_Tracker_Crypto::is_ready() ) {
			return new \WP_Error( 'yeffoprint_tracker_crypto', __( 'The tracker isn’t set up on this site yet.', 'yeffoprint-core' ), [ 'status' => 503 ] );
		}

		return true;
	}

	public function get_state(): \WP_REST_Response {
		$user_id = get_current_user_id();
		YeffoPrint_Tracker_Usage::record( $user_id );

		$state = [];
		foreach ( self::WRITABLE_KINDS as $kind ) {
			$state[ $kind ] = (object) YeffoPrint_Tracker_Store::all( $user_id, $kind );
		}

		return self::no_store( [
			'records'    => $state,
			'push'       => [
				'publicKey' => YeffoPrint_Tracker_Push::public_key(),
				'devices'   => count( YeffoPrint_Tracker_Store::all( $user_id, 'push' ) ),
			],
			'shares'     => YeffoPrint_Tracker_Shares::list_for_user( $user_id ),
			'serverTime' => time(),
		] );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function put_record( \WP_REST_Request $request ) {
		$kind = (string) $request['kind'];
		$id   = (string) $request['id'];
		if ( ! in_array( $kind, self::WRITABLE_KINDS, true ) || ! YeffoPrint_Tracker_Store::valid_id( $id ) ) {
			return new \WP_Error( 'yeffoprint_tracker_bad_record', __( 'That entry couldn’t be saved.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$data = $request->get_json_params();
		$data = is_array( $data['data'] ?? null ) ? $data['data'] : null;
		if ( null === $data ) {
			return new \WP_Error( 'yeffoprint_tracker_bad_record', __( 'That entry couldn’t be saved.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$data = self::clean( $data, 0 );
		if ( strlen( (string) wp_json_encode( $data ) ) > YeffoPrint_Tracker_Store::MAX_RECORD_BYTES ) {
			return new \WP_Error( 'yeffoprint_tracker_too_big', __( 'That entry is too long.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$user_id  = get_current_user_id();
		$new_dose = 'dose' === $kind && ! YeffoPrint_Tracker_Store::exists( $user_id, $kind, $id );

		$result = YeffoPrint_Tracker_Store::put( $user_id, $kind, $id, $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		// Counts only (admin Dashboard) — see YeffoPrint_Tracker_Usage.
		YeffoPrint_Tracker_Usage::record( $user_id, $new_dose ? 1 : 0 );

		return self::no_store( [ 'ok' => true, 'data' => $data ] );
	}

	public function delete_record( \WP_REST_Request $request ): \WP_REST_Response {
		$kind = (string) $request['kind'];
		if ( in_array( $kind, self::WRITABLE_KINDS, true ) ) {
			YeffoPrint_Tracker_Store::delete( get_current_user_id(), $kind, (string) $request['id'] );
		}
		return self::no_store( [ 'ok' => true ] );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function subscribe( \WP_REST_Request $request ) {
		$sub      = $request->get_json_params();
		$endpoint = (string) ( $sub['endpoint'] ?? '' );
		$p256dh   = (string) ( $sub['keys']['p256dh'] ?? '' );
		$auth     = (string) ( $sub['keys']['auth'] ?? '' );

		if ( ! YeffoPrint_Tracker_Push::valid_endpoint( $endpoint )
			|| 65 !== strlen( YeffoPrint_Tracker_Push::b64url_decode( $p256dh ) )
			|| 16 !== strlen( YeffoPrint_Tracker_Push::b64url_decode( $auth ) ) ) {
			return new \WP_Error( 'yeffoprint_tracker_bad_push', __( 'This browser’s notifications couldn’t be turned on.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$user_id = get_current_user_id();
		if ( count( YeffoPrint_Tracker_Store::all( $user_id, 'push' ) ) >= 10 ) {
			// Oldest devices first would need timestamps; simply refusing
			// an 11th device is enough to stop runaway growth.
			return new \WP_Error( 'yeffoprint_tracker_push_full', __( 'Reminders are already on for 10 devices. Turn them off on one first.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$result = YeffoPrint_Tracker_Store::put( $user_id, 'push', self::push_id( $endpoint ), [
			'endpoint' => $endpoint,
			'keys'     => [ 'p256dh' => $p256dh, 'auth' => $auth ],
		] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		// Start the reminder window now, so turning reminders on never
		// fires one for a dose from earlier in the day.
		update_user_meta( $user_id, YeffoPrint_Tracker_Reminders::LAST_SWEEP_META, time() );

		return self::no_store( [ 'ok' => true ] );
	}

	public function unsubscribe( \WP_REST_Request $request ): \WP_REST_Response {
		$endpoint = (string) ( $request->get_json_params()['endpoint'] ?? '' );
		if ( '' !== $endpoint ) {
			YeffoPrint_Tracker_Store::delete( get_current_user_id(), 'push', self::push_id( $endpoint ) );
		}
		return self::no_store( [ 'ok' => true ] );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function test_push() {
		$sent = 0;
		foreach ( YeffoPrint_Tracker_Store::all( get_current_user_id(), 'push' ) as $sub ) {
			$status = YeffoPrint_Tracker_Push::send( $sub, [
				'title' => __( 'Reminders are on', 'yeffoprint-core' ),
				'body'  => __( 'This is how your dose reminders will look.', 'yeffoprint-core' ),
				'tag'   => 'yp-dose-test',
				'url'   => home_url( '/tracker/' ),
			], 300 );
			if ( $status >= 200 && $status < 300 ) {
				++$sent;
			}
		}

		if ( ! $sent ) {
			return new \WP_Error( 'yeffoprint_tracker_push_failed', __( 'The test reminder couldn’t be sent. Try turning reminders off and on again.', 'yeffoprint-core' ), [ 'status' => 502 ] );
		}
		return self::no_store( [ 'ok' => true, 'sent' => $sent ] );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function create_share( \WP_REST_Request $request ) {
		$params   = $request->get_json_params();
		$protocol = is_array( $params['protocol'] ?? null ) ? YeffoPrint_Tracker_Shares::sanitize( $params['protocol'] ) : null;
		if ( ! $protocol ) {
			return new \WP_Error( 'yeffoprint_tracker_bad_share', __( 'Add a name and dose before sharing.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$code = YeffoPrint_Tracker_Shares::create( get_current_user_id(), $protocol );
		if ( is_wp_error( $code ) ) {
			return $code;
		}
		return self::no_store( [ 'ok' => true, 'code' => $code, 'url' => YeffoPrint_Tracker_Shares::url( $code ) ] );
	}

	/** Scoped to the owner: someone else's code (or one already stopped) is a 404, not a way to probe codes. @return \WP_REST_Response|\WP_Error */
	public function delete_share( \WP_REST_Request $request ) {
		if ( ! YeffoPrint_Tracker_Shares::delete( get_current_user_id(), (string) $request['code'] ) ) {
			return new \WP_Error( 'yeffoprint_tracker_share_missing', __( 'That link was already stopped.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}
		return self::no_store( [ 'ok' => true ] );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function send_feedback( \WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$id     = YeffoPrint_Tracker_Feedback::submit( get_current_user_id(), is_array( $params ) ? $params : [] );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return self::no_store( [ 'ok' => true ] );
	}

	/**
	 * A progress photo: the app shrinks it to a JPEG of at most 1600px
	 * (which also drops the camera's location data) and sends it as a data
	 * URL. It's stored as its own encrypted record, kept out of
	 * /tracker/state so the app never downloads every photo at once.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function put_photo( \WP_REST_Request $request ) {
		$id      = (string) $request['id'];
		$user_id = get_current_user_id();
		$params  = $request->get_json_params();
		$url     = is_string( $params['data'] ?? null ) ? $params['data'] : '';

		$bytes = '';
		if ( preg_match( '#^data:image/(?:jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$#', $url, $m ) ) {
			$bytes = (string) base64_decode( $m[1], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		}
		$info = '' !== $bytes ? @getimagesizefromstring( $bytes ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! YeffoPrint_Tracker_Store::valid_id( $id ) || ! $info || ! in_array( $info['mime'] ?? '', self::PHOTO_TYPES, true ) ) {
			return new \WP_Error( 'yeffoprint_tracker_bad_photo', __( 'That photo couldn’t be saved. Try another one.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}
		if ( strlen( $bytes ) > YeffoPrint_Tracker_Store::MAX_PHOTO_BYTES ) {
			return new \WP_Error( 'yeffoprint_tracker_photo_big', __( 'That photo is too large.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}
		if ( ! YeffoPrint_Tracker_Store::exists( $user_id, 'photo', $id ) && YeffoPrint_Tracker_Store::count( $user_id, 'photo' ) >= YeffoPrint_Tracker_Store::MAX_PHOTOS ) {
			return new \WP_Error( 'yeffoprint_tracker_photos_full', __( 'You have the most progress photos the tracker can keep. Delete some older ones first.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$result = YeffoPrint_Tracker_Store::put( $user_id, 'photo', $id, [
			'mime' => $info['mime'],
			'b64'  => base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		YeffoPrint_Tracker_Usage::record( $user_id );
		return self::no_store( [ 'ok' => true ] );
	}

	/** Sends the image bytes straight out (the same nonce check as every tracker call, so it can't be hotlinked). */
	public function get_photo( \WP_REST_Request $request ) {
		$id    = (string) $request['id'];
		$photo = YeffoPrint_Tracker_Store::valid_id( $id ) ? YeffoPrint_Tracker_Store::get( get_current_user_id(), 'photo', $id ) : null;
		$bytes = $photo ? base64_decode( (string) ( $photo['b64'] ?? '' ), true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$mime  = $photo ? (string) ( $photo['mime'] ?? '' ) : '';
		if ( ! $bytes || ! in_array( $mime, self::PHOTO_TYPES, true ) ) {
			return new \WP_Error( 'yeffoprint_tracker_no_photo', __( 'That photo isn’t there any more.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}

		nocache_headers();
		header( 'Cache-Control: no-store, private' );
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . strlen( $bytes ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: inline' );
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- validated image bytes.
		exit;
	}

	public function delete_photo( \WP_REST_Request $request ): \WP_REST_Response {
		YeffoPrint_Tracker_Store::delete( get_current_user_id(), 'photo', (string) $request['id'] );
		return self::no_store( [ 'ok' => true ] );
	}

	public function delete_all(): \WP_REST_Response {
		YeffoPrint_Tracker_Store::delete_all( get_current_user_id() );
		return self::no_store( [ 'ok' => true ] );
	}

	/**
	 * The designs "Order labels" offers: published peptide/pen label
	 * Templates that can be added to the cart (every other Template if
	 * none are tagged yet). Public storefront data; each one's fields,
	 * sizes and materials come from the regular /templates/{id}/configurator
	 * route, and the order itself goes through /cart/add like the product page.
	 */
	public function label_templates(): \WP_REST_Response {
		$query = [
			'post_type'      => 'yp_template',
			'post_status'    => 'publish',
			'posts_per_page' => 60,
			'orderby'        => [ 'menu_order' => 'ASC', 'title' => 'ASC' ],
			'fields'         => 'ids',
		];

		$ids = get_posts( array_merge( $query, [
			'tax_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				[
					'taxonomy' => 'yp_product_type',
					'field'    => 'slug',
					'terms'    => [ 'peptide-vial-labels', 'pen-labels' ],
				],
			],
		] ) );
		if ( ! $ids ) {
			$ids = get_posts( $query );
		}

		$templates = [];
		foreach ( $ids as $id ) {
			if ( ! YeffoPrint_Linked_Product::get_linked_product_id( (int) $id ) ) {
				continue;
			}
			$templates[] = [
				'id'         => (int) $id,
				'title'      => get_post_field( 'post_title', $id ),
				'artworkUrl' => get_the_post_thumbnail_url( $id, 'medium_large' ) ?: '',
				'url'        => (string) get_permalink( $id ),
			];
		}

		return self::no_store( [
			'templates'     => $templates,
			'startingPrice' => function_exists( 'yeffoprint_core_starting_price_label' ) ? yeffoprint_core_starting_price_label() : '',
		] );
	}

	private static function push_id( string $endpoint ): string {
		return 'push-' . substr( hash( 'sha256', $endpoint ), 0, 40 );
	}

	/** Plain JSON only: strings (tag-stripped, capped), numbers, booleans, and small nested lists/objects. The app renders with textContent, so this is belt-and-braces, not the only guard. */
	private static function clean( $value, int $depth ) {
		if ( is_string( $value ) ) {
			return mb_substr( wp_strip_all_tags( $value ), 0, 1000 );
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return is_finite( (float) $value ) ? $value : 0;
		}
		if ( is_bool( $value ) || null === $value ) {
			return $value;
		}
		if ( is_array( $value ) && $depth < 4 ) {
			$out = [];
			foreach ( array_slice( $value, 0, 60, true ) as $k => $v ) {
				$key         = is_int( $k ) ? $k : substr( preg_replace( '/[^A-Za-z0-9_]/', '', (string) $k ), 0, 40 );
				$out[ $key ] = self::clean( $v, $depth + 1 );
			}
			return $out;
		}
		return null;
	}

	private static function no_store( array $data ): \WP_REST_Response {
		$response = rest_ensure_response( $data );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}
}
