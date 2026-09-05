<?php
/**
 * Admin view for a Fully Custom Design request (PROJECT_SPEC §17
 * "Custom Orders" admin section).
 *
 * Everything except Status is read-only here — it's what the customer
 * submitted (class-custom-order-controller.php) or what payment
 * completion filled in (class-custom-order-payment.php). Status is
 * the one thing staff actively drive, advancing it through PROJECT_SPEC
 * §13's six states as the job moves through production.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Custom_Order_Editor {

	private const NONCE_ACTION = 'yeffoprint_save_custom_order';
	private const NONCE_NAME   = 'yeffoprint_custom_order_nonce';

	public function __construct() {
		add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
		add_action( 'save_post_yp_custom_order', [ $this, 'save' ] );
	}

	public function add_meta_boxes(): void {
		add_meta_box(
			'yp-custom-order-details',
			__( 'Request Details', 'yeffoprint-core' ),
			[ $this, 'render_details_box' ],
			'yp_custom_order',
			'normal'
		);

		add_meta_box(
			'yp-custom-order-status',
			__( 'Status', 'yeffoprint-core' ),
			[ $this, 'render_status_box' ],
			'yp_custom_order',
			'side'
		);

		add_meta_box(
			'yp-custom-order-proofs',
			__( 'Proofs', 'yeffoprint-core' ),
			[ $this, 'render_proofs_box' ],
			'yp_custom_order',
			'side'
		);
	}

	public function render_details_box( \WP_Post $post ): void {
		$m = static function ( string $key ) use ( $post ) {
			return get_post_meta( $post->ID, $key, true );
		};

		$size_id     = (int) $m( YeffoPrint_Custom_Order_Meta::SIZE_ID );
		$material_id = (int) $m( YeffoPrint_Custom_Order_Meta::MATERIAL_ID );
		$uploads     = (array) $m( YeffoPrint_Custom_Order_Meta::INSPIRATION_UPLOADS );
		$wc_order_id = (int) $m( YeffoPrint_Custom_Order_Meta::WC_ORDER_ID );
		$change_request = $m( YeffoPrint_Custom_Order_Meta::CHANGE_REQUEST_NOTES );
		$order_type  = YeffoPrint_Custom_Order_Meta::get_order_type( $post->ID );
		$is_sticker  = 'sticker' === $order_type;

		// Direct request: three ways to skip the $25 design fee — see
		// YeffoPrint_Custom_Order_Meta's own doc comments. None ever
		// applies to a sticker order (that flow has no flat fee to skip
		// in the first place), so all three stay false there.
		$customer_provided_design = ! $is_sticker && (bool) $m( YeffoPrint_Custom_Order_Meta::CUSTOMER_PROVIDED_DESIGN );
		$source_custom_order_id   = $is_sticker ? 0 : (int) $m( YeffoPrint_Custom_Order_Meta::SOURCE_CUSTOM_ORDER_ID );
		$fee_waived               = ! $is_sticker && (bool) $m( YeffoPrint_Custom_Order_Meta::FEE_WAIVED );
		?>
		<?php if ( $change_request && 'design_in_progress' === $m( YeffoPrint_Custom_Order_Meta::STATUS ) ) : ?>
			<div class="notice notice-warning inline" style="margin: 0 0 12px; padding: 10px 12px;">
				<p style="margin: 0 0 4px;"><strong><?php esc_html_e( 'Customer requested changes to the last proof:', 'yeffoprint-core' ); ?></strong></p>
				<p style="margin: 0;"><?php echo nl2br( esc_html( $change_request ) ); ?></p>
			</div>
		<?php endif; ?>
		<?php if ( $customer_provided_design ) : ?>
			<div class="notice notice-warning inline" style="margin: 0 0 12px; padding: 10px 12px;">
				<p style="margin: 0;"><strong><?php esc_html_e( 'Customer provided their own print-ready design — no design work needed.', 'yeffoprint-core' ); ?></strong> <?php esc_html_e( "Check the attached file(s) below are print-ready, then move this straight to a proof/status update.", 'yeffoprint-core' ); ?></p>
			</div>
		<?php endif; ?>
		<?php if ( $fee_waived ) : ?>
			<div class="notice notice-warning inline" style="margin: 0 0 12px; padding: 10px 12px;">
				<p style="margin: 0;"><strong><?php esc_html_e( 'The design fee was waived by staff on this order.', 'yeffoprint-core' ); ?></strong> <?php esc_html_e( 'Design work is still needed — the customer just was not charged for it.', 'yeffoprint-core' ); ?></p>
			</div>
		<?php endif; ?>
		<table class="widefat striped">
			<tbody>
				<tr><th><?php esc_html_e( 'Type', 'yeffoprint-core' ); ?></th><td><?php echo esc_html( YeffoPrint_Custom_Order_Meta::ORDER_TYPES[ $order_type ] ); ?></td></tr>
				<?php if ( ! $is_sticker ) : ?>
					<tr><th><?php esc_html_e( 'Submission', 'yeffoprint-core' ); ?></th><td>
						<?php if ( $customer_provided_design ) : ?>
							<?php esc_html_e( 'Customer-provided design (no design work needed)', 'yeffoprint-core' ); ?>
						<?php elseif ( $source_custom_order_id ) : ?>
							<?php
							printf(
								/* translators: %s: link to the original custom design order */
								wp_kses(
									/* translators: %s: link to the original custom design order */
									__( 'Reorder of %s (fee skipped)', 'yeffoprint-core' ),
									[ 'a' => [ 'href' => [] ] ]
								),
								sprintf(
									'<a href="%s">%s</a>',
									esc_url( admin_url( 'post.php?post=' . $source_custom_order_id . '&action=edit' ) ),
									esc_html( sprintf( /* translators: %d: order id */ __( 'Order #%d', 'yeffoprint-core' ), $source_custom_order_id ) )
								)
							);
							?>
						<?php elseif ( $fee_waived ) : ?>
							<?php esc_html_e( 'New design (fee waived by staff)', 'yeffoprint-core' ); ?>
						<?php else : ?>
							<?php esc_html_e( 'New design', 'yeffoprint-core' ); ?>
						<?php endif; ?>
					</td></tr>
				<?php endif; ?>
				<tr><th><?php esc_html_e( 'Customer', 'yeffoprint-core' ); ?></th><td>
					<?php echo esc_html( $m( YeffoPrint_Custom_Order_Meta::CUSTOMER_NAME ) ); ?>
					<?php if ( $m( YeffoPrint_Custom_Order_Meta::CUSTOMER_EMAIL ) ) : ?>
						&mdash; <a href="mailto:<?php echo esc_attr( $m( YeffoPrint_Custom_Order_Meta::CUSTOMER_EMAIL ) ); ?>"><?php echo esc_html( $m( YeffoPrint_Custom_Order_Meta::CUSTOMER_EMAIL ) ); ?></a>
					<?php endif; ?>
				</td></tr>
				<?php if ( $is_sticker ) : ?>
					<?php
					$is_custom_size = $size_id && (bool) get_post_meta( $size_id, YeffoPrint_Sticker_Size_Meta::IS_CUSTOM, true );
					$sticker_type   = (string) $m( YeffoPrint_Custom_Order_Meta::STICKER_TYPE );
					$shape          = (string) $m( YeffoPrint_Custom_Order_Meta::SHAPE );
					$artwork        = (array) $m( YeffoPrint_Custom_Order_Meta::ARTWORK_UPLOADS );
					?>
					<tr><th><?php esc_html_e( 'Sticker Type', 'yeffoprint-core' ); ?></th><td><?php echo esc_html( YeffoPrint_Sticker_Pricing::TYPES[ $sticker_type ] ?? '—' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Shape', 'yeffoprint-core' ); ?></th><td><?php echo esc_html( YeffoPrint_Sticker_Pricing::SHAPES[ $shape ] ?? '—' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Size', 'yeffoprint-core' ); ?></th><td>
						<?php if ( $is_custom_size ) : ?>
							<?php
							printf(
								/* translators: 1: width in inches, 2: height in inches */
								esc_html__( 'Custom: %1$s" × %2$s"', 'yeffoprint-core' ),
								esc_html( $m( YeffoPrint_Custom_Order_Meta::CUSTOM_WIDTH_IN ) ),
								esc_html( $m( YeffoPrint_Custom_Order_Meta::CUSTOM_HEIGHT_IN ) )
							);
							?>
						<?php else : ?>
							<?php echo esc_html( $size_id ? get_the_title( $size_id ) : '—' ); ?>
						<?php endif; ?>
					</td></tr>
					<tr><th><?php esc_html_e( 'Material', 'yeffoprint-core' ); ?></th><td><?php echo esc_html( $material_id ? get_the_title( $material_id ) : '—' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Quantity', 'yeffoprint-core' ); ?></th><td><?php echo esc_html( (int) $m( YeffoPrint_Custom_Order_Meta::QUANTITY ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Instructions', 'yeffoprint-core' ); ?></th><td><?php echo nl2br( esc_html( $m( YeffoPrint_Custom_Order_Meta::INSTRUCTIONS ) ?: '—' ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Artwork Files', 'yeffoprint-core' ); ?></th><td>
						<?php echo $this->render_upload_list( $artwork ); ?>
					</td></tr>
				<?php else : ?>
					<tr><th><?php esc_html_e( 'Brand Name', 'yeffoprint-core' ); ?></th><td><?php echo esc_html( $m( YeffoPrint_Custom_Order_Meta::BRAND_NAME ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Batch', 'yeffoprint-core' ); ?></th><td>
						<?php
						$batch_rows = YeffoPrint_Custom_Order_Meta::get_batch_rows( $post->ID );
						?>
						<table class="widefat striped" style="margin: 0;">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Size', 'yeffoprint-core' ); ?></th>
									<th><?php esc_html_e( 'Material', 'yeffoprint-core' ); ?></th>
									<th><?php esc_html_e( 'Quantity', 'yeffoprint-core' ); ?></th>
									<th><?php esc_html_e( 'Compound / Strength', 'yeffoprint-core' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $batch_rows as $row ) : ?>
									<tr>
										<td><?php echo esc_html( ! empty( $row['size_id'] ) ? get_the_title( (int) $row['size_id'] ) : '—' ); ?></td>
										<td><?php echo esc_html( ! empty( $row['material_id'] ) ? get_the_title( (int) $row['material_id'] ) : '—' ); ?></td>
										<td><?php echo esc_html( (int) ( $row['quantity'] ?? 0 ) ); ?></td>
										<td><?php echo esc_html( $row['compound_strength'] ?? '' ?: '—' ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</td></tr>
					<tr><th><?php esc_html_e( 'Style / Colors', 'yeffoprint-core' ); ?></th><td><?php echo nl2br( esc_html( $m( YeffoPrint_Custom_Order_Meta::STYLE_NOTES ) ?: '—' ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Instructions', 'yeffoprint-core' ); ?></th><td><?php echo nl2br( esc_html( $m( YeffoPrint_Custom_Order_Meta::INSTRUCTIONS ) ?: '—' ) ); ?></td></tr>
					<tr><th><?php echo esc_html( $customer_provided_design ? __( 'Print-Ready Design File(s)', 'yeffoprint-core' ) : __( 'Inspiration Files', 'yeffoprint-core' ) ); ?></th><td>
						<?php
						$label_files = $customer_provided_design ? (array) $m( YeffoPrint_Custom_Order_Meta::ARTWORK_UPLOADS ) : $uploads;
						echo $this->render_upload_list( $label_files );
						?>
					</td></tr>
					<?php
					// Label Designer only — the original logo/image file(s)
					// the customer uploaded onto the canvas, separate from
					// the flattened PNG above. See CANVAS_SOURCE_IMAGE_UPLOADS'
					// own doc comment for why staff need the source file too.
					$source_images = (array) $m( YeffoPrint_Custom_Order_Meta::CANVAS_SOURCE_IMAGE_UPLOADS );
					if ( $source_images ) :
					?>
						<tr><th><?php esc_html_e( 'Uploaded Logo/Image File(s)', 'yeffoprint-core' ); ?></th><td>
							<?php echo $this->render_upload_list( $source_images ); ?>
							<p class="description"><?php esc_html_e( 'Original file(s) the customer placed on their design — use these instead of the exported PNG above if you need higher quality for print.', 'yeffoprint-core' ); ?></p>
						</td></tr>
					<?php endif; ?>
				<?php endif; ?>
				<tr><th>
					<?php
					// Stickers have no separate flat fee (class-custom-
					// order-payment.php's find_design_fee()) — this field
					// records the whole amount paid for that flow instead,
					// so the row is labeled to match what it's actually
					// showing.
					echo esc_html( $is_sticker ? __( 'Amount Paid', 'yeffoprint-core' ) : __( 'Design Fee', 'yeffoprint-core' ) );
					?>
				</th><td>
					<?php
					$fee = $m( YeffoPrint_Custom_Order_Meta::DESIGN_FEE );
					if ( $customer_provided_design ) {
						esc_html_e( '$0.00 — fee skipped (customer-provided design)', 'yeffoprint-core' );
					} elseif ( $source_custom_order_id ) {
						printf(
							/* translators: %d: order id */
							esc_html__( '$0.00 — fee skipped (reorder of Order #%d)', 'yeffoprint-core' ),
							$source_custom_order_id
						);
					} elseif ( $fee ) {
						echo esc_html( '$' . number_format_i18n( (float) $fee, 2 ) . ' — paid' );
					} else {
						esc_html_e( 'Awaiting payment', 'yeffoprint-core' );
					}
					?>
				</td></tr>
				<?php if ( $wc_order_id ) : ?>
					<tr><th><?php esc_html_e( 'Order', 'yeffoprint-core' ); ?></th><td>
						<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $wc_order_id . '&action=edit' ) ); ?>">#<?php echo esc_html( (string) $wc_order_id ); ?></a>
					</td></tr>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/** @param int[] $attachment_ids */
	private function render_upload_list( array $attachment_ids ): string {
		if ( ! $attachment_ids ) {
			return '—';
		}

		$items = '';
		foreach ( $attachment_ids as $attachment_id ) {
			$url = wp_get_attachment_url( $attachment_id );
			if ( ! $url ) {
				continue;
			}

			// A quick visual, not just a filename link — direct value for
			// the Label Designer's exported PNG specifically (staff used
			// to have to open every file in a new tab just to see it), but
			// applies to any image-type upload here, not only that flow's.
			$thumbnail_url = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );
			$thumbnail_html = $thumbnail_url
				? sprintf( '<img src="%s" alt="" class="yp-upload-thumbnail" />', esc_url( $thumbnail_url ) )
				: '';

			$items .= sprintf(
				'<li><a href="%s" target="_blank" rel="noopener noreferrer">%s%s</a></li>',
				esc_url( $url ),
				$thumbnail_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_url() above, or empty.
				esc_html( basename( $url ) )
			);
		}

		return $items ? '<ul>' . $items . '</ul>' : '—';
	}

	public function render_status_box( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		if ( 'publish' !== $post->post_status ) {
			echo '<p>' . esc_html__( 'Awaiting the design fee payment. Status is set automatically once paid.', 'yeffoprint-core' ) . '</p>';
			return;
		}

		$status = (string) get_post_meta( $post->ID, YeffoPrint_Custom_Order_Meta::STATUS, true );
		?>
		<select name="yp_custom_order_status" class="widefat">
			<?php foreach ( YeffoPrint_Custom_Order_Meta::STATUSES as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status, $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	public function render_proofs_box( \WP_Post $post ): void {
		$proof_ids = YeffoPrint_Proof_Meta::get_for_custom_order( $post->ID );

		if ( ! $proof_ids ) {
			echo '<p>' . esc_html__( 'No proofs uploaded yet — add one from the Proofs screen.', 'yeffoprint-core' ) . '</p>';
		} else {
			echo '<ul>';
			foreach ( $proof_ids as $proof_id ) {
				$file_id = (int) get_post_meta( $proof_id, YeffoPrint_Proof_Meta::FILE_ID, true );
				$url     = $file_id ? wp_get_attachment_url( $file_id ) : '';
				printf(
					'<li><a href="%s">%s</a> — %s</li>',
					esc_url( $url ?: '#' ),
					esc_html( get_the_date( '', $proof_id ) ),
					esc_html( get_the_title( $proof_id ) ?: __( 'Proof', 'yeffoprint-core' ) )
				);
			}
			echo '</ul>';
		}

		printf(
			'<p><a class="button" href="%s">%s</a></p>',
			esc_url( admin_url( 'post-new.php?post_type=yp_proof&custom_order=' . $post->ID ) ),
			esc_html__( 'Add Proof', 'yeffoprint-core' )
		);

		if ( $proof_ids && 'publish' === $post->post_status ) {
			$approval_url = yeffoprint_core_proof_approval_url( $post->ID );
			if ( $approval_url ) {
				?>
				<p>
					<label for="yp-proof-approval-link"><strong><?php esc_html_e( 'Customer approval link', 'yeffoprint-core' ); ?></strong></label><br />
					<input type="text" id="yp-proof-approval-link" class="widefat" readonly onclick="this.select();" value="<?php echo esc_attr( $approval_url ); ?>" />
					<span class="description"><?php esc_html_e( 'No account needed — emailed automatically when a proof advances the order to "Awaiting Proof Approval," and copyable here any time to resend (e.g. by text) for a guest order.', 'yeffoprint-core' ); ?></span>
				</p>
				<?php
			}
		}
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

		if ( isset( $_POST['yp_custom_order_status'] ) ) {
			$status = sanitize_key( wp_unslash( $_POST['yp_custom_order_status'] ) );
			if ( array_key_exists( $status, YeffoPrint_Custom_Order_Meta::STATUSES ) ) {
				update_post_meta( $post_id, YeffoPrint_Custom_Order_Meta::STATUS, $status );
			}
		}
	}
}
