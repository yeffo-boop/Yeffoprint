<?php
/**
 * Admin upload for a Proof against a CustomOrder (PROJECT_SPEC §13).
 *
 * V1 was staff-upload only, no customer-facing response (explicit V1
 * non-goal, PROJECT_SPEC §19). V2 adds that response (the public
 * proof-approval page, class-proof-approval-controller.php) — so
 * uploading a proof here now advances the CustomOrder straight to
 * "Awaiting Proof Approval" (only forward, never overwriting a status
 * staff already moved past) and emails the customer their one-time
 * approval link, since that status is now something the customer is
 * actually meant to act on, not just an internal marker.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Proof_Editor {

	private const NONCE_ACTION = 'yeffoprint_save_proof';
	private const NONCE_NAME   = 'yeffoprint_proof_nonce';

	public function __construct() {
		add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
		add_action( 'save_post_yp_proof', [ $this, 'save' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function enqueue_assets( string $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'yp_proof' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_script(
			'yeffoprint-core-vial-mockup-picker', // Generic wp.media picker script from Phase 4 — reused as-is.
			YEFFOPRINT_CORE_URL . 'assets/admin/vial-mockup-picker.js',
			[ 'media-editor' ],
			yeffoprint_core_asset_version( 'assets/admin/vial-mockup-picker.js' ),
			true
		);
	}

	public function add_meta_boxes(): void {
		add_meta_box(
			'yp-proof-file',
			__( 'Proof File', 'yeffoprint-core' ),
			[ $this, 'render_box' ],
			'yp_proof',
			'normal'
		);
	}

	public function render_box( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$custom_order_id = (int) get_post_meta( $post->ID, YeffoPrint_Proof_Meta::CUSTOM_ORDER_ID, true );
		if ( ! $custom_order_id && isset( $_GET['custom_order'] ) ) {
			$custom_order_id = absint( $_GET['custom_order'] );
		}

		$file_id  = (int) get_post_meta( $post->ID, YeffoPrint_Proof_Meta::FILE_ID, true );
		$file_url = $file_id ? wp_get_attachment_url( $file_id ) : '';

		$custom_orders = get_posts( [
			'post_type'      => 'yp_custom_order',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		] );
		?>
		<p>
			<label for="yp-proof-custom-order"><?php esc_html_e( 'Custom Order', 'yeffoprint-core' ); ?></label><br />
			<select id="yp-proof-custom-order" name="yp_custom_order_id" class="widefat">
				<option value=""><?php esc_html_e( '— Select —', 'yeffoprint-core' ); ?></option>
				<?php foreach ( $custom_orders as $custom_order ) : ?>
					<option value="<?php echo esc_attr( $custom_order->ID ); ?>" <?php selected( $custom_order_id, $custom_order->ID ); ?>>
						<?php echo esc_html( $custom_order->post_title ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label><?php esc_html_e( 'Proof file', 'yeffoprint-core' ); ?></label><br />
			<span id="yp-vial-mockup-preview">
				<?php if ( $file_url ) : ?>
					<a href="<?php echo esc_url( $file_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( basename( $file_url ) ); ?></a>
				<?php endif; ?>
			</span><br />
			<input type="hidden" id="yp-vial-mockup-id" name="yp_proof_file_id" value="<?php echo esc_attr( $file_id ); ?>" />
			<button type="button" class="button" id="yp-vial-mockup-select"><?php esc_html_e( 'Select file', 'yeffoprint-core' ); ?></button>
			<button type="button" class="button-link" id="yp-vial-mockup-remove" <?php echo $file_id ? '' : 'style="display:none;"'; ?>><?php esc_html_e( 'Remove', 'yeffoprint-core' ); ?></button>
		</p>
		<?php
	}

	public function save( int $post_id ): void {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( wp_unslash( $_POST[ self::NONCE_NAME ] ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$custom_order_id = isset( $_POST['yp_custom_order_id'] ) ? absint( $_POST['yp_custom_order_id'] ) : 0;
		$file_id         = isset( $_POST['yp_proof_file_id'] ) ? absint( $_POST['yp_proof_file_id'] ) : 0;

		update_post_meta( $post_id, YeffoPrint_Proof_Meta::CUSTOM_ORDER_ID, $custom_order_id );
		update_post_meta( $post_id, YeffoPrint_Proof_Meta::FILE_ID, $file_id );

		if ( $custom_order_id && $file_id ) {
			$this->advance_status_to_awaiting_approval( $custom_order_id );
		}
	}

	private function advance_status_to_awaiting_approval( int $custom_order_id ): void {
		$current = (string) get_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::STATUS, true );

		if ( ! in_array( $current, [ 'design_in_progress', 'proof_ready' ], true ) ) {
			return;
		}

		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::STATUS, 'awaiting_approval' );
		$this->notify_customer( $custom_order_id );
	}

	/**
	 * Best-effort only — a failed/absent email is never the sole way to
	 * reach the customer, since the admin screen always shows the same
	 * link for staff to copy and send directly (guest orders especially
	 * may have gone through with an email that bounces or was mistyped).
	 */
	private function notify_customer( int $custom_order_id ): void {
		$email = (string) get_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::CUSTOMER_EMAIL, true );
		if ( ! $email || ! is_email( $email ) ) {
			return;
		}

		$name = (string) get_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::CUSTOMER_NAME, true );
		$url  = yeffoprint_core_proof_approval_url( $custom_order_id );
		if ( ! $url ) {
			return;
		}

		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$subject = sprintf(
			/* translators: %s: site name */
			__( 'Your proof is ready to review — %s', 'yeffoprint-core' ),
			$site_name
		);

		$body = sprintf(
			/* translators: 1: customer's first name or "there", 2: proof approval URL, 3: site name */
			__( "Hi %1\$s,\n\nYour custom label proof is ready to review. Please take a look and let us know if it's good to print:\n\n%2\$s\n\nNo account needed — that link is yours alone, so don't share it.\n\nThanks,\n%3\$s", 'yeffoprint-core' ),
			$name ? $name : __( 'there', 'yeffoprint-core' ),
			$url,
			$site_name
		);

		wp_mail( $email, $subject, $body );
	}
}
