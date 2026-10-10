<?php
/**
 * Profile pictures — direct request (Jeff): "the ability for users to
 * add a profile picture to their YeffoDesign account that also shows up
 * in the tracker app".
 *
 * One photo per account, set or removed in two places:
 *   - My Account > Account details (a small form above the details form);
 *   - the YeffoHealth tracker's Me screen, through REST:
 *       POST   /account/photo   { data: "data:image/jpeg;base64,..." }  -> { url }
 *       DELETE /account/photo
 *
 * Whatever is uploaded is turned upright (phone photos carry a rotation
 * flag), cropped to the centre square and saved as a 256 px JPEG, which
 * also drops photo metadata such as location. The file lives in this
 * site's uploads (yeffoprint-avatars/) under a random name, so the
 * tracker page's locked-down image rules still allow it and the address
 * can't be guessed from a user id. It isn't tracker data and isn't
 * encrypted: it's an account picture, like a Gravatar.
 *
 * The photo also becomes the account's avatar everywhere WordPress asks
 * for one (get_avatar(): comments, the admin app, the My Account
 * dashboard), and goes with the account in YeffoPrint_Account_Deletion.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Profile_Photo {

	private const META      = '_yp_profile_photo';
	private const DIR       = 'yeffoprint-avatars';
	private const SIZE      = 256;
	private const MAX_BYTES = 15 * MB_IN_BYTES;
	private const TYPES     = [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ];
	private const NONCE     = 'yeffoprint_profile_photo';
	private const NAMESPACE = 'yeffoprint-core/v1';

	public function __construct() {
		add_filter( 'pre_get_avatar_data', [ $this, 'avatar_data' ], 10, 2 );
		add_action( 'woocommerce_before_edit_account_form', [ $this, 'render' ] );
		add_action( 'template_redirect', [ $this, 'handle_post' ] );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_action( 'delete_user', [ __CLASS__, 'remove' ] );
	}

	/** The photo's address, or '' when the customer hasn't set one. */
	public static function url( int $user_id ): string {
		$file = $user_id ? (string) get_user_meta( $user_id, self::META, true ) : '';
		if ( '' === $file || ! file_exists( self::dir() . $file ) ) {
			return '';
		}
		return self::dir_url() . rawurlencode( $file );
	}

	/**
	 * Saves an uploaded image as the customer's photo, replacing any old one.
	 *
	 * @return string|\WP_Error The new photo's address.
	 */
	public static function set_from_bytes( int $user_id, string $bytes ) {
		$bad = new \WP_Error( 'yeffoprint_photo_bad', __( 'That picture couldn’t be used. Try a JPEG or PNG photo.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		if ( '' === $bytes ) {
			return $bad;
		}
		if ( strlen( $bytes ) > self::MAX_BYTES ) {
			return new \WP_Error( 'yeffoprint_photo_big', __( 'That picture is too large. Please pick one under 15 MB.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}
		$info = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $info || ! in_array( $info['mime'] ?? '', self::TYPES, true ) ) {
			return $bad;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = wp_tempnam( 'yp-photo' );
		if ( ! $tmp || false === file_put_contents( $tmp, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return $bad;
		}

		$editor = wp_get_image_editor( $tmp, [ 'mime_type' => $info['mime'] ] );
		if ( is_wp_error( $editor ) ) {
			wp_delete_file( $tmp );
			return $bad;
		}
		if ( method_exists( $editor, 'maybe_exif_rotate' ) ) {
			$editor->maybe_exif_rotate();
		}
		$size = $editor->get_size();
		$side = (int) min( $size['width'], $size['height'] );
		$crop = $editor->crop(
			(int) floor( ( $size['width'] - $side ) / 2 ),
			(int) floor( ( $size['height'] - $side ) / 2 ),
			$side,
			$side,
			min( self::SIZE, $side ),
			min( self::SIZE, $side )
		);
		if ( is_wp_error( $crop ) ) {
			wp_delete_file( $tmp );
			return $bad;
		}
		$editor->set_quality( 85 );

		if ( ! wp_mkdir_p( self::dir() ) ) {
			wp_delete_file( $tmp );
			return $bad;
		}
		self::protect_dir();
		$name  = $user_id . '-' . strtolower( wp_generate_password( 16, false ) ) . '.jpg';
		$saved = $editor->save( self::dir() . $name, 'image/jpeg' );
		wp_delete_file( $tmp );
		if ( is_wp_error( $saved ) ) {
			return $bad;
		}

		self::delete_file( $user_id );
		update_user_meta( $user_id, self::META, $name );
		return self::url( $user_id );
	}

	public static function remove( int $user_id ): void {
		self::delete_file( $user_id );
		delete_user_meta( $user_id, self::META );
	}

	private static function delete_file( int $user_id ): void {
		$old = (string) get_user_meta( $user_id, self::META, true );
		if ( '' !== $old && $old === basename( $old ) && file_exists( self::dir() . $old ) ) {
			wp_delete_file( self::dir() . $old );
		}
	}

	private static function dir(): string {
		return trailingslashit( wp_upload_dir( null, false )['basedir'] ) . self::DIR . '/';
	}

	private static function dir_url(): string {
		return trailingslashit( set_url_scheme( wp_upload_dir( null, false )['baseurl'] ) ) . self::DIR . '/';
	}

	/** No folder listing, so the photos can't be browsed. */
	private static function protect_dir(): void {
		$index = self::dir() . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	/* ---------- get_avatar() ---------- */

	/**
	 * @param array $args
	 * @param mixed $id_or_email A user id, email, WP_User, WP_Post or WP_Comment.
	 */
	public function avatar_data( $args, $id_or_email ) {
		$user_id = 0;
		if ( is_numeric( $id_or_email ) ) {
			$user_id = (int) $id_or_email;
		} elseif ( $id_or_email instanceof \WP_User ) {
			$user_id = $id_or_email->ID;
		} elseif ( $id_or_email instanceof \WP_Post ) {
			$user_id = (int) $id_or_email->post_author;
		} elseif ( $id_or_email instanceof \WP_Comment ) {
			$user_id = (int) $id_or_email->user_id;
		} elseif ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$user    = get_user_by( 'email', $id_or_email );
			$user_id = $user ? $user->ID : 0;
		}
		$url = self::url( $user_id );
		if ( '' !== $url ) {
			$args['url']          = $url;
			$args['found_avatar'] = true;
		}
		return $args;
	}

	/* ---------- My Account > Account details ---------- */

	public function render(): void {
		$user = wp_get_current_user();
		if ( ! $user->exists() ) {
			return;
		}
		$url     = self::url( $user->ID );
		$initial = strtoupper( mb_substr( trim( $user->first_name ?: $user->display_name ), 0, 1 ) );
		?>
		<section class="yp-profile-photo" id="profile-photo" aria-labelledby="yp-profile-photo-title">
			<span class="yp-profile-photo__pic" aria-hidden="true">
				<?php if ( $url ) : ?>
					<img src="<?php echo esc_url( $url ); ?>" alt="" width="88" height="88">
				<?php else : ?>
					<?php echo esc_html( $initial ); ?>
				<?php endif; ?>
			</span>
			<div class="yp-profile-photo__body">
				<h2 id="yp-profile-photo-title"><?php esc_html_e( 'Profile picture', 'yeffoprint-core' ); ?></h2>
				<p><?php esc_html_e( 'Shows on your account and in the YeffoHealth tracker. We crop it to a square.', 'yeffoprint-core' ); ?></p>
				<form method="post" enctype="multipart/form-data" class="yp-profile-photo__form">
					<?php wp_nonce_field( self::NONCE ); ?>
					<label class="yp-profile-photo__pick">
						<?php $url ? esc_html_e( 'Change picture', 'yeffoprint-core' ) : esc_html_e( 'Upload a picture', 'yeffoprint-core' ); ?>
						<input type="file" name="yp_profile_photo" accept="image/jpeg,image/png,image/webp,image/gif" onchange="if(this.files.length){this.form.submit();}">
					</label>
					<?php if ( $url ) : ?>
						<button type="submit" class="yp-profile-photo__remove" name="yp_profile_photo_remove" value="1"><?php esc_html_e( 'Remove', 'yeffoprint-core' ); ?></button>
					<?php endif; ?>
				</form>
			</div>
		</section>
		<?php
	}

	public function handle_post(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! is_user_logged_in() ) {
			return;
		}
		$remove = isset( $_POST['yp_profile_photo_remove'] );
		$upload = isset( $_FILES['yp_profile_photo'] ) && is_array( $_FILES['yp_profile_photo'] );
		if ( ! $remove && ! $upload ) {
			return;
		}
		check_admin_referer( self::NONCE );
		$user_id = get_current_user_id();

		if ( $remove ) {
			self::remove( $user_id );
			wc_add_notice( __( 'Your profile picture was removed.', 'yeffoprint-core' ) );
		} else {
			$file  = $_FILES['yp_profile_photo']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read as image bytes and validated below.
			$error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
			$path  = (string) ( $file['tmp_name'] ?? '' );
			if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
				wc_add_notice( __( 'That picture is too large. Please pick a smaller one.', 'yeffoprint-core' ), 'error' );
			} elseif ( UPLOAD_ERR_OK !== $error || '' === $path || ! is_uploaded_file( $path ) ) {
				wc_add_notice( __( 'Please choose a picture to upload.', 'yeffoprint-core' ), 'error' );
			} else {
				$result = self::set_from_bytes( $user_id, (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				if ( is_wp_error( $result ) ) {
					wc_add_notice( $result->get_error_message(), 'error' );
				} else {
					wc_add_notice( __( 'Your profile picture was updated.', 'yeffoprint-core' ) );
				}
			}
		}
		wp_safe_redirect( wc_get_account_endpoint_url( 'edit-account' ) . '#profile-photo' );
		exit;
	}

	/* ---------- REST (the tracker's Me screen) ---------- */

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/account/photo', [
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'rest_set' ],
				'permission_callback' => [ __CLASS__, 'permission' ],
			],
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'rest_remove' ],
				'permission_callback' => [ __CLASS__, 'permission' ],
			],
		] );
	}

	public static function permission( \WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'yeffoprint_signed_out', __( 'Please sign in first.', 'yeffoprint-core' ), [ 'status' => 401 ] );
		}
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error( 'yeffoprint_invalid_nonce', __( 'Your session has expired. Please refresh the page and try again.', 'yeffoprint-core' ), [ 'status' => 403 ] );
		}
		return true;
	}

	public function rest_set( \WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$data   = is_string( $params['data'] ?? null ) ? $params['data'] : '';
		$bytes  = '';
		if ( preg_match( '#^data:image/(?:jpeg|png|webp|gif);base64,([A-Za-z0-9+/=]+)$#', $data, $m ) ) {
			$bytes = (string) base64_decode( $m[1], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		}
		$url = self::set_from_bytes( get_current_user_id(), $bytes );
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		return new \WP_REST_Response( [ 'url' => $url ], 200, [ 'Cache-Control' => 'no-store' ] );
	}

	public function rest_remove() {
		self::remove( get_current_user_id() );
		return new \WP_REST_Response( [ 'url' => '' ], 200, [ 'Cache-Control' => 'no-store' ] );
	}
}
