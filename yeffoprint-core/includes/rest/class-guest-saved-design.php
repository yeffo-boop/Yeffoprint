<?php
/**
 * Guest "save this design" + claim into Saved Designs.
 *
 * Guests can buy without an account, but Saved Designs need a
 * post_author. Pending batches are stored in the WooCommerce session
 * *and* a durable token (transient) so an email resume link still
 * works after the session expires. Claiming on login/register, or
 * opening ?pending={token} on the template, restores the batch.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Guest_Saved_Design {

	private const NAMESPACE   = 'yeffoprint-core/v1';
	private const SESSION_KEY = 'yp_pending_saved_design';
	private const TOKEN_TTL   = WEEK_IN_SECONDS;

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_action( 'wp_login', [ $this, 'claim_pending_on_login' ], 20, 2 );
		add_action( 'user_register', [ $this, 'claim_pending_on_register' ], 20 );
		add_action( 'woocommerce_thankyou', [ $this, 'render_thankyou_save_prompts' ], 25 );
		add_action( 'woocommerce_thankyou', [ $this, 'render_thankyou_continue_design' ], 26 );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/saved-designs/pending', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'stash_pending' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'guest_or_nonced_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/saved-designs/pending/(?P<token>[a-f0-9]{32})', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_pending_by_token' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( self::NAMESPACE, '/saved-designs/pending/email', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'email_pending_link' ],
			'permission_callback' => [ 'YeffoPrint_Rest_Security', 'guest_or_nonced_write' ],
		] );

		register_rest_route( self::NAMESPACE, '/saved-designs/claim', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'claim_pending' ],
			'permission_callback' => 'is_user_logged_in',
		] );

		register_rest_route( self::NAMESPACE, '/saved-designs/from-order-item', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'save_from_order_item' ],
			'permission_callback' => 'is_user_logged_in',
		] );
	}

	/**
	 * Guest mid-configurator save — stash the batch (session + token)
	 * so login or an emailed resume link can restore it.
	 */
	public function stash_pending( \WP_REST_Request $request ) {
		$payload = $this->normalize_batch_payload( $request );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		if ( is_user_logged_in() ) {
			$created = $this->create_saved_design( get_current_user_id(), $payload );
			if ( is_wp_error( $created ) ) {
				return $created;
			}

			return rest_ensure_response( [
				'saved'    => true,
				'id'       => $created,
				'edit_url' => $this->edit_url_for( $created, $payload['template_id'] ),
			] );
		}

		$token      = $this->persist_pending( $payload );
		$resume_url = $this->resume_url_for( $token, $payload['template_id'] );

		$this->ensure_cart();
		WC()->session->set( self::SESSION_KEY, $payload );
		WC()->session->set( self::SESSION_KEY . '_token', $token );

		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		$emailed = false;
		if ( is_email( $email ) ) {
			$emailed = $this->send_resume_email( $email, $resume_url, $payload['template_id'] );
		}

		$account_url = function_exists( 'wc_get_page_permalink' )
			? wc_get_page_permalink( 'myaccount' )
			: home_url( '/my-account/' );

		return rest_ensure_response( [
			'saved'      => false,
			'pending'    => true,
			'token'      => $token,
			'resume_url' => $resume_url,
			'emailed'    => $emailed,
			'login_url'  => add_query_arg( 'yp_claim_design', '1', $account_url ),
			'message'    => $emailed
				? __( 'Check your email for a link to finish this design anytime.', 'yeffoprint-core' )
				: __( 'Design saved for this browser. Email yourself a link, or log in to keep it in Saved Designs.', 'yeffoprint-core' ),
		] );
	}

	public function get_pending_by_token( \WP_REST_Request $request ) {
		$token   = (string) $request->get_param( 'token' );
		$payload = $this->load_pending( $token );

		if ( ! is_array( $payload ) ) {
			return new \WP_Error( 'yeffoprint_pending_expired', __( 'That saved design link has expired. Please customize again.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}

		// Refresh session so a subsequent login can claim the same batch.
		$this->ensure_cart();
		if ( WC()->session ) {
			WC()->session->set( self::SESSION_KEY, $payload );
			WC()->session->set( self::SESSION_KEY . '_token', $token );
		}

		return rest_ensure_response( [
			'template_id' => (int) $payload['template_id'],
			'size_id'     => (int) $payload['size_id'],
			'material_id' => (int) $payload['material_id'],
			'variants'    => $payload['variants'],
		] );
	}

	public function email_pending_link( \WP_REST_Request $request ) {
		$token = preg_replace( '/[^a-f0-9]/', '', (string) $request->get_param( 'token' ) );
		$email = sanitize_email( (string) $request->get_param( 'email' ) );

		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'yeffoprint_invalid_email', __( 'Please enter a valid email address.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$payload = $this->load_pending( $token );
		if ( ! is_array( $payload ) ) {
			return new \WP_Error( 'yeffoprint_pending_expired', __( 'That saved design link has expired. Please customize again.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}

		$resume_url = $this->resume_url_for( $token, (int) $payload['template_id'] );
		$sent       = $this->send_resume_email( $email, $resume_url, (int) $payload['template_id'] );

		if ( ! $sent ) {
			return new \WP_Error( 'yeffoprint_email_failed', __( 'Could not send that email. Please try again or log in instead.', 'yeffoprint-core' ), [ 'status' => 500 ] );
		}

		return rest_ensure_response( [
			'emailed' => true,
			'message' => __( 'Check your email for a link to finish this design anytime.', 'yeffoprint-core' ),
		] );
	}

	public function claim_pending(): \WP_REST_Response|\WP_Error {
		$this->ensure_cart();
		$payload = WC()->session ? WC()->session->get( self::SESSION_KEY ) : null;

		if ( ! is_array( $payload ) ) {
			$token = WC()->session ? (string) WC()->session->get( self::SESSION_KEY . '_token' ) : '';
			if ( $token ) {
				$payload = $this->load_pending( $token );
			}
		}

		if ( ! is_array( $payload ) ) {
			return new \WP_Error( 'yeffoprint_no_pending_design', __( 'No pending design to save.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}

		$created = $this->create_saved_design( get_current_user_id(), $payload );
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		WC()->session->set( self::SESSION_KEY, null );
		WC()->session->set( self::SESSION_KEY . '_token', null );

		return rest_ensure_response( [
			'saved'    => true,
			'id'       => $created,
			'edit_url' => $this->edit_url_for( $created, (int) ( $payload['template_id'] ?? 0 ) ),
		] );
	}

	public function save_from_order_item( \WP_REST_Request $request ) {
		$order_id = absint( $request->get_param( 'order_id' ) );
		$item_id  = absint( $request->get_param( 'item_id' ) );
		$order    = wc_get_order( $order_id );

		if ( ! $order || (int) $order->get_user_id() !== get_current_user_id() ) {
			return new \WP_Error( 'yeffoprint_order_forbidden', __( 'That order was not found.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}

		$item = $order->get_item( $item_id );
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return new \WP_Error( 'yeffoprint_item_not_found', __( 'That order item was not found.', 'yeffoprint-core' ), [ 'status' => 404 ] );
		}

		$snapshot    = json_decode( (string) $item->get_meta( '_yp_template_snapshot' ), true );
		$template_id = (int) ( $snapshot['id'] ?? 0 );
		if ( ! $template_id ) {
			return new \WP_Error( 'yeffoprint_not_template_item', __( 'Only template label designs can be saved this way.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$size_snapshot     = json_decode( (string) $item->get_meta( '_yp_size_snapshot' ), true );
		$material_snapshot = json_decode( (string) $item->get_meta( '_yp_material_snapshot' ), true );
		$variants          = json_decode( (string) $item->get_meta( '_yp_variants' ), true );

		$payload = [
			'template_id' => $template_id,
			'size_id'     => (int) ( $size_snapshot['id'] ?? 0 ),
			'material_id' => (int) ( $material_snapshot['id'] ?? 0 ),
			'variants'    => is_array( $variants ) ? $variants : [],
		];

		$created = $this->create_saved_design( get_current_user_id(), $payload );
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		return rest_ensure_response( [
			'saved'    => true,
			'id'       => $created,
			'edit_url' => $this->edit_url_for( $created, $template_id ),
		] );
	}

	public function claim_pending_on_login( string $user_login, \WP_User $user ): void {
		unset( $user_login );
		$this->claim_for_user( (int) $user->ID );
	}

	public function claim_pending_on_register( int $user_id ): void {
		$this->claim_for_user( $user_id );
	}

	private function claim_for_user( int $user_id ): void {
		if ( $user_id <= 0 || ! function_exists( 'WC' ) ) {
			return;
		}

		$this->ensure_cart();
		if ( ! WC()->session ) {
			return;
		}

		$payload = WC()->session->get( self::SESSION_KEY );
		if ( ! is_array( $payload ) ) {
			$token = (string) WC()->session->get( self::SESSION_KEY . '_token' );
			if ( $token ) {
				$payload = $this->load_pending( $token );
			}
		}

		if ( ! is_array( $payload ) ) {
			return;
		}

		$created = $this->create_saved_design( $user_id, $payload );
		if ( ! is_wp_error( $created ) ) {
			WC()->session->set( self::SESSION_KEY, null );
			WC()->session->set( self::SESSION_KEY . '_token', null );
		}
	}

	public function render_thankyou_save_prompts( $order_id ): void {
		if ( ! is_user_logged_in() || ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || (int) $order->get_user_id() !== get_current_user_id() ) {
			return;
		}

		$saveable = [];
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$snapshot = json_decode( (string) $item->get_meta( '_yp_template_snapshot' ), true );
			if ( empty( $snapshot['id'] ) ) {
				continue;
			}
			$saveable[] = [
				'item_id' => (int) $item_id,
				'name'    => $item->get_name(),
			];
		}

		if ( ! $saveable ) {
			return;
		}

		$nonce = wp_create_nonce( 'wp_rest' );
		$rest  = esc_url_raw( rest_url( 'yeffoprint-core/v1/saved-designs/from-order-item' ) );
		?>
		<section class="yp-thankyou-save-designs" data-yp-thankyou-save style="margin:2rem 0;padding:1.25rem;border:1px solid var(--wp--preset--color--fog, #e5e5e5);border-radius:12px;">
			<h2><?php esc_html_e( 'Save these designs for next time', 'yeffoprint-core' ); ?></h2>
			<p><?php esc_html_e( 'Bookmark any label from this order under My Account → Saved Designs so you can reopen and reorder it later.', 'yeffoprint-core' ); ?></p>
			<ul class="yp-thankyou-save-designs__list" style="list-style:none;padding:0;margin:1rem 0 0;display:grid;gap:0.75rem;">
				<?php foreach ( $saveable as $row ) : ?>
					<li>
						<button
							type="button"
							class="wp-block-button__link"
							data-yp-save-order-item
							data-order-id="<?php echo esc_attr( (string) $order_id ); ?>"
							data-item-id="<?php echo esc_attr( (string) $row['item_id'] ); ?>"
						>
							<?php
							printf(
								/* translators: %s: product/label name */
								esc_html__( 'Save “%s”', 'yeffoprint-core' ),
								esc_html( $row['name'] )
							);
							?>
						</button>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="yp-thankyou-save-designs__status" data-yp-save-status hidden></p>
		</section>
		<script>
		(function () {
			var root = document.querySelector('[data-yp-thankyou-save]');
			if (!root) return;
			var statusEl = root.querySelector('[data-yp-save-status]');
			root.querySelectorAll('[data-yp-save-order-item]').forEach(function (button) {
				button.addEventListener('click', function () {
					button.disabled = true;
					fetch(<?php echo wp_json_encode( $rest ); ?>, {
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							'X-WP-Nonce': <?php echo wp_json_encode( $nonce ); ?>
						},
						body: JSON.stringify({
							order_id: parseInt(button.getAttribute('data-order-id'), 10),
							item_id: parseInt(button.getAttribute('data-item-id'), 10)
						})
					}).then(function (response) {
						return response.json().then(function (data) {
							return { ok: response.ok, data: data };
						});
					}).then(function (result) {
						if (!result.ok) {
							button.disabled = false;
							statusEl.hidden = false;
							statusEl.textContent = (result.data && result.data.message) || 'Could not save that design.';
							return;
						}
						button.textContent = 'Saved';
						statusEl.hidden = false;
						statusEl.textContent = 'Saved to My Account → Saved Designs.';
					}).catch(function () {
						button.disabled = false;
						statusEl.hidden = false;
						statusEl.textContent = 'Could not reach the server — please try again.';
					});
				});
			});
		})();
		</script>
		<?php
	}

	public function render_thankyou_continue_design( $order_id ): void {
		unset( $order_id );
		$account_url = function_exists( 'wc_get_page_permalink' )
			? wc_get_page_permalink( 'myaccount' )
			: home_url( '/my-account/' );
		$gallery_url = home_url( '/shop-labels/' );
		?>
		<section class="yp-continue-design yp-continue-design--thankyou" aria-label="<?php esc_attr_e( 'Save your design', 'yeffoprint-core' ); ?>">
			<strong><?php esc_html_e( 'Want this design next time too?', 'yeffoprint-core' ); ?></strong>
			<p><?php esc_html_e( 'Create an account (or log in) to keep labels under Saved Designs — or browse the gallery to start another.', 'yeffoprint-core' ); ?></p>
			<p>
				<a class="wp-block-button__link is-style-accent" href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'Go to My Account', 'yeffoprint-core' ); ?></a>
				<a class="wp-block-button__link" href="<?php echo esc_url( $gallery_url ); ?>"><?php esc_html_e( 'Browse gallery', 'yeffoprint-core' ); ?></a>
			</p>
		</section>
		<?php
	}

	/**
	 * @return array{template_id:int,size_id:int,material_id:int,variants:array}|\WP_Error
	 */
	private function normalize_batch_payload( \WP_REST_Request $request ) {
		$template_id = absint( $request->get_param( 'template_id' ) );
		$template    = get_post( $template_id );

		if ( ! $template || 'yp_template' !== $template->post_type || 'publish' !== $template->post_status ) {
			return new \WP_Error( 'yeffoprint_invalid_template', __( 'This design is not available.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$compatible_sizes     = array_map( 'absint', (array) get_post_meta( $template_id, YeffoPrint_Template_Meta::COMPATIBLE_SIZES, true ) );
		$compatible_materials = array_map( 'absint', (array) get_post_meta( $template_id, YeffoPrint_Template_Meta::COMPATIBLE_MATERIALS, true ) );

		$size_id     = absint( $request->get_param( 'size_id' ) );
		$material_id = absint( $request->get_param( 'material_id' ) );

		if ( $compatible_sizes && ! in_array( $size_id, $compatible_sizes, true ) ) {
			return new \WP_Error( 'yeffoprint_invalid_size', __( 'That size is not available for this design.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}
		if ( $compatible_materials && ! in_array( $material_id, $compatible_materials, true ) ) {
			return new \WP_Error( 'yeffoprint_invalid_material', __( 'That material is not available for this design.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$field_schema = YeffoPrint_Field_Schema::get( $template_id );
		$variants     = YeffoPrint_Field_Schema::sanitize_variants(
			(array) $request->get_param( 'variants' ),
			$field_schema,
			false
		);

		if ( ! $variants ) {
			return new \WP_Error( 'yeffoprint_empty_variants', __( 'Add at least one label variant before saving.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		return [
			'template_id' => $template_id,
			'size_id'     => $size_id,
			'material_id' => $material_id,
			'variants'    => $variants,
		];
	}

	/**
	 * @param array{template_id:int,size_id:int,material_id:int,variants:array} $payload
	 * @return int|\WP_Error
	 */
	private function create_saved_design( int $user_id, array $payload ) {
		$post_id = wp_insert_post( [
			'post_type'   => 'yp_saved_design',
			'post_status' => 'publish',
			'post_title'  => sprintf(
				/* translators: %s: template title */
				__( 'Saved: %s', 'yeffoprint-core' ),
				get_the_title( $payload['template_id'] )
			),
			'post_author' => $user_id,
		], true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, YeffoPrint_Saved_Design_Meta::TEMPLATE_ID, $payload['template_id'] );
		update_post_meta( $post_id, YeffoPrint_Saved_Design_Meta::SIZE_ID, $payload['size_id'] );
		update_post_meta( $post_id, YeffoPrint_Saved_Design_Meta::MATERIAL_ID, $payload['material_id'] );
		update_post_meta( $post_id, YeffoPrint_Saved_Design_Meta::VARIANTS, $payload['variants'] );

		return (int) $post_id;
	}

	/**
	 * @param array{template_id:int,size_id:int,material_id:int,variants:array} $payload
	 */
	private function persist_pending( array $payload ): string {
		$token = bin2hex( random_bytes( 16 ) );
		set_transient( $this->transient_key( $token ), $payload, self::TOKEN_TTL );
		return $token;
	}

	private function load_pending( string $token ): ?array {
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return null;
		}
		$payload = get_transient( $this->transient_key( $token ) );
		return is_array( $payload ) ? $payload : null;
	}

	private function transient_key( string $token ): string {
		return 'yp_pending_design_' . $token;
	}

	private function resume_url_for( string $token, int $template_id ): string {
		$permalink = get_permalink( $template_id );
		return $permalink ? (string) add_query_arg( 'pending', $token, $permalink ) : '';
	}

	private function edit_url_for( int $design_id, int $template_id ): string {
		$permalink = get_permalink( $template_id );
		return $permalink ? (string) add_query_arg( 'saved', $design_id, $permalink ) : '';
	}

	private function send_resume_email( string $email, string $resume_url, int $template_id ): bool {
		$title = get_the_title( $template_id ) ?: __( 'your label', 'yeffoprint-core' );
		$subject = sprintf(
			/* translators: %s: template title */
			__( 'Finish your %s design on YeffoDesign', 'yeffoprint-core' ),
			$title
		);

		$heading = __( 'Your design is waiting', 'yeffoprint-core' );
		$intro   = sprintf(
			/* translators: %s: template title */
			__( 'You saved a draft of “%s”. Open the link below anytime in the next 7 days to pick up where you left off — then log in to keep it under Saved Designs.', 'yeffoprint-core' ),
			$title
		);

		// Prefer the branded proof-notice email shell when WooCommerce is up.
		if ( function_exists( 'WC' ) && WC()->mailer() ) {
			require_once YEFFOPRINT_CORE_PATH . 'includes/woocommerce/class-email-proof-notice.php';
			$notice = new YeffoPrint_Email_Proof_Notice();
			return (bool) $notice->send_notice( $email, $subject, [
				'email_heading'   => $heading,
				'name'            => '',
				'eyebrow'         => __( 'Saved design', 'yeffoprint-core' ),
				'intro'           => $intro,
				'cta_url'         => $resume_url,
				'cta_label'       => __( 'Resume design', 'yeffoprint-core' ),
				'proof_image_url' => '',
			] );
		}

		$body = '<p>' . esc_html( $intro ) . '</p><p><a href="' . esc_url( $resume_url ) . '">' . esc_html__( 'Resume design', 'yeffoprint-core' ) . '</a></p>';
		return (bool) wp_mail( $email, $subject, $body, [ 'Content-Type: text/html; charset=UTF-8' ] );
	}

	private function ensure_cart(): void {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}
		if ( null === WC()->session ) {
			WC()->initialize_session();
		}
		if ( null === WC()->cart ) {
			wc_load_cart();
		}
	}
}
