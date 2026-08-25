<?php
/**
 * Post meta for the yp_maintenance_sub record — one per Stripe
 * maintenance-plan subscriber, kept in sync by the Stripe webhook
 * (class-stripe-webhook-controller.php). This CPT deliberately doesn't
 * go through WooCommerce's own order/product system at all — the
 * subscription is sold via a direct Stripe Payment Link, a second,
 * separate Stripe connection from the one WooPayments already manages
 * (see docs/ARCHITECTURE.md) — so this is the one place a maintenance
 * subscriber's status actually lives on this site.
 *
 * post_title is the customer's email, for a readable admin list with
 * zero extra rendering work.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Maintenance_Sub_Meta {

	public const STRIPE_SUBSCRIPTION_ID = '_yp_stripe_subscription_id';
	public const STRIPE_CUSTOMER_ID     = '_yp_stripe_customer_id';
	public const CUSTOMER_EMAIL         = '_yp_customer_email';

	/**
	 * A convenience link to a matching WP_User, resolved by email at
	 * webhook time (get_user_by('email', ...)) — not required for the
	 * record to be valid, since a Stripe subscriber has no obligation to
	 * also have a WordPress account here.
	 */
	public const CUSTOMER_USER_ID = '_yp_customer_user_id';

	public const PLAN_LABEL         = '_yp_plan_label';
	public const STATUS             = '_yp_status';
	public const CURRENT_PERIOD_END = '_yp_current_period_end';

	public const STATUSES = [
		'active'   => 'Active',
		'past_due' => 'Past Due',
		'canceled' => 'Canceled',
	];

	public function __construct() {
		add_action( 'init', [ $this, 'register_meta' ] );
		add_filter( 'manage_yp_maintenance_sub_posts_columns', [ $this, 'columns' ] );
		add_action( 'manage_yp_maintenance_sub_posts_custom_column', [ $this, 'render_column' ], 10, 2 );
	}

	public function register_meta(): void {
		foreach ( [ self::STRIPE_SUBSCRIPTION_ID, self::STRIPE_CUSTOMER_ID, self::CUSTOMER_EMAIL, self::PLAN_LABEL, self::STATUS ] as $key ) {
			register_post_meta( 'yp_maintenance_sub', $key, [
				'type'          => 'string',
				'single'        => true,
				'default'       => '',
				'show_in_rest'  => true,
				'auth_callback' => [ $this, 'can_edit' ],
			] );
		}

		register_post_meta( 'yp_maintenance_sub', self::CUSTOMER_USER_ID, [
			'type'          => 'integer',
			'single'        => true,
			'default'       => 0,
			'show_in_rest'  => true,
			'auth_callback' => [ $this, 'can_edit' ],
		] );

		register_post_meta( 'yp_maintenance_sub', self::CURRENT_PERIOD_END, [
			'type'          => 'integer',
			'single'        => true,
			'default'       => 0,
			'show_in_rest'  => true,
			'auth_callback' => [ $this, 'can_edit' ],
			'description'   => 'Unix timestamp.',
		] );
	}

	public function can_edit(): bool {
		return current_user_can( 'edit_posts' );
	}

	public function columns( array $columns ): array {
		$result = [];

		foreach ( $columns as $key => $label ) {
			if ( 'title' === $key ) {
				$result[ $key ]      = __( 'Customer', 'yeffoprint-core' );
				$result['yp_plan']   = __( 'Plan', 'yeffoprint-core' );
				$result['yp_status'] = __( 'Status', 'yeffoprint-core' );
				$result['yp_renews'] = __( 'Renews', 'yeffoprint-core' );
				continue;
			}
			$result[ $key ] = $label;
		}

		return $result;
	}

	public function render_column( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'yp_plan':
				echo esc_html( (string) get_post_meta( $post_id, self::PLAN_LABEL, true ) ?: '—' );
				break;

			case 'yp_status':
				$status = (string) get_post_meta( $post_id, self::STATUS, true );
				echo esc_html( self::STATUSES[ $status ] ?? '—' );
				break;

			case 'yp_renews':
				$timestamp = (int) get_post_meta( $post_id, self::CURRENT_PERIOD_END, true );
				echo $timestamp ? esc_html( date_i18n( get_option( 'date_format' ), $timestamp ) ) : esc_html__( '—', 'yeffoprint-core' );
				break;
		}
	}

	/** The existing record for a Stripe subscription id, if one exists — the webhook's own upsert key. */
	public static function find_by_subscription_id( string $subscription_id ): ?WP_Post {
		$posts = get_posts( [
			'post_type'      => 'yp_maintenance_sub',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'meta_query'     => [ [ 'key' => self::STRIPE_SUBSCRIPTION_ID, 'value' => $subscription_id ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small, admin-managed table.
		] );

		return $posts ? $posts[0] : null;
	}

	/** Published/any-status records currently marked 'active', for the Dashboard widget and admin lookups. */
	public static function get_active(): array {
		return get_posts( [
			'post_type'      => 'yp_maintenance_sub',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'meta_query'     => [ [ 'key' => self::STATUS, 'value' => 'active' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small, admin-managed table.
			'orderby'        => 'date',
			'order'          => 'DESC',
		] );
	}
}
