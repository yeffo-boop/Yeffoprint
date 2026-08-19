<?php namespace TierPricingTable\Addons\RequestAQuote\CPT;

use TierPricingTable\Addons\RequestAQuote\Models\QuoteRequest;
use TierPricingTable\TierPricingTablePlugin;

class QuoteRequestAdmin {

	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'addMetaBoxes' ) );
		add_action( 'save_post', array( $this, 'savePost' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'adminAssets' ) );

		add_filter( 'manage_' . QuoteRequestCPT::POST_TYPE . '_posts_columns', array( $this, 'setCustomColumns' ) );
		add_action( 'manage_' . QuoteRequestCPT::POST_TYPE . '_posts_custom_column', array( $this, 'customColumnData' ),
				10, 2 );
		add_action( 'restrict_manage_posts', array( $this, 'addProductsFilter' ) );
		add_action( 'pre_get_posts', array( $this, 'filterByProduct' ) );

		add_filter( 'bulk_actions-edit-' . QuoteRequestCPT::POST_TYPE, array( $this, 'registerBulkActions' ) );
		add_filter( 'handle_bulk_actions-edit-' . QuoteRequestCPT::POST_TYPE, array( $this, 'processBulkActions' ), 10,
				3 );
		add_action( 'admin_notices', array( $this, 'bulkActionNotices' ) );
	}

	public function adminAssets() {
		global $post_type;
		if ( QuoteRequestCPT::POST_TYPE === $post_type ) {
			wp_enqueue_style( 'tpt-quote-admin', plugins_url( '../assets/css/admin-quote-request.css', __FILE__ ),
					array(), TierPricingTablePlugin::VERSION );
			wp_enqueue_script( 'tpt-quote-admin', plugins_url( '../assets/js/admin-quote-request.js', __FILE__ ),
					array( 'jquery' ), TierPricingTablePlugin::VERSION, true );
		}
	}

	public function addMetaBoxes() {
		add_meta_box( 'tier_pricing_table_quote_details', __( 'Customer Request', 'tier-pricing-table' ),
				array( $this, 'renderDetailsMetaBox' ), QuoteRequestCPT::POST_TYPE, 'normal', 'high' );

		add_meta_box( 'tier_pricing_table_quote_product', __( 'Product', 'tier-pricing-table' ),
				array( $this, 'renderProductMetaBox' ), QuoteRequestCPT::POST_TYPE, 'normal', 'high' );

		add_meta_box( 'tier_pricing_table_quote_customer', __( 'User', 'tier-pricing-table' ), array( $this, 'renderCustomerMetaBox' ),
				QuoteRequestCPT::POST_TYPE, 'side', 'high' );

		add_meta_box( 'tier_pricing_table_quote_actions', __( 'Quote Actions', 'tier-pricing-table' ),
				array( $this, 'renderActionsMetaBox' ), QuoteRequestCPT::POST_TYPE, 'side', 'high' );
	}

	public function renderDetailsMetaBox( $post ) {
		$quote        = new QuoteRequest( $post );
		$customFields = $quote->getCustomFields();

		?>
		<div class="tpt-quote-grid">
			<?php
				foreach ( $customFields as $key => $fieldData ) {
					if ( ! is_array( $fieldData ) ) {
						$fieldData = array(
								'label' => ucfirst( str_replace( '_', ' ', $key ) ),
								'value' => $fieldData,
						);
					}
					$label = isset( $fieldData['label'] ) && ! empty( $fieldData['label'] ) ? $fieldData['label'] : ucfirst( str_replace( '_',
							' ', $key ) );
					$value = isset( $fieldData['value'] ) ? $fieldData['value'] : '';

					?>
					<div class="tpt-quote-field">
						<label><?php echo esc_html( $label ); ?></label>
						<?php
							if ( isset( $fieldData['type'] ) && $fieldData['type'] === 'file' && ! empty( $value ) && filter_var( $value,
											FILTER_VALIDATE_URL ) ) :
								$fileName = basename( parse_url( $value, PHP_URL_PATH ) );
								?>
								<span>
						<a href="<?php echo esc_url( $value ); ?>" target="_blank"
						   style="color: #2271b1; font-weight: 500; text-decoration: underline;"><?php echo esc_html( $fileName ); ?></a>
					</span>
							<?php else : ?>
								<span><?php echo esc_html( $value ); ?></span>
							<?php endif; ?>
					</div>
					<?php
				}
			?>
		</div>
		<?php
	}

	public function renderProductMetaBox( $post ) {
		$quote     = new QuoteRequest( $post );
		$productId = $quote->getProductId();
		$quantity  = $quote->getQuantity();
		$price     = $quote->getPrice();

		$product = wc_get_product( $productId );

		if ( ! $product ) {
			?>
			<p><?php esc_html_e( 'Product not found.', 'tier-pricing-table' ); ?></p>
			<?php
			return;
		}
		?>
		<table class="tpt-quote-table">
			<thead>
			<tr>
				<th><?php esc_html_e( 'Product', 'tier-pricing-table' ); ?></th>
				<th><?php esc_html_e( 'Quantity', 'tier-pricing-table' ); ?></th>
				<th><?php esc_html_e( 'Unit Price', 'tier-pricing-table' ); ?></th>
				<th><?php esc_html_e( 'Total', 'tier-pricing-table' ); ?></th>
			</tr>
			</thead>
			<tbody>
			<tr>
				<td style="display: flex; align-items: center; gap: 12px;">
					<div style="width: 48px; height: 48px; border-radius: 4px; overflow: hidden; border: 1px solid #e5e5e5; flex-shrink: 0; display: flex; align-items: center; justify-content: center; background: #fff;">
						<?php echo wp_kses_post( $product->get_image( array( 48, 48 ) ) ); ?>
					</div>
					<div>
						<strong><a href="<?php echo esc_url( get_edit_post_link( $product->get_id() ) ); ?>"
						           target="_blank"><?php echo wp_kses_post( $product->get_name() ); ?></a></strong>
						<?php if ( $product->get_sku() ) : ?>
							<div style="font-size: 12px; color: #646970; margin-top: 4px;">
								<?php esc_html_e( 'SKU:',
										'tier-pricing-table' ); ?><?php echo esc_html( $product->get_sku() ); ?>
							</div>
						<?php endif; ?>
					</div>
				</td>
				<td>
					<input type="number" min="1" step="1" name="tier_pricing_table_quote_quantity"
					       value="<?php echo esc_attr( $quantity ? $quantity : 1 ); ?>"
					       style="width: 80px;">
				</td>
				<td>
					<input type="number" step="0.01" name="tier_pricing_table_quote_price"
					       value="<?php echo esc_attr( $price ); ?>"
					       style="width: 100px;">
				</td>
				<td>
					<strong>
						<?php
							$total = (float) $price * (int) $quantity;
							echo wp_kses_post( wc_price( $total ) );
						?>
					</strong>
				</td>
			</tr>
			</tbody>
		</table>

		<div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #e5e5e5; display: flex; justify-content: flex-end; align-items: center;">
			<?php if ( $quote->getConvertedOrderId() ) : ?>
				<p style="margin:0;">
					<?php esc_html_e( 'Converted to Order:', 'tier-pricing-table' ); ?>
					<a href="<?php echo esc_url( get_edit_post_link( $quote->getConvertedOrderId() ) ); ?>">
						<strong>#<?php echo esc_html( $quote->getConvertedOrderId() ); ?></strong>
					</a>
				</p>
			<?php else : ?>
				<input type="submit" name="tpt_convert_to_order" class="button button-primary"
				       value="<?php esc_attr_e( 'Convert to Order', 'tier-pricing-table' ); ?>">
			<?php endif; ?>
		</div>
		<?php
	}

	public function renderActionsMetaBox( $post ) {
		$quote = new QuoteRequest( $post );
		wp_nonce_field( 'tier_pricing_table_quote_save_product', 'tier_pricing_table_quote_nonce' );
		?>
		<div class="submitbox" id="submitpost">
			<div id="minor-publishing">
				<div style="padding: 10px;">
					<label for="tier_pricing_table_quote_status"
					       style="font-weight: 600; display: block; margin-bottom: 5px;"><?php esc_html_e( 'Status:',
								'tier-pricing-table' ); ?></label>
					<select name="tier_pricing_table_quote_status" id="tier_pricing_table_quote_status" style="width: 100%;">
						<option value="unread" <?php selected( $quote->getStatus(),
								'unread' ); ?>><?php esc_html_e( 'Unread', 'tier-pricing-table' ); ?></option>
						<option value="read" <?php selected( $quote->getStatus(), 'read' ); ?>><?php esc_html_e( 'Read',
									'tier-pricing-table' ); ?></option>
						<option value="converted" <?php selected( $quote->getStatus(),
								'converted' ); ?>><?php esc_html_e( 'Converted', 'tier-pricing-table' ); ?></option>
						<option value="rejected" <?php selected( $quote->getStatus(),
								'rejected' ); ?>><?php esc_html_e( 'Rejected', 'tier-pricing-table' ); ?></option>
					</select>
				</div>
				<?php
					$formId = $quote->getMeta( '_form_id' );
					if ( $formId ) {
						$form = \TierPricingTable\Addons\RequestAQuote\Models\RequestQuoteForm::get( (string) $formId );
						if ( $form ) {
							$formUrl = admin_url( 'admin.php?page=wc-settings&tab=tiered_pricing_table_settings&section=request-a-quote&form_id=' . $formId );
							?>
							<div style="padding: 10px; border-top: 1px solid #dcdcde;">
								<label style="font-weight: 600; display: block; margin-bottom: 5px;"><?php esc_html_e( 'Submitted via Form:',
											'tier-pricing-table' ); ?></label>
								<a href="<?php echo esc_url( $formUrl ); ?>" target="_blank"
								   style="text-decoration: none; font-weight: 500; display: flex; align-items: center; gap: 4px;">
									<span class="dashicons dashicons-admin-page"
									      style="font-size: 16px; width: 16px; height: 16px;"></span>
									<?php echo esc_html( $form->getName() ?: __( 'Unnamed Form',
											'tier-pricing-table' ) ); ?>
								</a>
							</div>
							<?php
						}
					}
				?>
			</div>
			<div id="major-publishing-actions"
			     style="display: flex; justify-content: space-between; align-items: center;">
				<div id="delete-action">
					<a class="submitdelete deletion"
					   href="<?php echo get_delete_post_link( $post->ID ); ?>"><?php esc_html_e( 'Move to Trash' ); ?></a>
				</div>
				<div id="publishing-action">
					<span class="spinner"></span>
					<input type="submit" name="save" id="publish" class="button button-primary button-large"
					       value="<?php esc_attr_e( 'Save Quote', 'tier-pricing-table' ); ?>">
				</div>
			</div>
		</div>
		<?php
	}

	public function renderCustomerMetaBox( $post ) {
		$quote        = new QuoteRequest( $post );
		$userId       = $quote->getUserId();
		$email        = $quote->getCustomerEmail();
		$customFields = $quote->getCustomFields();

		$name = '';
		$user = null;

		if ( $userId ) {
			$user = get_userdata( $userId );
			if ( $user ) {
				$name  = $user->display_name;
				$email = $user->user_email ?: $email;
			}
		}

		if ( ! $name ) {
			// Try to find name in custom fields
			foreach ( $customFields as $k => $field ) {
				$fieldKey = strtolower( $k );
				if ( strpos( $fieldKey, 'name' ) !== false || strpos( $fieldKey, 'first_name' ) !== false ) {
					$name = is_array( $field ) ? ( $field['value'] ?? '' ) : $field;
					break;
				}
			}
			if ( ! $name ) {
				$name = __( 'Guest', 'tier-pricing-table' );
			}
		}

		$avatarUrl = get_avatar_url( $userId ?: $email, array( 'size' => 160 ) );
		?>
		<div class="tpt-quote-customer-widget" style="text-align: center; padding: 10px 0;">
			<div class="tpt-avatar-wrapper" style="margin-bottom: 15px;">
				<?php if ( $userId && $user ) : ?>
					<img src="<?php echo esc_url( $avatarUrl ); ?>" alt="Avatar"
					     style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover;">
				<?php else : ?>
					<div style="width: 80px; height: 80px; border-radius: 50%; background: #f0f0f1; display: inline-flex; align-items: center; justify-content: center; margin: 0 auto; border: 1px solid #c3c4c7;">
						<span class="dashicons dashicons-admin-users"
						      style="font-size: 40px; width: 40px; height: 40px; color: #8c8f94;"></span>
					</div>
				<?php endif; ?>
			</div>

			<div style="font-size: 16px; font-weight: 600; color: #1d2327; margin-bottom: 5px;">
				<?php echo esc_html( $name ); ?>
			</div>

			<?php if ( $email ) : ?>
				<div style="font-size: 13px; color: #50575e; margin-bottom: 20px;">
					<a href="mailto:<?php echo esc_attr( $email ); ?>"
					   style="text-decoration: none; color: inherit;"><?php echo esc_html( $email ); ?></a>
				</div>
			<?php endif; ?>

			<?php if ( $userId && $user ) : ?>
				<a href="<?php echo esc_url( get_edit_user_link( $userId ) ); ?>" class="button"
				   style="width: 100%; text-align: center; display: block; padding: 4px 0;">
					<?php esc_html_e( 'View Profile', 'tier-pricing-table' ); ?>
				</a>
			<?php else : ?>
				<div style="background: #f0f0f1; color: #3c434a; padding: 8px; border-radius: 4px; font-weight: 500; text-align: center; border: 1px solid #c3c4c7; display: flex; justify-content: center; align-items: center; gap: 8px;">
					<span class="dashicons dashicons-admin-users"
					      style="font-size: 18px; line-height: 1; width: 18px; height: 18px; color: #8c8f94;"></span>
					<span><?php esc_html_e( 'Guest User', 'tier-pricing-table' ); ?></span>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	public function savePost( $post_id, $post ) {
		if ( get_post_type( $post_id ) !== QuoteRequestCPT::POST_TYPE ) {
			return;
		}

		if ( ! isset( $_POST['tier_pricing_table_quote_nonce'] ) || ! wp_verify_nonce( $_POST['tier_pricing_table_quote_nonce'],
						'tier_pricing_table_quote_save_product' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$quote = new QuoteRequest( $post_id );

		if ( isset( $_POST['tier_pricing_table_quote_price'] ) ) {
			$quote->setPrice( wc_format_decimal( $_POST['tier_pricing_table_quote_price'] ) );
		}

		if ( isset( $_POST['tier_pricing_table_quote_quantity'] ) ) {
			$quote->setQuantity( (int) $_POST['tier_pricing_table_quote_quantity'] );
		}

		if ( isset( $_POST['tier_pricing_table_quote_status'] ) ) {
			$quote->setStatus( sanitize_text_field( $_POST['tier_pricing_table_quote_status'] ) );
		}

		// Handle Conversion to Order
		if ( isset( $_POST['tpt_convert_to_order'] ) && ! $quote->getConvertedOrderId() ) {
			$productId = $quote->getProductId();
			$quantity  = $quote->getQuantity();
			$price     = $quote->getPrice();

			$order = wc_create_order();
			$order->add_product( wc_get_product( $productId ), $quantity, array(
					'subtotal' => $price * $quantity,
					'total'    => $price * $quantity,
			) );

			$order->calculate_totals();

			// Add customer note
			// Add customer note
			$customFields = $quote->getCustomFields();
			$notes        = "Quote Request Notes:\n";
			foreach ( $customFields as $k => $fieldData ) {
				if ( ! is_array( $fieldData ) ) {
					$fieldData = array( 'label' => ucfirst( str_replace( '_', ' ', $k ) ), 'value' => $fieldData );
				}
				$label = isset( $fieldData['label'] ) && ! empty( $fieldData['label'] ) ? $fieldData['label'] : ucfirst( str_replace( '_',
						' ', $k ) );
				$value = isset( $fieldData['value'] ) ? $fieldData['value'] : '';

				$notes .= $label . ": " . $value . "\n";
			}
			$order->update_meta_data( 'quote_request_notes', $notes );
			$order->save();

			// Update quote
			remove_action( 'save_post', array( $this, 'savePost' ), 10, 2 );

			$quote->setConvertedOrderId( $order->get_id() );
			$quote->setStatus( 'converted' );
			$quote->save();

			add_filter( 'redirect_post_location', function ( $location ) use ( $order ) {
				return $order->get_edit_order_url();
			} );

			return;
		}

		$quote->save();
	}

	public function setCustomColumns( $columns ) {
		$columns = array(
				'cb'       => '<input type="checkbox" />',
				'title'    => __( 'Quote', 'tier-pricing-table' ),
				'customer' => __( 'Customer', 'tier-pricing-table' ),
				'product'  => __( 'Product', 'tier-pricing-table' ),
				'status'   => __( 'Status', 'tier-pricing-table' ),
				'date'     => __( 'Date', 'tier-pricing-table' ),
		);

		return $columns;
	}

	public function customColumnData( $column, $post_id ) {
		$quote                = new QuoteRequest( $post_id );

		switch ( $column ) {
			case 'customer':
				$name = $quote->getCustomerName();
				$email        = $quote->getCustomerEmail();
				?>
				<?php echo esc_html( $name ); ?><br>
				<?php if ( $email ) : ?>
				<a href="mailto:<?php echo esc_attr( $email ); ?>"><?php echo esc_html( $email ); ?></a>
			<?php endif; ?>
				<?php
				break;

			case 'product':
				$productId = $quote->getProductId();
				$quantity     = $quote->getQuantity();

				$product = wc_get_product( $productId );
				if ( $product ) :
					?>
					<a href="<?php echo esc_url( get_edit_post_link( $product->get_id() ) ); ?>"><strong><?php echo wp_kses_post( $product->get_name() ); ?></strong></a>
					<br>
					<small><?php esc_html_e( 'Qty:',
								'tier-pricing-table' ); ?><?php echo esc_html( $quantity ? $quantity : 1 ); ?></small>
				<?php
				else :
					echo '&mdash;';
				endif;
				break;

			case 'status':
				$status = $quote->getStatus();
				$statusLabels = array(
						'unread'    => __( 'Unread', 'tier-pricing-table' ),
						'read'      => __( 'Read', 'tier-pricing-table' ),
						'converted' => __( 'Converted', 'tier-pricing-table' ),
						'rejected'  => __( 'Rejected', 'tier-pricing-table' ),
				);
				$label        = isset( $statusLabels[ $status ] ) ? $statusLabels[ $status ] : $status;
				?>
				<mark class="tpt-quote-status status-<?php echo esc_attr( $status ); ?>">
					<span><?php echo esc_html( $label ); ?></span></mark>
				<?php
				break;
		}
	}

	public function addProductsFilter() {
		global $typenow;

		if ( QuoteRequestCPT::POST_TYPE === $typenow ) {
			wp_enqueue_script( 'wc-enhanced-select' );
			wp_enqueue_style( 'woocommerce_admin_styles' );

			$selected = isset( $_GET['tpt_product_filter'] ) ? absint( $_GET['tpt_product_filter'] ) : 0;
			?>
			<select name="tpt_product_filter" id="tpt_product_filter" class="wc-product-search" style="width: 250px;"
			        data-allow_clear="true"
			        data-placeholder="<?php esc_attr_e( 'Filter by product', 'tier-pricing-table' ); ?>"
			        data-action="woocommerce_json_search_products_and_variations">
				<option value=""><?php esc_html_e( 'Show all products', 'tier-pricing-table' ); ?></option>
				<?php if ( $selected > 0 ) : ?>
					<?php $product = wc_get_product( $selected ); ?>
					<?php if ( $product ) : ?>
						<option value="<?php echo esc_attr( $selected ); ?>"
						        selected="selected"><?php echo esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ); ?></option>
					<?php endif; ?>
				<?php endif; ?>
			</select>
			<?php
		}
	}

	public function filterByProduct( $query ) {
		global $pagenow;

		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( 'edit.php' === $pagenow && isset( $_GET['post_type'] ) && QuoteRequestCPT::POST_TYPE === $_GET['post_type'] && isset( $_GET['tpt_product_filter'] ) && $_GET['tpt_product_filter'] > 0 ) {
			$meta_query = $query->get( 'meta_query' );
			if ( ! is_array( $meta_query ) ) {
				$meta_query = array();
			}

			$meta_query[] = array(
					'key'     => '_product_id',
					'value'   => absint( $_GET['tpt_product_filter'] ),
					'compare' => '=',
			);

			$query->set( 'meta_query', $meta_query );
		}
	}

	public function registerBulkActions( $bulk_actions ) {
		$bulk_actions['tpt_mark_unread']   = __( 'Mark as Unread', 'tier-pricing-table' );
		$bulk_actions['tpt_mark_read']     = __( 'Mark as Read', 'tier-pricing-table' );
		$bulk_actions['tpt_mark_rejected'] = __( 'Mark as Rejected', 'tier-pricing-table' );

		return $bulk_actions;
	}

	public function processBulkActions( $redirect_to, $doaction, $post_ids ) {
		if ( ! in_array( $doaction, array( 'tpt_mark_unread', 'tpt_mark_read', 'tpt_mark_rejected' ) ) ) {
			return $redirect_to;
		}

		$changed = 0;
		foreach ( $post_ids as $post_id ) {
			$quote = new QuoteRequest( $post_id );
			if ( 'tpt_mark_unread' === $doaction ) {
				$quote->setStatus( 'unread' );
			} elseif ( 'tpt_mark_read' === $doaction ) {
				$quote->setStatus( 'read' );
			} elseif ( 'tpt_mark_rejected' === $doaction ) {
				$quote->setStatus( 'rejected' );
			}
			$quote->save();
			$changed ++;
		}

		$redirect_to = add_query_arg( 'tpt_bulk_changed', $changed, $redirect_to );
		$redirect_to = add_query_arg( 'tpt_bulk_action', $doaction, $redirect_to );

		return $redirect_to;
	}

	public function bulkActionNotices() {
		if ( ! empty( $_REQUEST['tpt_bulk_changed'] ) && ! empty( $_REQUEST['tpt_bulk_action'] ) ) {
			$changed = absint( $_REQUEST['tpt_bulk_changed'] );
			$action  = sanitize_text_field( $_REQUEST['tpt_bulk_action'] );

			if ( 'tpt_mark_unread' === $action ) {
				// translators: %s: number of quotes.
				$message = sprintf( _n( '%s quote marked as unread.', '%s quotes marked as unread.', $changed,
						'tier-pricing-table' ), $changed );
			} elseif ( 'tpt_mark_read' === $action ) {
				// translators: %s: number of quotes.
				$message = sprintf( _n( '%s quote marked as read.', '%s quotes marked as read.', $changed,
						'tier-pricing-table' ), $changed );
			} elseif ( 'tpt_mark_rejected' === $action ) {
				// translators: %s: number of quotes.
				$message = sprintf( _n( '%s quote marked as rejected.', '%s quotes marked as rejected.', $changed,
						'tier-pricing-table' ), $changed );
			} else {
				return;
			}

			echo '<div id="message" class="updated notice is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
	}
}