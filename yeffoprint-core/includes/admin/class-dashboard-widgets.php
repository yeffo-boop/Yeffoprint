<?php
/**
 * The YeffoPrint dashboard's operational widgets — direct request:
 * "in one screen: pending orders, pending proofs, proofs waiting on
 * customer approval," each flagged once it's past a configurable due
 * date (default 7 days from the order date, see
 * YeffoPrint_Admin_Menu::DASHBOARD_DUE_DATE_DAYS_OPTION).
 *
 * Rendered from class-admin-menu.php's own render_dashboard(), which
 * still owns the page itself (menu registration, the existing quick-
 * links grid) — this class only owns the data tables, kept separate
 * since it needs its own WC-order/CustomOrder/subscriber queries and
 * due-date math that don't belong mixed into that already
 * multi-purpose class.
 *
 * "Pending orders" and the due-date check both cover two genuinely
 * different systems that don't share a status model (docs/
 * ARCHITECTURE.md §6): native WooCommerce orders still in
 * "Processing" (paid, not yet fulfilled), and yp_custom_order requests
 * still stuck in the design/proof pipeline — a direct follow-up
 * request, since a stalled custom design needs staff action just as
 * much as an unshipped order does. Both are read-only "what needs
 * attention" views; nothing here changes order/proof state.
 *
 * A fourth section, Active Maintenance Subscribers, is a third and
 * genuinely separate system again (includes/maintenance/) — a Stripe
 * subscription synced in by webhook, not a WooCommerce order or a
 * yp_custom_order at all. See render_maintenance_section()'s own
 * docblock for why it isn't built on the shared render_section() below.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Dashboard_Widgets {

	/** How many rows each section shows at most — this is a glanceable dashboard, not a full order list (every section links through to its own full admin list). */
	private const ROW_LIMIT = 25;

	public function render(): void {
		$due_date_days = self::due_date_days();
		?>
		<div class="yp-dashboard__widgets">
			<?php $this->render_orders_section( $due_date_days ); ?>
			<?php $this->render_custom_order_section(
				__( 'Pending Proofs', 'yeffoprint-core' ),
				__( 'Custom orders staff still owes a proof — brand new, or the customer just requested changes.', 'yeffoprint-core' ),
				'design_in_progress',
				$due_date_days
			); ?>
			<?php $this->render_custom_order_section(
				__( 'Awaiting Customer Approval', 'yeffoprint-core' ),
				__( "A proof has been sent — waiting on the customer to approve it or request changes.", 'yeffoprint-core' ),
				'awaiting_approval',
				$due_date_days
			); ?>
			<?php $this->render_maintenance_section(); ?>
		</div>
		<?php
	}

	/**
	 * Active Stripe maintenance-plan subscribers (includes/maintenance/).
	 * Deliberately not built on render_section() above — that method's
	 * fourth column is "how overdue," which has no equivalent meaning
	 * for a healthy, ongoing subscription (there's no due date a
	 * subscriber can be "late" against), so forcing this data through it
	 * would show a confusing overdue/days-ago label on every row. Same
	 * section/table styling classes, own column set instead.
	 */
	private function render_maintenance_section(): void {
		$subscribers = YeffoPrint_Maintenance_Sub_Meta::get_active();
		?>
		<section class="yp-dashboard__section">
			<div class="yp-dashboard__section-header">
				<h2><?php esc_html_e( 'Active Maintenance Subscribers', 'yeffoprint-core' ); ?></h2>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=yp_maintenance_sub' ) ); ?>"><?php esc_html_e( 'View all', 'yeffoprint-core' ); ?> &rarr;</a>
			</div>
			<p class="description"><?php esc_html_e( 'Customers currently paying for ongoing site maintenance & monitoring.', 'yeffoprint-core' ); ?></p>

			<?php if ( ! $subscribers ) : ?>
				<p class="description"><?php esc_html_e( 'Nothing here right now.', 'yeffoprint-core' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Customer', 'yeffoprint-core' ); ?></th>
							<th><?php esc_html_e( 'Plan', 'yeffoprint-core' ); ?></th>
							<th><?php esc_html_e( 'Renews', 'yeffoprint-core' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $subscribers as $post ) :
							$plan    = get_post_meta( $post->ID, YeffoPrint_Maintenance_Sub_Meta::PLAN_LABEL, true );
							$renews  = (int) get_post_meta( $post->ID, YeffoPrint_Maintenance_Sub_Meta::CURRENT_PERIOD_END, true );
							?>
							<tr>
								<td><a href="<?php echo esc_url( (string) get_edit_post_link( $post->ID, 'raw' ) ); ?>"><?php echo esc_html( get_the_title( $post ) ); ?></a></td>
								<td><?php echo esc_html( $plan ?: '—' ); ?></td>
								<td><?php echo $renews ? esc_html( wp_date( get_option( 'date_format' ), $renews ) ) : '&#8212;'; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
		<?php
	}

	/** Regular WooCommerce orders, paid but not yet fulfilled — "Processing" is this store's own established meaning for that (class-custom-order-payment.php treats it as one of the three "payment confirmed" triggers). */
	private function render_orders_section( int $due_date_days ): void {
		$orders = wc_get_orders( [
			'status'  => 'processing',
			'limit'   => self::ROW_LIMIT,
			'orderby' => 'date',
			'order'   => 'ASC', // Oldest (most overdue-prone) first.
		] );

		$rows = array_map( function ( \WC_Order $order ) use ( $due_date_days ) {
			$date = $order->get_date_created();
			return [
				'label'    => sprintf(
					/* translators: %s: order number */
					__( 'Order %s', 'yeffoprint-core' ),
					$order->get_order_number()
				),
				'customer' => $order->get_formatted_billing_full_name() ?: $order->get_billing_email(),
				'url'      => $order->get_edit_order_url(),
				'date'     => $date,
			];
		}, $orders );

		$this->render_section(
			__( 'Pending Orders', 'yeffoprint-core' ),
			__( 'Paid, not yet shipped.', 'yeffoprint-core' ),
			$this->orders_list_url(),
			$rows,
			$due_date_days
		);
	}

	/** Both "Pending Proofs" and "Awaiting Customer Approval" are the same query shape, just a different _yp_status value — one method, not two near-duplicates. */
	private function render_custom_order_section( string $title, string $description, string $status, int $due_date_days ): void {
		$query = new \WP_Query( [
			'post_type'      => 'yp_custom_order',
			'post_status'    => 'publish',
			'posts_per_page' => self::ROW_LIMIT,
			'orderby'        => 'date',
			'order'          => 'ASC',
			'meta_query'     => [
				[
					'key'   => YeffoPrint_Custom_Order_Meta::STATUS,
					'value' => $status,
				],
			],
		] );

		$rows = array_map( function ( \WP_Post $post ) {
			$customer = get_post_meta( $post->ID, YeffoPrint_Custom_Order_Meta::CUSTOMER_NAME, true )
				?: get_post_meta( $post->ID, YeffoPrint_Custom_Order_Meta::CUSTOMER_EMAIL, true );

			return [
				'label'    => get_the_title( $post ),
				'customer' => (string) $customer,
				'url'      => (string) get_edit_post_link( $post->ID, 'raw' ),
				'date'     => get_post_datetime( $post ) ?: null,
			];
		}, $query->posts );

		$this->render_section( $title, $description, admin_url( 'edit.php?post_type=yp_custom_order' ), $rows, $due_date_days );
	}

	/**
	 * @param array<int, array{label:string, customer:string, url:string, date:?\DateTimeInterface}> $rows
	 */
	private function render_section( string $title, string $description, string $view_all_url, array $rows, int $due_date_days ): void {
		?>
		<section class="yp-dashboard__section">
			<div class="yp-dashboard__section-header">
				<h2><?php echo esc_html( $title ); ?></h2>
				<a href="<?php echo esc_url( $view_all_url ); ?>"><?php esc_html_e( 'View all', 'yeffoprint-core' ); ?> &rarr;</a>
			</div>
			<p class="description"><?php echo esc_html( $description ); ?></p>

			<?php if ( ! $rows ) : ?>
				<p class="description"><?php esc_html_e( 'Nothing here right now.', 'yeffoprint-core' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Order', 'yeffoprint-core' ); ?></th>
							<th><?php esc_html_e( 'Customer', 'yeffoprint-core' ); ?></th>
							<th><?php esc_html_e( 'Date', 'yeffoprint-core' ); ?></th>
							<th><?php esc_html_e( 'Status', 'yeffoprint-core' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) :
							$days_open  = $row['date'] ? (int) floor( ( time() - $row['date']->getTimestamp() ) / DAY_IN_SECONDS ) : null;
							$is_overdue = null !== $days_open && $days_open > $due_date_days;
							?>
							<tr<?php echo $is_overdue ? ' class="yp-dashboard__row--overdue"' : ''; ?>>
								<td><a href="<?php echo esc_url( $row['url'] ); ?>"><?php echo esc_html( $row['label'] ); ?></a></td>
								<td><?php echo esc_html( $row['customer'] ); ?></td>
								<td><?php echo $row['date'] ? esc_html( wp_date( get_option( 'date_format' ), $row['date']->getTimestamp() ) ) : '&#8212;'; ?></td>
								<td>
									<?php if ( null === $days_open ) : ?>
										&#8212;
									<?php elseif ( $is_overdue ) : ?>
										<span class="yp-dashboard__overdue-tag">
											<?php
											printf(
												/* translators: %d: number of days past the due date */
												esc_html( _n( '%d day overdue', '%d days overdue', $days_open - $due_date_days, 'yeffoprint-core' ) ),
												$days_open - $due_date_days
											);
											?>
										</span>
									<?php else : ?>
										<?php
										printf(
											/* translators: %d: number of days since the order/request was placed */
											esc_html( _n( '%d day ago', '%d days ago', $days_open, 'yeffoprint-core' ) ),
											$days_open
										);
										?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
		<?php
	}

	private function orders_list_url(): string {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
		) {
			return admin_url( 'admin.php?page=wc-orders' );
		}

		return admin_url( 'edit.php?post_type=shop_order' );
	}

	/** Admin-configurable on the Settings page (class-admin-menu.php) — never less than 1, since a same-day due date makes every fresh order instantly "overdue." */
	public static function due_date_days(): int {
		return max( 1, (int) get_option( YeffoPrint_Admin_Menu::DASHBOARD_DUE_DATE_DAYS_OPTION, YeffoPrint_Admin_Menu::DASHBOARD_DUE_DATE_DAYS_DEFAULT ) );
	}
}
